<?php
declare(strict_types=1);

namespace XaNmea\Decode;

use XaNmea\Sentence;
use XaNmea\StateStore;

/**
 * Core NMEA-0183 decoder. Fed by the router's decode hook (inside
 * try/catch). Updates the StateStore; never throws, never blocks.
 *
 * Covers: position/nav (GGA GLL RMC VTG GSA GSV HDG HDT HDM ROT
 * DBT DPT VHW XTE APB BWC RMB), weather (MWV MWD VWR VWT MDA MTW XDR),
 * engine/misc (RPM, XDR), AIS via Ais decoder, and a generic registry
 * for everything seen.
 */
final class Decoder
{
    private StateStore $state;
    private Ais $ais;

    public function __construct(StateStore $state)
    {
        $this->state = $state;
        $this->ais = new Ais();
    }

    public function feed(Sentence $s): void
    {
        $t = $s->type;
        $ownship = null;
        $weather = null;
        $status = 'decoded';

        switch ($t) {
            case 'GGA': $ownship = $this->gga($s); break;
            case 'GNS': $ownship = $this->gns($s); break;
            case 'GLL': $ownship = $this->gll($s); break;
            case 'RMC': $ownship = $this->rmc($s); break;
            case 'VTG': $ownship = $this->vtg($s); break;
            case 'GSA': $ownship = $this->gsa($s); break;
            case 'GSV': $this->gsv($s); break;
            case 'ZDA': $ownship = $this->zda($s); break;
            case 'HDT': $ownship = $this->hdt($s); break;
            case 'HDG': $ownship = $this->hdg($s); break;
            case 'HDM': $ownship = $this->hdm($s); break;
            case 'ROT': $ownship = $this->rot($s); break;
            case 'DBT': $ownship = $this->dbt($s); break;
            case 'DPT': $ownship = $this->dpt($s); break;
            case 'VHW': $ownship = $this->vhw($s); break;
            case 'VBW': $ownship = $this->vbw($s); break;
            case 'VLW': $ownship = $this->vlw($s); break;
            case 'XTE': $ownship = $this->xte($s); break;
            case 'APB': $ownship = $this->apb($s); break;
            case 'BWC': case 'BWR': $ownship = $this->bwc($s); break;
            case 'RMB': $ownship = $this->rmb($s); break;

            case 'ALR': $this->alr($s); break;
            case 'HBT': $this->hbt($s); break;

            case 'MWV': $weather = $this->mwv($s); break;
            case 'MWD': $weather = $this->mwd($s); break;
            case 'VWR': $weather = $this->vwr($s); break;
            case 'VWT': $weather = $this->vwt($s); break;
            case 'MDA': $weather = $this->mda($s); break;
            case 'MTW': $weather = $this->mtw($s); break;
            case 'XDR': $this->xdr($s); break;
            case 'RPM': $this->rpm($s); break;

            case 'VDM': case 'VDO': $this->aisFeed($s); break;

            default:
                $status = 'unknown';
                $this->state->updateMisc($s->talker . $t, $s->talker, $t, $this->genericFields($s));
                break;
        }

        if ($ownship) {
            $this->state->updateOwnship($ownship);
        }
        if ($weather) {
            $this->state->updateWeather($weather);
        }
        $this->state->registerSentence($s->talker, $t, $status);
    }

    // ---- helpers ----

    private static function f(array $fields, int $i): ?string
    {
        $v = $fields[$i] ?? '';
        return $v === '' ? null : (string)$v;
    }

    private static function num(array $fields, int $i): ?float
    {
        $v = self::f($fields, $i);
        return $v !== null && is_numeric($v) ? (float)$v : null;
    }

    /** NMEA lat/lon "ddmm.mmm" + hemisphere -> decimal degrees. */
    private static function latlon(?string $val, ?string $hemi): ?float
    {
        if ($val === null || $hemi === null || $val === '') {
            return null;
        }
        $dot = strpos($val, '.');
        if ($dot === false || $dot < 3) {
            return null;
        }
        $degLen = $dot - 2;
        $deg = (float)substr($val, 0, $degLen);
        $min = (float)substr($val, $degLen);
        $d = $deg + $min / 60.0;
        if ($hemi === 'S' || $hemi === 'W') {
            $d = -$d;
        }
        return round($d, 7);
    }

    private function genericFields(Sentence $s): array
    {
        $out = [];
        foreach (array_slice($s->fields, 0, 8) as $i => $v) {
            if ($v !== '') {
                $out["f$i"] = $v;
            }
        }
        return $out;
    }

    // ---- position / nav ----

