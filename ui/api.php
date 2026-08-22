<?php
declare(strict_types=1);

/**
 * api.php: JSON endpoint.
 *
 * GET  ?action=ping                 -> PING proxy + heartbeat info
 * GET  ?action=stats                -> STATS proxy (+ heartbeat info)
 * GET  ?action=state[&section=]     -> STATE proxy
 * GET  ?action=backup               -> config file download (admin)
 *
 * POST (JSON body, X-CSRF-Token header):
 *   save_interface, delete_interface, toggle_interface, reload, kick,
 *   change_password, add_user, save_settings, restore_config
 *
 * Daemon errors are returned verbatim inside the reply.
 */

require __DIR__ . '/lib/common.php';

header('Content-Type: application/json');

function reply(array $r): void
{
    echo json_encode($r);
    exit;
}

$action = (string)($_GET['action'] ?? '');

// ---------------------------------------------------------------- GET

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = requireLogin(null, true);

    switch ($action) {
        case 'ping':
            $r = xan_client()->oneShot('PING', 2.0);
            $r['heartbeat'] = xan_read_heartbeat();
            reply($r);

        case 'stats':
            $r = xan_client()->oneShot('STATS', 3.0);
            $r['heartbeat'] = xan_read_heartbeat();
            reply($r);

        case 'state':
            $section = (string)($_GET['section'] ?? 'all');
            if (!in_array($section, ['all', 'ownship', 'ais', 'weather', 'misc', 'sentences'], true)) {
                $section = 'all';
            }
            reply(xan_client()->oneShot('STATE ' . $section, 3.0));

        case 'backup':
            requireLogin('admin', true);
            $path = xan_config_path();
            $json = @file_get_contents($path);
            if ($json === false) {
                http_response_code(500);
                reply(['ok' => false, 'error' => 'cannot read config file']);
            }
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="xanmea-config-' . date('Ymd-His') . '.json"');
            echo $json;
            exit;

        default:
            http_response_code(400);
            reply(['ok' => false, 'error' => 'unknown action']);
    }
}

// ---------------------------------------------------------------- POST

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    reply(['ok' => false, 'error' => 'method not allowed']);
}

$user = requireLogin(null, true);
if (!csrf_verify()) {
    http_response_code(403);
    reply(['ok' => false, 'error' => 'CSRF check failed']);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    reply(['ok' => false, 'error' => 'invalid JSON body']);
}

// change_password is the only action open to non-admins.
$adminActions = ['save_interface', 'delete_interface', 'toggle_interface', 'reload',
                 'kick', 'add_user', 'save_settings', 'restore_config'];
if (in_array($action, $adminActions, true) && $user['role'] !== 'admin') {
    http_response_code(403);
    reply(['ok' => false, 'error' => 'admin role required']);
}

/** Save config then ask the daemon to reload; reply includes daemon reply. */
function save_and_reload(array $cfg, string $note = ''): void
{
    $res = xan_save_config($cfg);
    if ($res !== true) {
        reply(['ok' => false, 'error' => 'config write failed: ' . $res]);
    }
    $r = xan_client()->oneShot('RELOAD', 3.0);
    $out = ['ok' => true, 'note' => $note, 'daemon' => $r];
    if (!($r['ok'] ?? false)) {
        $out['note'] = trim(($note !== '' ? $note . '; ' : '') . 'config saved but RELOAD failed: ' . ($r['error'] ?? 'daemon unreachable'));
    }
    reply($out);
}

