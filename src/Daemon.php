<?php
declare(strict_types=1);

namespace XaNmea;

use XaNmea\Control\ControlServer;
use XaNmea\Decode\Decoder;
use XaNmea\Io\Iface;
use XaNmea\Io\SerialIface;
use XaNmea\Io\TcpClientIface;
use XaNmea\Io\TcpServerIface;
use XaNmea\Io\UdpIface;

/**
 * Daemon: owns the event loop and all components.
 *
 * Reliability rules enforced here:
 *  - every per-interface and per-client callback runs in its own
 *    try/catch; a faulting interface is closed and retried, never fatal
 *  - decode/tail hooks are fire-and-forget from the router's perspective
 *  - the select loop has a bounded 1 Hz housekeeping tick: reconnects,
 *    rate computation, AIS ageing, watchdog heartbeat
 *  - config reload validates the WHOLE new config before touching
 *    anything running
 */
final class Daemon
{
    public const VERSION = '0.1.0';

    private string $configPath;
    private bool $debug;
    private Logger $log;
    private Watchdog $watchdog;
    private Router $router;
    private ControlServer $control;
    private StateStore $state;
    private Decoder $decoder;

    /** @var array<string,Iface> */
    private array $ifaces = [];
    /** @var array<string,string> config hash per iface key, for change detection */
    private array $defHashes = [];

    private bool $running = true;
    private bool $reloadRequested = false;

    // rate computation
    private int $prevIn = 0;
    private int $prevOut = 0;
    private float $ratesIn1s = 0.0;
    private float $ratesOut1s = 0.0;

    public function __construct(string $configPath, bool $debug = false)
    {
        $this->configPath = $configPath;
        $this->debug = $debug;
    }

    public function run(): void
    {
        $config = Config::load($this->configPath); // throws on invalid - fatal at boot, fine
        $d = $config->daemon;

        $this->log = new Logger($this->debug ? 'debug' : $d['log_level'], (bool)$d['syslog']);
        $this->log->info('xanmead ' . self::VERSION . ' starting, config: ' . $this->configPath);

        $this->watchdog = new Watchdog($d['heartbeat_file'], $this->log);
        $this->router = new Router($this->log);
        $this->state = new StateStore($d['ais_max_targets'], $d['ais_stale_sec'], $d['ais_drop_sec']);
        $this->decoder = new Decoder($this->state);
        $this->control = new ControlServer($d['control_socket'], $this->log, $d['stream_qsize']);

        $this->router->setDecodeHook(fn(Sentence $s) => $this->decoder->feed($s));
        $this->router->setTailHook(function (Sentence $s, array $dst) {
            $this->control->broadcastTail($s->srcName, $dst, $s->raw, $s->checksumOk || $s->checksum === null);
        });
        $this->state->setDeltaHook(fn(array $delta) => $this->control->broadcastState($delta));
        $this->control->setCommandHook(fn(string $line) => $this->handleControlCommand($line));

        $this->installSignals();
        $this->applyConfig($config);
        $this->control->open();
        $this->watchdog->ready();

        $this->log->info('event loop started, ' . count($this->ifaces) . ' interfaces');
        $this->loop();
        $this->shutdown();
    }

    // ---- main event loop ----

