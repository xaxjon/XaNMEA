<?php
declare(strict_types=1);

/**
 * weather.php: wind dial (AWA needle + ghost trail), barometer with 24h
 * pressure sparkline + 3h trend arrow, temperature/humidity cards, wind
 * speed sparkline. STATE snapshot + SSE deltas.
 */

require __DIR__ . '/lib/common.php';
requireLogin();
page_header('Weather', 'weather');
?>
<h1>Weather</h1>

<div class="grid cols-2">
  <div class="card" data-live="weather">
    <h2>Wind</h2>
    <div class="flex">
      <canvas id="winddial" style="height:300px;flex:1;min-width:280px"></canvas>
      <div style="min-width:180px">
        <div class="nums mono">
          <div class="mt"><div class="big" id="w-aws" style="font-size:2.2rem">--</div><div class="sub">AWS kn (apparent)</div></div>
          <div class="mt"><div class="big" id="w-awa" style="font-size:2.2rem">--</div><div class="sub">AWA &deg;</div></div>
          <div class="mt"><div class="big" id="w-tws" style="font-size:1.6rem">--</div><div class="sub">TWS kn</div></div>
          <div><div class="big" id="w-twa" style="font-size:1.6rem">--</div><div class="sub">TWA &deg;</div></div>
          <div><div class="big" id="w-twd" style="font-size:1.6rem">--</div><div class="sub">TWD &deg;</div></div>
        </div>
      </div>
    </div>
  </div>

  <div>
    <div class="card" data-live="weather">
      <h2>Barometer</h2>
      <div class="flex" style="align-items:baseline">
        <div class="big" id="w-baro">--</div>
        <div class="big" id="w-trend" style="font-size:1.8rem">&#8226;</div>
      </div>
      <div class="sub">mbar &middot; last 24 h</div>
      <canvas id="baro" style="height:90px"></canvas>
    </div>
    <div class="card mt" data-live="weather">
      <h2>Wind speed history (10 min)</h2>
      <canvas id="windhist" style="height:90px"></canvas>
    </div>
    <div class="grid cols-3 mt">
      <div class="card" data-live="weather"><h2>Air temp</h2><div class="big" id="w-air">--</div></div>
      <div class="card" data-live="weather"><h2>Water temp</h2><div class="big" id="w-water">--</div></div>
      <div class="card" data-live="weather"><h2>Humidity</h2><div class="big" id="w-hum">--</div></div>
    </div>
  </div>
</div>