switch ($action) {

    case 'save_interface': {
        $raw = $body['interface'] ?? null;
        if (!is_array($raw)) {
            reply(['ok' => false, 'error' => 'missing interface definition']);
        }
        $orig = strtolower(trim((string)($body['orig_name'] ?? '')));
        $cfg = xan_load_raw_config();
        $taken = [];
        foreach ($cfg['interfaces'] as $if) {
            $n = strtolower((string)($if['name'] ?? ''));
            if ($n !== '' && $n !== $orig) {
                $taken[$n] = true;
            }
        }
        [$def, $errors] = xan_validate_interface($raw, $taken);
        if ($def === null) {
            reply(['ok' => false, 'error' => implode('; ', $errors)]);
        }
        // The form always sends 'enabled'; replace in place or append.
        $replaced = false;
        foreach ($cfg['interfaces'] as $i => $if) {
            if (strtolower((string)($if['name'] ?? '')) === $orig) {
                $cfg['interfaces'][$i] = $def;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $cfg['interfaces'][] = $def;
        }
        save_and_reload($cfg, "interface '{$def['name']}' saved");
    }

    case 'delete_interface': {
        $name = strtolower(trim((string)($body['name'] ?? '')));
        $cfg = xan_load_raw_config();
        $found = false;
        $remaining = [];
        foreach ($cfg['interfaces'] as $if) {
            if (strtolower((string)($if['name'] ?? '')) === $name) {
                $found = true;
                continue;
            }
            $remaining[] = $if;
        }
        if (!$found) {
            reply(['ok' => false, 'error' => "no such interface '$name'"]);
        }
        $enabledLeft = 0;
        foreach ($remaining as $if) {
            if (!empty($if['enabled'])) {
                $enabledLeft++;
            }
        }
        if ($enabledLeft === 0) {
            reply(['ok' => false, 'error' => 'cannot delete: daemon requires at least one enabled interface']);
        }
        $cfg['interfaces'] = $remaining;
        save_and_reload($cfg, "interface '$name' deleted");
    }

    case 'toggle_interface': {
        $name = strtolower(trim((string)($body['name'] ?? '')));
        $enabled = !empty($body['enabled']);
        $cfg = xan_load_raw_config();
        $found = false;
        foreach ($cfg['interfaces'] as $i => $if) {
            if (strtolower((string)($if['name'] ?? '')) === $name) {
                $cfg['interfaces'][$i]['enabled'] = $enabled;
                $found = true;
            }
        }
        if (!$found) {
            reply(['ok' => false, 'error' => "no such interface '$name'"]);
        }
        if (!$enabled) {
            $enabledLeft = 0;
            foreach ($cfg['interfaces'] as $if) {
                if (!empty($if['enabled'])) {
                    $enabledLeft++;
                }
            }
            if ($enabledLeft === 0) {
                reply(['ok' => false, 'error' => 'cannot disable: daemon requires at least one enabled interface']);
            }
        }
        save_and_reload($cfg, "interface '$name' " . ($enabled ? 'enabled' : 'disabled'));
    }

    case 'reload':
        reply(xan_client()->oneShot('RELOAD', 3.0));

    case 'kick': {
        $iface = (string)($body['iface'] ?? '');
        $clientId = (int)($body['client_id'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $iface) || $clientId < 1) {
            reply(['ok' => false, 'error' => 'bad iface or client id']);
        }
        reply(xan_client()->oneShot('KICK ' . $iface . ' ' . $clientId, 3.0));
    }

    case 'change_password': {
        $current = (string)($body['current'] ?? '');
        $new = (string)($body['new'] ?? '');
        if (strlen($new) < 8) {
            reply(['ok' => false, 'error' => 'new password must be at least 8 characters']);
        }
        $cfg = xan_load_raw_config();
        $done = false;
        foreach ($cfg['users'] as $i => $u) {
            if (is_array($u) && strcasecmp((string)($u['username'] ?? ''), $user['username']) === 0) {
                if (!password_verify($current, (string)($u['password_hash'] ?? ''))) {
                    reply(['ok' => false, 'error' => 'current password incorrect']);
                }
                $cfg['users'][$i]['password_hash'] = password_hash($new, PASSWORD_BCRYPT);
                $done = true;
                break;
            }
        }
        if (!$done) {
            reply(['ok' => false, 'error' => 'user not found in config']);
        }
        $res = xan_save_config($cfg);
        if ($res !== true) {
            reply(['ok' => false, 'error' => 'config write failed: ' . $res]);
        }
        reply(['ok' => true, 'note' => 'password changed']);
    }

    case 'add_user': {
        $username = trim((string)($body['username'] ?? ''));
        $pass = (string)($body['password'] ?? '');
        $role = (string)($body['role'] ?? 'viewer');
        if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $username)) {
            reply(['ok' => false, 'error' => 'username: 1-32 chars of [A-Za-z0-9_.-]']);
        }
        if (strlen($pass) < 8) {
            reply(['ok' => false, 'error' => 'password must be at least 8 characters']);
        }
        if (!in_array($role, ['admin', 'viewer'], true)) {
            reply(['ok' => false, 'error' => 'role must be admin|viewer']);
        }
        $cfg = xan_load_raw_config();
        foreach ($cfg['users'] as $u) {
            if (is_array($u) && strcasecmp((string)($u['username'] ?? ''), $username) === 0) {
                reply(['ok' => false, 'error' => "user '$username' already exists"]);
            }
        }
        $cfg['users'][] = [
            'username' => $username,
            'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
            'role' => $role,
        ];
        $res = xan_save_config($cfg);
        if ($res !== true) {
            reply(['ok' => false, 'error' => 'config write failed: ' . $res]);
        }
        reply(['ok' => true, 'note' => "user '$username' added"]);
    }

    case 'save_settings': {
        $in = $body['daemon'] ?? null;
        if (!is_array($in)) {
            reply(['ok' => false, 'error' => 'missing daemon settings']);
        }
        $errors = [];
        $d = [];
        $logLevel = (string)($in['log_level'] ?? 'info');
        if (!in_array($logLevel, ['debug', 'info', 'notice', 'warning', 'error'], true)) {
            $errors[] = 'log_level must be debug|info|notice|warning|error';
        }
        $d['log_level'] = $logLevel;
        $d['checksum'] = !empty($in['checksum']);
        $d['strict'] = !empty($in['strict']);
        $d['syslog'] = !empty($in['syslog']);
        foreach (['ais_max_targets' => 10, 'ais_stale_sec' => 10, 'ais_drop_sec' => 60,
                  'qsize' => 10, 'stream_qsize' => 50] as $k => $min) {
            $v = (int)($in[$k] ?? $min);
            if ($v < $min) {
                $errors[] = "$k must be >= $min";
            }
            $d[$k] = max($min, $v);
        }
        if ($errors) {
            reply(['ok' => false, 'error' => implode('; ', $errors)]);
        }
        $cfg = xan_load_raw_config();
        // Merge: keep control_socket / heartbeat_file as-is.
        $cfg['daemon'] = array_merge(is_array($cfg['daemon'] ?? null) ? $cfg['daemon'] : [], $d);
        save_and_reload($cfg, 'daemon settings saved');
    }

    case 'restore_config': {
        $json = (string)($body['config'] ?? '');
        $data = json_decode($json, true);
        if (!is_array($data)) {
            reply(['ok' => false, 'error' => 'uploaded file is not valid JSON']);
        }
        // Validate every interface with the same rules the daemon applies.
        $names = [];
        $errors = [];
        foreach (($data['interfaces'] ?? []) as $i => $rawIf) {
            if (!is_array($rawIf)) {
                $errors[] = "interfaces[$i]: not an object";
                continue;
            }
            [$def, $errs] = xan_validate_interface($rawIf, $names);
            if ($def === null) {
                foreach ($errs as $e) {
                    $errors[] = "interfaces[$i]: $e";
                }
            } else {
                $names[strtolower($def['name'])] = true;
            }
        }
        $enabled = 0;
        foreach (($data['interfaces'] ?? []) as $if) {
            if (is_array($if) && !array_key_exists('enabled', $if) || (is_array($if) && !empty($if['enabled']))) {
                $enabled++;
            }
        }
        if ($enabled === 0) {
            $errors[] = 'no enabled interfaces defined';
        }
        if ($errors) {
            reply(['ok' => false, 'error' => 'restore rejected: ' . implode('; ', $errors)]);
        }
        // Normalize: keep known top-level keys, carry users/failovers through.
        $cfg = [
            'daemon'     => is_array($data['daemon'] ?? null) ? $data['daemon'] : [],
            'interfaces' => is_array($data['interfaces'] ?? null) ? $data['interfaces'] : [],
            'failovers'  => is_array($data['failovers'] ?? null) ? $data['failovers'] : [],
            'users'      => is_array($data['users'] ?? null) ? $data['users'] : [],
        ];
        save_and_reload($cfg, 'config restored');
    }

    default:
        http_response_code(400);
        reply(['ok' => false, 'error' => 'unknown action']);
}
