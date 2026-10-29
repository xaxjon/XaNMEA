<?php
declare(strict_types=1);

namespace XaNmea\Io;

use XaNmea\Logger;
use XaNmea\Sentence;

/**
 * Internal 1 Hz GPS source derived from one selected input interface.
 * GNS/GLL provide position and fix state, ZDA provides UTC date/time,
 * and VTG provides ground speed and course.
 */
final class SyntheticIface extends Iface
{
    public string $type = 'synthetic';

    private string $source;
    private int $maxAge;
    private array $position = [];
    private array $gns = [];
    private array $gll = [];
    private array $clock = [];
    private array $motion = [];

    public function __construct(array $def, Logger $log)
    {
        parent::__construct($def, $log);
        $this->source = $def['source'];
        $this->maxAge = $def['max_age'];
    }

    public function open(): void
    {
        $this->state = 'up';
        $this->upSince = microtime(true);
        $this->lastError = null;
        $this->log->info("{$this->name}: synthetic GGA/RMC at 1 Hz from {$this->source}");
    }

    public function close(): void
    {
        $this->state = 'down';
        $this->upSince = null;
    }

    public function observe(Sentence $s): void
    {
        if (strcasecmp($s->srcName, $this->source) !== 0) {
            return;
        }
        switch ($s->type) {
            case 'GNS':
                $this->observeGns($s);
                break;
            case 'GLL':
                $this->observeGll($s);
                break;
            case 'ZDA':
                $this->observeZda($s);
                break;
            case 'VTG':
                $this->observeVtg($s);
                break;
        }
    }

    public function tick(float $now): void
    {
        if ($this->state !== 'up' || !$this->fresh($this->position, $now)) {
            return;
        }
        $clockFresh = $this->fresh($this->clock, $now);
        $utc = $clockFresh ? $this->clock['utc'] : ($this->position['utc'] ?? '');
        if ($utc === '') {
            return;
        }

        $this->emit($this->buildGga($utc, $now), $now);
        if ($clockFresh && $this->clock['date'] !== '') {
            $this->emit($this->buildRmc($utc, $this->clock['date'], $now), $now);
        }
    }

    public function statsRow(): array
    {
        return parent::statsRow() + [
            'source' => $this->source,
            'max_age' => $this->maxAge,
        ];
    }

    private function observeGns(Sentence $s): void
    {
        $f = $s->fields;
        $mode = strtoupper((string)($f[5] ?? ''));
        $quality = 0;
        for ($i = 0; $i < strlen($mode); $i++) {
            $q = match ($mode[$i]) {
                'A', 'P' => 1,
                'D' => 2,
                'F' => 5,
                'R' => 4,
                'E', 'M', 'S' => 6,
                default => 0,
            };
            $quality = max($quality, $q);
        }
        $this->gns = [
            'ts' => $s->ts,
            'quality' => $quality,
            'mode' => $mode,
            'sats' => $this->numberField($f, 6),
            'hdop' => $this->numberField($f, 7),
            'altitude' => $this->numberField($f, 8),
            'geoid' => $this->numberField($f, 9),
            'age' => $this->numberField($f, 10),
            'station' => (string)($f[11] ?? ''),
        ];
        $this->updatePosition($s, 1, 0);
    }

    private function observeGll(Sentence $s): void
    {
        $f = $s->fields;
        $status = strtoupper((string)($f[5] ?? ''));
        $this->gll = [
            'ts' => $s->ts,
            'quality' => $status === 'A' ? 1 : 0,
            'mode' => strtoupper((string)($f[6] ?? '')),
        ];
        if ($status !== 'V') {
            $this->updatePosition($s, 0, 4);
        }
    }