<script src="assets/gauges.js"></script>
<script>
(function () {
  'use strict';

  var state = { weather: { latest: {}, wind_history: [], pressure_history: [] } };
  var lastWx = 0; // local ms of last weather delta

  function lv(k) {
    var e = state.weather.latest[k];
    if (!e) return null;
    if (typeof e === 'object' && e !== null && 'v' in e) return e.v;
    return e;
  }
  function fnum(v, dec) { return (v === null || v === undefined || isNaN(v)) ? '--' : Number(v).toFixed(dec); }
  function setText(id, txt) { document.getElementById(id).textContent = txt; }

  var baroSpark = Gauges.sparkline(document.getElementById('baro'), { color: Gauges.CYAN || '#3fc6d8' });
  var windSpark = Gauges.sparkline(document.getElementById('windhist'), { color: Gauges.GREEN });

  // ---------------- wind dial ----------------
  function drawWind() {
    var canvas = document.getElementById('winddial');
    var g = Gauges.fit(canvas);
    if (!g) return;
    var ctx = g.ctx, w = g.w, h = g.h;
    var cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 12;
    var MONO = Gauges.MONO;

    // rose
    ctx.strokeStyle = Gauges.FAINT;
    ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.stroke();
    for (var d = 0; d < 360; d += 15) {
      var a = (d - 90) * Math.PI / 180;
      var mj = d % 45 === 0;
      ctx.strokeStyle = mj ? Gauges.DIM : Gauges.FAINT;
      ctx.lineWidth = mj ? 2 : 1;
      ctx.beginPath();
      ctx.moveTo(cx + (r - 2) * Math.cos(a), cy + (r - 2) * Math.sin(a));
      ctx.lineTo(cx + (r - (mj ? 14 : 7)) * Math.cos(a), cy + (r - (mj ? 14 : 7)) * Math.sin(a));
      ctx.stroke();
    }
    ctx.fillStyle = Gauges.FG;
    ctx.font = '700 13px ' + MONO;
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    [['0', 0], ['90', 90], ['180', 180], ['270', 270]].forEach(function (p) {
      var a = (p[1] - 90) * Math.PI / 180;
      ctx.fillText(p[0], cx + (r - 26) * Math.cos(a), cy + (r - 26) * Math.sin(a));
    });
    // port/starboard tint
    ctx.fillStyle = Gauges.RED;
    ctx.beginPath(); ctx.arc(cx - r + 20, cy, 3, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = Gauges.GREEN;
    ctx.beginPath(); ctx.arc(cx + r - 20, cy, 3, 0, Math.PI * 2); ctx.fill();

    function needle(deg, color, width, alpha, len) {
      var a = (deg - 90) * Math.PI / 180;
      ctx.save();
      ctx.globalAlpha = alpha;
      ctx.strokeStyle = color;
      ctx.lineWidth = width;
      ctx.lineCap = 'round';
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.lineTo(cx + len * Math.cos(a), cy + len * Math.sin(a));
      ctx.stroke();
      ctx.restore();
    }

    // ghost needles: last 60 wind history samples, fading
    var hist = state.weather.wind_history || [];
    var tail = hist.slice(-60);
    tail.forEach(function (s, i) {
      if (s.awa === null || s.awa === undefined) return;
      needle(s.awa, Gauges.GREEN, 1, 0.05 + 0.25 * (i / Math.max(1, tail.length - 1)), r - 34);
    });

    var awa = lv('awa'), twd = lv('twd');
    if (twd !== null) needle(twd, Gauges.CYAN || '#3fc6d8', 2, 0.8, r - 20);
    if (awa !== null) needle(awa, Gauges.GREEN, 4, 1.0, r - 20);
  }

  // ---------------- render ----------------
  function render() {
    setText('w-aws', fnum(lv('aws'), 1));
    setText('w-awa', fnum(lv('awa'), 0));
    setText('w-tws', fnum(lv('tws'), 1));
    setText('w-twa', fnum(lv('twa'), 0));
    setText('w-twd', fnum(lv('twd'), 0));
    setText('w-air', fnum(lv('air_temp'), 1));
    setText('w-water', fnum(lv('water_temp'), 1));
    setText('w-hum', fnum(lv('humidity'), 0));

    var p = lv('pressure');
    setText('w-baro', fnum(p, 1));

    // 3 h trend from pressure_history [{ts, mb}]
    var ph = state.weather.pressure_history || [];
    var trendEl = document.getElementById('w-trend');
    var now = Date.now() / 1000;
    var recent = ph.filter(function (s) { return s.ts >= now - 3 * 3600; });
    if (recent.length >= 2) {
      var slope = recent[recent.length - 1].mb - recent[0].mb;
      if (slope > 0.5) { trendEl.textContent = '\u2197'; trendEl.style.color = Gauges.GREEN; }
      else if (slope < -0.5) { trendEl.textContent = '\u2198'; trendEl.style.color = Gauges.RED; }
      else { trendEl.textContent = '\u2192'; trendEl.style.color = Gauges.DIM; }
    } else {
      trendEl.textContent = '\u2022'; trendEl.style.color = '';
    }

    baroSpark(ph.map(function (s) { return s.mb; }));
    windSpark((state.weather.wind_history || []).map(function (s) { return s.aws; }));
    drawWind();
  }

  // stale dimming on weather cards
  setInterval(function () {
    var stale = lastWx > 0 && (Date.now() - lastWx) > 10000;
    document.querySelectorAll('[data-live="weather"]').forEach(function (el) {
      el.classList.toggle('stale', stale);
    });
  }, 1000);

  // ---------------- data ----------------
  async function boot() {
    try {
      var r = await fetch('api.php?action=state&section=weather');
      var j = await r.json();
      if (j.ok && j.state && j.state.weather) {
        state.weather = Object.assign(state.weather, j.state.weather);
        lastWx = Date.now();
      }
    } catch (e) { }
    render();

    var es = new EventSource('sse.php?stream=state');
    es.onmessage = function (ev) {
      var msg;
      try { msg = JSON.parse(ev.data); } catch (e) { return; }
      if (msg && msg.d && msg.d.weather) {
        lastWx = Date.now();
        var latest = state.weather.latest;
        Object.keys(msg.d.weather).forEach(function (k) {
          latest[k] = { v: msg.d.weather[k], ts: Date.now() / 1000 };
        });
        // keep local histories in step with the daemon's ring buffers
        var d = msg.d.weather;
        if (d.aws !== undefined || d.awa !== undefined) {
          state.weather.wind_history.push({ ts: Date.now() / 1000, aws: d.aws !== undefined ? d.aws : null, awa: d.awa !== undefined ? d.awa : null });
          if (state.weather.wind_history.length > 600) state.weather.wind_history.shift();
        }
        if (d.pressure !== undefined) {
          var ph = state.weather.pressure_history;
          if (!ph.length || Date.now() / 1000 - ph[ph.length - 1].ts >= 55) {
            ph.push({ ts: Date.now() / 1000, mb: d.pressure });
            if (ph.length > 1440) ph.shift();
          }
        }
        render();
      }
    };
  }

  boot();
})();
</script>
<?php
page_footer();
