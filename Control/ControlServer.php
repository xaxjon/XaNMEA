<?php
declare(strict_types=1);

namespace XaNmea\Control;

use XaNmea\Logger;

/**
 * Control server: unix-domain socket, newline-oriented protocol.
 *
 * One-shot commands (reply is one JSON line, connection then closes):
 *   PING                  -> {ok,version,uptime}
 *   STATS                 -> full interface stats snapshot
 *   STATE [section]       -> decoded state snapshot (ownship|ais|weather|misc|sentences|all)
 *   RELOAD                -> re-read + validate + apply config diff
 *   KICK <iface> <id>     -> disconnect a tcp server client
 *
 * Streaming commands (connection stays open, server pushes JSON lines):
 *   TAIL [iface|all]      -> {"ts","src","dst":[],"raw","valid"} per sentence
 *   STREAM [state]        -> {"ts","d":{...}} decoded-state deltas
 *
 * Reliability: subscriber queues are bounded; a slow subscriber is dropped
 * without ceremony and never blocks routing or decoding.
 */
final class ControlServer
{
    /** @var resource|null */
    private $listenFd = null;
    private string $path;
    private Logger $log;
    private int $streamQsize;

    /** @var array<int,array{fd:resource,inBuf:string,mode:string,filter:?string,outQ:string[]}> */
    private array $clients = [];

    /** @var callable|null command handler fn(string $line): array (reply payload) */
    private $cmdHook = null;

    public function __construct(string $path, Logger $log, int $streamQsize = 500)
    {
        $this->path = $path;
        $this->log = $log;
        $this->streamQsize = $streamQsize;
    }

    public function setCommandHook(callable $cb): void
    {
        $this->cmdHook = $cb;
    }

    public function open(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (file_exists($this->path)) {
            @unlink($this->path); // stale socket from previous run
        }
        $errno = 0;
        $errstr = '';
        $fd = @stream_socket_server('unix://' . $this->path, $errno, $errstr);
        if ($fd === false) {
            throw new \RuntimeException("control socket {$this->path}: $errstr");
        }
        @chmod($this->path, 0660);
        stream_set_blocking($fd, false);
        $this->listenFd = $fd;
        $this->log->info('control socket listening on ' . $this->path);
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
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    public function readFds(): array
    {
        $fds = [];
        if ($this->listenFd !== null) {
            $fds[] = $this->listenFd;
        }
        foreach ($this->clients as $c) {
            if ($c['mode'] === 'cmd') {
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
            for ($i = 0; $i < 16; $i++) {
                $c = @stream_socket_accept($this->listenFd, 0);
                if ($c === false) {
                    return;
                }
                stream_set_blocking($c, false);
                $this->clients[] = ['fd' => $c, 'inBuf' => '', 'mode' => 'cmd', 'filter' => null, 'outQ' => []];
            }
            return;
        }
        $id = $this->idOf($fd);
        if ($id === null) {
            return;
        }
        $data = @fread($fd, 4096);
        if ($data === false || ($data === '' && feof($fd))) {
            $this->drop($id);
            return;
        }
        $this->clients[$id]['inBuf'] .= $data;
        while (($pos = strpos($this->clients[$id]['inBuf'], "\n")) !== false) {
            $line = trim(substr($this->clients[$id]['inBuf'], 0, $pos));
            $this->clients[$id]['inBuf'] = substr($this->clients[$id]['inBuf'], $pos + 1);
            if ($line !== '') {
                $this->handleCommand($id, $line);
            }
        }
    }

    public function onWritable($fd): void
    {
        $id = $this->idOf($fd);
        if ($id === null) {
            return;
        }
        while ($this->clients[$id]['outQ']) {
            $line = $this->clients[$id]['outQ'][0];
            $n = @fwrite($fd, $line);
            if ($n === false || $n === 0) {
                if ($n === false) {
                    $this->drop($id);
                }
                return;
            }
            if ($n < strlen($line)) {
                $this->clients[$id]['outQ'][0] = substr($line, $n);
                return;
            }
            array_shift($this->clients[$id]['outQ']);
        }
    }

    private function handleCommand(int $id, string $line): void
    {
        $parts = preg_split('/\s+/', $line);
        $cmd = strtoupper($parts[0] ?? '');
        $arg = $parts[1] ?? null;

        switch ($cmd) {
            case 'TAIL':
                $this->clients[$id]['mode'] = 'tail';
                $this->clients[$id]['filter'] = ($arg === null || strcasecmp($arg, 'all') === 0) ? null : strtolower($arg);
                $this->push($id, json_encode(['ok' => true, 'mode' => 'tail']) . "\n");
                break;

            case 'STREAM':
                $this->clients[$id]['mode'] = 'stream';
                $this->clients[$id]['filter'] = null;
                $this->push($id, json_encode(['ok' => true, 'mode' => 'stream']) . "\n");
                break;

            default:
                if ($this->cmdHook === null) {
                    $reply = ['ok' => false, 'error' => 'no handler'];
                } else {
                    try {
                        $reply = ($this->cmdHook)($line);
                    } catch (\Throwable $e) {
                        $reply = ['ok' => false, 'error' => $e->getMessage()];
                    }
                }
                $this->push($id, json_encode($reply) . "\n");
                if ($this->clients[$id]['mode'] === 'cmd') {
                    $this->clients[$id]['mode'] = 'close-after-flush';
                }
                break;
        }
    }

    private function push(int $id, string $data): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        if (count($this->clients[$id]['outQ']) >= $this->streamQsize) {
            $this->drop($id); // slow consumer: drop, never block
            return;
        }
        $this->clients[$id]['outQ'][] = $data;
    }

    /** Router tail hook: broadcast a raw sentence event. */
    public function broadcastTail(string $src, array $dst, string $raw, bool $valid): void
    {
        $line = null;
        foreach ($this->clients as $id => $c) {
            if ($c['mode'] !== 'tail') {
                continue;
            }
            if ($c['filter'] !== null && strtolower($src) !== $c['filter'] && !in_array($c['filter'], array_map('strtolower', $dst), true)) {
                continue;
            }
            if ($line === null) {
                $line = json_encode([
                    'ts' => microtime(true),
                    'src' => $src,
                    'dst' => $dst,
                    'raw' => $raw,
                    'valid' => $valid,
                ]) . "\n";
            }
            $this->push($id, $line);
        }
    }

    /** State store delta hook: broadcast a decoded-state delta. */
    public function broadcastState(array $delta): void
    {
        $line = null;
        foreach ($this->clients as $id => $c) {
            if ($c['mode'] !== 'stream') {
                continue;
            }
            if ($line === null) {
                $line = json_encode(['ts' => microtime(true), 'd' => $delta]) . "\n";
            }
            $this->push($id, $line);
        }
    }

    /** Housekeeping: close 'close-after-flush' clients once drained. */
    public function tick(): void
    {
        foreach ($this->clients as $id => $c) {
            if ($c['mode'] === 'close-after-flush' && !$c['outQ']) {
                $this->drop($id);
            }
        }
    }

    private function idOf($fd): ?int
    {
        foreach ($this->clients as $id => $c) {
            if ($c['fd'] === $fd) {
                return $id;
            }
        }
        return null;
    }

    private function drop(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        try {
            fclose($this->clients[$id]['fd']);
        } catch (\Throwable $e) {
        }
        unset($this->clients[$id]);
    }
}
