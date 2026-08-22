<?php
declare(strict_types=1);

/**
 * diagnostics.php: live sentence viewer (SSE tail), TCP server clients with
 * kick, daemon event log, full per-interface counter table.
 */

require __DIR__ . '/lib/common.php';
$user = requireLogin();
$isAdmin = $user['role'] === 'admin';
page_header('Diagnostics', 'diagnostics');
?>
<h1>Diagnostics</h1>

<div class="tabs">
  <button data-tab="live" class="active">Live viewer</button>
  <button data-tab="clients">TCP clients</button>
  <button data-tab="events">Events</button>
  <button data-tab="stats">Stats</button>
</div>

<!-- ---------------- live viewer ---------------- -->
<section id="tab-live">
  <div class="toolbar">
    <select id="lv-iface"><option value="all">all interfaces</option></select>
    <button class="btn small" id="lv-pause" type="button">Pause</button>
    <button class="btn small" id="lv-clear" type="button">Clear</button>
    <span class="dim mono" id="lv-status" style="font-size:0.85rem">connecting...</span>
  </div>
  <div class="viewer" id="lv-view">
    <table><tbody id="lv-body"></tbody></table>
  </div>
</section>

<!-- ---------------- tcp clients ---------------- -->
<section id="tab-clients" style="display:none">
  <div id="clients-wrap"></div>
</section>

<!-- ---------------- events ---------------- -->
<section id="tab-events" style="display:none">
  <div class="card"><div id="events-list" class="mono"></div></div>
</section>

<!-- ---------------- stats ---------------- -->
<section id="tab-stats" style="display:none">
  <div id="stats-wrap"></div>
</section>

