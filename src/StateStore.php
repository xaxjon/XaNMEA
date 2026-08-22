<?php
declare(strict_types=1);

namespace XaNmea;

/**
 * Live decoded state store. Owns ownship / AIS targets / weather / misc /
 * sentence registry. Every mutation produces a compact delta queued to
 * STREAM subscribers via the delta hook.
 *
 * Reliability contract: this store is fed from the decode hook, which the
 * router calls inside try/catch. Writes here must be cheap and bounded:
 * AIS targets and delta queues are capped.
 */
final class StateStore
{
    public array $ownship = [];
    /** @var array<string,array> mmsi => target */
    public array $ais = [];
    public array $weather = ['latest' => [], 'wind_history' => [], 'pressure_history' => []];
    /** @var array<string,array> "TALKERTYPE" => {talker,type,fields,ts,rate} */
    public array $misc = [];
    /** @var array<string,array> sentence registry */
    public array $sentences = [];

    private int $aisMax;
    private int $aisStaleSec;
    private int $aisDropSec;

    /** @var callable|null fn(array $delta): void */
    private $deltaHook = null;

    // rate limiting for deltas: min interval per section
    private float $lastDelta = 0.0;
    private array $pendingDelta = [];

    // history ring sizes
    private const WIND_RING = 600;      // ~10 min @ 1 Hz
    private const PRESSURE_RING = 1440; // 24 h @ 1/min

    private float $lastPressureSample = 0.0;

    public function __construct(int $aisMax, int $aisStaleSec, int $aisDropSec)
    {
        $this->aisMax = $aisMax;
        $this->aisStaleSec = $aisStaleSec;
        $this->aisDropSec = $aisDropSec;
    }

    public function setDeltaHook(callable $cb): void
    {
        $this->deltaHook = $cb;
    }

    /** Merge fields into ownship and emit delta. */
    public function updateOwnship(array $fields): void
    {
        $now = microtime(true);
        foreach ($fields as $k => $v) {
            $this->ownship[$k] = $v;
        }
        $this->ownship['updated'] = $now;
        $this->emit(['ownship' => $fields]);
    }

    /** Merge fields into an AIS target, creating it if needed. */
    public function updateAis(string $mmsi, array $fields): void
    {
        $now = microtime(true);
        if (!isset($this->ais[$mmsi])) {
            if (count($this->ais) >= $this->aisMax) {
                return; // table full; oldest pruned by sweep
            }
            $this->ais[$mmsi] = ['mmsi' => $mmsi, 'first_seen' => $now, 'msg_count' => 0];
        }
        $t = &$this->ais[$mmsi];
        foreach ($fields as $k => $v) {
            $t[$k] = $v;
        }
        $t['last_seen'] = $now;
        $t['msg_count'] = ($t['msg_count'] ?? 0) + 1;
        unset($t);
        $this->deriveAis($mmsi);
        $this->emit(['ais' => [$mmsi => $fields]]);
    }

    /** Drop all AIS targets; emits removal deltas. Returns the count cleared. */
    public function clearAis(): int
    {
        $changed = [];
        foreach ($this->ais as $mmsi => $_) {
            $changed[$mmsi] = null; // null = removed
        }
        $n = count($this->ais);
        $this->ais = [];
        if ($changed) {
            $this->emit(['ais' => $changed]);
        }
        return $n;
    }

    /** Recompute distance/bearing/CPA/TCPA against ownship. */
    private function deriveAis(string $mmsi): void
    {
        $t = $this->ais[$mmsi];
        $o = $this->ownship;
        if (!isset($t['lat'], $t['lon'], $o['lat'], $o['lon'])) {
            return;
        }
        [$distNm, $bearing] = self::distBearing($o['lat'], $o['lon'], $t['lat'], $t['lon']);
        $patch = ['distance_nm' => round($distNm, 3), 'bearing_deg' => round($bearing, 1)];
        if (isset($t['sog'], $t['cog'], $o['sog'], $o['cog'])) {
            [$cpa, $tcpa] = self::cpaTcpa(
                $o['lat'], $o['lon'], (float)$o['sog'], (float)$o['cog'],
                $t['lat'], $t['lon'], (float)$t['sog'], (float)$t['cog']
            );
            $patch['cpa_nm'] = round($cpa, 3);
            $patch['tcpa_min'] = round($tcpa, 1);
        }
        foreach ($patch as $k => $v) {
            $this->ais[$mmsi][$k] = $v;
        }
        $this->emit(['ais' => [$mmsi => $patch]]);
    }

    public function updateWeather(array $fields): void
    {
        $now = microtime(true);
        foreach ($fields as $k => $v) {
            $this->weather['latest'][$k] = ['v' => $v, 'ts' => $now];
        }
        if (isset($fields['aws']) || isset($fields['awa'])) {
            $this->weather['wind_history'][] = [
                'ts' => $now,
                'aws' => $fields['aws'] ?? null,
                'awa' => $fields['awa'] ?? null,
            ];
            if (count($this->weather['wind_history']) > self::WIND_RING) {
                array_shift($this->weather['wind_history']);
            }
        }
        if (isset($fields['pressure']) && $now - $this->lastPressureSample >= 60.0) {
            $this->lastPressureSample = $now;
            $this->weather['pressure_history'][] = ['ts' => $now, 'mb' => $fields['pressure']];
            if (count($this->weather['pressure_history']) > self::PRESSURE_RING) {
                array_shift($this->weather['pressure_history']);
            }
        }
        $this->emit(['weather' => $fields]);
    }

