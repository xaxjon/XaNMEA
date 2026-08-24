<?php
declare(strict_types=1);

namespace XaNmea\Io;

use XaNmea\Logger;
use XaNmea\Sentence;

/**
 * TCP client: connects out to a remote NMEA server. Non-blocking connect,
 * persist/retry with backoff, optional preamble (e.g. AIS aggregation ID
 * or gpsd ?WATCH), optional TCP keepalive and TCP_NODELAY.
 */
class TcpClientIface extends Iface
{
    public string $type = 'tcp_client';

    private string $address;
    private int $port;
    private bool $persist;
    private int $retry;
    private string $preamble;
    private bool $nodelay;
    private bool $keepalive;

    /** @var resource|null */
    private $fd = null;
    private bool $connecting = false;
    private float $connectStart = 0.0;
    private float $nextRetry = 0.0;
    private string $inBuf = '';
    /** @var string[] */
    private array $outQ = [];
    /** @var string|null resolved numeric IP, cached for the iface lifetime ('' = unresolvable) */
    private ?string $resolvedAddr = null;

    public function __construct(array $def, Logger $log)
    {
        parent::__construct($def, $log);
        $this->address = $def['address'];
        $this->port = $def['port'];
        $this->persist = $def['persist'];
        $this->retry = $def['retry'];
        $this->preamble = $this->unescape($def['preamble']);
        $this->nodelay = $def['nodelay'];
        $this->keepalive = $def['keepalive'];
    }

    public function open(): void
    {
        // state managed by tick()/tryConnect; nothing to do here
        $this->state = 'down';
        $this->nextRetry = 0.0;
    }

    private function tryConnect(float $now): void
    {
        $dest = $this->destAddress();
        if ($dest === '') {
            $this->state = 'retry';
            $this->nextRetry = $now + $this->retry;
            if ($this->lastError !== "cannot resolve {$this->address}") {
                $this->noteError("cannot resolve {$this->address}");
            }
            return;
        }
        $errno = 0;
        $errstr = '';
        $fd = @stream_socket_client(
            "tcp://{$dest}:{$this->port}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );
        if ($fd === false) {
            $this->lastError = "connect {$this->address}:{$this->port}: $errstr";
            $this->state = 'retry';
            $this->nextRetry = $now + $this->retry;
            return;
        }
        stream_set_blocking($fd, false);
        $this->fd = $fd;
        $this->connecting = true;
        $this->connectStart = $now;
        $this->state = 'connecting';
    }

    /**
     * Resolve the remote hostname once and cache the numeric IP for the
     * iface lifetime: gethostbyname() blocks the event loop, so it must
     * not run on every connect retry.
     */
    private function destAddress(): string
    {
        if ($this->resolvedAddr !== null) {
            return $this->resolvedAddr;
        }
        $this->resolvedAddr = '';
        if (filter_var($this->address, FILTER_VALIDATE_IP)) {
            $this->resolvedAddr = $this->address;
        } else {
            $ip = gethostbyname($this->address);
            if ($ip !== $this->address && filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->resolvedAddr = $ip;
            }
        }
        return $this->resolvedAddr;
    }

    private function finishConnect(float $now): void
    {
        // writability signals connect completion; verify via peer name
        $peer = @stream_socket_get_name($this->fd, true);
        if ($peer === false) {
            $this->teardown("connect to {$this->address}:{$this->port} refused/failed");
            return;
        }
        $this->connecting = false;
        $this->state = 'up';
        $this->upSince = $now;
        $this->lastError = null;
        $this->inBuf = '';
        $this->applySockopts();
        $this->log->info("{$this->name}: connected to $peer");
        if ($this->preamble !== '') {
            $this->outQ[] = $this->preamble;
        }
    }

    private function applySockopts(): void
    {
        if (!extension_loaded('sockets') || $this->fd === null) {
            return;
        }
        try {
            $sock = socket_import_stream($this->fd);
            if ($sock !== false) {
                if ($this->nodelay && defined('TCP_NODELAY')) {
                    @socket_set_option($sock, IPPROTO_TCP, TCP_NODELAY, 1);
                }
                if ($this->keepalive) {
                    @socket_set_option($sock, SOL_SOCKET, SO_KEEPALIVE, 1);
                    if (defined('TCP_KEEPIDLE')) {
                        @socket_set_option($sock, IPPROTO_TCP, TCP_KEEPIDLE, 30);
                    }
                    if (defined('TCP_KEEPINTVL')) {
                        @socket_set_option($sock, IPPROTO_TCP, TCP_KEEPINTVL, 10);
                    }
                    if (defined('TCP_KEEPCNT')) {
                        @socket_set_option($sock, IPPROTO_TCP, TCP_KEEPCNT, 3);
                    }
                }
            }
        } catch (\Throwable $e) {
            // sockopts are best-effort
        }
    }

    private function teardown(string $why): void
    {
        if ($this->state === 'up' || $this->connecting) {
            $this->noteError($why);
        }
        if ($this->fd !== null) {
            try {
                fclose($this->fd);
            } catch (\Throwable $e) {
            }
            $this->fd = null;
        }
        $this->connecting = false;
        $this->upSince = null;
        $this->outQ = []; // discard potentially stale data (kplex semantics)
        $this->inBuf = '';
        if ($this->persist) {
            $this->state = 'retry';
            $this->nextRetry = microtime(true) + $this->retry;
            $this->counters['reconnects']++;
        } else {
            $this->state = 'down';
        }
    }

    public function tick(float $now): void
    {
        if ($this->fd === null) {
            if ($this->persist && $now >= $this->nextRetry && $this->state !== 'connecting') {
                $this->tryConnect($now);
            }
            return;
        }
        if ($this->connecting && $now - $this->connectStart > 10.0) {
            $this->teardown('connect timeout');
        }
    }

    public function readFds(): array
    {
        if ($this->fd === null || $this->connecting) {
            return [];
        }
        return $this->wantsInput() ? [$this->fd] : [];
    }

    public function writeFds(): array
    {
        if ($this->fd === null) {
            return [];
        }
        if ($this->connecting || $this->outQ) {
            return [$this->fd];
        }
        return [];
    }

    public function onReadable($fd): void
    {
        $data = @fread($fd, 8192);
        if ($data === false || ($data === '' && feof($fd))) {
            $this->teardown('peer closed');
            return;
        }
        if ($data === '') {
            return;
        }
        $this->inBuf .= $data;
        if (strlen($this->inBuf) > 65536) {
            $this->inBuf = substr($this->inBuf, -4096);
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
        if ($this->connecting) {
            $this->finishConnect(microtime(true));
            return;
        }
        while ($this->outQ) {
            $line = $this->outQ[0];
            $n = @fwrite($fd, $line);
            if ($n === false) {
                $this->teardown('write error');
                return;
            }
            if ($n === 0) {
                return; // socket buffer full
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
            array_shift($this->outQ);
            $this->counters['dropped']++;
        }
        $this->outQ[] = $s->toWire($this->srctagValue($s), $this->timestamp);
    }

    private function srctagValue(Sentence $s): ?string
    {
        return match ($this->srctag) {
            'yes' => $this->name,
            'input' => $s->srcName !== '' ? $s->srcName : $this->name,
            default => null,
        };
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
        $this->state = 'down';
        $this->connecting = false;
        $this->upSince = null;
    }

    private function unescape(string $s): string
    {
        if ($s === '') {
            return '';
        }
        return stripcslashes($s);
    }

    public function statsRow(): array
    {
        return parent::statsRow() + [
            'address' => $this->address,
            'port' => $this->port,
            'queue' => count($this->outQ),
        ];
    }
}
