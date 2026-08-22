#!/usr/bin/env php
<?php
/**
 * XaNMEA NMEA-0183 simulator.
 *
 * TCP server (default port 4010) emitting a realistic synthetic data mix
 * at ~1 Hz: GPS position/velocity, heading, depth, wind, water temp, XDR
 * voltage, engine RPM, GSV satellites, and AIS VDM traffic (Class A type 1
 * + Class B type 18 + Class B static type 24, incl. multi-part type 5).
 *
 * Usage: php tools/simulator.php [port] [baseLat] [baseLon]
 */
declare(strict_types=1);
set_time_limit(0);

$port = (int)($argv[1] ?? 4010);
$baseLat = (float)($argv[2] ?? 50.80);
$baseLon = (float)($argv[3] ?? -1.30);

// ---------- AIS payload encoder (test-data generator, mirrors Decode/Ais.php) ----------

function aisBits(int $val, int $len): string
{
    return str_pad(decbin($val & ((1 << $len) - 1)), $len, '0', STR_PAD_LEFT);
}

function aisStrBits(string $s, int $lenChars): string
{
    $table = '@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_ !"#$%&\'()*+,-./0123456789:;<=>?';
    $map = array_flip(str_split($table));
    $s = strtoupper(str_pad(substr($s, 0, $lenChars), $lenChars));
    $bits = '';
    for ($i = 0; $i < $lenChars; $i++) {
        $bits .= aisBits($map[$s[$i]] ?? 32, 6); // 32 = space
    }
    return $bits;
}

function aisArmour(string $bits, int &$fill): string
{
    $pad = (6 - (strlen($bits) % 6)) % 6;
    $bits .= str_repeat('0', $pad);
    $fill = $pad;
    $out = '';
    for ($i = 0; $i < strlen($bits); $i += 6) {
        $v = bindec(substr($bits, $i, 6));
        $c = $v + 48;
        if ($c > 87) {
            $c += 8;
        }
        $out .= chr($c);
    }
    return $out;
}

function aisMsg1(int $mmsi, float $lat, float $lon, float $sog, float $cog, int $hdg): string
{
    $b = aisBits(1, 6) . aisBits(0, 2) . aisBits($mmsi, 30)
        . aisBits(0, 4)              // nav status: underway
        . aisBits(0, 8)              // rot: none
        . aisBits((int)round($sog * 10), 10)
        . aisBits(0, 1)              // accuracy
        . aisBits((int)round($lon * 600000), 28)
        . aisBits((int)round($lat * 600000), 27)
        . aisBits((int)round($cog * 10), 12)
        . aisBits($hdg, 9)
        . aisBits((int)date('s'), 6) // utc second
        . aisBits(0, 4) . aisBits(0, 2) . aisBits(0, 2) . aisBits(0, 1) . aisBits(0, 1);
    $fill = 0;
    return aisArmour($b, $fill) . ',' . $fill;
}

function aisMsg18(int $mmsi, float $lat, float $lon, float $sog, float $cog): string
{
    $b = aisBits(18, 6) . aisBits(0, 2) . aisBits($mmsi, 30)
        . aisBits(0, 8)
        . aisBits((int)round($sog * 10), 10)
        . aisBits(0, 1)
        . aisBits((int)round($lon * 600000), 28)
        . aisBits((int)round($lat * 600000), 27)
        . aisBits((int)round($cog * 10), 12)
        . aisBits(511, 9)            // hdg not available
        . aisBits((int)date('s'), 6)
        . aisBits(0, 2) . aisBits(0, 2) . aisBits(0, 2)
        . aisBits(1, 1)              // class B
        . str_repeat('0', 20);
    $fill = 0;
    return aisArmour($b, $fill) . ',' . $fill;
}

function aisMsg24A(int $mmsi, string $name): string
{
    $b = aisBits(24, 6) . aisBits(0, 2) . aisBits($mmsi, 30)
        . aisBits(0, 2)              // part A
        . aisStrBits($name, 20);
    $fill = 0;
    return aisArmour($b, $fill) . ',' . $fill;
}

