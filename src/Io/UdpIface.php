<?php
declare(strict_types=1);

namespace XaNmea\Io;

use XaNmea\Logger;
use XaNmea\Sentence;

/**
 * UDP interface: unicast / broadcast / multicast, input and/or output.
 *
 * Requires ext-sockets (bundled in default PHP builds): broadcast needs
 * SO_BROADCAST, multicast needs group membership options. The raw Socket
 * is exported to a stream so it joins the main stream_select() loop;
 * datagrams are sent via socket_sendto() on the original Socket.
 *
 * Output: one datagram per sentence, or AIS multi-part coalescing when
 * enabled. Input: each datagram may carry one or more CRLF lines.
 */
class UdpIface extends Iface
{
    public string $type = 'udp';

    private string $address;
    private int $port;
    private bool $coalesce;

    /** @var \Socket|null raw dgram socket (sendto) */
    private ?\Socket $sock = null;
    /** @var resource|null exported stream for select loop */
    private $fd = null;
    private string $inBuf = '';
    /** @var array<string,array{t:float,parts:array<int,string>}> coalesce buffers per AIS channel/seq */
    private array $coalBuf = [];
    private string $mode = 'unicast'; // unicast|broadcast|multicast
    /** @var string|null resolved numeric IP, cached for the iface lifetime ('' = unresolvable) */
    private ?string $resolvedAddr = null;

    public function __construct(array $def, Logger $log)
    {
        parent::__construct($def, $log);
        $this->address = $def['address'];
        $this->port = $def['port'];
        $this->coalesce = $def['coalesce'];
        $this->mode = self::classifyAddress($this->address);
    }

    public static function classifyAddress(string $addr): string
    {
        $parts = explode('.', $addr);
        $first = isset($parts[0]) && ctype_digit($parts[0]) ? (int)$parts[0] : 0;
        if ($first >= 224 && $first <= 239) {
            return 'multicast';
        }
        if ($addr === '255.255.255.255' || (isset($parts[3]) && $parts[3] === '255')) {
            return 'broadcast';
        }
        return 'unicast';
    }

    public function open(): void
    {
        $this->close();
        if (!extension_loaded('sockets')) {
            throw new \RuntimeException('udp requires the sockets PHP extension');
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            throw new \RuntimeException('socket_create failed: ' . socket_strerror(socket_last_error()));
        }
        $this->sock = $sock;

        if ($this->wantsOutput() && $this->mode === 'broadcast') {
            @socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
        }

        if ($this->wantsInput()) {
            @socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
            $bindAddr = '0.0.0.0';
            // For unicast input to a specific local address, bind to it.
            if ($this->mode === 'unicast' && $this->address !== '0.0.0.0' && $this->address !== '255.255.255.255') {
                $bindAddr = $this->address;
            }
            if (!@socket_bind($sock, $bindAddr, $this->port)) {
                throw new \RuntimeException("udp bind $bindAddr:{$this->port}: " . socket_strerror(socket_last_error($sock)));
            }
            if ($this->mode === 'multicast') {
                $mreq = ['group' => $this->address, 'interface' => 0];
                if (!@socket_set_option($sock, IPPROTO_IP, MCAST_JOIN_GROUP, $mreq)) {
                    $this->log->warning("{$this->name}: multicast join {$this->address} failed: " . socket_strerror(socket_last_error($sock)));
                }
            }
        }

        $fd = @socket_export_stream($sock);
        if ($fd === false) {
            throw new \RuntimeException('socket_export_stream failed');
        }
        stream_set_blocking($fd, false);
        $this->fd = $fd;
        $this->state = 'up';
        $this->upSince = microtime(true);
        $this->lastError = null;
        $this->log->info("{$this->name}: udp {$this->mode} {$this->address}:{$this->port} ({$this->direction})");
    }

    public function close(): void
    {
        // Closing the exported stream closes the underlying socket; don't double-close.
        if ($this->fd !== null) {
            try {
                fclose($this->fd);
            } catch (\Throwable $e) {
            }
        }
        $this->fd = null;
        $this->sock = null;
        $this->coalBuf = [];
        $this->state = 'down';
        $this->upSince = null;
    }

    public function readFds(): array
    {
        return ($this->fd !== null && $this->wantsInput()) ? [$this->fd] : [];
    }

