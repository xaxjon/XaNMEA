<?php
declare(strict_types=1);

namespace XaNmea\Decode;

use XaNmea\Sentence;

/**
 * AIS decoder: VDM/VDO sentences -> target records.
 *
 * Handles 6-bit ASCII armouring, multi-part fragment reassembly
 * (keyed by channel + sequential message id, 30 s timeout) and the
 * common message types. Bit offsets per ITU-R M.1371.
 *
 * Pure functions + a small fragment buffer. Never throws.
 */
final class Ais
{
    /** @var array<string,array{ts:float,payload:string,fill:int,total:int,got:array<int,bool>}> */
    private array $fragments = [];
    private const FRAG_TTL = 30.0;

    /**
     * Feed one VDM/VDO sentence. Returns a decoded message array
     * (['message_id'=>int,'mmsi'=>string, ...fields]) or null if the
     * message is incomplete / undecodable.
     */
    public function feed(Sentence $s): ?array
    {
        $f = $s->fields;
        if (count($f) < 6) {
            return null;
        }
        $total = (int)$f[0];
        $num = (int)$f[1];
        $seq = (string)$f[2];
        $channel = (string)$f[3];
        $payload = (string)$f[4];
        $fill = (int)($f[5] ?? 0);

        if ($total < 1 || $total > 9 || $num < 1 || $num > $total) {
            return null;
        }
        if (!$this->payloadValid($payload)) {
            return null;
        }

        if ($total === 1) {
            return $this->decodePayload($payload, $fill);
        }

        // Multi-part reassembly
        $key = $s->srcName . '|' . $channel . '|' . $seq;
        $this->expireFragments();
        if ($num === 1 || !isset($this->fragments[$key])) {
            $this->fragments[$key] = ['ts' => microtime(true), 'payload' => '', 'fill' => 0, 'total' => $total, 'got' => []];
        }
        $frag = &$this->fragments[$key];
        if (isset($frag['got'][$num])) {
            return null; // duplicate fragment
        }
        // Fragments 1..n-1 carry full 6-bit chars; last carries the fill bits
        $frag['payload'] .= $payload;
        $frag['got'][$num] = true;
        if ($num === $total) {
            $frag['fill'] = $fill;
        }
        if (count($frag['got']) < $total) {
            return null; // still waiting
        }
        $full = $frag['payload'];
        $fullFill = $frag['fill'];
        unset($this->fragments[$key]);
        return $this->decodePayload($full, $fullFill);
    }

    private function expireFragments(): void
    {
        $now = microtime(true);
        foreach ($this->fragments as $k => $frag) {
            if ($now - $frag['ts'] > self::FRAG_TTL) {
                unset($this->fragments[$k]);
            }
        }
    }

    private function payloadValid(string $p): bool
    {
        for ($i = 0, $n = strlen($p); $i < $n; $i++) {
            if (self::armourValue($p[$i]) < 0) {
                return false;
            }
        }
        return true;
    }

    // ---- 6-bit armour / bit extraction ----

    private static function armourValue(string $ch): int
    {
        $v = ord($ch);
        if ($v < 48 || $v > 119 || ($v > 87 && $v < 96)) {
            return -1;
        }
        $v -= 48;
        if ($v > 40) {
            $v -= 8;
        }
        return $v;
    }