/** Type 5 as TWO VDM fragments (exercises daemon reassembly). */
function aisMsg5(int $mmsi, string $name, string $callsign, int $shipType): array
{
    $b = aisBits(5, 6) . aisBits(0, 2) . aisBits($mmsi, 30)
        . aisBits(1, 2)              // ais version
        . aisBits(0, 30)             // imo
        . aisStrBits($callsign, 7)
        . aisStrBits($name, 20)
        . aisBits($shipType, 8)
        . aisBits(12, 9) . aisBits(6, 9) . aisBits(3, 6) . aisBits(3, 6) // dims
        . aisBits(1, 4)              // fix type gps
        . aisBits(0, 4) . aisBits(0, 5) . aisBits(0, 5) . aisBits(0, 6)  // ETA
        . aisBits(25, 8)             // draught 2.5m
        . aisStrBits('', 20)
        . aisBits(0, 1) . aisBits(0, 1); // dte + spare = 424 bits
    $fill = 0;
    $payload = aisArmour($b, $fill); // 71 chars -> split 60 + 11
    $p1 = substr($payload, 0, 60);
    $p2 = substr($payload, 60);
    return [
        "!AIVDM,2,1,5,A,$p1,0",
        "!AIVDM,2,2,5,A,$p2,$fill",
    ];
}

// ---------- NMEA sentence helper ----------

function nmea(string $talker, string $type, array $fields): string
{
    $body = $talker . $type . ',' . implode(',', $fields);
    $cs = 0;
    for ($i = 0; $i < strlen($body); $i++) {
        $cs ^= ord($body[$i]);
    }
    return '$' . $body . '*' . strtoupper(str_pad(dechex($cs), 2, '0', STR_PAD_LEFT));
}

function aisNmea(string $bodyNoChecksum): string
{
    $cs = 0;
    for ($i = 1; $i < strlen($bodyNoChecksum); $i++) { // skip leading '!'
        $cs ^= ord($bodyNoChecksum[$i]);
    }
    return $bodyNoChecksum . '*' . strtoupper(str_pad(dechex($cs), 2, '0', STR_PAD_LEFT));
}

function fmtLatLon(float $deg, bool $isLat): array
{
    $hemi = $deg >= 0 ? ($isLat ? 'N' : 'E') : ($isLat ? 'S' : 'W');
    $deg = abs($deg);
    $d = intdiv((int)floor($deg * 1000000), 1000000);
    $min = ($deg - $d) * 60;
    $fmt = $isLat ? '%02d%07.4f' : '%03d%07.4f';
    return [sprintf($fmt, $d, $min), $hemi];
}

// ---------- simulation state ----------

$own = ['lat' => $baseLat, 'lon' => $baseLon, 'sog' => 5.2, 'cog' => 220.0, 'hdg' => 218.0];
$targets = [
    ['mmsi' => 235001001, 'name' => 'SOLENT STAR', 'call' => '2ABCD', 'type' => 70, 'lat' => $baseLat + 0.03, 'lon' => $baseLon - 0.05, 'sog' => 12.0, 'cog' => 90.0, 'class' => 'A'],
    ['mmsi' => 235001002, 'name' => 'WIGHT TRADER', 'call' => '2EFGH', 'type' => 36, 'lat' => $baseLat - 0.02, 'lon' => $baseLon + 0.06, 'sog' => 8.5, 'cog' => 250.0, 'class' => 'A'],
    ['mmsi' => 235001003, 'name' => 'SEAGULL', 'call' => '', 'type' => 36, 'lat' => $baseLat + 0.01, 'lon' => $baseLon + 0.02, 'sog' => 4.0, 'cog' => 45.0, 'class' => 'B'],
];
$wind = ['aws' => 12.0, 'awa' => 35.0];
$press = 1015.0;
$t = 0;

$server = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "simulator: $errstr\n");
    exit(1);
}
stream_set_blocking($server, false);
fwrite(STDERR, "simulator: listening on tcp://0.0.0.0:$port\n");

$clients = [];

