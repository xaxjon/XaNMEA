<?php
declare(strict_types=1);

namespace XaNmea\Io;

use XaNmea\Filter;
use XaNmea\Logger;
use XaNmea\Router;
use XaNmea\Sentence;

/**
 * Abstract I/O interface. Implementations own OS resources and expose
 * them to the select loop. All overridable methods must be exception-safe:
 * the daemon wraps calls in try/catch, but implementations should convert
 * expected I/O errors into state, not exceptions.
 */
abstract class Iface
{
    public string $name;
    public string $type = 'abstract';
    public string $direction = 'in';   // in|out|both
    public string $state = 'init';     // init|up|down|retry
    public ?string $lastError = null;
    public bool $enabled = true;
    public bool $loopback = false;

    // effective options (per-iface override ?? daemon default)
    public bool $checksum = true;
    public bool $strict = false;
    public int $qsize = 200;
    public string $srctag = 'no';      // no|yes|input
    public string $timestamp = 'no';   // no|s|ms

    protected Filter $ifilter;
    protected Filter $ofilter;

    /** @var array<string,int> */
    public array $counters = [
        'in' => 0, 'out' => 0, 'bytes_in' => 0, 'bytes_out' => 0,
        'dropped' => 0, 'checksum_err' => 0, 'parse_err' => 0,
        'filtered_in' => 0, 'failover_dropped' => 0, 'reconnects' => 0,
    ];
    public float $lastActivity = 0.0; // last sentence in or out
    public ?float $upSince = null;

    protected Logger $log;
    protected Router $router;

    public function __construct(array $def, Logger $log)
    {
        $this->name = $def['name'];
        $this->direction = $def['direction'];
        $this->enabled = $def['enabled'];
        $this->loopback = $def['loopback'];
        if ($def['checksum'] !== null) {
            $this->checksum = $def['checksum'];
        }
        if ($def['strict'] !== null) {
            $this->strict = $def['strict'];
        }
        if ($def['qsize'] > 0) {
            $this->qsize = $def['qsize'];
        }
        $this->srctag = $def['srctag'];
        $this->timestamp = $def['timestamp'];
        $this->ifilter = Filter::compile($def['ifilter'] ?? null);
        $this->ofilter = Filter::compile($def['ofilter'] ?? null);
        $this->log = $log;
    }

    public function setRouter(Router $r): void
    {
        $this->router = $r;
    }

    public function wantsInput(): bool
    {
        return $this->direction === 'in' || $this->direction === 'both';
    }

    public function wantsOutput(): bool
    {
        return $this->direction === 'out' || $this->direction === 'both';
    }

    public function allowsOut(Sentence $s): bool
    {
        return $this->ofilter->passes($s);
    }

    /** Outbound enqueue - overridden by output-capable ifaces. */
    public function enqueue(Sentence $s): void {}

    public function noteError(string $msg): void
    {
        $this->lastError = $msg;
        $this->log->warning($this->name . ': ' . $msg);
    }

    /**
     * Handle one raw input line (already split). Applies structural
     * validation, checksum policy and input filter, then routes.
     */
    protected function handleInputLine(string $line): void
    {
        $s = Sentence::parse($line);
        if ($s === null) {
            $this->counters['parse_err']++;
            return;
        }
        if ($s->checksum !== null && !$s->checksumOk && $this->checksum) {
            $this->counters['checksum_err']++;
            return;
        }
        if ($this->strict && $s->checksum === null) {
            $this->counters['parse_err']++;
            return;
        }
        if (!$this->ifilter->passes($s)) {
            $this->counters['filtered_in']++;
            return;
        }
        $this->counters['in']++;
        $this->counters['bytes_in'] += strlen($line);
        $this->lastActivity = microtime(true);
        $this->router->dispatch($this, $s);
    }

    // ---- select-loop contract ----

    /** @return array<int,resource> streams to watch for readability */
    public function readFds(): array { return []; }

    /** @return array<int,resource> streams to watch for writability */
    public function writeFds(): array { return []; }

    public function onReadable($fd): void {}
    public function onWritable($fd): void {}

    /** 1 Hz housekeeping: reconnects, stale detection, etc. */
    public function tick(float $now): void {}

    abstract public function open(): void;
    abstract public function close(): void;

    /** Stats row for the STATS control reply. */
    public function statsRow(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'direction' => $this->direction,
            'enabled' => $this->enabled,
            'state' => $this->state,
            'last_error' => $this->lastError,
            'last_activity' => $this->lastActivity,
            'up_since' => $this->upSince,
            'counters' => $this->counters,
        ];
    }
}
