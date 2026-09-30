<?php
declare(strict_types=1);

namespace XaNmea;

/**
 * Sentence filter. Two spec forms:
 *
 * 1. Structured rules (current UI), as a JSON object:
 *      {"rules": [
 *        {"action":"pass","class":"navigation"},
 *        {"action":"drop","class":"ais","src":"gps-serial"},
 *        {"action":"pass","class":"custom","match":"GP***","limit_s":5}
 *      ]}
 *    OPNsense-style semantics: rules are evaluated top-down, first match
 *    wins; a sentence matches NO rule => BLOCKED (implicit deny). An empty
 *    rules list (or absent filter) passes everything.
 *    class: all | navigation | ais | weather | alarms | custom
 *    custom requires match (5-char talker+type with '*' wildcards).
 *    src optionally restricts the rule to sentences from one interface.
 *    limit_s optionally rate-limits a pass rule (min seconds between passes).
 *
 * 2. Legacy kplex string "+GP***:-all:~GPGGA%gps/5" (and the equivalent
 *    list-of-arrays form): kept for backward compatibility with existing
 *    configs. Legacy semantics: first match wins, no match => ALLOW.
 */
final class Filter
{
    public const CLASSES = [
        'navigation' => ['GGA', 'GNS', 'GLL', 'RMC', 'VTG', 'GSA', 'GSV', 'ZDA', 'HDG', 'HDT', 'HDM', 'ROT', 'DBT', 'DPT', 'VHW', 'VBW', 'VLW', 'XTE', 'APB', 'BWC', 'BWR', 'RMB'],
        'ais' => ['VDM', 'VDO'],
        'weather' => ['MWV', 'MWD', 'VWR', 'VWT', 'MDA', 'MTW', 'XDR'],
        'alarms' => ['ALR', 'ALC', 'ACK'],
    ];

    /** @var array<int,array> */
    private array $rules = [];
    private bool $legacy = false;

    /** @param string|array|null $spec */
    public static function compile($spec): self
    {
        $f = new self();
        if ($spec === null || $spec === '' || $spec === []) {
            return $f; // pass all
        }
        if (is_string($spec)) {
            $f->legacy = true;
            foreach (explode(':', $spec) as $tok) {
                $tok = trim($tok);
                if ($tok !== '') {
                    $f->addLegacyRule($tok);
                }
            }
            return $f;
        }
        if (is_array($spec) && isset($spec['rules']) && is_array($spec['rules'])) {
            foreach ($spec['rules'] as $r) {
                $f->addStructuredRule($r);
            }
            return $f;
        }
        if (is_array($spec)) {
            // legacy list-of-arrays: [{"op":"+","match":"GP***",...}]
            $f->legacy = true;
            foreach ($spec as $r) {
                if (!is_array($r) || !isset($r['op'], $r['match'])) {
                    continue;
                }
                $op = (string)$r['op'];
                if (!in_array($op, ['+', '-', '~'], true)) {
                    continue;
                }
                $f->rules[] = [
                    'op' => $op,
                    'pattern' => strcasecmp((string)$r['match'], 'all') === 0 ? null : strtoupper(substr((string)$r['match'], 0, 5)),
                    'src' => isset($r['src']) && $r['src'] !== '' ? (string)$r['src'] : null,
                    'period' => isset($r['period']) ? max(0, (int)$r['period']) : 0,
                    'last' => 0.0,
                ];
            }
        }
        return $f;
    }

    private function addStructuredRule($r): void
    {
        if (!is_array($r)) {
            return;
        }
        $action = (string)($r['action'] ?? '');
        if (!in_array($action, ['pass', 'drop'], true)) {
            return;
        }
        $class = strtolower((string)($r['class'] ?? 'all'));
        $pattern = null;
        if ($class === 'custom') {
            $match = strtoupper(trim((string)($r['match'] ?? '')));
            if ($match === '' || !preg_match('/^[A-Z0-9*]{1,5}$/', $match)) {
                return; // custom without a valid match pattern: skip rule
            }
            $pattern = substr($match, 0, 5);
        } elseif ($class !== 'all' && !isset(self::CLASSES[$class])) {
            return; // unknown class: skip rule
        }
        $this->rules[] = [
            'action' => $action,
            'class' => $class,
            'pattern' => $pattern,
            'src' => isset($r['src']) && $r['src'] !== '' ? (string)$r['src'] : null,
            'limit_s' => isset($r['limit_s']) ? max(0, (int)$r['limit_s']) : 0,
            'last' => 0.0,
        ];
    }

    private function addLegacyRule(string $tok): void
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
            'pattern' => strcasecmp($rest, 'all') === 0 ? null : strtoupper(substr($rest, 0, 5)),
            'src' => $src,
            'period' => $period,
            'last' => 0.0,
        ];
    }

    /** Does this sentence pass the filter? */
    public function passes(Sentence $s): bool
    {
        if (!$this->rules) {
            return true; // pass all
        }
        return $this->legacy ? $this->passesLegacy($s) : $this->passesStructured($s);
    }

    /** Structured: first match wins; no match => BLOCK. */
    private function passesStructured(Sentence $s): bool
    {
        foreach ($this->rules as $i => $rule) {
            if ($rule['src'] !== null && strcasecmp($rule['src'], $s->srcName) !== 0) {
                continue;
            }
            if (!$this->matchClass($rule, $s)) {
                continue;
            }
            if ($rule['action'] === 'drop') {
                return false;
            }
            if ($rule['limit_s'] > 0) {
                $now = microtime(true);
                if ($now - $this->rules[$i]['last'] < $rule['limit_s']) {
                    return false;
                }
                $this->rules[$i]['last'] = $now;
            }
            return true;
        }
        return false; // implicit deny
    }

    private function matchClass(array $rule, Sentence $s): bool
    {
        if ($rule['class'] === 'all') {
            return true;
        }
        if ($rule['class'] === 'custom') {
            return $this->matchPattern($rule['pattern'], strtoupper($s->talker . $s->type));
        }
        return in_array($s->type, self::CLASSES[$rule['class']], true);
    }

    /** Legacy: first match wins; no match => ALLOW (kplex semantics). */
    private function passesLegacy(Sentence $s): bool
    {
        $addr = strtoupper($s->talker . $s->type);
        foreach ($this->rules as $i => $rule) {
            if ($rule['src'] !== null && strcasecmp($rule['src'], $s->srcName) !== 0) {
                continue;
            }
            if ($rule['pattern'] !== null && !$this->matchPattern($rule['pattern'], $addr)) {
                continue;
            }
            if ($rule['op'] === '+') {
                return true;
            }
            if ($rule['op'] === '-') {
                return false;
            }
            $now = microtime(true);
            if ($now - $this->rules[$i]['last'] >= $rule['period']) {
                $this->rules[$i]['last'] = $now;
                return true;
            }
            return false;
        }
        return true;
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