while (true) {
    $read = [$server];
    foreach ($clients as $c) {
        $read[] = $c;
    }
    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 1);

    if (in_array($server, $read, true)) {
        while ($fd = @stream_socket_accept($server, 0)) {
            stream_set_blocking($fd, true);
            $clients[] = $fd;
            fwrite(STDERR, "simulator: client connected (" . count($clients) . ")\n");
        }
    }

    // advance simulation 1 second
    $t++;
    $own['cog'] = fmod($own['cog'] + 0.3, 360.0);
    $own['hdg'] = fmod($own['cog'] + 358 + 2.0, 360.0);
    $own['lat'] += cos(deg2rad($own['cog'])) * $own['sog'] / 3600 / 60;
    $own['lon'] += sin(deg2rad($own['cog'])) * $own['sog'] / 3600 / 60 / max(0.2, cos(deg2rad($own['lat'])));
    $wind['aws'] = max(2, $wind['aws'] + sin($t / 20) * 0.4);
    $wind['awa'] = fmod($wind['awa'] + sin($t / 40) * 2 + 360, 360);
    $press += sin($t / 300) * 0.05;
    foreach ($targets as &$tg) {
        $tg['lat'] += cos(deg2rad($tg['cog'])) * $tg['sog'] / 3600 / 60;
        $tg['lon'] += sin(deg2rad($tg['cog'])) * $tg['sog'] / 3600 / 60 / max(0.2, cos(deg2rad($tg['lat'])));
    }
    unset($tg);

    [$latStr, $latH] = fmtLatLon($own['lat'], true);
    [$lonStr, $lonH] = fmtLatLon($own['lon'], false);
    $utc = date('His');

    $lines = [];
    $lines[] = nmea('GP', 'RMC', [$utc, 'A', $latStr, $latH, $lonStr, $lonH, number_format($own['sog'], 1, '.', ''), number_format($own['cog'], 1, '.', ''), date('dmy'), '', '', 'A']);
    $lines[] = nmea('GP', 'GGA', [$utc, $latStr, $latH, $lonStr, $lonH, '1', '09', '0.9', '12.3', 'M', '', '', '', '']);
    $lines[] = nmea('GP', 'VTG', [number_format($own['cog'], 1, '.', ''), 'T', '', 'M', number_format($own['sog'], 1, '.', ''), 'N', '', 'K', 'A']);
    $lines[] = nmea('HC', 'HDT', [number_format($own['hdg'], 1, '.', ''), 'T']);
    $lines[] = nmea('SD', 'DBT', ['32.8', 'f', '10.0', 'M', '5.5', 'F']);
    $lines[] = nmea('II', 'MWV', [number_format($wind['awa'], 1, '.', ''), 'R', number_format($wind['aws'], 1, '.', ''), 'N', 'A']);
    $lines[] = nmea('II', 'MTW', ['14.5', 'C']);
    $lines[] = nmea('WI', 'XDR', ['P', number_format($press / 1000, 5, '.', ''), 'B', 'Baro', 'V', '12.6', 'V', 'BAT1']);
    $lines[] = nmea('II', 'RPM', ['S', '0', '2400', '100', 'A']);
    if ($t % 5 === 0) {
        $lines[] = nmea('GP', 'GSV', ['2', '1', '08', '03', '45', '120', '38', '11', '30', '200', '30', '22', '60', '310', '42', '07', '15', '045', '25']);
    }
    // AIS: each class-A target every 3s, class-B every 10s, statics every 60s
    foreach ($targets as $tg) {
        $isA = $tg['class'] === 'A';
        if (($isA && $t % 3 === 0) || (!$isA && $t % 10 === 0)) {
            $payload = $isA
                ? aisMsg1($tg['mmsi'], $tg['lat'], $tg['lon'], $tg['sog'], $tg['cog'], (int)$tg['cog'])
                : aisMsg18($tg['mmsi'], $tg['lat'], $tg['lon'], $tg['sog'], $tg['cog']);
            $lines[] = aisNmea("!AIVDM,1,1,,A,$payload");
        }
        if ($t % 60 === 1) {
            if ($isA) {
                foreach (aisMsg5($tg['mmsi'], $tg['name'], $tg['call'], $tg['type']) as $frag) {
                    $lines[] = aisNmea($frag);
                }
            } else {
                $lines[] = aisNmea('!AIVDM,1,1,,B,' . aisMsg24A($tg['mmsi'], $tg['name']));
            }
        }
    }

    $blob = implode("\r\n", $lines) . "\r\n";
    foreach ($clients as $i => $fd) {
        $n = @fwrite($fd, $blob);
        if ($n === false || ($n === 0 && !is_resource($fd)) || feof($fd)) {
            unset($clients[$i]);
            fwrite(STDERR, "simulator: client disconnected\n");
            continue;
        }
    }
    $clients = array_values($clients);
}
