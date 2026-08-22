<?php
declare(strict_types=1);

/**
 * dashboard.php: Position & Nav. STATE snapshot + SSE state deltas.
 * Gauges: SOG, STW, depth dials; HDG/COG compasses; waypoint panel;
 * satellite sky plot from misc GSV keys.
 */

require __DIR__ . '/lib/common.php';
requireLogin();
page_header('Dashboard', 'dashboard');
?>
<h1>Dashboard</h1>

<div class="fixbar red" id="fixbar">NO FIX</div>

<div class="grid cols-2">
  <div class="card" data-live="ownship">
    <h2>Position</h2>
    <div class="posread" id="pos-lat">--</div>
    <div class="posread" id="pos-lon">--</div>
    <div class="sub mono" id="pos-meta">--</div>
  </div>
  <div class="card" data-live="ownship">
    <h2>Waypoint</h2>
    <div class="big" id="wp-name" style="font-size:1.8rem">--</div>
    <div class="nums mono" style="display:flex;gap:1.6rem;margin-top:0.4rem">
      <div><div class="big" style="font-size:1.6rem" id="wp-dtw">--</div><div class="sub">DTW nm</div></div>
      <div><div class="big" style="font-size:1.6rem" id="wp-btw">--</div><div class="sub">BTW &deg;</div></div>
      <div><div class="big" style="font-size:1.6rem" id="wp-xte">--</div><div class="sub">XTE nm</div></div>
      <div><div class="big" style="font-size:2.2rem" id="wp-steer">&#8226;</div><div class="sub">steer</div></div>
    </div>
  </div>
</div>

<div class="grid cols-4 mt">
  <div class="card gauge-wrap" data-live="ownship">
    <canvas id="g-sog" style="height:180px"></canvas><div class="glabel">SOG</div>
  </div>
  <div class="card gauge-wrap" data-live="ownship">
    <canvas id="g-stw" style="height:180px"></canvas><div class="glabel">STW</div>
  </div>
  <div class="card gauge-wrap" data-live="ownship">
    <canvas id="g-hdg" style="height:180px"></canvas><div class="glabel">HDG (bug = COG)</div>
  </div>
  <div class="card gauge-wrap" data-live="ownship">
    <canvas id="g-depth" style="height:180px"></canvas><div class="glabel">Depth</div>
  </div>
</div>

<div class="grid cols-2 mt">
  <div class="card" data-live="misc">
    <h2>Satellites</h2>
    <canvas id="g-sky" style="height:340px"></canvas>
    <div class="sub mono" id="sky-meta">--</div>
  </div>
  <div class="card" data-live="ownship">
    <h2>Course over ground</h2>
    <canvas id="g-cog" style="height:260px"></canvas>
    <div class="sub mono" id="own-extra">--</div>
  </div>
</div>