    private function gga(Sentence $s): ?array
    {
        $f = $s->fields;
        $lat = self::latlon(self::f($f, 1), self::f($f, 2));
        $lon = self::latlon(self::f($f, 3), self::f($f, 4));
        $out = [];
        if ($lat !== null && $lon !== null) {
            $out['lat'] = $lat;
            $out['lon'] = $lon;
        }
        $q = self::num($f, 5);
        if ($q !== null) {
            $out['fix_quality'] = (int)$q;
        }
        $sats = self::num($f, 6);
        if ($sats !== null) {
            $out['sats'] = (int)$sats;
        }
        $hdop = self::num($f, 7);
        if ($hdop !== null) {
            $out['hdop'] = $hdop;
        }
        $alt = self::num($f, 8);
        if ($alt !== null) {
            $out['altitude_m'] = $alt;
        }
        if (self::f($f, 0) !== null) {
            $out['utc'] = self::f($f, 0);
        }
        return $out ?: null;
    }

    private function gll(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        // Status field: A = valid, V = invalid; mode (NMEA 2.3+) at index 6.
        $status = self::f($f, 5);
        if ($status !== null) {
            $out['fix_active'] = ($status === 'A');
            $out['fix_quality'] = ($status === 'A') ? 1 : 0;
        }
        if ($status === 'V') {
            return $out; // don't trust position in a void GLL
        }
        $lat = self::latlon(self::f($f, 0), self::f($f, 1));
        $lon = self::latlon(self::f($f, 2), self::f($f, 3));
        if ($lat !== null && $lon !== null) {
            $out['lat'] = $lat;
            $out['lon'] = $lon;
        }
        return $out ?: null;
    }

    /**
     * GNS: the multi-GNSS replacement for GGA.
     * $--GNS,utc,lat,NS,lon,EW,mode,numSV,HDOP,alt,sep,age,station[,navStatus]
     * mode is one char per constellation: N=none A=autonomous D=differential
     * P=precise R=RTK-fixed F=RTK-float E=estimated/DR M=manual S=simulator.
     */
    private function gns(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $lat = self::latlon(self::f($f, 1), self::f($f, 2));
        $lon = self::latlon(self::f($f, 3), self::f($f, 4));
        if ($lat !== null && $lon !== null) {
            $out['lat'] = $lat;
            $out['lon'] = $lon;
        }

        // Best fix across constellations, mapped onto GGA-style fix_quality.
        $mode = strtoupper((string)self::f($f, 5));
        $best = 0;
        for ($i = 0; $i < strlen($mode); $i++) {
            $q = match ($mode[$i]) {
                'A', 'P' => 1,
                'D' => 2,
                'F' => 5,
                'R' => 4,
                'E', 'M', 'S' => 6,
                default => 0,
            };
            $best = max($best, $q);
        }
        $out['fix_quality'] = $best;
        $out['fix_active'] = $best > 0;
        if ($mode !== '') {
            $out['fix_mode'] = $mode;
        }

        $sats = self::num($f, 6);
        if ($sats !== null) {
            $out['sats'] = (int)$sats;
        }
        $hdop = self::num($f, 7);
        if ($hdop !== null) {
            $out['hdop'] = $hdop;
        }
        $alt = self::num($f, 8);
        if ($alt !== null) {
            $out['altitude_m'] = $alt;
        }
        if (self::f($f, 0) !== null) {
            $out['utc'] = self::f($f, 0);
        }
        return $out;
    }

    /** ZDA: time & date. $--ZDA,hhmmss.ss,dd,mm,yyyy,lzh,lzn */
    private function zda(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        if (self::f($f, 0) !== null) {
            $out['utc'] = self::f($f, 0);
        }
        $d = self::f($f, 1);
        $m = self::f($f, 2);
        $y = self::f($f, 3);
        if ($d !== null && $m !== null && $y !== null) {
            $out['date'] = sprintf('%02d%02d%02d', (int)$d, (int)$m, ((int)$y) % 100);
        }
        return $out ?: null;
    }

    private function rmc(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $status = self::f($f, 1);
        $out['fix_active'] = ($status === 'A');
        if ($status === 'A') {
            $lat = self::latlon(self::f($f, 2), self::f($f, 3));
            $lon = self::latlon(self::f($f, 4), self::f($f, 5));
            if ($lat !== null && $lon !== null) {
                $out['lat'] = $lat;
                $out['lon'] = $lon;
            }
        }
        $sog = self::num($f, 6);
        if ($sog !== null) {
            $out['sog'] = $sog;
        }
        $cog = self::num($f, 7);
        if ($cog !== null) {
            $out['cog'] = $cog;
        }
        if (self::f($f, 8) !== null) {
            $out['date'] = self::f($f, 8);
        }
        return $out;
    }

