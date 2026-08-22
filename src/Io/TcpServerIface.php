<?php
declare(strict_types=1);

namespace XaNmea\Io;

use XaNmea\Logger;
use XaNmea\Sentence;

/**
 * TCP server: one listening socket, many client connections.
 * Clients inherit this interface's direction and filters.
 * Each client has an independent bounded output queue; a stalled client
 * drops data (and is eventually disconnected), never the router.
 */
class TcpServerIface extends Iface
{
    public string $type = 'tcp_server';

    private string $address;
    private int $port;
    /** @var resource|null listening stream */
    private $listenFd = null;
    /** @var array<int,array{fd:resource,ip:string,since:float,inBuf:string,outQ:string[],sent:int}> */
    private array $clients = [];
    private int $nextClientId = 1;

    public function __construct(array $def, Logger $log)
    {
        parent::__construct($def, $log);
        $this->address = $def['address'] ?? '0.0.0.0';
        $this->port = $def['port'];
    }

    public function open(): void
    {
        $this->close();
        $errno = 0;
        $errstr = '';
        $fd = @stream_socket_server("tcp://{$this->address}:{$this->port}", $errno, $errstr);
        if ($fd === false) {
            throw new \RuntimeException("listen {$this->address}:{$this->port} failed: $errstr");
        }
        stream_set_blocking($fd, false);
        $this->listenFd = $fd;
        $this->state = 'up';
        $this->upSince = microtime(true);
        $this->lastError = null;
        $this->log->info("{$this->name}: tcp server listening on {$this->address}:{$this->port} ({$this->direction})");
    }

    public function close(): void
    {
        foreach ($this->clients as $c) {
            try {
                fclose($c['fd']);
            } catch (\Throwable $e) {
            }
        }
        $this->clients = [];
        if ($this->listenFd !== null) {
            try {
                fclose($this->listenFd);
            } catch (\Throwable $e) {
            }
            $this->listenFd = null;
        }
        $this->state = 'down';
        $this->upSince = null;
    }

    public function readFds(): array
    {
        $fds = [];
        if ($this->listenFd !== null) {
            $fds[] = $this->listenFd;
        }
        if ($this->wantsInput()) {
            foreach ($this->clients as $c) {
                $fds[] = $c['fd'];
            }
        }
        return $fds;
    }

    public function writeFds(): array
    {
        $fds = [];
        foreach ($this->clients as $c) {
            if ($c['outQ']) {
                $fds[] = $c['fd'];
            }
        }
        return $fds;
    }

    public function onReadable($fd): void
    {
        if ($this->listenFd !== null && $fd === $this->listenFd) {
            $this->acceptClients();
            return;
        }
        $id = $this->clientIdOf($fd);
        if ($id === null) {
            return;
        }
        $data = @fread($fd, 8192);
        if ($data === false || ($data === '' && feof($fd))) {
            $this->dropClient($id, 'peer closed');
            return;
        }
        if ($data === '') {
            return;
        }
        $this->clients[$id]['inBuf'] .= $data;
        if (strlen($this->clients[$id]['inBuf']) > 65536) {
            $this->clients[$id]['inBuf'] = substr($this->clients[$id]['inBuf'], -4096);
            $this->counters['parse_err']++;
        }
        while (($pos = strpos($this->clients[$id]['inBuf'], "\n")) !== false) {
            $line = substr($this->clients[$id]['inBuf'], 0, $pos + 1);
            $this->clients[$id]['inBuf'] = substr($this->clients[$id]['inBuf'], $pos + 1);
            $this->handleInputLine($line);
        }
    }

    public function onWritable($fd): void
    {
        $id = $this->clientIdOf($fd);
        if ($id === null) {
            return;
        }
        while ($this->clients[$id]['outQ']) {
            $line = $this->clients[$id]['outQ'][0];
            $n = @fwrite($fd, $line);
            if ($n === false) {
                $this->dropClient($id, 'write error');
                return;
            }
            if ($n === 0) {
                return;
            }
            $this->counters['bytes_out'] += $n;
            if ($n < strlen($line)) {
                $this->clients[$id]['outQ'][0] = substr($line, $n);
                return;
            }
            array_shift($this->clients[$id]['outQ']);
            $this->clients[$id]['sent']++;
            $this->counters['out']++;
            $this->lastActivity = microtime(true);
        }
    }

    private function acceptClients(): void
    {
        // drain all pending accepts
        for ($i = 0; $i < 32; $i++) {
            $fd = @stream_socket_accept($this->listenFd, 0);
            if ($fd === false) {
                return;
            }
            stream_set_blocking($fd, false);
            $peer = (string)@stream_socket_get_name($fd, true);
            $ip = explode(':', $peer)[0] ?? $peer;
            $id = $this->nextClientId++;
            $this->clients[$id] = [
                'fd' => $fd, 'ip' => $ip, 'since' => microtime(true),
                'inBuf' => '', 'outQ' => [], 'sent' => 0,
            ];
            $this->log->info("{$this->name}: client #{$id} connected from $peer");
        }
    }

    public function dropClient(int $id, string $why): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        try {
            fclose($this->clients[$id]['fd']);
        } catch (\Throwable $e) {
        }
        unset($this->clients[$id]);
        $this->log->info("{$this->name}: client #{$id} dropped ($why)");
    }

    /** Control-socket KICK command. */
    public function kick(int $id): bool
    {
        if (!isset($this->clients[$id])) {
            return false;
        }
        $this->dropClient($id, 'kicked via control');
        return true;
    }

    private function clientIdOf($fd): ?int
    {
        foreach ($this->clients as $id => $c) {
            if ($c['fd'] === $fd) {
                return $id;
            }
        }
        return null;
    }

    public function enqueue(Sentence $s): void
    {
        if (!$this->wantsOutput() || !$this->clients) {
            return;
        }
        $wire = $s->toWire($this->srctagValue($s), $this->timestamp);
        foreach ($this->clients as $id => $c) {
            if (count($c['outQ']) >= $this->qsize) {
                // stalled client: drop oldest; if chronically stalled, kick
                array_shift($c['outQ']);
                $this->counters['dropped']++;
                if ($this->counters['dropped'] % 1000 === 0) {
                    $this->dropClient($id, 'chronically stalled');
                    continue;
                }
            }
            $c['outQ'][] = $wire;
            $this->clients[$id] = $c;
        }
    }

    private function srctagValue(Sentence $s): ?string
    {
        return match ($this->srctag) {
            'yes' => $this->name,
            'input' => $s->srcName !== '' ? $s->srcName : $this->name,
            default => null,
        };
    }

    public function statsRow(): array
    {
        $clients = [];
        foreach ($this->clients as $id => $c) {
            $clients[] = [
                'id' => $id,
                'ip' => $c['ip'],
                'since' => $c['since'],
                'sent' => $c['sent'],
                'queued' => count($c['outQ']),
            ];
        }
        return parent::statsRow() + [
            'address' => $this->address,
            'port' => $this->port,
            'clients' => $clients,
        ];
    }
}
