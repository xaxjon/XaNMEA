<?php
declare(strict_types=1);

namespace XaNmea;

/**
 * Config: load JSON, validate strictly, expose typed accessors.
 * The daemon validates the ENTIRE config before applying anything;
 * a rejected config never disturbs the running set.
 */
final class Config
{
    public array $daemon;
    /** @var array<int,array> validated interface definitions */
    public array $interfaces = [];
    /** @var array<int,array{match:string,priorities:array<int,array{interface:string,delay:int}>}> */
    public array $failovers = [];
    /** @var array<int,array{username:string,password_hash:string,role:string}> */
    public array $users = [];

    public const TYPES = ['serial', 'tcp_server', 'tcp_client', 'udp'];
    public const DIRECTIONS = ['in', 'out', 'both'];
    public const BAUDS = [4800, 9600, 19200, 38400, 57600, 115200, 230400, 460800];

    public static function load(string $path): self
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("cannot read config file: $path");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("config file is not valid JSON: $path");
        }
        $cfg = new self();
        $errors = $cfg->validate($data);
        if ($errors) {
            throw new \RuntimeException("config invalid: " . implode('; ', $errors));
        }
        return $cfg;
    }

    /** Validate raw decoded JSON. Returns list of errors (empty = valid). */
    public function validate(array $data): array
    {
        $errors = [];
        $this->daemon = [];
        $this->interfaces = [];
        $this->failovers = [];
        $this->users = [];

        // ---- daemon section (all optional, defaults applied)
        $d = $data['daemon'] ?? [];
        if (!is_array($d)) {
            $errors[] = 'daemon: must be an object';
            $d = [];
        }
        $this->daemon = [
            'control_socket' => $this->str($d, 'control_socket', '/run/xanmea/control.sock'),
            'heartbeat_file' => $this->str($d, 'heartbeat_file', '/run/xanmea/heartbeat.json'),
            'checksum' => $this->bool($d, 'checksum', true),
            'strict' => $this->bool($d, 'strict', false),
            'qsize' => max(10, $this->int($d, 'qsize', 200)),
            'log_level' => $this->str($d, 'log_level', 'info'),
            'syslog' => $this->bool($d, 'syslog', false),
            'ais_max_targets' => max(10, $this->int($d, 'ais_max_targets', 500)),
            'ais_stale_sec' => max(10, $this->int($d, 'ais_stale_sec', 60)),
            'ais_drop_sec' => max(60, $this->int($d, 'ais_drop_sec', 600)),
            'stream_qsize' => max(50, $this->int($d, 'stream_qsize', 500)),
        ];
        foreach (['ais_max_targets', 'ais_stale_sec', 'ais_drop_sec', 'qsize'] as $k) {
            // validated above via max(); nothing further
        }

        // ---- interfaces
        $ifaces = $data['interfaces'] ?? [];
        if (!is_array($ifaces)) {
            $errors[] = 'interfaces: must be an array';
            $ifaces = [];
        }
        $names = [];
        foreach ($ifaces as $i => $raw) {
            $label = "interfaces[$i]";
            if (!is_array($raw)) {
                $errors[] = "$label: must be an object";
                continue;
            }
            $name = isset($raw['name']) ? trim((string)$raw['name']) : '';
            if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name)) {
                $errors[] = "$label: name must be 1-32 chars of [A-Za-z0-9_-]";
                continue;
            }
            if ($name[0] === '_') {
                $errors[] = "$label: names starting with '_' are reserved";
                continue;
            }
            if (isset($names[strtolower($name)])) {
                $errors[] = "$label: duplicate name '$name'";
                continue;
            }
            $names[strtolower($name)] = true;
            $label = "interface '$name'";

            $type = $raw['type'] ?? '';
            if (!in_array($type, self::TYPES, true)) {
                $errors[] = "$label: type must be one of " . implode(',', self::TYPES);
                continue;
            }
            $direction = $raw['direction'] ?? ($type === 'tcp_server' ? 'both' : 'in');
            if (!in_array($direction, self::DIRECTIONS, true)) {
                $errors[] = "$label: direction must be in|out|both";
                continue;
            }
            if ($type === 'udp' && $direction === 'both') {
                // legal but flagged in UI; allowed here
            }

            $if = [
                'name' => $name,
                'enabled' => $this->bool($raw, 'enabled', true),
                'type' => $type,
                'direction' => $direction,
                // Per-iface value wins; unset falls back to the daemon default.
                'checksum' => $this->boolNullable($raw, 'checksum') ?? $this->daemon['checksum'],
                'strict' => $this->boolNullable($raw, 'strict') ?? $this->daemon['strict'],
                'optional' => $this->bool($raw, 'optional', true),
                'loopback' => $this->bool($raw, 'loopback', false),
                'qsize' => ($q = $this->int($raw, 'qsize', 0)) > 0 ? max(10, $q) : $this->daemon['qsize'], // 0/unset => daemon default
                'srctag' => $this->str($raw, 'srctag', 'no'),
                'timestamp' => $this->str($raw, 'timestamp', 'no'),
                'ifilter' => $raw['ifilter'] ?? null,
                'ofilter' => $raw['ofilter'] ?? null,
            ];
            if (!in_array($if['srctag'], ['no', 'yes', 'input'], true)) {
                $errors[] = "$label: srctag must be no|yes|input";
                continue;
            }
            if (!in_array($if['timestamp'], ['no', 's', 'ms'], true)) {
                $errors[] = "$label: timestamp must be no|s|ms";
                continue;
            }

            switch ($type) {
                case 'serial':
                    $dev = trim((string)($raw['device'] ?? ''));
                    if ($dev === '' || !preg_match('#^/dev/[A-Za-z0-9_./-]+$#', $dev)) {
                        $errors[] = "$label: device must be a /dev/... path";
                        continue 2;
                    }
                    $baud = (int)($raw['baud'] ?? 4800);
                    if (!in_array($baud, self::BAUDS, true)) {
                        $errors[] = "$label: unsupported baud $baud";
                        continue 2;
                    }
                    $if['device'] = $dev;
                    $if['baud'] = $baud;
                    break;

                case 'tcp_server':
                    $port = (int)($raw['port'] ?? 10110);
                    if ($port < 1 || $port > 65535) {
                        $errors[] = "$label: invalid port";
                        continue 2;
                    }
                    $if['port'] = $port;
                    $if['address'] = $this->str($raw, 'address', '0.0.0.0');
                    break;

                case 'tcp_client':
                    $addr = trim((string)($raw['address'] ?? ''));
                    if ($addr === '') {
                        $errors[] = "$label: address is required";
                        continue 2;
                    }
                    $port = (int)($raw['port'] ?? 10110);
                    if ($port < 1 || $port > 65535) {
                        $errors[] = "$label: invalid port";
                        continue 2;
                    }
                    $if['address'] = $addr;
                    $if['port'] = $port;
                    $if['persist'] = $this->bool($raw, 'persist', true);
                    $if['retry'] = max(1, $this->int($raw, 'retry', 10));
                    $if['preamble'] = $this->str($raw, 'preamble', '');
                    $if['nodelay'] = $this->bool($raw, 'nodelay', true);
                    $if['keepalive'] = $this->bool($raw, 'keepalive', true);
                    break;

                case 'udp':
                    $port = (int)($raw['port'] ?? 10110);
                    if ($port < 1 || $port > 65535) {
                        $errors[] = "$label: invalid port";
                        continue 2;
                    }
                    $addr = trim((string)($raw['address'] ?? ''));
                    if ($direction !== 'in' && $addr === '') {
                        $errors[] = "$label: address is required for udp output";
                        continue 2;
                    }
                    $if['port'] = $port;
                    $if['address'] = $addr !== '' ? $addr : '0.0.0.0';
                    $if['coalesce'] = $this->bool($raw, 'coalesce', false);
                    break;
            }

            $this->interfaces[] = $if;
        }

        // ---- failovers
        $fos = $data['failovers'] ?? [];
        if (!is_array($fos)) {
            $errors[] = 'failovers: must be an array';
            $fos = [];
        }
        foreach ($fos as $i => $fo) {
            if (!is_array($fo) || !isset($fo['match']) || !is_array($fo['priorities'] ?? null)) {
                $errors[] = "failovers[$i]: needs match + priorities[]";
                continue;
            }
            $prios = [];
            foreach ($fo['priorities'] as $p) {
                if (!is_array($p) || !isset($p['interface'])) {
                    continue;
                }
                $iname = (string)$p['interface'];
                if (!isset($names[strtolower($iname)])) {
                    $errors[] = "failovers[$i]: unknown interface '$iname'";
                    continue;
                }
                $prios[] = ['interface' => $iname, 'delay' => max(0, (int)($p['delay'] ?? 0))];
            }
            if ($prios) {
                $this->failovers[] = ['match' => (string)$fo['match'], 'priorities' => $prios];
            }
        }

        // ---- users (web UI auth; daemon only validates shape)
        $users = $data['users'] ?? [];
        if (is_array($users)) {
            foreach ($users as $u) {
                if (is_array($u) && isset($u['username'], $u['password_hash'])) {
                    $this->users[] = [
                        'username' => (string)$u['username'],
                        'password_hash' => (string)$u['password_hash'],
                        'role' => in_array($u['role'] ?? '', ['admin', 'viewer'], true) ? $u['role'] : 'viewer',
                    ];
                }
            }
        }

        // At least one enabled interface required
        $enabled = array_filter($this->interfaces, fn($x) => $x['enabled']);
        if (!$enabled) {
            $errors[] = 'no enabled interfaces defined';
        }

        return $errors;
    }

    private function str(array $a, string $k, string $def): string
    {
        return isset($a[$k]) && is_string($a[$k]) ? $a[$k] : $def;
    }

    private function int(array $a, string $k, int $def): int
    {
        return isset($a[$k]) && is_numeric($a[$k]) ? (int)$a[$k] : $def;
    }

    private function bool(array $a, string $k, bool $def): bool
    {
        if (!isset($a[$k])) {
            return $def;
        }
        return filter_var($a[$k], FILTER_VALIDATE_BOOLEAN);
    }

    private function boolNullable(array $a, string $k): ?bool
    {
        if (!isset($a[$k])) {
            return null;
        }
        return filter_var($a[$k], FILTER_VALIDATE_BOOLEAN);
    }
}
