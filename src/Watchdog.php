<?php
declare(strict_types=1);

namespace XaNmea;

/**
 * Watchdog: layered health assurance for the daemon.
 *
 * Layer 1 - heartbeat file: JSON written atomically every HEARTBEAT_SEC.
 *           External checkers (UI, cron, monit) compare 'ts' to now.
 * Layer 2 - systemd sd_notify: READY=1 at start, WATCHDOG=1 keepalives.
 *           Unit file sets WatchdogSec=; systemd restarts us if we stall.
 * Layer 3 - self-check: tick() verifies loop liveness is monotonic; the
 *           main loop calls beat() every iteration so a wedged loop is
 *           detectable even without systemd.
 *
 * All operations are best-effort and never throw.
 */
final class Watchdog
{
    private const HEARTBEAT_SEC = 5.0;

    private ?string $heartbeatFile;
    private Logger $log;
    private float $lastHeartbeat = 0.0;
    private float $lastNotify = 0.0;
    private float $startTs;
    private int $loopCount = 0;
    private bool $notifyReady = false;
    /** @var resource|null unix dgram socket for sd_notify */
    private $notifySock = null;
    private string $notifyAddr = '';

    // Injected counters, refreshed by Daemon each tick
    public array $metrics = ['in_per_sec' => 0.0, 'out_per_sec' => 0.0, 'ifaces_up' => 0, 'ifaces_total' => 0];

    public function __construct(?string $heartbeatFile, Logger $log)
    {
        $this->heartbeatFile = $heartbeatFile;
        $this->log = $log;
        $this->startTs = microtime(true);
        $this->setupNotify();
    }

    private function setupNotify(): void
    {
        $addr = getenv('NOTIFY_SOCKET');
        if (!is_string($addr) || $addr === '' || !extension_loaded('sockets')) {
            return;
        }
        // Abstract namespace addresses start with '@'
        if ($addr[0] === '@') {
            $addr = "\0" . substr($addr, 1);
        }
        $sock = @socket_create(AF_UNIX, SOCK_DGRAM, 0);
        if ($sock === false) {
            return;
        }
        // Never let a backed-up systemd block the event loop on sendto.
        @socket_set_nonblock($sock);
        $this->notifySock = $sock;
        $this->notifyAddr = $addr;
    }

    private function notify(string $state): void
    {
        if ($this->notifySock === null) {
            return;
        }
        try {
            @socket_sendto($this->notifySock, $state, strlen($state), 0, $this->notifyAddr);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** Call once when fully started. */
    public function ready(): void
    {
        $this->notify('READY=1');
        $this->notifyReady = true;
    }

    /** Call every loop iteration - cheap. */
    public function beat(): void
    {
        $this->loopCount++;
    }

    /** Call from the 1 Hz housekeeping tick. */
    public function tick(): void
    {
        $now = microtime(true);

        if ($this->notifyReady && $now - $this->lastNotify >= 2.0) {
            $this->notify('WATCHDOG=1');
            $this->lastNotify = $now;
        }

        if ($this->heartbeatFile !== null && $now - $this->lastHeartbeat >= self::HEARTBEAT_SEC) {
            $this->lastHeartbeat = $now;
            $payload = json_encode([
                'pid' => getmypid(),
                'ts' => $now,
                'uptime_sec' => (int)($now - $this->startTs),
                'loops_total' => $this->loopCount,
                'mem_kb' => (int)(memory_get_usage(true) / 1024),
            ] + $this->metrics);
            if ($payload !== false) {
                $tmp = $this->heartbeatFile . '.tmp';
                if (@file_put_contents($tmp, $payload) !== false) {
                    @rename($tmp, $this->heartbeatFile);
                }
            }
        }
    }

    public function uptime(): float
    {
        return microtime(true) - $this->startTs;
    }

    public function stopping(): void
    {
        $this->notify('STOPPING=1');
    }
}
