<?php
declare(strict_types=1);

namespace XaNmea\Io;

use XaNmea\Logger;
use XaNmea\Sentence;

/**
 * Serial device (RS232/RS422 via USB adapters). Configured via `stty`
 * (portable across Linux without the pecl/dio extension), then used as a
 * non-blocking stream. Direction decides open mode.
 */
class SerialIface extends Iface
{
    public string $type = 'serial';

    private string $device;
    private int $baud;
    /** @var resource|null */
    private $fd = null;
    private string $inBuf = '';
    /** @var string[] outbound queue (already wire-formatted) */
    private array $outQ = [];
    private float $nextRetry = 0.0;

    public function __construct(array $def, Logger $log)
    {
        parent::__construct($def, $log);
        $this->device = $def['device'];
        $this->baud = $def['baud'];
    }

    public function open(): void
    {
        $this->close();
        if (!file_exists($this->device)) {
            throw new \RuntimeException("device not found: {$this->device}");
        }
        $this->configurePort();

        $mode = match ($this->direction) {
            'in' => 'rb',
            'out' => 'wb',
            default => 'r+b',
        };
        $fd = @fopen($this->device, $mode);
        if ($fd === false) {
            throw new \RuntimeException("cannot open {$this->device}: " . (error_get_last()['message'] ?? '?'));
        }
        stream_set_blocking($fd, false);
        $this->fd = $fd;
        $this->state = 'up';
        $this->upSince = microtime(true);
        $this->lastError = null;
        $this->log->info("{$this->name}: serial {$this->device} @{$this->baud} open ({$this->direction})");
    }

    private function configurePort(): void
    {
        $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
        $args = sprintf(
            '%s %s %d cs8 -cstopb -parenb -ixon -ixoff raw -echo min 0 time 5',
            $flag,
            escapeshellarg($this->device),
            $this->baud
        );
        // Some distros ship a limited stty that cannot set baud rates
        // (e.g. uutils coreutils on Ubuntu 25.10: "invalid argument '4800'").
        // Fall back to busybox stty, which implements the full flag set.
        $errors = [];
        foreach (['stty', 'busybox stty'] as $stty) {
            if ($stty !== 'stty' && !is_file('/usr/bin/busybox') && !is_file('/bin/busybox')) {
                continue;
            }
            $out = [];
            exec("$stty $args 2>&1", $out, $rc);
            if ($rc === 0) {
                return;
            }
            $errors[] = "$stty: " . implode(' ', $out);
        }
        throw new \RuntimeException(
            'stty failed on ' . $this->device . ' (' . implode(' | ', $errors) . ')'
            . ' - install GNU coreutils or busybox-static'
        );
    }

    public function close(): void
    {
        if ($this->fd !== null) {
            try {
                fclose($this->fd);
            } catch (\Throwable $e) {
            }
            $this->fd = null;
        }
        if ($this->state !== 'retry') {
            $this->state = 'down';
        }
        $this->upSince = null;
    }

    /** Serial ports can vanish (USB unplug): schedule reopen attempts. */
    public function tick(float $now): void
    {
        if ($this->state === 'retry' && $now >= $this->nextRetry) {
            try {
                $this->open();
                $this->counters['reconnects']++;
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();
                $this->state = 'retry';
                $this->nextRetry = $now + 5.0;
            }
            return;
        }
        // Liveness: device disappeared?
        if ($this->state === 'up' && !file_exists($this->device)) {
            $this->noteError('device disappeared, will retry');
            $this->close();
            $this->state = 'retry';
            $this->nextRetry = $now + 5.0;
        }
    }

    public function failToRetry(string $why): void
    {
        $this->noteError($why);
        $this->close();
        $this->state = 'retry';
        $this->nextRetry = microtime(true) + 5.0;
    }

    public function readFds(): array
    {
        return ($this->fd !== null && $this->wantsInput()) ? [$this->fd] : [];
    }

    public function writeFds(): array
    {
        return ($this->fd !== null && $this->outQ) ? [$this->fd] : [];
    }

    public function onReadable($fd): void
    {
        $data = @fread($fd, 8192);
        if ($data === false) {
            return;
        }
        if ($data === '') {
            if (feof($fd)) {
                $this->failToRetry('eof on device');
            }
            return;
        }
        $this->inBuf .= $data;
        if (strlen($this->inBuf) > 65536) {
            $this->inBuf = substr($this->inBuf, -4096); // garbage storm protection
            $this->counters['parse_err']++;
        }
        while (($pos = strpos($this->inBuf, "\n")) !== false) {
            $line = substr($this->inBuf, 0, $pos + 1);
            $this->inBuf = substr($this->inBuf, $pos + 1);
            $this->handleInputLine($line);
        }
    }

    public function onWritable($fd): void
    {
        while ($this->outQ) {
            $line = $this->outQ[0];
            $n = @fwrite($fd, $line);
            if ($n === false) {
                return;
            }
            if ($n === 0) {
                return;
            }
            $this->counters['bytes_out'] += $n;
            if ($n < strlen($line)) {
                $this->outQ[0] = substr($line, $n);
                return;
            }
            array_shift($this->outQ);
            $this->counters['out']++;
            $this->lastActivity = microtime(true);
        }
    }

    public function enqueue(Sentence $s): void
    {
        if (!$this->wantsOutput() || $this->state !== 'up') {
            return;
        }
        if (count($this->outQ) >= $this->qsize) {
            array_shift($this->outQ); // drop oldest, never block the router
            $this->counters['dropped']++;
        }
        $this->outQ[] = $s->toWire($this->srctagValue($s), $this->timestamp);
    }

    protected function srctagValue(Sentence $s): ?string
    {
        return match ($this->srctag) {
            'yes' => $this->name,
            'input' => $s->srcName !== '' ? $s->srcName : $this->name,
            default => null,
        };
    }

    public function statsRow(): array
    {
        return parent::statsRow() + [
            'device' => $this->device,
            'baud' => $this->baud,
            'queue' => count($this->outQ),
        ];
    }
}