    public function onReadable($fd): void
    {
        // Datagrams: read all pending; each datagram processed independently.
        for ($i = 0; $i < 32; $i++) {
            $data = @fread($fd, 8192);
            if ($data === false || $data === '') {
                return;
            }
            $this->inBuf .= $data;
            while (($pos = strpos($this->inBuf, "\n")) !== false) {
                $line = substr($this->inBuf, 0, $pos + 1);
                $this->inBuf = substr($this->inBuf, $pos + 1);
                $this->handleInputLine($line);
            }
            // UDP datagrams are atomic: a newline-less remainder is either a
            // complete line (some senders omit the terminator) or garbage to
            // discard - but never glue it onto the next datagram.
            if ($this->inBuf !== '') {
                if (strlen($this->inBuf) < 1024) {
                    $rest = $this->inBuf;
                    $this->inBuf = '';
                    $this->handleInputLine($rest . "\n");
                } else {
                    $this->inBuf = '';
                    $this->counters['parse_err']++;
                }
            }
        }
    }

    public function enqueue(Sentence $s): void
    {
        if (!$this->wantsOutput() || $this->sock === null) {
            return;
        }
        if ($this->coalesce && $this->isAisFragment($s) && !$this->isAisLastFragment($s)) {
            $this->coalBuffer($s);
            return;
        }
        $wire = $s->raw;
        if ($this->coalesce) {
            $wire = $this->coalFlushWith($s);
        }
        $this->send($wire . "\r\n");
    }

    private function send(string $payload): void
    {
        $dest = $this->destAddress();
        if ($dest === '') {
            $this->counters['dropped']++;
            return;
        }
        $n = @socket_sendto($this->sock, $payload, strlen($payload), 0, $dest, $this->port);
        if ($n === false) {
            $this->noteError('udp send failed: ' . socket_strerror(socket_last_error($this->sock)));
            return;
        }
        $this->counters['out']++;
        $this->counters['bytes_out'] += $n;
        $this->lastActivity = microtime(true);
    }

    /**
     * Resolve the destination hostname once and cache the numeric IP for
     * the iface lifetime: gethostbyname() blocks the event loop, so it
     * must not run per datagram.
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
        if ($this->resolvedAddr === '') {
            $this->noteError("cannot resolve {$this->address}");
        }
        return $this->resolvedAddr;
    }

    // ---- AIS multi-part coalescing (single-buffer, kplex-style) ----

    private function isAisFragment(Sentence $s): bool
    {
        // VDM/VDO: fields: [0]=total,[1]=num,[2]=seq,[3]=channel,[4]=payload...
        if (!in_array($s->type, ['VDM', 'VDO'], true) || count($s->fields) < 2) {
            return false;
        }
        return (int)($s->fields[0] ?? 1) > 1;
    }

    private function isAisLastFragment(Sentence $s): bool
    {
        return (int)($s->fields[0] ?? 1) === (int)($s->fields[1] ?? 1);
    }

    private function coalBuffer(Sentence $s): void
    {
        $key = ($s->fields[3] ?? 'A') . ':' . ($s->fields[2] ?? '');
        if (strlen(implode('', $this->coalBuf[$key]['parts'] ?? [])) > 400) {
            unset($this->coalBuf[$key]); // overflow: drop buffer, send fragment alone
            $this->send($s->raw . "\r\n");
            return;
        }
        if (!isset($this->coalBuf[$key]) && count($this->coalBuf) >= 8) {
            // cap: drop the oldest buffer (keys derive from wire data)
            $oldest = array_key_first($this->coalBuf);
            foreach ($this->coalBuf as $k => $b) {
                if ($b['t'] < $this->coalBuf[$oldest]['t']) {
                    $oldest = $k;
                }
            }
            unset($this->coalBuf[$oldest]);
            $this->counters['dropped']++;
        }
        $this->coalBuf[$key]['t'] = microtime(true);
        $this->coalBuf[$key]['parts'][(int)$s->fields[1]] = $s->raw;
    }

    private function coalFlushWith(Sentence $last): string
    {
        $key = ($last->fields[3] ?? 'A') . ':' . ($last->fields[2] ?? '');
        $buf = $this->coalBuf[$key]['parts'] ?? [];
        if (!$buf || !$this->isAisFragment($last)) {
            return $last->raw;
        }
        unset($this->coalBuf[$key]);
        $buf[(int)$last->fields[1]] = $last->raw;
        ksort($buf);
        return implode("\r\n", $buf);
    }

    /** Expire stale coalesce buffers so wire-keyed state cannot accumulate. */
    public function tick(float $now): void
    {
        foreach ($this->coalBuf as $key => $buf) {
            if ($now - $buf['t'] > 10.0) {
                unset($this->coalBuf[$key]);
                $this->counters['dropped']++;
            }
        }
    }

    public function statsRow(): array
    {
        return parent::statsRow() + [
            'address' => $this->address,
            'port' => $this->port,
            'mode' => $this->mode,
        ];
    }
}