    private function vtg(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $cog = self::num($f, 0);
        if ($cog !== null) {
            $out['cog'] = $cog;
        }
        $sog = self::num($f, 4); // knots field
        if ($sog !== null) {
            $out['sog'] = $sog;
        }
        return $out ?: null;
    }

    private function gsa(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $fix = self::f($f, 1);
        if ($fix !== null) {
            $out['fix_type'] = (int)$fix; // 1=none 2=2D 3=3D
        }
        foreach (['pdop' => 14, 'hdop' => 15, 'vdop' => 16] as $k => $i) {
            $v = self::num($f, $i);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        return $out ?: null;
    }

    private function gsv(Sentence $s): void
    {
        // $--GSV,total,num,inView, then groups of (prn,elev,azim,snr)
        $f = $s->fields;
        $inView = self::num($f, 2);
        if ($inView !== null) {
            $this->state->updateOwnship(['sats_in_view' => (int)$inView]);
        }
        $sats = [];
        for ($base = 3; $base + 3 < count($f); $base += 4) {
            $prn = self::f($f, $base);
            if ($prn === null) {
                continue;
            }
            $sats[] = [
                'prn' => $prn,
                'elev' => self::num($f, $base + 1),
                'azim' => self::num($f, $base + 2),
                'snr' => self::num($f, $base + 3),
            ];
        }
        if ($sats) {
            // GSV arrives as a burst of messages; store the latest burst's
            // sats keyed by talker. Good enough for the sky plot.
            $this->state->updateMisc('GSVSAT', $s->talker, 'GSV', ['sats' => $sats]);
        }
    }

    private function hdt(Sentence $s): ?array
    {
        $v = self::num($s->fields, 0);
        return $v !== null ? ['hdg' => $v, 'hdg_type' => 'true'] : null;
    }

    private function hdg(Sentence $s): ?array
    {
        $v = self::num($s->fields, 0);
        if ($v === null) {
            return null;
        }
        $var = self::num($s->fields, 3);
        $varDir = self::f($s->fields, 4);
        if ($var !== null) {
            $v += ($varDir === 'W' ? -$var : $var); // to true
        }
        return ['hdg' => round(fmod($v + 360.0, 360.0), 1), 'hdg_type' => 'magnetic+var'];
    }

    private function hdm(Sentence $s): ?array
    {
        $v = self::num($s->fields, 0);
        return $v !== null ? ['hdg_mag' => $v] : null;
    }

    private function rot(Sentence $s): ?array
    {
        $v = self::num($s->fields, 0);
        return $v !== null ? ['rot' => $v] : null;
    }

    private function dbt(Sentence $s): ?array
    {
        $v = self::num($s->fields, 2); // metres field
        return $v !== null ? ['depth_m' => $v] : null;
    }

    private function dpt(Sentence $s): ?array
    {
        $v = self::num($s->fields, 0);
        $offset = self::num($s->fields, 1) ?? 0.0;
        return $v !== null ? ['depth_m' => $v + $offset] : null;
    }

    private function vhw(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $stw = self::num($f, 4); // knots
        if ($stw !== null) {
            $out['stw'] = $stw;
        }
        $hdg = self::num($f, 0); // true heading
        if ($hdg !== null) {
            $out['hdg'] = $hdg;
        }
        $hdgMag = self::num($f, 2); // magnetic heading
        if ($hdgMag !== null) {
            $out['hdg_mag'] = $hdgMag;
        }
        return $out ?: null;
    }

    /**
     * VBW: dual ground/water speed (Doppler logs).
     * $--VBW,longWater,transWater,stWater,longGround,transGround,stGround,
     *        sternWater,stSternWater,sternGround,stSternGround
     * Validity flags are honoured: invalid values are not published, and the
     * valid flags themselves are exposed so the UI can show "no bottom track".
     */
    private function vbw(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $waterOk = self::f($f, 2) === 'A';
        $groundOk = self::f($f, 5) === 'A';
        $out['stw_valid'] = $waterOk;
        $out['sog_ground_valid'] = $groundOk;
        $stw = self::num($f, 0);
        if ($waterOk && $stw !== null) {
            $out['stw'] = $stw;
        }
        $sog = self::num($f, 3);
        if ($groundOk && $sog !== null) {
            $out['sog_ground'] = $sog;
        }
        return $out;
    }

    /** VLW: distance log. $--VLW,total,N,trip,N[,total2,N,trip2,N] */
    private function vlw(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $total = self::num($f, 0);
        if ($total !== null) {
            $out['log_total_nm'] = $total;
        }
        $trip = self::num($f, 2);
        if ($trip !== null) {
            $out['log_trip_nm'] = $trip;
        }
        return $out ?: null;
    }

    /**
     * ALR: alarm state report. $--ALR,time,id,condition,ack,description
     * condition: A=active V=cleared; ack: A=acknowledged V=not acknowledged.
     * Keyed by talker+alarm id; cleared+acknowledged alarms are dropped.
     */
    private function alr(Sentence $s): void
    {
        $f = $s->fields;
        $id = self::f($f, 1) ?? '?';
        $this->state->updateAlarm(
            $s->talker . ':' . $id,
            $s->talker,
            self::f($f, 2) === 'A',
            self::f($f, 3) === 'A',
            (string)(self::f($f, 4) ?? '')
        );
    }

    /** HBT: heartbeat supervision. $--HBT,interval,status,count */
    private function hbt(Sentence $s): void
    {
        $f = $s->fields;
        $fields = [];
        $interval = self::num($f, 0);
        if ($interval !== null) {
            $fields['interval_s'] = $interval;
        }
        if (self::f($f, 1) !== null) {
            $fields['status'] = self::f($f, 1);
        }
        $count = self::num($f, 2);
        if ($count !== null) {
            $fields['count'] = (int)$count;
        }
        if ($fields) {
            $this->state->updateMisc('HBT.' . $s->talker, $s->talker, 'HBT', $fields);
        }
    }
        $dist = self::num($f, 2);
        if ($dist === null) {
            return null;
        }
        $dir = self::f($f, 3);
        return ['xte_nm' => ($dir === 'L' ? -1 : 1) * $dist];
    }

