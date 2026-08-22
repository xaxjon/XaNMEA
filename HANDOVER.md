# XaNMEA — Handover / Test Plan

Written: initial v0.1 code drop. **Nothing in this repo has been executed yet**
(no PHP runtime on the authoring machine). Everything below is the shake-out
plan for the Linux sandbox.

## 1. Environment

Debian/Ubuntu/Raspberry Pi OS, PHP ≥ 8.1 CLI with `pcntl` + `sockets`
(default builds have both):

```sh
apt install php-cli php-sqlite3   # sqlite only needed later; cli is enough now
php -m | grep -E 'pcntl|sockets'  # confirm
# first thing: lint everything (nothing in this repo has been executed)
find . -name '*.php' -o -name 'xanmead' | xargs -n1 php -l
```

## 2. Smoke test WITHOUT install (dev mode)

```sh
cd xanmea
cp config/config.example.json config/config.json
# edit config.json: point control_socket + heartbeat_file at writable paths:
#   "control_socket": "/tmp/xanmea/control.sock",
#   "heartbeat_file": "/tmp/xanmea/heartbeat.json"
php tools/simulator.php 4010 &          # synthetic feed
php bin/xanmead -c config/config.json -d   # daemon, debug logging
```

Expected: simulator logs "listening", daemon logs "connected to 127.0.0.1:4010"
and "tcp server listening on 0.0.0.0:10110".

## 3. Verify routing

```sh
nc 127.0.0.1 10110        # should stream $GPRMC/GGA/HDT/DBT/MWV/!AIVDM...
nc 127.0.0.1 10110        # second client simultaneously — both get data
```

## 4. Verify control socket

```sh
printf 'PING\n'    | nc -U /tmp/xanmea/control.sock
printf 'STATS\n'   | nc -U /tmp/xanmea/control.sock | python3 -m json.tool
printf 'STATE ais\n' | nc -U /tmp/xanmea/control.sock | python3 -m json.tool
printf 'TAIL all\n'  | nc -U /tmp/xanmea/control.sock     # live stream, Ctrl-C to stop
printf 'STREAM\n'    | nc -U /tmp/xanmea/control.sock     # decoded deltas
printf 'RELOAD\n'    | nc -U /tmp/xanmea/control.sock
```

Within ~30 s `STATE ais` should show 3 targets with names (type 5 arrives
two-part, type 24A single); `STATE ownship` should show moving lat/lon/sog/cog.

## 5. Verify watchdog layers

```sh
cat /tmp/xanmea/heartbeat.json     # ts must refresh every ~5 s
kill -STOP <xanmead pid>           # freeze it; heartbeat goes stale
#   systemd (installed mode) restarts it after WatchdogSec=15
kill -CONT <pid>; kill -TERM <pid> # clean shutdown, socket file removed
```

## 6. Verify reliability properties

- Kill the simulator → `sim` interface enters `retry`, daemon keeps serving
  existing TCP clients; restart simulator → reconnects within `retry` seconds.
- `nc 127.0.0.1 10110` then throttle reading (Ctrl-Z the nc) → daemon stays
  responsive; `dropped` counter climbs for that client; router unaffected.
- Garbage into an input (`echo "junk" | nc <sim port>` style) → `parse_err`
  climbs, nothing else happens.
- Corrupt config.json → `RELOAD` → daemon logs "reload rejected, keeping
  current config" and keeps routing.

## 7. Web UI

```sh
# quickest: PHP built-in server
XANMEA_CONFIG=/path/to/config.json php -S 0.0.0.0:8080 -t ui ui/router.php
# or Apache/nginx: docroot = ui/, PHP-FPM, user in group xanmea (control socket)
```

First visit → create admin (config.json `users[]` placeholder is replaced).
Then check: Status cards live-update; Interfaces add/edit/delete + RELOAD;
Diagnostics live tail / clients / events; the four dashboard pages animate.

## 8. Serial test (needs hardware)

Plug a USB-serial adapter with an NMEA feed (or a null-modem pair), add via UI:
device `/dev/ttyUSB0`, baud 4800. The user `xanmea` must be in `dialout`
(installer does this). `min 0 time 5` stty settings are used; if your distro's
stty rejects a flag, that's the place to look (`src/Io/SerialIface.php`).

## Known limitations / TODO (v0.1)

- **Not yet tested anywhere** — first run on Linux may surface syntax nits.
- Position page uses a grid fallback only — no Leaflet map yet (offline-first).
- Serial path untested (needs real hardware).
- Failover config exists in daemon; failover UI is minimal/absent in v1 UI.
- pty/file interfaces, GoFree, recording: not implemented (v2 per DESIGN.md).
- `socket_export_stream` + `socket_sendto` pairing in UdpIface is the least
  certain piece; if UDP input misbehaves, check there first.
- AIS decoder covers msg types 1/2/3/5/18/19/21/24/27; others register as
  `undecoded`.
