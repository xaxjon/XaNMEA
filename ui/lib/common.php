<?php
declare(strict_types=1);

/**
 * common.php: shared helpers for the XaNMEA web UI.
 * Config path resolution, config load/save (atomic), session auth, CSRF,
 * shared page chrome, interface validation mirroring src/Config.php.
 */

require_once __DIR__ . '/XanClient.php';

// ---------------------------------------------------------------- session

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('xanmea_ui');
    session_start();
}

// ---------------------------------------------------------------- escaping

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------- config file

/**
 * Config path, in priority order:
 *  1. env XANMEA_CONFIG
 *  2. /etc/xanmea/config.json
 *  3. ../config/config.json relative to ui/
 */
function xan_config_path(): string
{
    $env = getenv('XANMEA_CONFIG');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    if (is_file('/etc/xanmea/config.json')) {
        return '/etc/xanmea/config.json';
    }
    return dirname(__DIR__) . '/../config/config.json';
}

/** Load the raw config as an array. Missing/invalid file -> empty skeleton. */
function xan_load_raw_config(): array
{
    $path = xan_config_path();
    $json = @file_get_contents($path);
    if ($json === false) {
        return ['daemon' => [], 'interfaces' => [], 'failovers' => [], 'users' => []];
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['daemon' => [], 'interfaces' => [], 'failovers' => [], 'users' => []];
    }
    foreach (['daemon', 'interfaces', 'failovers', 'users'] as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) {
            $data[$k] = [];
        }
    }
    return $data;
}

/**
 * Write config ATOMICALLY: temp file in the same directory + rename.
 * Returns true on success, or an error string.
 * @return true|string
 */
function xan_save_config(array $cfg)
{
    $path = xan_config_path();
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return 'JSON encode failed';
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true)) {
            return "cannot create config directory: $dir";
        }
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json . "\n") === false) {
        return "cannot write temp config: $tmp";
    }
    if (is_file($path)) {
        @chmod($tmp, fileperms($path) & 0777);
    } else {
        @chmod($tmp, 0640);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return "cannot replace config file: $path";
    }
    return true;
}

function xan_control_socket(): string
{
    $cfg = xan_load_raw_config();
    $sock = $cfg['daemon']['control_socket'] ?? '/run/xanmea/control.sock';
    return is_string($sock) && $sock !== '' ? $sock : '/run/xanmea/control.sock';
}

function xan_client(): XanClient
{
    return new XanClient(xan_control_socket());
}

/**
 * Read the daemon heartbeat JSON file.
 * @return array{present:bool, ts:?float, age:?float, stale:bool, data:array}
 */
function xan_read_heartbeat(): array
{
    $cfg = xan_load_raw_config();
    $file = $cfg['daemon']['heartbeat_file'] ?? '/run/xanmea/heartbeat.json';
    $out = ['present' => false, 'ts' => null, 'age' => null, 'stale' => true, 'data' => []];
    if (!is_string($file) || $file === '') {
        return $out;
    }
    $json = @file_get_contents($file);
    if ($json === false) {
        return $out;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['ts']) || !is_numeric($data['ts'])) {
        return $out;
    }
    $age = microtime(true) - (float)$data['ts'];
    return ['present' => true, 'ts' => (float)$data['ts'], 'age' => $age, 'stale' => $age > 15.0, 'data' => $data];
}

// ---------------------------------------------------------------- auth

/** @return array{username:string,role:string}|null */
function xan_current_user(): ?array
{
    if (isset($_SESSION['xan_user'], $_SESSION['xan_role'])) {
        return ['username' => (string)$_SESSION['xan_user'], 'role' => (string)$_SESSION['xan_role']];
    }
    return null;
}

/** True when config holds at least one user with a usable bcrypt hash. */
function xan_has_valid_users(?array $cfg = null): bool
{
    $cfg = $cfg ?? xan_load_raw_config();
    foreach ($cfg['users'] ?? [] as $u) {
        if (!is_array($u)) {
            continue;
        }
        $hash = (string)($u['password_hash'] ?? '');
        if ($hash !== '' && strpos($hash, '$2y$') === 0 && stripos($hash, 'REPLACE_ME') === false) {
            return true;
        }
    }
    return false;
}

/**
 * Require a logged-in session (optionally a role). For HTML pages: redirect
 * to login.php. For JSON endpoints (pass $json=true): emit 401/403 JSON.
 */
