<?php
declare(strict_types=1);

/**
 * XanClient: minimal client for the xanmead unix control socket.
 *
 * Line protocol: send one command line, daemon replies with one JSON line
 * (one-shot commands) or keeps the socket open and pushes JSON lines
 * (TAIL / STREAM). All failures are returned, never thrown.
 */
final class XanClient
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Send a one-shot command (PING, STATS, STATE ..., RELOAD, KICK ...).
     * Returns the decoded reply, or ['ok' => false, 'error' => ...].
     */
    public function oneShot(string $cmd, float $timeout = 3.0): array
    {
        [$fd, $err] = $this->connect($timeout);
        if ($fd === null) {
            return ['ok' => false, 'error' => $err ?? 'connect failed'];
        }
        stream_set_timeout($fd, (int)$timeout, (int)(($timeout - (int)$timeout) * 1e6));
        @fwrite($fd, $cmd . "\n");
        $line = @fgets($fd);
        fclose($fd);
        if ($line === false) {
            return ['ok' => false, 'error' => 'no reply from daemon'];
        }
        $data = json_decode(trim($line), true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'invalid reply from daemon'];
        }
        return $data;
    }

    /**
     * Open a streaming command (TAIL ..., STREAM). The socket stays open;
     * the daemon pushes one JSON object per line.
     *
     * @return array{0: resource|null, 1: string|null} [stream, error]
     */
    public function openStream(string $cmd, float $timeout = 5.0): array
    {
        [$fd, $err] = $this->connect($timeout);
        if ($fd === null) {
            return [null, $err ?? 'connect failed'];
        }
        if (@fwrite($fd, $cmd . "\n") === false) {
            fclose($fd);
            return [null, 'write failed'];
        }
        return [$fd, null];
    }

    /** @return array{0: resource|null, 1: string|null} */
    private function connect(float $timeout): array
    {
        if ($this->path === '') {
            return [null, 'no control socket configured'];
        }
        $errno = 0;
        $errstr = '';
        $fd = @stream_socket_client('unix://' . $this->path, $errno, $errstr, $timeout);
        if ($fd === false) {
            return [null, "control socket unavailable ({$this->path}): $errstr"];
        }
        return [$fd, null];
    }
}
