<?php
declare(strict_types=1);

namespace XaNmea;

/**
 * kplex-compatible filter: ordered rules "+match", "-match", "~match/seconds",
 * match is "all" or 5 chars with '*' wildcards, optional "%srcInterface".
 * No match => allow. Stateless apart from LIMIT rule timestamps.
 */
final class Filter
{
    /** @var array<int,array{op:string,pattern:?string,src:?string,period:int,last:float}> */
    private array $rules = [];

    /**
     * Compile a filter spec string like "+GP***:+AI***:-all" or an array of
     * rule arrays from JSON config: [{"op":"+","match":"GP***","period":0,"src":null}]
     * @param string|array|null $spec
     */
    public static function compile($spec): self
    {
        $f = new self();
        if ($spec === null || $spec === '' || $spec === []) {
            return $f;
        }
        if (is_string($spec)) {
            foreach (explode(':', $spec) as $tok) {
                $tok = trim($tok);
                if ($tok === '') {
                    continue;
                }
                $f->addRule($tok);
            }
        } elseif (is_array($spec)) {
            foreach ($spec as $r) {
                if (!is_array($r) || !isset($r['op'], $r['match'])) {
                    continue;
                }
                $rule = [
                    'op' => (string)$r['op'],
                    'pattern' => null,
                    'src' => isset($r['src']) && $r['src'] !== '' ? (string)$r['src'] : null,
                    'period' => isset($r['period']) ? max(0, (int)$r['period']) : 0,
                    'last' => 0.0,
                ];
                if ($r['match'] !== 'all') {
                    $rule['pattern'] = strtoupper(substr((string)$r['match'], 0, 5));
                }
                if (in_array($rule['op'], ['+', '-', '~'], true)) {
                    $f->rules[] = $rule;
                }
            }
        }
        return $f;
    }

    private function addRule(string $tok): void
    {
        $op = $tok[0];
        if (!in_array($op, ['+', '-', '~'], true)) {
            return;
        }
        $rest = substr($tok, 1);
        // Parse %src first so both "~GPGGA%gps/5" and "~GPGGA/5%gps" work.
        $src = null;
        $pct = strpos($rest, '%');
        if ($pct !== false) {
            $src = substr($rest, $pct + 1);
            // % may precede the /period suffix; strip a trailing "/N" from src
            if ($op === '~' && preg_match('#^(.*?)/(\d+)$#', $src, $m)) {
                $src = $m[1];
                $rest = substr($rest, 0, $pct) . '/' . $m[2];
            } else {
                $rest = substr($rest, 0, $pct);
            }
        }
        $period = 0;
        if ($op === '~') {
            $slash = strpos($rest, '/');
            if ($slash === false) {
                return;
            }
            $period = max(0, (int)substr($rest, $slash + 1));
            $rest = substr($rest, 0, $slash);
        }
        $this->rules[] = [
            'op' => $op,
            'pattern' => $rest === 'all' ? null : strtoupper(substr($rest, 0, 5)),
            'src' => $src,
            'period' => $period,
            'last' => 0.0,
        ];
    }

    /** Does this sentence pass the filter? */
    public function passes(Sentence $s): bool
    {
        $addr = strtoupper(($s->talker === 'P' ? 'P' : $s->talker) . $s->type);
        foreach ($this->rules as $i => $rule) {
            if ($rule['src'] !== null && strcasecmp($rule['src'], $s->srcName) !== 0) {
                continue;
            }
            if ($rule['pattern'] !== null && !$this->matchPattern($rule['pattern'], $addr)) {
                continue;
            }
            // Rule fires
            if ($rule['op'] === '+') {
                return true;
            }
            if ($rule['op'] === '-') {
                return false;
            }
            // LIMIT: pass if >= period seconds since last pass
            $now = microtime(true);
            if ($now - $this->rules[$i]['last'] >= $rule['period']) {
                $this->rules[$i]['last'] = $now;
                return true;
            }
            return false;
        }
        return true; // no rule matched => allow
    }

    private function matchPattern(string $pattern, string $addr): bool
    {
        $pattern = str_pad($pattern, 5, '*');
        for ($i = 0; $i < 5; $i++) {
            $p = $pattern[$i];
            if ($p === '*') {
                continue;
            }
            if (!isset($addr[$i]) || $addr[$i] !== $p) {
                return false;
            }
        }
        return true;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }
}