    private function observeZda(Sentence $s): void
    {
        $f = $s->fields;
        $utc = (string)($f[0] ?? '');
        $day = (int)($f[1] ?? 0);
        $month = (int)($f[2] ?? 0);
        $year = (int)($f[3] ?? 0);
        if (!preg_match('/^\d{6}(?:\.\d+)?$/', $utc) || !checkdate($month, $day, $year)) {
            return;
        }
        $this->clock = [
            'ts' => $s->ts,
            'utc' => $utc,
            'date' => sprintf('%02d%02d%02d', $day, $month, $year % 100),
        ];
    }

    private function observeVtg(Sentence $s): void
    {
        $this->motion = [
            'ts' => $s->ts,
            'cog' => $this->numberField($s->fields, 0),
            'sog' => $this->numberField($s->fields, 4),
        ];
    }

    private function updatePosition(Sentence $s, int $latIndex, int $utcIndex): void
    {
        $f = $s->fields;
        $lat = (string)($f[$latIndex] ?? '');
        $latH = strtoupper((string)($f[$latIndex + 1] ?? ''));
        $lon = (string)($f[$latIndex + 2] ?? '');
        $lonH = strtoupper((string)($f[$latIndex + 3] ?? ''));
        $utc = (string)($f[$utcIndex] ?? '');
        if (!preg_match('/^\d{4}(?:\.\d+)?$/', $lat) || !in_array($latH, ['N', 'S'], true)
            || !preg_match('/^\d{5}(?:\.\d+)?$/', $lon) || !in_array($lonH, ['E', 'W'], true)
            || !preg_match('/^\d{6}(?:\.\d+)?$/', $utc)) {
            return;
        }
        $this->position = [
            'ts' => $s->ts,
            'lat' => $lat,
            'lat_h' => $latH,
            'lon' => $lon,
            'lon_h' => $lonH,
            'utc' => $utc,
        ];
    }

    private function buildGga(string $utc, float $now): Sentence
    {
        [$quality] = $this->fixState($now);
        $gns = $this->fresh($this->gns, $now) ? $this->gns : [];
        $sats = $gns['sats'] ?? '';
        if ($sats !== '') {
            $sats = sprintf('%02d', (int)$sats);
        }
        return Sentence::build('GP', 'GGA', [
            $utc,
            $this->position['lat'], $this->position['lat_h'],
            $this->position['lon'], $this->position['lon_h'],
            (string)$quality,
            $sats,
            $gns['hdop'] ?? '',
            $gns['altitude'] ?? '', 'M',
            $gns['geoid'] ?? '', 'M',
            $gns['age'] ?? '',
            $gns['station'] ?? '',
        ]);
    }

    private function buildRmc(string $utc, string $date, float $now): Sentence
    {
        [$quality, $mode] = $this->fixState($now);
        $motion = $this->fresh($this->motion, $now) ? $this->motion : [];
        return Sentence::build('GP', 'RMC', [
            $utc,
            $quality > 0 ? 'A' : 'V',
            $this->position['lat'], $this->position['lat_h'],
            $this->position['lon'], $this->position['lon_h'],
            $motion['sog'] ?? '',
            $motion['cog'] ?? '',
            $date,
            '', '',
            $mode !== '' ? substr($mode, 0, 1) : 'N',
        ]);
    }

    private function fixState(float $now): array
    {
        if ($this->fresh($this->gns, $now)) {
            return [(int)$this->gns['quality'], (string)$this->gns['mode']];
        }
        if ($this->fresh($this->gll, $now)) {
            return [(int)$this->gll['quality'], (string)$this->gll['mode']];
        }
        return [0, 'N'];
    }

    private function fresh(array $sample, float $now): bool
    {
        return isset($sample['ts']) && $now - (float)$sample['ts'] <= $this->maxAge;
    }

    private function numberField(array $fields, int $index): string
    {
        $value = (string)($fields[$index] ?? '');
        return is_numeric($value) ? $value : '';
    }

    private function emit(Sentence $s, float $now): void
    {
        $this->counters['in']++;
        $this->counters['bytes_in'] += strlen($s->raw);
        $this->lastActivity = $now;
        $this->router->dispatch($this, $s);
    }
}