function requireLogin(?string $role = null, bool $json = false): array
{
    $fail = function (int $code, string $msg) use ($json) {
        if ($json) {
            http_response_code($code);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
        } else {
            header('Location: login.php');
        }
        exit;
    };
    $user = xan_current_user();
    if ($user === null) {
        $fail(401, 'not logged in');
    }
    if ($role === 'admin' && $user['role'] !== 'admin') {
        if ($json) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'admin role required']);
            exit;
        }
        http_response_code(403);
        echo 'Forbidden: admin role required';
        exit;
    }
    return $user;
}

// ---------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['xan_csrf'])) {
        $_SESSION['xan_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['xan_csrf'];
}

function csrf_verify(): bool
{
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    return is_string($tok) && $tok !== '' && hash_equals(csrf_token(), $tok);
}

// ---------------------------------------------------------------- page chrome

function page_header(string $title, string $active): void
{
    $user = xan_current_user();
    $nav = [
        'index.php'       => ['Status', 'status'],
        'dashboard.php'   => ['Dashboard', 'dashboard'],
        'ais.php'         => ['AIS', 'ais'],
        'weather.php'     => ['Weather', 'weather'],
        'misc.php'        => ['Misc', 'misc'],
        'interfaces.php'  => ['Interfaces', 'interfaces'],
        'diagnostics.php' => ['Diagnostics', 'diagnostics'],
        'settings.php'    => ['Settings', 'settings'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="csrf" content="' . h(csrf_token()) . '">';
    echo '<title>XaNMEA - ' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="assets/style.css">';
    echo '</head><body>';
    echo '<header class="topbar">';
    echo '<div class="brand">Xa<span>NMEA</span></div>';
    echo '<nav>';
    foreach ($nav as $href => [$label, $key]) {
        $cls = $key === $active ? ' class="active"' : '';
        echo '<a href="' . h($href) . '"' . $cls . '>' . h($label) . '</a>';
    }
    echo '</nav>';
    echo '<div class="topbar-right">';
    if ($user !== null) {
        echo '<span class="who">' . h($user['username']) . ' <em>' . h($user['role']) . '</em></span>';
    }
    echo '<a class="logout" href="logout.php">Logout</a>';
    echo '</div></header>';
    echo '<div id="daemon-banner"></div>';
    echo '<main class="page">';
}

function page_footer(): void
{
    echo '</main>';
    echo '<script>';
    // Shared: daemon-reachability banner, CSRF helper for fetch().
    echo <<<'JS'
(function () {
  window.XAN = window.XAN || {};
  window.XAN.csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  window.XAN.post = async function (action, payload) {
    const r = await fetch('api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.XAN.csrf},
      body: JSON.stringify(payload || {})
    });
    return r.json();
  };
  async function banner() {
    const el = document.getElementById('daemon-banner');
    if (!el) return;
    try {
      const r = await fetch('api.php?action=ping');
      const j = await r.json();
      if (j.ok) {
        el.innerHTML = '';
        if (j.heartbeat && j.heartbeat.stale) {
          el.innerHTML = '<div class="banner warn">Daemon heartbeat is stale (' +
            Math.round(j.heartbeat.age) + 's old) - daemon may be wedged</div>';
        }
      } else {
        el.innerHTML = '<div class="banner err">Daemon unreachable: ' +
          (j.error || 'no reply') + '</div>';
      }
    } catch (e) {
      el.innerHTML = '<div class="banner err">Daemon unreachable</div>';
    }
  }
  banner();
  setInterval(banner, 5000);
})();
JS;
    echo '</script>';
    echo '</body></html>';
}

// ---------------------------------------------------------------- validation
// Mirrors the daemon's rules in src/Config.php. Returns [def|null, errors[]].

const XAN_TYPES = ['serial', 'tcp_server', 'tcp_client', 'udp'];
const XAN_DIRECTIONS = ['in', 'out', 'both'];
const XAN_BAUDS = [4800, 9600, 19200, 38400, 57600, 115200, 230400, 460800];

/**
 * Validate a raw interface definition from the edit form.
 * @param array<string,bool> $takenNames lowercased names already in use
 * @return array{0:?array,1:array<int,string>}
 */
function xan_validate_interface(array $raw, array $takenNames = []): array
{
    $errors = [];

    $name = isset($raw['name']) ? trim((string)$raw['name']) : '';
    if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name)) {
        $errors[] = 'name must be 1-32 chars of [A-Za-z0-9_-]';
    } elseif ($name[0] === '_') {
        $errors[] = "names starting with '_' are reserved";
    } elseif (isset($takenNames[strtolower($name)])) {
        $errors[] = "duplicate name '$name'";
    }

    $type = (string)($raw['type'] ?? '');
    if (!in_array($type, XAN_TYPES, true)) {
        $errors[] = 'type must be one of ' . implode(',', XAN_TYPES);
    }
    $direction = (string)($raw['direction'] ?? ($type === 'tcp_server' ? 'both' : 'in'));
    if (!in_array($direction, XAN_DIRECTIONS, true)) {
        $errors[] = 'direction must be in|out|both';
    }
    if ($errors) {
        return [null, $errors];
    }

    $def = [
        'name'      => $name,
        'enabled'   => !empty($raw['enabled']),
        'type'      => $type,
        'direction' => $direction,
        'optional'  => !array_key_exists('optional', $raw) || !empty($raw['optional']),
        'loopback'  => !empty($raw['loopback']),
    ];

    // nullable tri-state: inherit daemon default when not set
    foreach (['checksum', 'strict'] as $k) {
        if (array_key_exists($k, $raw) && $raw[$k] !== null && $raw[$k] !== '') {
            $def[$k] = (bool)$raw[$k];
        }
    }
    $qsize = (int)($raw['qsize'] ?? 0);
    if ($qsize > 0) {
        $def['qsize'] = max(10, $qsize);
    }
    foreach (['srctag' => ['no', 'yes', 'input'], 'timestamp' => ['no', 's', 'ms']] as $k => $allowed) {
        $v = (string)($raw[$k] ?? 'no');
        if (!in_array($v, $allowed, true)) {
            $errors[] = "$k must be " . implode('|', $allowed);
        } elseif ($v !== 'no') {
            $def[$k] = $v;
        }
    }
    foreach (['ifilter', 'ofilter'] as $k) {
        $v = trim((string)($raw[$k] ?? ''));
        if ($v !== '') {
            $def[$k] = $v;
        }
    }
    $comment = trim((string)($raw['comment'] ?? ''));
    if ($comment !== '') {
        $def['comment'] = $comment;
    }

    switch ($type) {
        case 'serial':
            $dev = trim((string)($raw['device'] ?? ''));
            if ($dev === '' || !preg_match('#^/dev/[A-Za-z0-9_./-]+$#', $dev)) {
                $errors[] = 'device must be a /dev/... path';
            }
            $baud = (int)($raw['baud'] ?? 4800);
            if (!in_array($baud, XAN_BAUDS, true)) {
                $errors[] = "unsupported baud $baud";
            }
            $def['device'] = $dev;
            $def['baud'] = $baud;
            break;

        case 'tcp_server':
            $port = (int)($raw['port'] ?? 10110);
            if ($port < 1 || $port > 65535) {
                $errors[] = 'invalid port';
            }
            $def['port'] = $port;
            $addr = trim((string)($raw['address'] ?? '0.0.0.0'));
            $def['address'] = $addr !== '' ? $addr : '0.0.0.0';
            break;

        case 'tcp_client':
            $addr = trim((string)($raw['address'] ?? ''));
            if ($addr === '') {
                $errors[] = 'address is required';
            }
            $port = (int)($raw['port'] ?? 10110);
            if ($port < 1 || $port > 65535) {
                $errors[] = 'invalid port';
            }
            $def['address'] = $addr;
            $def['port'] = $port;
            $def['persist'] = !array_key_exists('persist', $raw) || !empty($raw['persist']);
            $def['retry'] = max(1, (int)($raw['retry'] ?? 10));
            $def['nodelay'] = !array_key_exists('nodelay', $raw) || !empty($raw['nodelay']);
            $def['keepalive'] = !array_key_exists('keepalive', $raw) || !empty($raw['keepalive']);
            $preamble = (string)($raw['preamble'] ?? '');
            if ($preamble !== '') {
                $def['preamble'] = $preamble;
            }
            break;

        case 'udp':
            $port = (int)($raw['port'] ?? 10110);
            if ($port < 1 || $port > 65535) {
                $errors[] = 'invalid port';
            }
            $addr = trim((string)($raw['address'] ?? ''));
            if ($direction !== 'in' && $addr === '') {
                $errors[] = 'address is required for udp output';
            }
            $def['port'] = $port;
            $def['address'] = $addr !== '' ? $addr : '0.0.0.0';
            $def['coalesce'] = !empty($raw['coalesce']);
            break;
    }

    if ($errors) {
        return [null, $errors];
    }
    return [$def, []];
}