    public function updateMisc(string $key, string $talker, string $type, array $fields): void
    {
        $now = microtime(true);
        $prev = $this->misc[$key] ?? null;
        $this->misc[$key] = [
            'talker' => $talker,
            'type' => $type,
            'fields' => $fields,
            'ts' => $now,
            'count' => ($prev['count'] ?? 0) + 1,
        ];
        $this->emit(['misc' => [$key => ['fields' => $fields, 'ts' => $now]]]);
    }

    /** Sentence registry: track every type seen + decode status. */
    public function registerSentence(string $talker, string $type, string $status): void
    {
        $key = $talker . $type;
        $now = microtime(true);
        if (!isset($this->sentences[$key])) {
            $this->sentences[$key] = ['talker' => $talker, 'type' => $type, 'count' => 0, 'first_seen' => $now, 'status' => $status];
        }
        $this->sentences[$key]['count']++;
        $this->sentences[$key]['last_seen'] = $now;
        if ($status !== 'decoded') {
            $this->sentences[$key]['status'] = $status; // failed/unknown stick unless decoded later
        } else {
            $this->sentences[$key]['status'] = 'decoded';
        }
    }

    /** 1 Hz sweep: mark stale, drop ancient, refresh derived values. */
    public function sweep(float $now): void
    {
        $changed = [];
        foreach ($this->ais as $mmsi => $t) {
            $age = $now - ($t['last_seen'] ?? 0);
            if ($age > $this->aisDropSec) {
                unset($this->ais[$mmsi]);
                $changed[$mmsi] = null; // null = removed
                continue;
            }
            $stale = $age > $this->aisStaleSec;
            if (($t['stale'] ?? false) !== $stale) {
                $this->ais[$mmsi]['stale'] = $stale;
                $changed[$mmsi] = ['stale' => $stale];
            }
            if (!$stale && isset($t['lat'])) {
                // PHP casts numeric-string array keys to int; deriveAis needs string
                $this->deriveAis((string)$mmsi); // keep CPA fresh as ownship moves
            }
        }
        if ($changed) {
            $this->emit(['ais' => $changed]);
        }
    }

    /** Batched delta emission: coalesce to max ~5 Hz on the wire. */
    private function emit(array $delta): void
    {
        if ($this->deltaHook === null) {
            return;
        }
        $this->pendingDelta = self::mergeDelta($this->pendingDelta, $delta);
        $now = microtime(true);
        if ($now - $this->lastDelta >= 0.2) {
            $this->flushDeltas();
        }
    }

    public function flushDeltas(): void
    {
        if ($this->pendingDelta && $this->deltaHook !== null) {
            $d = $this->pendingDelta;
            $this->pendingDelta = [];
            $this->lastDelta = microtime(true);
            try {
                ($this->deltaHook)($d);
            } catch (\Throwable $e) {
                // streaming must never affect the daemon
            }
        }
    }

    /** Deep-merge two delta payloads (ais/misc keyed maps merged per key). */
    public static function mergeDelta(array $a, array $b): array
    {
        foreach ($b as $section => $val) {
            if (in_array($section, ['ais', 'misc'], true) && isset($a[$section]) && is_array($val)) {
                foreach ($val as $k => $v) {
                    $a[$section][$k] = $v === null ? null : array_merge($a[$section][$k] ?? [], $v);
                }
            } elseif (isset($a[$section]) && is_array($a[$section]) && is_array($val)) {
                $a[$section] = array_merge($a[$section], $val);
            } else {
                $a[$section] = $val;
            }
        }
        return $a;
    }

    public function snapshot(?string $section): array
    {
        if ($section === null || $section === 'all') {
            return [
                'ownship' => $this->ownship,
                'ais' => $this->ais,
                'weather' => $this->weather,
                'misc' => $this->misc,
                'sentences' => $this->sentences,
            ];
        }
        return [$section => $this->{$section} ?? null];
    }

    // ---- navigation math (flat-earth, fine at AIS ranges) ----

    /** @return array{0:float,1:float} distance NM, initial bearing deg */
    public static function distBearing(float $lat1, float $lon1, float $lat2, float $lon2): array
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $distNm = sqrt($dLat * $dLat + $dLon * $dLon) * 3440.065; // rad -> NM
        $bearing = fmod(rad2deg(atan2($dLon, $dLat)) + 360.0, 360.0);
        return [$distNm, $bearing];
    }

    /**
     * CPA/TCPA of a target vs ownship.
     * @return array{0:float,1:float} cpa NM, tcpa minutes (negative = diverging)
     */
    public static function cpaTcpa(
        float $lat1, float $lon1, float $sog1, float $cog1,
        float $lat2, float $lon2, float $sog2, float $cog2
    ): array {
        // positions in NM relative to ownship
        $dx = deg2rad($lon2 - $lon1) * cos(deg2rad($lat1)) * 60.0;
        $dy = deg2rad($lat2 - $lat1) * 60.0;
        // velocities in NM/h
        $v1x = $sog1 * sin(deg2rad($cog1));
        $v1y = $sog1 * cos(deg2rad($cog1));
        $v2x = $sog2 * sin(deg2rad($cog2));
        $v2y = $sog2 * cos(deg2rad($cog2));
        $rvx = $v2x - $v1x;
        $rvy = $v2y - $v1y;
        $rv2 = $rvx * $rvx + $rvy * $rvy;
        if ($rv2 < 1e-6) {
            return [sqrt($dx * $dx + $dy * $dy), -1.0]; // no relative motion
        }
        $tH = -($dx * $rvx + $dy * $rvy) / $rv2; // hours to CPA
        if ($tH < 0) {
            return [sqrt($dx * $dx + $dy * $dy), -1.0]; // diverging already
        }
        $cx = $dx + $rvx * $tH;
        $cy = $dy + $rvy * $tH;
        return [sqrt($cx * $cx + $cy * $cy), $tH * 60.0];
    }
}
