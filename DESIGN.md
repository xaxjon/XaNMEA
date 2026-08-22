# XaNMEA — Design Document

**Status:** v0.3 design + v0.1 code drop (untested on target — see HANDOVER.md)
**Project name:** XaNMEA

**Decided:** unprivileged daemon (`xanmea` user + `dialout` group) · hand-rolled `stream_select` event loop, no Composer deps in daemon · plain PHP UI, no framework, fetch/SSE for live data · Linux-only daemon (Debian/Ubuntu/Raspberry Pi OS) · SQLite config store with JSON export/import · in-daemon sentence decoding with a live state store · dashboard visualisation in vanilla JS + canvas/SVG, Leaflet vendored for the map

## 1. Purpose

A web-managed NMEA-0183 multiplexer, inspired by [kplex](https://github.com/stripydog/kplex), but:

- Written in **PHP**
- Configured and monitored entirely through a **user-friendly web front end**
- Ships with an **NMEA dashboard** that decodes all detected sentences and presents them live on attractive, purpose-built pages (position/nav, AIS, weather, engine & misc)
- Built around two components:
  - **`xanmead`** — an unprivileged long-running daemon that does the actual data work (routing **and decoding**)
  - **Web UI** — an unprivileged configuration, diagnostics and dashboard application

### Goals

- Aggregate many NMEA-0183 input streams and rebroadcast to many outputs
- Inputs: **Serial**, **TCP client**, **TCP server (accept inbound data)**, **UDP** (unicast / broadcast / multicast listener)
- Outputs: **TCP server** (multiple simultaneous clients per port), **TCP client**, **UDP** (unicast / broadcast / multicast), multiple concurrent outputs
- Per-interface **filtering** (allow / deny / rate-limit) and optional **failover** between sources
- **Decode** all detected sentence types (incl. AIS over VDM/VDO) into a live state store
- Real-time **dashboard pages**: Position & Nav · AIS Targets · Weather · Engine & Misc
- Live **diagnostic screens**: per-interface status, data rates, connected clients, live sentence viewer, event log
- All configuration through the web UI — no hand-edited config files

### Non-goals (v1)

- NMEA-2000 / Signal K translation (engine data arriving via N2K gateways as `$PCDIN` is displayed raw-decoded, not fully translated)
- Acting as a chartplotter or data logger (though a "record to file" output is a cheap later addition)
- Route planning, waypoints management, alarms with audible alerting (visual alarm states only in v1)

## 2. kplex feature baseline

kplex is the reference implementation for the **routing** side. Features we should match, and how:

| kplex feature | XaNMEA plan |
|---|---|
| serial / tcp / udp interfaces | Matched (core requirement) |
| file & pty interfaces | v2 (useful for OpenCPN-style virtual ports and logging) |
| gofree (Navico discovery) | v2 — niche, easy add-on: listen 239.2.1.1:2052, connect to announced TCP service |
| direction=in/out/both | Matched |
| ifilter / ofilter (`+GP***:-all:~GPGGA/5`) | Matched, with a **UI filter builder** instead of raw syntax |
| failover between sources | Matched (v1.1 acceptable) |
| TAG blocks (srctag, timestamp) | Matched — checkbox per output |
| checksum / strict parsing options | Matched — global + per-interface override |
| loopback prevention | Matched — automatic; expose as advanced toggle |
| optional interfaces (don't die if one fails) | Matched — default behaviour; failed interfaces show red in UI and retry |
| TCP persist/retry/keepalive | Matched — sensible defaults, advanced section in UI |
| UDP coalesce (AIS multi-part) | Matched — also feeds the AIS decoder's fragment reassembly |
| Text config file | **Replaced** by web UI + JSON/SQLite config store |

**Beyond kplex:** sentence decoding, live state store, and the four dashboard pages below.

## 3. Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                          Browser                                 │
│  Status │ Interfaces │ Filters │ Diagnostics │ DASHBOARD         │
│           (Position/Nav · AIS · Weather · Engine/Misc)          │
└──────────────────────────────┬──────────────────────────────────┘
                               │ HTTP + SSE
┌──────────────────────────────▼──────────────────────────────────┐
│  Web UI (PHP, unprivileged user e.g. www-data)                   │
│  - CRUD for interfaces, filters, users                           │
│  - Writes config to store                                        │
│  - Bridges control socket → SSE/fetch for live data              │
│  - Renders dashboard pages (data comes decoded from daemon)      │
└───────────────┬───────────────────────────────┬──────────────────┘
                │ writes config                 │ control channel
                │ (SQLite + JSON export)        │ (reload, stats, tail, state)
┌───────────────▼───────────────────────────────▼──────────────────┐
│  xanmead (unprivileged daemon, user `xanmea`)                    │
│  - Event loop, owns all sockets & serial ports                   │
│  - Validates & applies config                                    │
│  - Routes sentences, enforces filters/failover                   │
│  - DECODES sentences → live state store (ownship, AIS,           │
│    weather, misc) incl. AIS fragment reassembly & CPA/TCPA       │
│  - Publishes stats, events, state snapshots & deltas             │
└──────┬──────────┬──────────┬──────────┬──────────┬───────────────┘
     Serial    TCP srv    TCP cli    UDP out    UDP in
   /dev/tty*   :10110    remote     bcast/mcast listener
```

### 3.1 Privilege model — DECIDED

**Unprivileged daemon.** The daemon runs as a dedicated `xanmea` user, added to the `dialout` group for serial device access. All ports used (10110 etc.) are >1024, so no root capability is needed. The installer and systemd unit handle user/group setup. This is kplex's own recommended model and keeps the privilege boundary clean: the UI user (`www-data`) and daemon user are both unprivileged, sharing access only via the group-scoped control socket. Root operation remains possible as an unsupported fallback for awkward systems.

### 3.2 Component communication

- **Config flow (UI → daemon):** UI writes config to SQLite at `/var/lib/xanmea/config.db` (atomic write + version stamp), then sends `RELOAD` over a unix socket `/run/xanmea/control.sock`. Daemon re-reads, **validates the whole config**, and either applies the diff (open new interfaces, close removed ones, retune changed ones) or rejects with per-item errors the UI displays. No half-applied states.
- **Status flow (daemon → UI):** control socket accepts:
  - `STATS` — JSON snapshot: per-interface counters, rates, state, connected TCP clients
  - `TAIL <interface|all>` — stream of live raw sentences for the diagnostic viewer
  - `STATE <section>` — JSON snapshot of the decoded state store (`ownship` | `ais` | `weather` | `misc` | `all`)
  - `STREAM state` — subscription: newline-delimited JSON **deltas** as decoded values change (the dashboard's live feed)
- UI never touches serial/network resources directly; daemon never serves HTTP. Clean privilege boundary.
- The UI's SSE endpoints are thin proxies over `TAIL`/`STREAM state` — all parsing and state lives daemon-side, so dashboard state is continuous across browser reloads and multiple browsers see the same state.

### 3.3 Daemon internals (routing)

- **Single process, event loop** built on a **hand-rolled `stream_select()`** over all sockets/serial FDs, one FD per interface. **No Composer dependencies in the daemon** — it must install as a near-bare PHP CLI script on a Pi. NMEA-0183 line rates (even 10 streams at 38400 baud ≈ 4 KB/s each) are trivially within PHP's reach; no threads, no ReactPHP/Amp.
- **Sentence pipeline per input:**
  1. Read bytes, split on CRLF (tolerate bare LF), reassemble partial lines
  2. Validate: `$...*CS` framing, checksum (if enabled), length cap (82 chars + TAG block)
  3. Parse talker+sentence ID (5 chars), optional TAG block
  4. Apply input filter
  5. Apply failover logic
  6. Stamp provenance (source interface name, arrival time) — kept in-memory, not written to the sentence unless an output requests a TAG block
  7. Enqueue to every output whose output filter passes, **except** the originating interface (loopback prevention)
  8. **Feed the decoder** (§3.4) — decoding never blocks routing; a decode failure is counted and swallowed
- **Per-output queue** (bounded, default ~200 sentences). Slow consumer policy: drop oldest + increment `dropped` counter (surfaced in UI). Never let one stuck TCP client stall the router.
- **Serial handling:** `stty`-style termios configuration via `dio` extension if available, else shell out to `stty` at open time, else fopen + warning. This is the riskiest PHP area — needs early prototyping on the target platform (§9, M0).
- **TCP server output:** listening socket per configured output; each accepted client gets its own queue; client list (IP, connect time, sentences sent) exposed to diagnostics.
- **TCP client (in or out):** connect, auto-reconnect with backoff (`retry` interval), TCP keepalive enabled by default, optional preamble string.
- **UDP:** output = sendto per sentence (or coalesced AIS multi-part); input = bound socket feeding the pipeline. Broadcast/multicast chosen automatically from address, with UI dropdown override.

### 3.4 Decoding layer & live state store

A decoder module inside the daemon, fed from pipeline step 8. Pure PHP, no dependencies. Decoding is **best-effort**: unknown or malformed sentences are counted and passed through untouched (routing is unaffected by decode failures).

**Sentence coverage (v1):**

| Domain | Sentences |
|---|---|
| Position/nav | GGA, GLL, RMC, VTG, GSA, GSV, HDG, HDT, HDM, ROT, DBT, DPT, VHW, XTE, APB, BWC, BWR, RMB |
| AIS | VDM, VDO — message types 1, 2, 3 (Class A position), 5 (Class A static), 18 (Class B position), 24 (Class B static), 19, 21 (AtoN), 27 optional |
| Weather | MWV, MWD, VWR, VWT (wind), MDA (met composite), MTW (water temp), XDR (air temp / pressure / humidity transducers) |
| Engine/misc | RPM, XDR (voltage, temperature, tank), known proprietary (`$PCDIN` shown raw-decoded), plus a generic fallback for anything else |

**AIS decoder specifics** — the chunky part (~600–900 lines):
- 6-bit ASCII armour decoding, bit-field extraction per message type
- Multi-part VDM reassembly (fragments keyed by channel+sequential ID, with timeout) — shared with the UDP coalesce logic
- Lat/lon in 1/10000-minute units → degrees; SOG in 0.1 kn; COG 0.1°
- Class A static (type 5) and Class B static (type 24 part A/B) merged into the target record when they arrive later than position reports

**State store** (in-memory, daemon-owned):

```
ownship   : lat, lon, sog, cog, hdg, rot, fix_quality, sats, depth, stw,
            waypoint {id, btw, dtw, xte}, updated_per_field timestamps
ais       : map[mmsi] → {dynamic: lat,lon,sog,cog,hdg,rot,nav_status,
                         static: name,callsign,ship_type,dims,destination,
                         derived: distance, bearing, cpa, tcpa,
                         first_seen, last_seen, msg_counts}
weather   : latest {aws, awa, tws, twa, water_temp, air_temp, pressure,
                    humidity} + rolling history rings
misc      : latest values keyed by (talker, sentence) → decoded fields
sentences : registry of every sentence type seen: talker, type, count,
            rate, last_seen, decode_status (decoded/unknown/failed)
```

- **AIS ageing:** targets grey at >60 s silent, drop at >10 min (configurable); Class A at high SOG updates fast, Class B statics are slow — thresholds account for class.
- **CPA/TCPA** computed in-daemon for each target against ownship whenever either side updates (flat-earth approximation is fine at AIS ranges).
- **Weather history rings:** wind at 1 s resolution for 10 min; pressure/temp at 1 min for 24 h (barometric trend is the key weather indicator). In-memory only; no persistence in v1.
- **Deltas:** every state mutation emits a compact delta onto `STREAM state` subscribers, e.g. `{"ownship":{"sog":5.2,"cog":143}}` or `{"ais":{"mmsi":"235001234","fields":{...}}}`. UI re-renders the affected gauges only.

### 3.5 Config schema (SQLite / JSON export)

```
interfaces
  id, name, enabled, type(serial|tcp_server|tcp_client|udp),
  direction(in|out|both),
  config_json        -- type-specific: {device,baud} | {port,address} | {address,port,mode}
  options_json       -- {checksum, strict, optional, qsize, srctag, timestamp,
                        persist, retry, keepalive, coalesce, loopback}
  ifilter_json       -- ordered rule list [{op:+|-|~, match:"GP***", src?:name, period?:s}]
  ofilter_json
failovers
  id, match("GP***"), priorities_json [{interface, delay_s}, ...]
settings
  key, value         -- global checksum/strict, log level, ui port,
                        units (kn|kmh|ms, c|f, hPa|inHg), map tile URL,
                        AIS ageing thresholds, etc.
users
  id, username, password_hash, role(admin|viewer)
```

Same schema exported as pretty JSON for backup/restore and version control.

## 4. Web UI design

Single PHP app, **no framework, no build step**: server-rendered pages + `fetch()` polling for stats and **SSE** for streaming views (dashboard deltas, live sentence tail, events). No Node, no frontend toolchain — the app surface is small and must install trivially on a Pi.

**Visualisation toolkit:** vanilla JS + hand-rolled **canvas/SVG gauges** (consistent marine-instrument look, zero dependencies), plus **Leaflet vendored** (single JS+CSS, no build) for the map. Dark instrument-style theme, large legible numerals — designed for a sunlight-readable tablet on a boat.

### 4.1 NMEA dashboard pages (real-time, decoded)

All four pages share: SSE delta feed, stale-data dimming (values grey out when their source sentence hasn't arrived within its expected interval), unit preferences from Settings, and a per-page "show source sentences" expander that jumps to the diagnostic viewer pre-filtered.

**Page 1 — Position & Nav Status**
- Primary instrument row: **lat/lon** (with fix-quality icon and satellite count), **SOG**, **COG**, **heading** (HDT/HDG), **depth**, **speed through water**
- **Moving map** (Leaflet): own vessel marker with COG vector and breadcrumb track; falls back to a plain lat/lon grid plot when no tile source is reachable (offline at sea — see §10)
- **Satellite sky plot** (GSV): polar plot of satellites in view, coloured by constellation, SNR bars — pure canvas, very effective visually
- Nav/waypoint panel (RMB/BWC/APB/XTE): bearing & distance to waypoint, cross-track error bar with steer-to arrow, ETA
- Fix status banner: green "GPS 3D fix", amber "dead reckoning / DGPS", red "no fix"
- ROT gauge and a compass-rose heading dial with COG marker

**Page 2 — AIS Targets**
- **Radar-style relative plot** (canvas): own vessel centred, range rings (0.5/1/2/5 NM selectable), north-up or heading-up toggle; targets drawn as oriented ship triangles, Class A vs B distinguished, colour-shifted to red when CPA below threshold
- **Target table**: MMSI, name, call sign, ship type, distance, bearing, SOG, COG, CPA, TCPA, age — sortable, click a row to centre/highlight the plot target
- **Target detail panel**: full static + dynamic record, message counts, time since last report, raw contributing sentences expander
- CPA/TCPA alarm state (visual only in v1): banner + red outline when any target breaches configured CPA within TCPA window
- Stale targets fade, then drop per §3.4 ageing rules

**Page 3 — Weather**
- **Wind dial**: compass-style gauge with apparent wind angle needle + trailing history arc (last 10 min ghost needles), numeric AWS/AWA and TWS/TWA
- **Barometer**: large pressure readout with 24 h trend sparkline and rise/fall rate arrow (the single most useful weather cue)
- Air temp, water temp (with a small depth-linked panel if wanted), humidity
- Wind speed trend sparkline (1 s samples, 10 min)
- All gauges canvas-drawn, marine-instrument styled; history rings come from the daemon state store so a browser reload keeps trends

**Page 4 — Engine & Misc**
- Engine panel (where data exists): RPM gauge, engine temp, alternator/battery voltage, tank levels (via XDR mappings), `$PCDIN` raw-decoded view for N2K gateways
- **Catch-all "everything else" board**: every other decoded sentence type gets an auto-generated card — title (talker+type), decoded fields as label/value rows, update rate, freshness dimming. New or unusual devices appear here automatically with zero configuration.
- Full sentence registry table (from §3.4 `sentences`): every type seen, decoded/unknown/failed status, rate — doubles as a "what is my boat actually transmitting" discovery tool

### 4.2 Management & diagnostics pages

- **Status (home)** — at-a-glance: per-interface card showing name, type, direction, state (● green/amber/red), sentences/sec in & out, bytes, error count, connected clients for TCP servers. Global totals. "System running since …".
- **Inputs / Outputs** — list + add/edit forms. Forms are type-aware: choosing "Serial" shows device dropdown (populated by daemon scanning `/dev/ttyUSB*`, `/dev/ttyAMA*`) and baud selector; "TCP server" shows port + bind address; "UDP" shows address/port/mode with a one-line explanation of unicast vs broadcast vs multicast. Every field has inline help text. Validation both client- and daemon-side.
- **Filters** — visual rule builder per interface: ordered rows of [allow|deny|limit] [talker dropdown or custom] [sentence dropdown] [every N seconds]. Live preview of the resulting kplex-style filter string for the curious. Sentence/talker lists auto-populated from sentences actually seen.
- **Failover** — pick a sentence group (e.g. `GP***`), then drag-order interfaces with per-step timeout.
- **Diagnostics**
  - **Live viewer** — scrolling sentence feed (all or per-interface), colour-coded by source, with pause, and toggle to show raw vs annotated (parsed talker/sentence/checksum ok, decoded field preview).
  - **Clients** — who's connected to each TCP server output (IP, duration, rate) with a kick button.
  - **Events** — daemon log tail: interface up/down, reconnects, config reloads, validation failures.
  - **Stats** — counters and sparkline-ish rates per interface; a "last sentence seen" column is the single most useful real-world diagnostic.
- **Settings** — global options, units, map tile source, AIS thresholds, backup/restore (JSON download/upload), user management, daemon restart/apply.

### 4.3 UX principles

- A first-time user should get *serial in → TCP out* running in under 2 minutes: wizard-like "Add your first input/output" on an empty install.
- Sensible defaults everywhere (port 10110, checksum on, persist on for clients); advanced options collapsed behind "Advanced".
- Every diagnostic answerable from the UI: *is data arriving? is it valid? where is it going? who's receiving it?*
- Dashboard pages must remain useful with **no internet connection** (boat at sea): everything vendored/local, map degrades gracefully to grid plot.

## 5. Security

- Web UI auth required (session login; default admin created at install, forced password change). Role `viewer` for read-only diagnostics **and dashboard pages**.
- UI binds to localhost or LAN per setting; HTTPS termination left to reverse proxy or built-in later.
- Control socket: unix socket, file permissions restrict to `xanmea` group (UI user is a member). No TCP control channel in v1.
- Config validation in the daemon, never trusting the UI: device paths whitelisted to plausible serial devices, ports 1–65535, addresses parsed, filter strings sanitised.
- Passwords hashed (PHP `password_hash`). No credentials stored in config for NMEA interfaces (none needed).

## 6. Deployment

- Target: **Linux only** (Debian/Ubuntu/Raspberry Pi OS primary). PHP ≥ 8.1 CLI for the daemon, PHP-FPM or `php -S`-style for the UI behind nginx/apache. Serial code targets Linux termios/`stty` only; macOS may work for casual dev but is not supported.
- Install: package-ish script creating user `xanmea`, dirs (`/etc/xanmea`, `/var/lib/xanmea`, `/run/xanmea`), systemd units:
  - `xanmead.service` — the daemon (restart=always)
  - web UI via nginx/php-fpm, or `xanmea-ui.service` using PHP built-in server for zero-dependency installs
- Updates: replace files, `systemctl reload-or-restart` — config persists in SQLite.

## 7. Diagnostics & observability (daemon side)

- Per-interface counters: sentences in/out, bytes, checksum errors, parse errors, queue drops, reconnects; rates computed over 1s/10s/60s windows.
- Per-sentence-type registry (§3.4): decode coverage stats feed the "unknown sentence" reports.
- Ring-buffer event log (last ~500 events) served to UI.
- Optional syslog.
- `TAIL` streams: newline-delimited JSON `{ts, src, dst[], raw, valid}` — the UI live viewer is a thin renderer over this.
- `STREAM state` deltas as per §3.4.

## 8. Failure behaviour

- One interface failing never kills the daemon (kplex's `optional` as the *default*). Interface enters retry loop with backoff; UI shows amber/red with last error.
- Decode failures never affect routing; repeated failures for a sentence type are surfaced in the registry as `failed`.
- Config reload validates fully before applying; bad config → keep running on old config, report errors.
- Daemon crash → systemd restarts; UI shows "daemon unreachable" state gracefully instead of fatal errors. State store is rebuilt from the live stream within seconds (AIS static data takes up to 6 min to fully repopulate — acceptable).

## 9. Milestones

- **M0 — Feasibility spike:** PHP serial port read/write at 4800 & 38400 baud on Linux (termios path), `stream_select` loop with 3+ FDs, unix-socket control channel. *Go/no-go for pure-PHP daemon.*
- **M1 — Core router:** serial + TCP server + UDP out, hardcoded JSON config, no UI. Proven with real NMEA feed (or simulator) to a TCP client.
- **M2 — Config engine:** SQLite schema, validation, control-socket reload, JSON import/export.
- **M3 — Web UI v1:** auth, status page, interface CRUD, live stats.
- **M4 — Diagnostics:** live sentence viewer, client list, event log.
- **M5 — Decoding layer I:** core NMEA decoder (position/nav/weather/misc table §3.4), state store, `STATE`/`STREAM state` socket commands, sentence registry.
- **M6 — Dashboard pages 1, 3, 4:** Position/Nav (incl. satellite plot + map), Weather (wind dial + barometer trend), Engine/Misc (auto-cards + registry). Canvas gauge library built here.
- **M7 — Decoding layer II + AIS page:** VDM/VDO decoder with fragment reassembly, target store with ageing, CPA/TCPA, radar plot + target table.
- **M8 — Filters & failover UI.**
- **M9 — Packaging:** installer, systemd, backup/restore, docs.
- Later: pty/file interfaces, GoFree, recording/replay, audible alarms, Signal K bridge.

(AIS deliberately sequenced last among features: it's the most complex decoder and the pages 1/3/4 deliver visible value sooner.)

## 10. Open questions

*Settled:* project name (XaNMEA) · privilege model (unprivileged + `dialout`) · event loop (hand-rolled `stream_select`, no deps) · UI stack (plain PHP + fetch/SSE) · platforms (Linux only) · config store (SQLite + JSON export/import) · decoding lives in the daemon with a state store · gauges hand-rolled canvas/SVG, Leaflet vendored for maps.

Remaining:

1. **Serial configuration mechanism** — `dio` pecl ext vs `stty` shell-out vs raw `fopen`. Depends on M0 spike results; `stty` shell-out is the portable fallback.
2. **Map tiles strategy** — configurable online tile URL plus optional pre-downloaded offline tile cache (directory or mbtiles)? Offline matters at sea; grid-plot fallback is the baseline. Decide scope before M6.
3. **NMEA 2000 / Signal K** — explicitly out of scope; the pipeline is sentence-agnostic strings, so a future bridge costs nothing to add later.
