<?php
declare(strict_types=1);

namespace XaNmea;

/**
 * Router: fan-out of validated sentences from inputs to outputs.
 * Owns failover state. Knows nothing about sockets - only Iface objects.
 */
final class Router
{
    /** @var array<string,Iface> name => iface (outputs and bidirectional) */
    private array $outputs = [];
    private Logger $log;
    /** @var callable|null tail broadcaster: fn(Sentence, string[] dstNames): void */
    private $tailHook = null;
    /** @var callable|null decode hook: fn(Sentence): void */
    private $decodeHook = null;

    // Failover state: ruleIndex => ifaceName(lower) => last-seen ts of matching sentence
    private array $foLastSeen = [];
    private array $failovers = [];

    public function __construct(Logger $log)
    {
        $this->log = $log;
    }

    public function setTailHook(callable $cb): void { $this->tailHook = $cb; }
    public function setDecodeHook(callable $cb): void { $this->decodeHook = $cb; }

    public function setFailovers(array $failovers): void
    {
        $this->failovers = $failovers;
        $this->foLastSeen = [];
    }

    public function setOutputs(array $ifaces): void
    {
        $this->outputs = [];
        foreach ($ifaces as $if) {
            if ($if->wantsOutput()) {
                $this->outputs[strtolower($if->name)] = $if;
            }
        }
    }

    /**
     * Dispatch one sentence read from $src. Called from the event loop -
     * must be fast and must never throw.
     */
    public function dispatch(Iface $src, Sentence $s): void
    {
        $s->srcName = $src->name;

        if (!$this->failoverAllows($s)) {
            $src->counters['failover_dropped']++;
            return;
        }

        $dstNames = [];
        $srcKey = strtolower($src->name);
        foreach ($this->outputs as $key => $out) {
            if ($key === $srcKey && !$src->loopback) {
                continue;
            }
            try {
                if (!$out->allowsOut($s)) {
                    continue;
                }
                $out->enqueue($s);
                $dstNames[] = $out->name;
            } catch (\Throwable $e) {
                // one bad output must never affect the rest
                $out->noteError('enqueue: ' . $e->getMessage());
            }
        }

        if ($this->tailHook !== null) {
            try {
                ($this->tailHook)($s, $dstNames);
            } catch (\Throwable $e) {
                // diagnostics must never affect routing
            }
        }
        if ($this->decodeHook !== null) {
            try {
                ($this->decodeHook)($s);
            } catch (\Throwable $e) {
                // decoding must never affect routing
            }
        }
    }

    /**
     * kplex-style failover: for each failover rule matching the sentence,
     * an interface at priority level i may pass it only if no matching
     * sentence has been seen on ANY higher-priority interface within that
     * interface's delay. Primary (delay 0) always passes.
     */
    private function failoverAllows(Sentence $s): bool
    {
        if (!$this->failovers) {
            return true;
        }
        $allowed = true;
        $now = microtime(true);
        foreach ($this->failovers as $ri => $fo) {
            if (!$this->ruleMatches($fo['match'], $s)) {
                continue;
            }
            // Find where this src sits in the priority list
            $srcLower = strtolower($s->srcName);
            $myLevel = null;
            foreach ($fo['priorities'] as $level => $p) {
                if (strtolower($p['interface']) === $srcLower) {
                    $myLevel = $level;
                    break;
                }
            }
            if ($myLevel === null) {
                return false; // not in the failover list: never passes matching data
            }
            $myDelay = $fo['priorities'][$myLevel]['delay'];
            // Check all higher-priority interfaces for recent matching data
            foreach ($fo['priorities'] as $level => $p) {
                if ($level >= $myLevel) {
                    break;
                }
                $last = $this->foLastSeen[$ri][strtolower($p['interface'])] ?? 0.0;
                if ($now - $last < $myDelay) {
                    return false;
                }
            }
            $this->foLastSeen[$ri][$srcLower] = $now;
        }
        return $allowed;
    }

    private function ruleMatches(string $match, Sentence $s): bool
    {
        if ($match === 'all') {
            return true;
        }
        $addr = strtoupper($s->talker . $s->type);
        $pattern = str_pad(strtoupper(substr($match, 0, 5)), 5, '*');
        for ($i = 0; $i < 5; $i++) {
            if ($pattern[$i] === '*') {
                continue;
            }
            if (!isset($addr[$i]) || $addr[$i] !== $pattern[$i]) {
                return false;
            }
        }
        return true;
    }
}