<script src="assets/gauges.js"></script>
<script>
(function () {
  'use strict';

  var state = { ownship: {}, ais: {}, weather: { latest: {}, wind_history: [], pressure_history: [] }, misc: {}, sentences: {} };
  var lastDelta = {}; // section -> local ms timestamp

  // ---------------- merge daemon deltas into the snapshot ----------------
  function mergeDelta(d) {
    Object.keys(d || {}).forEach(function (section) {
      var val = d[section];
      lastDelta[section] = Date.now();
      if (section === 'ais' || section === 'misc') {
        var dst = state[section] || (state[section] = {});
        Object.keys(val || {}).forEach(function (k) {
          if (val[k] === null) { delete dst[k]; return; }
          dst[k] = Object.assign(dst[k] || {}, val[k]);
        });
      } else if (section === 'weather') {
        var latest = state.weather.latest;
        Object.keys(val || {}).forEach(function (k) {
          latest[k] = { v: val[k], ts: Date.now() / 1000 };
        });
      } else if (typeof val === 'object' && val !== null) {
        state[section] = Object.assign(state[section] || {}, val);
      }
    });
  }

  // ---------------- formatting ----------------
  function fmtLatLon(v, isLat) {
    if (v === null || v === undefined || isNaN(v)) return '--';
    var hemi = isLat ? (v >= 0 ? 'N' : 'S') : (v >= 0 ? 'E' : 'W');
    var a = Math.abs(v);
    var deg = Math.floor(a);
    var min = (a - deg) * 60;
    return (isLat ? String(deg).padStart(2, '0') : String(deg).padStart(3, '0')) +
           '\u00B0 ' + min.toFixed(3).padStart(6, '0') + '\' ' + hemi;
  }
  function fnum(v, dec) {
    return (v === null || v === undefined || isNaN(v)) ? '--' : Number(v).toFixed(dec);
  }

  // ---------------- instruments ----------------
  var sog = Gauges.dial(document.getElementById('g-sog'), { min: 0, max: 15, unit: 'kn', decimals: 1, arc: Gauges.GREEN });
  var stw = Gauges.dial(document.getElementById('g-stw'), { min: 0, max: 15, unit: 'kn', decimals: 1, arc: Gauges.GREEN });
  var depth = Gauges.dial(document.getElementById('g-depth'), { min: 0, max: 50, unit: 'm', decimals: 1, arc: Gauges.RED });
  var hdg = Gauges.compass(document.getElementById('g-hdg'), { unit: 'deg' });
  var cog = Gauges.compass(document.getElementById('g-cog'), { unit: 'deg' });

  // ---------------- fix banner ----------------
  function renderFix(o) {
    var el = document.getElementById('fixbar');
    var q = o.fix_quality, active = o.fix_active, type = o.fix_type, hdop = o.hdop;
    var cls = 'red', txt = 'NO FIX';
    if (q !== undefined && q !== null && q > 0 && active !== false) {
      cls = 'green'; txt = 'FIX';
      if (type !== undefined && String(type).indexOf('3') !== 0) { cls = 'amber'; txt = 'FIX (' + type + ')'; }
      if (hdop !== undefined && hdop !== null && hdop > 5) { cls = 'amber'; txt += ' - HDOP ' + fnum(hdop, 1); }
    }
    el.className = 'fixbar ' + cls;
    el.textContent = txt;
  }

  // ---------------- satellite sky plot ----------------
  function drawSky() {
    var canvas = document.getElementById('g-sky');
    var g = Gauges.fit(canvas);
    if (!g) return;
    var ctx = g.ctx, w = g.w, h = g.h;
    var cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 14;

    ctx.strokeStyle = Gauges.FAINT;
    ctx.lineWidth = 1;
    [1, 2 / 3, 1 / 3].forEach(function (f) {
      ctx.beginPath(); ctx.arc(cx, cy, r * f, 0, Math.PI * 2); ctx.stroke();
    });
    ctx.beginPath(); ctx.moveTo(cx - r, cy); ctx.lineTo(cx + r, cy); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx, cy - r); ctx.lineTo(cx, cy + r); ctx.stroke();
    ctx.fillStyle = Gauges.DIM;
    ctx.font = '11px ' + Gauges.MONO;
    ctx.textAlign = 'center';
    ctx.fillText('N', cx, cy - r - 3);
    ctx.fillText('S', cx, cy + r + 11);
    ctx.fillText('E', cx + r + 8, cy + 4);
    ctx.fillText('W', cx - r - 8, cy + 4);

    // gather sats from every misc GSV key
    var sats = [];
    Object.keys(state.misc || {}).forEach(function (k) {
      if (k.indexOf('GSV') < 0) return;
      var f = state.misc[k] && state.misc[k].fields;
      if (f && Array.isArray(f.sats)) sats = sats.concat(f.sats);
    });
    var n = 0;
    sats.forEach(function (s) {
      var elev = Number(s.elev), azim = Number(s.azim);
      if (isNaN(elev) || isNaN(azim)) return;
      n++;
      var rr = r * (1 - elev / 90);
      var a = (azim - 90) * Math.PI / 180;
      var x = cx + rr * Math.cos(a), y = cy + rr * Math.sin(a);
      var snr = Number(s.snr) || 0;
      var col = snr >= 35 ? Gauges.GREEN : (snr >= 20 ? Gauges.AMBER : (snr > 0 ? Gauges.RED : Gauges.FAINT));
      ctx.fillStyle = col;
      ctx.beginPath();
      ctx.arc(x, y, 3 + Math.min(5, snr / 10), 0, Math.PI * 2);
      ctx.fill();
      ctx.fillStyle = Gauges.FG;
      ctx.font = '9px ' + Gauges.MONO;
      ctx.fillText(String(s.prn || ''), x, y - 8);
    });
    var o = state.ownship;
    document.getElementById('sky-meta').textContent =
      'plotted ' + n + ' | used ' + (o.sats !== undefined ? o.sats : '--') +
      ' | in view ' + (o.sats_in_view !== undefined ? o.sats_in_view : '--') +
      ' | HDOP ' + fnum(o.hdop, 1) + ' | PDOP ' + fnum(o.pdop, 1);
  }

  // ---------------- render ----------------
  function render() {
    var o = state.ownship || {};
    renderFix(o);
    document.getElementById('pos-lat').textContent = fmtLatLon(o.lat, true);
    document.getElementById('pos-lon').textContent = fmtLatLon(o.lon, false);
    document.getElementById('pos-meta').textContent =
      (o.utc ? 'UTC ' + o.utc + ' ' : '') + (o.date || '') +
      (o.altitude_m !== undefined ? '  alt ' + fnum(o.altitude_m, 1) + ' m' : '') +
      (o.rot !== undefined ? '  ROT ' + fnum(o.rot, 1) + '\u00B0/min' : '');
    sog(o.sog === undefined ? null : o.sog);
    stw(o.stw === undefined ? null : o.stw);
    depth(o.depth_m === undefined ? null : o.depth_m);
    hdg({ deg: o.hdg === undefined ? null : o.hdg, bug: o.cog === undefined ? null : o.cog });
    cog({ deg: o.cog === undefined ? null : o.cog, bug: null });
    document.getElementById('own-extra').textContent =
      'COG ' + fnum(o.cog, 0) + '\u00B0  SOG ' + fnum(o.sog, 1) + ' kn';

    document.getElementById('wp-name').textContent = o.waypoint || 'no active waypoint';
    document.getElementById('wp-dtw').textContent = fnum(o.dtw_nm, 2);
    document.getElementById('wp-btw').textContent = fnum(o.btw_deg, 0);
    document.getElementById('wp-xte').textContent = fnum(o.xte_nm, 3);
    var steer = document.getElementById('wp-steer');
    if (o.xte_nm === undefined || o.xte_nm === null) {
      steer.textContent = '\u2022'; steer.style.color = '';
    } else if (o.xte_nm > 0.01) {
      steer.textContent = '\u2192'; steer.style.color = Gauges.AMBER; // steer starboard
    } else if (o.xte_nm < -0.01) {
      steer.textContent = '\u2190'; steer.style.color = Gauges.AMBER; // steer port
    } else {
      steer.textContent = '\u2191'; steer.style.color = Gauges.GREEN; // on track
    }

    drawSky();
  }

  // ---------------- stale dimming ----------------
  setInterval(function () {
    document.querySelectorAll('[data-live]').forEach(function (el) {
      var section = el.getAttribute('data-live');
      var t = lastDelta[section];
      el.classList.toggle('stale', t !== undefined && (Date.now() - t) > 10000);
    });
  }, 1000);

  // ---------------- data ----------------
  async function boot() {
    try {
      var r = await fetch('api.php?action=state&section=all');
      var j = await r.json();
      if (j.ok && j.state) {
        Object.keys(j.state).forEach(function (s) {
          if (j.state[s] !== null && j.state[s] !== undefined) state[s] = j.state[s];
          lastDelta[s] = Date.now();
        });
      }
    } catch (e) { /* daemon down: banner covers it */ }
    render();

    var es = new EventSource('sse.php?stream=state');
    es.onmessage = function (ev) {
      var msg;
      try { msg = JSON.parse(ev.data); } catch (e) { return; }
      if (msg && msg.d) { mergeDelta(msg.d); render(); }
    };
    es.onerror = function () { /* EventSource auto-reconnects */ };
  }

  boot();
})();
</script>
<?php
page_footer();