<script>
(function () {
  'use strict';

  var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
  var MAX_ROWS = 500;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ---------------- tabs ----------------
  document.querySelectorAll('.tabs button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.tabs button').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      ['live', 'clients', 'events', 'stats'].forEach(function (t) {
        document.getElementById('tab-' + t).style.display = t === btn.getAttribute('data-tab') ? '' : 'none';
      });
    });
  });

  // ---------------- live viewer ----------------
  var es = null, paused = false;
  var srcColors = {};

  function srcColor(name) {
    if (!srcColors[name]) {
      var h = 0;
      for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;
      srcColors[name] = 'hsl(' + h + ', 60%, 60%)';
    }
    return srcColors[name];
  }

  function addRow(msg) {
    if (paused) return;
    if (msg.mode) { // stream ack line
      document.getElementById('lv-status').textContent = 'streaming';
      return;
    }
    var body = document.getElementById('lv-body');
    var tr = document.createElement('tr');
    if (msg.valid === false) tr.className = 'bad';
    var d = new Date((msg.ts || 0) * 1000);
    var t = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':' +
            String(d.getSeconds()).padStart(2, '0') + '.' + String(d.getMilliseconds()).padStart(3, '0');
    tr.innerHTML = '<td class="t">' + t + '</td>' +
      '<td class="s" style="color:' + srcColor(msg.src || '?') + '">' + esc(msg.src) + '</td>' +
      '<td class="d">' + esc((msg.dst || []).join(', ')) + '</td>' +
      '<td class="raw">' + esc(msg.raw) + (msg.valid === false ? '  [BAD CHECKSUM]' : '') + '</td>';
    body.appendChild(tr);
    while (body.children.length > MAX_ROWS) body.removeChild(body.firstChild);
    var view = document.getElementById('lv-view');
    view.scrollTop = view.scrollHeight;
  }

  function connect() {
    if (es) { es.close(); es = null; }
    var iface = document.getElementById('lv-iface').value;
    document.getElementById('lv-status').textContent = 'connecting...';
    es = new EventSource('sse.php?stream=tail&iface=' + encodeURIComponent(iface));
    es.onmessage = function (ev) {
      var msg;
      try { msg = JSON.parse(ev.data); } catch (e) { return; }
      addRow(msg);
    };
    es.onerror = function () {
      document.getElementById('lv-status').textContent = 'disconnected - retrying';
    };
  }

  document.getElementById('lv-iface').addEventListener('change', connect);
  document.getElementById('lv-clear').addEventListener('click', function () {
    document.getElementById('lv-body').innerHTML = '';
  });
  document.getElementById('lv-pause').addEventListener('click', function () {
    paused = !paused;
    this.textContent = paused ? 'Resume' : 'Pause';
    document.getElementById('lv-status').textContent = paused ? 'paused' : 'streaming';
  });

  // ---------------- stats-driven sections ----------------
  function renderClients(ifaces) {
    var html = '';
    ifaces.forEach(function (it) {
      if (it.type !== 'tcp_server') return;
      html += '<div class="card" style="margin-bottom:0.9rem"><h2>' + esc(it.name) +
        ' <span class="dim" style="text-transform:none">' + esc(it.address) + ':' + esc(it.port) + '</span></h2>';
      var cs = it.clients || [];
      if (!cs.length) {
        html += '<div class="dim">No connected clients.</div></div>';
        return;
      }
      html += '<table class="tbl"><thead><tr><th>ID</th><th>IP</th><th>Since</th><th>Sent</th><th>Queued</th>' +
        (IS_ADMIN ? '<th></th>' : '') + '</tr></thead><tbody>';
      cs.forEach(function (c) {
        html += '<tr><td>' + esc(c.id) + '</td><td>' + esc(c.ip) + '</td>' +
          '<td>' + (c.since ? new Date(c.since * 1000).toLocaleTimeString() : '--') + '</td>' +
          '<td>' + esc(c.sent) + '</td><td>' + esc(c.queued) + '</td>' +
          (IS_ADMIN ? '<td class="right"><button class="btn small danger" data-kick="' +
            esc(it.name) + '|' + esc(c.id) + '">Kick</button></td>' : '') +
          '</tr>';
      });
      html += '</tbody></table></div>';
    });
    document.getElementById('clients-wrap').innerHTML = html ||
      '<div class="card dim">No tcp_server interfaces.</div>';
  }

  var LEVELS = ['debug', 'info', 'notice', 'warning', 'error'];

  function renderEvents(events) {
    var html = '';
    (events || []).slice().reverse().forEach(function (e) {
      var lv = (e.level === undefined) ? 1 : e.level;
      var t = e.ts ? new Date(e.ts * 1000).toLocaleTimeString() : '--:--:--';
      html += '<div class="ev l' + lv + '">' + t + '  [' + (LEVELS[lv] || lv) + ']  ' + esc(e.msg) + '</div>';
    });
    document.getElementById('events-list').innerHTML = html || '<div class="dim">No events.</div>';
  }

  var COUNTER_KEYS = ['in', 'out', 'bytes_in', 'bytes_out', 'dropped', 'checksum_err', 'parse_err',
                      'filtered_in', 'failover_dropped', 'reconnects'];

  function renderStats(ifaces) {
    var html = '';
    ifaces.forEach(function (it) {
      var c = it.counters || {};
      html += '<div class="card" style="margin-bottom:0.9rem"><h2>' + esc(it.name) + '</h2><table class="tbl"><tbody>';
      COUNTER_KEYS.forEach(function (k) {
        html += '<tr><td class="dim">' + k + '</td><td>' + esc(c[k] !== undefined ? c[k] : 0) + '</td></tr>';
      });
      html += '</tbody></table></div>';
    });
    document.getElementById('stats-wrap').innerHTML = html ||
      '<div class="card dim">No interfaces.</div>';
  }

  async function refreshStats() {
    var j;
    try {
      var r = await fetch('api.php?action=stats');
      j = await r.json();
    } catch (e) { return; }
    if (!j.ok) return;
    var ifaces = j.interfaces || [];
    renderClients(ifaces);
    renderEvents(j.events);
    renderStats(ifaces);

    // (re)populate the interface filter, preserving selection
    var sel = document.getElementById('lv-iface');
    var cur = sel.value;
    var opts = '<option value="all">all interfaces</option>';
    ifaces.forEach(function (it) {
      opts += '<option value="' + esc(it.name) + '"' + (it.name === cur ? ' selected' : '') + '>' + esc(it.name) + '</option>';
    });
    if (sel.getAttribute('data-prev') !== opts) {
      sel.innerHTML = opts;
      sel.setAttribute('data-prev', opts);
    }
  }

  document.getElementById('clients-wrap').addEventListener('click', function (ev) {
    var k = ev.target.getAttribute && ev.target.getAttribute('data-kick');
    if (!k) return;
    var parts = k.split('|');
    if (!confirm('Kick client ' + parts[1] + ' on ' + parts[0] + '?')) return;
    window.XAN.post('kick', { iface: parts[0], client_id: parseInt(parts[1], 10) }).then(function (r) {
      if (!r.ok) alert(r.error || 'Kick failed');
      refreshStats();
    });
  });

  connect();
  refreshStats();
  setInterval(refreshStats, 2000);
})();
</script>
<?php
page_footer();
