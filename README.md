# XaNMEA

A web-managed NMEA-0183 multiplexer in PHP — kplex-style routing with a friendly
web front end and a live decoded dashboard (Position/Nav, AIS targets, Weather,
Engine/Misc).

- **`xanmead`** — unprivileged daemon: serial/TCP/UDP in, TCP/UDP out, filtering,
  failover, TAG blocks, decoding, live state store. Single process, `stream_select`
  loop, zero Composer dependencies.
- **Web UI** — plain PHP (no framework, no build step, works offline): config CRUD,
  diagnostics, and four real-time dashboard pages.

See `DESIGN.md` for the full design and `HANDOVER.md` for build/test instructions.

## Quick start (Linux)

```sh
sudo ./install.sh
sudo systemctl start xanmead
php tools/simulator.php 4010 &     # synthetic NMEA feed for testing
```

Point a browser at the UI host; create the admin user on first visit. Connect
OpenCPN/iNavX to TCP port 10110.

## Layout

- `bin/xanmead` — daemon entry point
- `src/` — daemon (Io, Router, Filter, Control, Decode, StateStore, Watchdog)
- `ui/` — web UI
- `config/config.example.json` — annotated example
- `tools/simulator.php` — synthetic NMEA + AIS feed
- `systemd/xanmead.service` — unit with WatchdogSec
- `install.sh` — Debian/Ubuntu/Raspberry Pi OS installer

## Reliability notes

Routing is never blocked by decoding, diagnostics or the dashboard: decode runs
behind a try/catch hook, stream subscribers have bounded queues (slow consumers
are dropped), and every interface callback is fault-isolated with automatic
retry. The daemon heartbeat (`/run/xanmea/heartbeat.json`) plus systemd
`WatchdogSec`/`Restart=always` provide layered watchdog protection.