    private function loop(): void
    {
        $nextTick = microtime(true) + 1.0;
        while ($this->running) {
            $this->watchdog->beat();

            $readFds = [];
            $fdOwners = []; // int (resource id) => object handling it

            foreach ($this->control->readFds() as $fd) {
                $readFds[] = $fd;
                $fdOwners[(int)$fd] = $this->control;
            }
            $writeFds = [];
            foreach ($this->ifaces as $if) {
                if (!$if->enabled) {
                    continue;
                }
                foreach ($if->readFds() as $fd) {
                    $readFds[] = $fd;
                    $fdOwners[(int)$fd] = $if;
                }
                foreach ($if->writeFds() as $fd) {
                    $writeFds[] = $fd;
                    $fdOwners[(int)$fd] = $if;
                }
            }
            foreach ($this->control->writeFds() as $fd) {
                $writeFds[] = $fd;
                $fdOwners[(int)$fd] = $this->control;
            }

            $except = null;
            $timeout = max(0.05, $nextTick - microtime(true));
            $sec = (int)$timeout;
            $usec = (int)(($timeout - $sec) * 1e6);

            if ($readFds || $writeFds) {
                $n = @stream_select($readFds, $writeFds, $except, $sec, $usec);
            } else {
                usleep((int)($timeout * 1e6));
                $n = 0;
            }

            if ($n === false) {
                continue; // interrupted by signal
            }
            if ($n > 0) {
                foreach ($readFds as $fd) {
                    $owner = $fdOwners[(int)$fd] ?? null;
                    if ($owner === null) {
                        continue;
                    }
                    try {
                        $owner->onReadable($fd);
                    } catch (\Throwable $e) {
                        $this->faultOwner($owner, 'read', $e);
                    }
                }
                foreach ($writeFds as $fd) {
                    $owner = $fdOwners[(int)$fd] ?? null;
                    if ($owner === null) {
                        continue;
                    }
                    try {
                        $owner->onWritable($fd);
                    } catch (\Throwable $e) {
                        $this->faultOwner($owner, 'write', $e);
                    }
                }
            }

            if (microtime(true) >= $nextTick) {
                $this->tick();
                $nextTick = microtime(true) + 1.0;
            }
            if ($this->reloadRequested) {
                $this->reloadRequested = false;
                $this->reload();
            }
        }
    }

    /** A faulting interface/control owner: log, close, let tick() retry. */
    private function faultOwner(object $owner, string $op, Throwable $e): void
    {
        if ($owner instanceof Iface) {
            $owner->noteError("$op fault: " . $e->getMessage());
            try {
                $owner->close();
            } catch (\Throwable $ignored) {
            }
            if ($owner instanceof TcpClientIface || $owner instanceof SerialIface) {
                $owner->state = 'retry'; // tick() will reopen
            }
            return;
        }
        $this->log->error('control server fault (' . $op . '): ' . $e->getMessage());
    }

    // ---- 1 Hz housekeeping ----

    private function tick(): void
    {
        $now = microtime(true);

        if ($this->reloadRequested) {
            return; // handled in loop after tick
        }

        foreach ($this->ifaces as $if) {
            if (!$if->enabled) {
                continue;
            }
            try {
                $if->tick($now);
            } catch (\Throwable $e) {
                $if->noteError('tick fault: ' . $e->getMessage());
            }
        }

        $this->state->sweep($now);
        $this->state->flushDeltas();
        $this->control->tick();

        // rates
        $totIn = 0;
        $totOut = 0;
        $up = 0;
        foreach ($this->ifaces as $if) {
            $totIn += $if->counters['in'];
            $totOut += $if->counters['out'];
            if ($if->enabled && $if->state === 'up') {
                $up++;
            }
        }
        $this->ratesIn1s = (float)($totIn - $this->prevIn);
        $this->ratesOut1s = (float)($totOut - $this->prevOut);
        $this->prevIn = $totIn;
        $this->prevOut = $totOut;

        $this->watchdog->metrics = [
            'in_per_sec' => $this->ratesIn1s,
            'out_per_sec' => $this->ratesOut1s,
            'ifaces_up' => $up,
            'ifaces_total' => count($this->ifaces),
        ];
        $this->watchdog->tick();
    }

    // ---- config lifecycle ----

