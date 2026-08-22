<?php
declare(strict_types=1);

namespace XaNmea;

/**
 * Minimal leveled logger: stderr (+ optional syslog). Never throws.
 */
final class Logger
{
    public const DEBUG = 0;
    public const INFO = 1;
    public const NOTICE = 2;
    public const WARNING = 3;
    public const ERROR = 4;

    private int $level;
    private bool $syslog;
    /** @var array<int,array{ts:float,level:int,msg:string}> ring buffer served to UI */
    private array $events = [];
    private int $maxEvents = 500;

    public function __construct(string $level = 'info', bool $syslog = false)
    {
        $map = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4];
        $this->level = $map[strtolower($level)] ?? 1;
        $this->syslog = $syslog && function_exists('openlog');
        if ($this->syslog) {
            openlog('xanmead', LOG_PID | LOG_NDELAY, LOG_DAEMON);
        }
    }

    public function debug(string $msg): void { $this->log(self::DEBUG, $msg); }
    public function info(string $msg): void { $this->log(self::INFO, $msg); }
    public function notice(string $msg): void { $this->log(self::NOTICE, $msg); }
    public function warning(string $msg): void { $this->log(self::WARNING, $msg); }
    public function error(string $msg): void { $this->log(self::ERROR, $msg); }

    public function log(int $level, string $msg): void
    {
        $names = ['DEBUG', 'INFO', 'NOTICE', 'WARN', 'ERROR'];
        $this->events[] = ['ts' => microtime(true), 'level' => $level, 'msg' => $msg];
        if (count($this->events) > $this->maxEvents) {
            array_shift($this->events);
        }
        if ($level < $this->level) {
            return;
        }
        $line = date('Y-m-d H:i:s') . ' [' . ($names[$level] ?? '?') . '] ' . $msg . "\n";
        try {
            fwrite(STDERR, $line);
            if ($this->syslog) {
                $prio = [LOG_DEBUG, LOG_INFO, LOG_NOTICE, LOG_WARNING, LOG_ERR][$level] ?? LOG_INFO;
                syslog($prio, $msg);
            }
        } catch (\Throwable $e) {
            // logging must never take the daemon down
        }
    }

    /** @return array<int,array{ts:float,level:int,msg:string}> */
    public function tail(int $limit = 200): array
    {
        return array_slice($this->events, -max(1, $limit));
    }
}
