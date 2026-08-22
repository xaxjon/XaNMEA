<?php
declare(strict_types=1);

/**
 * ais.php: canvas radar plot (north-up, own ship center) + sortable target
 * table + detail card. STATE snapshot + SSE deltas; null delta removes a target.
 */

require __DIR__ . '/lib/common.php';
requireLogin();
page_header('AIS', 'ais');
?>
<h1>AIS</h1>

<div class="flex">
  <div class="card" style="flex:0 0 560px">
    <div class="toolbar">
      <h2 style="margin:0">Radar</h2>
      <label class="dim" for="range" style="font-size:0.85rem">Range</label>
      <select id="range">
        <option value="0.5">0.5 NM</option>
        <option value="1" selected>1 NM</option>
        <option value="2">2 NM</option>
        <option value="5">5 NM</option>
      </select>
      <span class="dim mono" id="tgt-count" style="font-size:0.85rem"></span>
    </div>
    <canvas id="radar" style="height:520px"></canvas>
  </div>
  <div class="grow">
    <div class="card" id="detail" style="display:none"></div>
    <div class="card mt">
      <h2>Targets</h2>
      <table class="tbl" id="targets">
        <thead><tr>
          <th class="sortable" data-k="mmsi">MMSI</th>
          <th class="sortable" data-k="name">Name</th>
          <th class="sortable" data-k="class">Cls</th>
          <th class="sortable" data-k="distance_nm">Dist</th>
          <th class="sortable" data-k="bearing_deg">Brg</th>
          <th class="sortable" data-k="sog">SOG</th>
          <th class="sortable" data-k="cog">COG</th>
          <th class="sortable" data-k="cpa_nm">CPA</th>
          <th class="sortable" data-k="tcpa_min">TCPA</th>
          <th class="sortable" data-k="age">Age</th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var state = { ais: {}, ownship: {} };
  var targets = {}; // mmsi -> target (client-side copy)
  var selected = null;
  var sortK = 'distance_nm', sortDir = 1;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fnum(v, dec) { return (v === null || v === undefined || isNaN(v)) ? '--' : Number(v).toFixed(dec); }
  function ageOf(t) { return t.last_seen ? Math.max(0, Date.now() / 1000 - t.last_seen) : 1e9; }
  function isDanger(t) {
    return t.cpa_nm !== undefined && t.cpa_nm !== null && t.cpa_nm < 0.5 &&
           t.tcpa_min !== undefined && t.tcpa_min !== null && t.tcpa_min >= 0 && t.tcpa_min < 30;
  }

  // ---------------- radar ----------------
  var canvas = document.getElementById('radar');

  function drawRadar() {
    var dpr = window.devicePixelRatio || 1;
    var w = canvas.clientWidth, h = canvas.clientHeight;
    if (!w || !h) return;
    if (canvas.width !== Math.round(w * dpr)) { canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr); }
    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    var range = parseFloat(document.getElementById('range').value);
    var cx = w / 2, cy = h / 2, R = Math.min(w, h) / 2 - 16;
    var MONO = 'ui-monospace, Menlo, Consolas, monospace';

    // rings (4 rings)
    ctx.strokeStyle = '#1d2d25';
    ctx.fillStyle = '#6b8577';
    ctx.font = '10px ' + MONO;
    ctx.textAlign = 'left';
    for (var i = 1; i <= 4; i++) {
      ctx.beginPath(); ctx.arc(cx, cy, R * i / 4, 0, Math.PI * 2); ctx.stroke();
      ctx.fillText((range * i / 4).toFixed(range < 1 ? 2 : 1), cx + R * i / 4 + 3, cy - 3);
    }
    // bearing ticks
    ctx.strokeStyle = '#3d5449';
    for (var b = 0; b < 360; b += 30) {
      var a = (b - 90) * Math.PI / 180;
      ctx.beginPath();
      ctx.moveTo(cx + (R - (b % 90 === 0 ? 12 : 6)) * Math.cos(a), cy + (R - (b % 90 === 0 ? 12 : 6)) * Math.sin(a));
      ctx.lineTo(cx + R * Math.cos(a), cy + R * Math.sin(a));
      ctx.stroke();
      if (b % 90 === 0) {
        ctx.fillStyle = '#6b8577';
        ctx.textAlign = 'center';
        ctx.fillText({0: 'N', 90: 'E', 180: 'S', 270: 'W'}[b],
          cx + (R + 10) * Math.cos(a), cy + (R + 10) * Math.sin(a) + 4);
      }
    }

    // targets
    Object.keys(targets).forEach(function (mmsi) {
      var t = targets[mmsi];
      if (t.distance_nm === undefined || t.bearing_deg === undefined) return;
      if (t.distance_nm > range) return;
      var rr = R * (t.distance_nm / range);
      var ta = (t.bearing_deg - 90) * Math.PI / 180;
      var x = cx + rr * Math.cos(ta), y = cy + rr * Math.sin(ta);

      var danger = isDanger(t);
      var col = danger ? '#ff5252' : (t['class'] === 'AtoN' ? '#3fc6d8' : '#2ee884');
      ctx.save();
      if (t.stale) ctx.globalAlpha = 0.35;
      ctx.translate(x, y);
      ctx.rotate((t.cog || 0) * Math.PI / 180);
      ctx.beginPath();
      ctx.moveTo(0, -7); ctx.lineTo(4.5, 6); ctx.lineTo(-4.5, 6); ctx.closePath();
      if (t['class'] === 'B') {
        ctx.strokeStyle = col; ctx.lineWidth = 1.5; ctx.stroke();
      } else {
        ctx.fillStyle = col; ctx.fill();
      }
      ctx.restore();

      if (selected === mmsi) {
        ctx.strokeStyle = '#ffb02e';
        ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.arc(x, y, 12, 0, Math.PI * 2); ctx.stroke();
      }
      if (danger) {
        ctx.fillStyle = '#ff5252';
        ctx.font = 'bold 10px ' + MONO;
        ctx.textAlign = 'left';
        ctx.fillText('CPA ' + fnum(t.cpa_nm, 2) + ' / ' + fnum(t.tcpa_min, 0) + 'min', x + 10, y - 8);
      }
    });

    // own ship: triangle up at center
    ctx.fillStyle = '#cfe8da';
    ctx.beginPath();
    ctx.moveTo(cx, cy - 9); ctx.lineTo(cx + 6, cy + 8); ctx.lineTo(cx - 6, cy + 8);
    ctx.closePath(); ctx.fill();

    document.getElementById('tgt-count').textContent = Object.keys(targets).length + ' targets';
  }

  // ---------------- table ----------------
  function val(t, k) {
    if (k === 'age') return ageOf(t);
    if (k === 'name') return (t.name || '').toLowerCase();
    var v = t[k];
    return (v === undefined || v === null) ? (sortDir > 0 ? 1e12 : -1e12) : v;
  }

  function renderTable() {
    var now = Date.now() / 1000;
    var rows = Object.keys(targets).map(function (m) { return targets[m]; });
    rows.sort(function (a, b) {
      var x = val(a, sortK), y = val(b, sortK);
      if (x < y) return -sortDir;
      if (x > y) return sortDir;
      return 0;
    });
    var html = '';
    rows.forEach(function (t) {
      var danger = isDanger(t);
      var cls = (selected === t.mmsi ? 'selected ' : '') + (t.stale ? 'faded' : '');
      html += '<tr data-mmsi="' + esc(t.mmsi) + '" class="' + cls + '">' +
        '<td>' + esc(t.mmsi) + '</td>' +
        '<td>' + esc(t.name || '') + '</td>' +
        '<td>' + esc(t['class'] || '?') + '</td>' +
        '<td>' + fnum(t.distance_nm, 2) + '</td>' +
        '<td>' + fnum(t.bearing_deg, 0) + '</td>' +
        '<td>' + fnum(t.sog, 1) + '</td>' +
        '<td>' + fnum(t.cog, 0) + '</td>' +
        '<td' + (danger ? ' style="color:#ff5252;font-weight:700"' : '') + '>' + fnum(t.cpa_nm, 2) + '</td>' +
        '<td' + (danger ? ' style="color:#ff5252;font-weight:700"' : '') + '>' + fnum(t.tcpa_min, 0) + '</td>' +
        '<td>' + (t.last_seen ? Math.round(now - t.last_seen) + 's' : '--') + '</td>' +
        '</tr>';
    });
    document.querySelector('#targets tbody').innerHTML = html ||
      '<tr><td colspan="10" class="dim">No targets</td></tr>';
  }

  function renderDetail() {
    var el = document.getElementById('detail');
    var t = selected && targets[selected];
    if (!t) { el.style.display = 'none'; return; }
    el.style.display = '';
    var rows = [
      ['Name', t.name], ['Callsign', t.callsign], ['Class', t['class']],
      ['Ship type', t.ship_type], ['Destination', t.destination],
      ['Nav status', t.nav_status], ['Draught', t.draught !== undefined ? t.draught + ' m' : null],
      ['Dim B/S/P/Sb', [t.dim_bow, t.dim_stern, t.dim_port, t.dim_starboard].every(function (v) { return v !== undefined; })
        ? t.dim_bow + '/' + t.dim_stern + '/' + t.dim_port + '/' + t.dim_starboard + ' m' : null],
      ['Position', (t.lat !== undefined && t.lon !== undefined) ? fnum(t.lat, 5) + ', ' + fnum(t.lon, 5) : null],
      ['SOG / COG / HDG', t.sog !== undefined ? fnum(t.sog, 1) + ' kn / ' + fnum(t.cog, 0) + '\u00B0 / ' + fnum(t.hdg, 0) + '\u00B0' : null],
      ['Distance / Bearing', t.distance_nm !== undefined ? fnum(t.distance_nm, 2) + ' nm / ' + fnum(t.bearing_deg, 0) + '\u00B0' : null],
      ['CPA / TCPA', t.cpa_nm !== undefined ? fnum(t.cpa_nm, 2) + ' nm / ' + fnum(t.tcpa_min, 1) + ' min' : null],
      ['Messages', t.msg_count],
      ['First seen', t.first_seen ? new Date(t.first_seen * 1000).toLocaleTimeString() : null],
      ['Last seen', t.last_seen ? new Date(t.last_seen * 1000).toLocaleTimeString() + ' (' + Math.round(ageOf(t)) + 's ago)' : null],
      ['Stale', t.stale ? 'yes' : 'no'],
    ];
    var html = '<h2>' + esc(t.name || t.mmsi) + ' <span class="dim">' + esc(t.mmsi) + '</span></h2><table class="tbl">';
    rows.forEach(function (r) {
      if (r[1] === undefined || r[1] === null || r[1] === '') return;
      html += '<tr><td class="dim">' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>';
    });
    el.innerHTML = html + '</table>';
  }

  // ---------------- events ----------------
  document.querySelectorAll('#targets th.sortable').forEach(function (th) {
    th.addEventListener('click', function () {
      var k = th.getAttribute('data-k');
      if (sortK === k) sortDir = -sortDir; else { sortK = k; sortDir = 1; }
      renderTable();
    });
  });
  document.querySelector('#targets tbody').addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr');
    if (!tr || !tr.getAttribute('data-mmsi')) return;
    selected = tr.getAttribute('data-mmsi');
    renderTable(); renderDetail(); drawRadar();
  });
  document.getElementById('range').addEventListener('change', drawRadar);

  // age column + stale fade tick over even without deltas
  setInterval(function () { renderTable(); drawRadar(); }, 2000);

  // ---------------- data ----------------
  function merge(d) {
    if (d.ownship) state.ownship = Object.assign(state.ownship, d.ownship);
    if (d.ais) {
      Object.keys(d.ais).forEach(function (mmsi) {
        if (d.ais[mmsi] === null) {
          delete targets[mmsi];
          if (selected === mmsi) { selected = null; renderDetail(); }
        } else {
          targets[mmsi] = Object.assign(targets[mmsi] || { mmsi: mmsi }, d.ais[mmsi]);
        }
      });
    }
  }

  async function boot() {
    try {
      var r = await fetch('api.php?action=state&section=all');
      var j = await r.json();
      if (j.ok && j.state) {
        if (j.state.ownship) state.ownship = j.state.ownship;
        if (j.state.ais) {
          Object.keys(j.state.ais).forEach(function (m) { targets[m] = j.state.ais[m]; });
        }
      }
    } catch (e) { }
    renderTable(); renderDetail(); drawRadar();

    var es = new EventSource('sse.php?stream=state');
    es.onmessage = function (ev) {
      var msg;
      try { msg = JSON.parse(ev.data); } catch (e) { return; }
      if (msg && msg.d) { merge(msg.d); renderTable(); renderDetail(); drawRadar(); }
    };
  }

  boot();
})();
</script>
<?php
page_footer();