    private function apb(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $xte = self::num($f, 2);
        $xteDir = self::f($f, 3);
        if ($xte !== null) {
            $out['xte_nm'] = ($xteDir === 'L' ? -1 : 1) * $xte;
        }
        $btw = self::num($f, 10); // bearing present->dest, true (v2.x) or mag
        if ($btw !== null) {
            $out['btw_deg'] = $btw;
        }
        if (self::f($f, 9) !== null) {
            $out['waypoint'] = self::f($f, 9);
        }
        return $out ?: null;
    }

    private function bwc(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $btw = self::num($f, 5);
        if ($btw !== null) {
            $out['btw_deg'] = $btw;
        }
        $dtw = self::num($f, 9);
        if ($dtw !== null) {
            $out['dtw_nm'] = $dtw;
        }
        if (self::f($f, 11) !== null) {
            $out['waypoint'] = self::f($f, 11);
        }
        return $out ?: null;
    }

    private function rmb(Sentence $s): ?array
    {
        $f = $s->fields;
        $out = [];
        $xte = self::num($f, 1);
        $xteDir = self::f($f, 2);
        if ($xte !== null) {
            $out['xte_nm'] = ($xteDir === 'L' ? -1 : 1) * $xte;
        }
        $dtw = self::num($f, 9);
        if ($dtw !== null) {
            $out['dtw_nm'] = $dtw;
        }
        if (self::f($f, 4) !== null) {
            $out['waypoint'] = self::f($f, 4);
        }
        return $out ?: null;
    }

    // ---- weather ----

    private function mwv(Sentence $s): ?array
    {
        // $--MWV,angle,R|T,speed,K|M|N,status
        $f = $s->fields;
        $angle = self::num($f, 0);
        $ref = strtoupper((string)self::f($f, 1));
        $speed = self::num($f, 2);
        $unit = strtoupper((string)self::f($f, 3));
        $status = self::f($f, 4);
        if ($angle === null || $speed === null || $status === 'V') {
            return null;
        }
        $kn = self::toKnots($speed, $unit);
        $out = [];
        if ($ref === 'R') {
            $out['awa'] = $angle;
            $out['aws'] = $kn;
        } else {
            $out['twa'] = $angle;
            $out['tws'] = $kn;
        }
        return $out;
    }

    private function mwd(Sentence $s): ?array
    {
        // $--MWD,dirT,T,dirM,M,speedN,N,speedM,M  (speed in knots then m/s)
        $f = $s->fields;
        $out = [];
        $dir = self::num($f, 0);
        if ($dir !== null) {
            $out['twd'] = $dir;
        }
        $kn = self::num($f, 4);
        if ($kn !== null) {
            $out['tws'] = $kn;
        }
        return $out ?: null;
    }