    /** Decode payload to a bit string ('0'/'1' chars), minus fill bits. */
    private static function toBits(string $payload, int $fill): string
    {
        $bits = '';
        for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
            $v = self::armourValue($payload[$i]);
            if ($v < 0) {
                return '';
            }
            $bits .= str_pad(decbin($v), 6, '0', STR_PAD_LEFT);
        }
        if ($fill > 0 && strlen($bits) >= $fill) {
            $bits = substr($bits, 0, strlen($bits) - $fill);
        }
        return $bits;
    }

    private static function uint(string $bits, int $start, int $len): int
    {
        $s = substr($bits, $start, $len);
        return $s === '' ? 0 : bindec($s);
    }

    private static function sint(string $bits, int $start, int $len): int
    {
        $v = self::uint($bits, $start, $len);
        if ($v >= (1 << ($len - 1))) {
            $v -= (1 << $len);
        }
        return $v;
    }

    private static function str6(string $bits, int $start, int $lenChars): string
    {
        static $table = '@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_ !"#$%&\'()*+,-./0123456789:;<=>?';
        $out = '';
        for ($i = 0; $i < $lenChars; $i++) {
            $v = self::uint($bits, $start + $i * 6, 6);
            $out .= $table[$v] ?? ' ';
        }
        return rtrim($out, " \t\n\r\0@");
    }

    // ---- message decoders ----

    private function decodePayload(string $payload, int $fill): ?array
    {
        $bits = self::toBits($payload, $fill);
        if (strlen($bits) < 38) {
            return null;
        }
        $type = self::uint($bits, 0, 6);
        $mmsi = (string)self::uint($bits, 8, 30);

        switch ($type) {
            case 1:
            case 2:
            case 3:
                if (strlen($bits) < 168) {
                    return null;
                }
                $lon = self::sint($bits, 61, 28);
                $lat = self::sint($bits, 89, 27);
                if ($lon === 0x6791AC0 || $lat === 0x3412140) {
                    $posValid = false; // "not available" markers
                } else {
                    $posValid = true;
                }
                $msg = [
                    'message_id' => $type,
                    'mmsi' => $mmsi,
                    'class' => 'A',
                    'nav_status' => self::uint($bits, 38, 4),
                    'rot' => self::rotDecode(self::sint($bits, 42, 8)),
                    'sog' => self::uint($bits, 50, 10) / 10.0,
                    'cog' => self::uint($bits, 116, 12) / 10.0,
                    'hdg' => ($h = self::uint($bits, 128, 9)) === 511 ? null : $h,
                ];
                if ($posValid) {
                    $msg['lon'] = $lon / 600000.0;
                    $msg['lat'] = $lat / 600000.0;
                }
                return $msg;

            case 5:
                if (strlen($bits) < 424) {
                    return null;
                }
                return [
                    'message_id' => 5,
                    'mmsi' => $mmsi,
                    'class' => 'A',
                    'name' => self::str6($bits, 112, 20),
                    'callsign' => self::str6($bits, 90, 7),
                    'ship_type' => self::uint($bits, 232, 8),
                    'dim_bow' => self::uint($bits, 240, 9),
                    'dim_stern' => self::uint($bits, 249, 9),
                    'dim_port' => self::uint($bits, 258, 6),
                    'dim_starboard' => self::uint($bits, 264, 6),
                    'draught' => self::uint($bits, 294, 8) / 10.0,
                    'destination' => self::str6($bits, 302, 20),
                ];

            case 18:
                if (strlen($bits) < 168) {
                    return null;
                }
                $lon = self::sint($bits, 57, 28);
                $lat = self::sint($bits, 85, 27);
                $msg = [
                    'message_id' => 18,
                    'mmsi' => $mmsi,
                    'class' => 'B',
                    'sog' => self::uint($bits, 46, 10) / 10.0,
                    'cog' => self::uint($bits, 112, 12) / 10.0,
                    'hdg' => ($h = self::uint($bits, 124, 9)) === 511 ? null : $h,
                ];
                if ($lon !== 0x6791AC0 && $lat !== 0x3412140) {
                    $msg['lon'] = $lon / 600000.0;
                    $msg['lat'] = $lat / 600000.0;
                }
                return $msg;

            case 19:
                if (strlen($bits) < 312) {
                    return null;
                }
                $lon = self::sint($bits, 61, 28);
                $lat = self::sint($bits, 89, 27);
                $msg = [
                    'message_id' => 19,
                    'mmsi' => $mmsi,
                    'class' => 'B',
                    'sog' => self::uint($bits, 46, 10) / 10.0,
                    'cog' => self::uint($bits, 112, 12) / 10.0,
                    'name' => self::str6($bits, 143, 20),
                ];
                if ($lon !== 0x6791AC0 && $lat !== 0x3412140) {
                    $msg['lon'] = $lon / 600000.0;
                    $msg['lat'] = $lat / 600000.0;
                }
                return $msg;

            case 21: // Aid to Navigation
                if (strlen($bits) < 272) {
                    return null;
                }
                $lon = self::sint($bits, 61, 28);
                $lat = self::sint($bits, 89, 27);
                $nameLen = (strlen($bits) >= 272 + 88) ? 20 + self::uint($bits, 270, 2) : 20;
                $msg = [
                    'message_id' => 21,
                    'mmsi' => $mmsi,
                    'class' => 'AtoN',
                    'aton_type' => self::uint($bits, 38, 5),
                    'name' => self::str6($bits, 43, min(20 + $nameLen, 34)),
                ];
                if ($lon !== 0x6791AC0 && $lat !== 0x3412140) {
                    $msg['lon'] = $lon / 600000.0;
                    $msg['lat'] = $lat / 600000.0;
                }
                return $msg;

            case 24: // Class B static: part A (name) or part B (type/dims)
                if (strlen($bits) < 160) {
                    return null;
                }
                $part = self::uint($bits, 38, 2);
                if ($part === 0) {
                    return [
                        'message_id' => 24,
                        'mmsi' => $mmsi,
                        'class' => 'B',
                        'name' => self::str6($bits, 40, 20),
                    ];
                }
                if (strlen($bits) < 168) {
                    return null;
                }
                return [
                    'message_id' => 24,
                    'mmsi' => $mmsi,
                    'class' => 'B',
                    'callsign' => self::str6($bits, 90, 7),
                    'ship_type' => self::uint($bits, 40, 8),
                    'dim_bow' => self::uint($bits, 132, 9),
                    'dim_stern' => self::uint($bits, 141, 9),
                    'dim_port' => self::uint($bits, 150, 6),
                    'dim_starboard' => self::uint($bits, 156, 6),
                ];

            case 27: // long-range
                if (strlen($bits) < 96) {
                    return null;
                }
                $lon = self::sint($bits, 44, 18);
                $lat = self::sint($bits, 62, 17);
                $msg = [
                    'message_id' => 27,
                    'mmsi' => $mmsi,
                    'class' => 'A',
                    'sog' => (float)self::uint($bits, 79, 6),
                    'cog' => (float)self::uint($bits, 85, 9),
                ];
                if ($lon !== 0x1A838 || $lat !== 0xD548) {
                    $msg['lon'] = $lon / 600.0;
                    $msg['lat'] = $lat / 600.0;
                }
                return $msg;

            default:
                return ['message_id' => $type, 'mmsi' => $mmsi, 'class' => '?', 'undecoded' => true];
        }
    }

    private static function rotDecode(int $raw): ?float
    {
        if ($raw === -128) {
            return null; // not available
        }
        $sign = $raw < 0 ? -1 : 1;
        return $sign * (($raw / 4.733) ** 2);
    }
}