    private function applyConfig(Config $config): void
    {
        $this->router->setFailovers($config->failovers);

        $wanted = [];
        foreach ($config->interfaces as $def) {
            $wanted[strtolower($def['name'])] = $def;
        }

        // remove interfaces no longer configured
        foreach ($this->ifaces as $key => $if) {
            if (!isset($wanted[$key])) {
                $this->log->info($if->name . ': removed by config');
                try {
                    $if->close();
                } catch (\Throwable $e) {
                }
                unset($this->ifaces[$key], $this->defHashes[$key]);
            }
        }

        // add new / recreate changed
        foreach ($wanted as $key => $def) {
            $hash = $this->defHash($def);
            $existing = $this->ifaces[$key] ?? null;
            if ($existing !== null && ($this->defHashes[$key] ?? null) === $hash) {
                continue; // unchanged: leave running
            }
            if ($existing !== null) {
                $this->log->info($def['name'] . ': config changed, recreating');
                try {
                    $existing->close();
                } catch (\Throwable $e) {
                }
                unset($this->ifaces[$key], $this->defHashes[$key]);
            }
            if (!$def['enabled']) {
                $this->log->info($def['name'] . ': disabled');
                continue;
            }
            $if = $this->buildIface($def);
            $if->setRouter($this->router);
            $this->ifaces[$key] = $if;
            $this->defHashes[$key] = $hash;
            try {
                $if->open();
            } catch (\Throwable $e) {
                $if->noteError('open failed: ' . $e->getMessage());
                $if->state = 'retry';
                if (!$def['optional']) {
                    $this->log->error($def['name'] . ': non-optional interface failed to open');
                }
            }
        }

        $this->router->setOutputs($this->ifaces);
    }

    private function defHash(array $def): string
    {
        return md5(json_encode($def) ?: '');
    }

    private function buildIface(array $def): Iface
    {
        return match ($def['type']) {
            'serial' => new SerialIface($def, $this->log),
            'tcp_server' => new TcpServerIface($def, $this->log),
            'tcp_client' => new TcpClientIface($def, $this->log),
            'udp' => new UdpIface($def, $this->log),
            default => throw new \RuntimeException('unknown type ' . $def['type']),
        };
    }

    private function reload(): void
    {
        $this->log->info('reload requested');
        try {
            $config = Config::load($this->configPath);
            $this->applyConfig($config);
            $this->log->notice('config reloaded and applied');
        } catch (\Throwable $e) {
            // keep running on the old config
            $this->log->error('reload rejected, keeping current config: ' . $e->getMessage());
        }
    }

    // ---- control commands ----

    private function handleControlCommand(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line));
        $cmd = strtoupper($parts[0] ?? '');

        switch ($cmd) {
            case 'PING':
                return ['ok' => true, 'version' => self::VERSION, 'uptime' => (int)$this->watchdog->uptime()];

            case 'STATS':
                $rows = [];
                foreach ($this->ifaces as $if) {
                    $rows[] = $if->statsRow();
                }
                return [
                    'ok' => true,
                    'version' => self::VERSION,
                    'uptime' => (int)$this->watchdog->uptime(),
                    'rates' => ['in_1s' => $this->ratesIn1s, 'out_1s' => $this->ratesOut1s],
                    'interfaces' => $rows,
                    'events' => $this->log->tail(200),
                ];

            case 'STATE':
                return ['ok' => true, 'state' => $this->state->snapshot($parts[1] ?? 'all')];

            case 'RELOAD':
                $this->reloadRequested = true;
                return ['ok' => true, 'note' => 'reload scheduled'];

            case 'KICK':
                $ifName = strtolower($parts[1] ?? '');
                $clientId = (int)($parts[2] ?? 0);
                $if = $this->ifaces[$ifName] ?? null;
                if ($if instanceof TcpServerIface && $clientId > 0) {
                    return ['ok' => $if->kick($clientId)];
                }
                return ['ok' => false, 'error' => 'unknown interface or client'];

            default:
                return ['ok' => false, 'error' => "unknown command '$cmd'"];
        }
    }

    // ---- signals / shutdown ----

    private function installSignals(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->log->warning('pcntl not available: signals disabled');
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            $this->log->notice('SIGTERM received, shutting down');
            $this->running = false;
        });
        pcntl_signal(SIGINT, function () {
            $this->log->notice('SIGINT received, shutting down');
            $this->running = false;
        });
        pcntl_signal(SIGHUP, function () {
            $this->reloadRequested = true;
        });
        pcntl_signal(SIGPIPE, SIG_IGN);
    }

    private function shutdown(): void
    {
        $this->watchdog->stopping();
        $this->log->info('shutting down');
        foreach ($this->ifaces as $if) {
            try {
                $if->close();
            } catch (\Throwable $e) {
            }
        }
        try {
            $this->control->close();
        } catch (\Throwable $e) {
        }
    }
}