    private function vwr(Sentence $s): ?array
    {
        // $--VWR,angle,L|R,speedN,N,speedM,M,speedK,K
        $f = $s->fields;
        $angle = self::num($f, 0);
        $side = self::f($f, 1);
        $kn = self::num($f, 2);
        if ($angle === null || $kn === null) {
            return null;
        }
        return ['awa' => $side === 'L' ? -$angle : $angle, 'aws' => $kn];
    }

    private function vwt(Sentence $s): ?array
    {
        $f = $s->fields;
        $angle = self::num($f, 0);
        $side = self::f($f, 1);
        $kn = self::num($f, 2);
        if ($angle === null || $kn === null) {
            return null;
        }
        return ['twa' => $side === 'L' ? -$angle : $angle, 'tws' => $kn];
    }

    private function mda(Sentence $s): ?array
    {
        // $--MDA,baroI,I,baroB,B,airT,C,...,dewC,...,windT,T,windM,M,windKn,N,windMs,M
        $f = $s->fields;
        $out = [];
        $baroIn = self::num($f, 0);
        $baroBar = self::num($f, 2); // field 2 is BARS (the 'B' unit literal follows)
        if ($baroBar !== null) {
            $out['pressure'] = round($baroBar * 1000, 1); // bar -> mbar
        } elseif ($baroIn !== null) {
            $out['pressure'] = round($baroIn * 33.8639, 1);
        }
        $airT = self::num($f, 4);
        if ($airT !== null) {
            $out['air_temp'] = $airT;
        }
        $hum = self::num($f, 8);
        if ($hum !== null) {
            $out['humidity'] = $hum;
        }
        return $out ?: null;
    }

    private function mtw(Sentence $s): ?array
    {
        $v = self::num($s->fields, 0);
        return $v !== null ? ['water_temp' => $v] : null;
    }

    /** XDR: groups of (type, value, unit, name). Common: C=temp, V=volts, P=pressure, H=humidity, R=rate(level %) */
    private function xdr(Sentence $s): void
    {
        $f = $s->fields;
        $weather = [];
        $misc = [];
        for ($i = 0; $i + 3 < count($f); $i += 4) {
            $type = strtoupper((string)($f[$i] ?? ''));
            $val = is_numeric($f[$i + 1] ?? '') ? (float)$f[$i + 1] : null;
            $unit = (string)($f[$i + 2] ?? '');
            $name = (string)($f[$i + 3] ?? "x$i");
            if ($val === null) {
                continue;
            }
            switch ($type) {
                case 'C':
                    if (stripos($name, 'air') !== false || $name === 'TEMP') {
                        $weather['air_temp'] = $val;
                    } else {
                        $misc[$name] = $val;
                    }
                    break;
                case 'P':
                    // unit B = bar -> mbar
                    $weather['pressure'] = round(strtoupper($unit) === 'B' ? $val * 1000 : $val, 1);
                    break;
                case 'H':
                    $weather['humidity'] = $val;
                    break;
                case 'V':
                    $misc[$name !== '' ? $name : 'volts'] = $val;
                    break;
                case 'R':
                    $misc[$name !== '' ? $name : 'rate'] = $val;
                    break;
                default:
                    $misc[$name !== '' ? $name : $type] = $val;
                    break;
            }
        }
        if ($weather) {
            $this->state->updateWeather($weather);
        }
        if ($misc) {
            $this->state->updateMisc('XDR', $s->talker, 'XDR', $misc);
        }
    }

    /** RPM: $--RPM,S|C|... source,engine#,rpm,pitch%,status */
    private function rpm(Sentence $s): void
    {
        $f = $s->fields;
        $rpm = self::num($f, 2);
        $src = self::f($f, 1) ?? 'engine';
        if ($rpm !== null) {
            $key = substr(preg_replace('/[^A-Za-z0-9]/', '', $src), 0, 8);
            if ($key === '') {
                $key = '0';
            }
            $this->state->updateMisc('RPM' . $key, $s->talker, 'RPM', ['rpm' => $rpm, 'source' => $src]);
        }
    }

    // ---- AIS ----

    private function aisFeed(Sentence $s): void
    {
        $msg = $this->ais->feed($s);
        if ($msg === null) {
            return; // incomplete fragment or undecodable
        }
        $mmsi = $msg['mmsi'];
        unset($msg['mmsi']);
        $this->state->updateAis($mmsi, $msg);
    }

    private static function toKnots(float $v, string $unit): float
    {
        return match ($unit) {
            'M' => $v * 1.94384,
            'K' => $v / 1.852,
            default => $v, // N = knots
        };
    }
}
