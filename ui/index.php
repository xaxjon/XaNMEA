<?php
declare(strict_types=1);

/**
 * index.php: Status home. Interface cards with live state, global totals,
 * uptime, heartbeat staleness banner. Auto-refreshes STATS every 2s.
 */

require __DIR__ . '/lib/common.php';
$user = requireLogin();
page_header('Status', 'status');
?>
<h1>Status</h1>

<div class="grid cols-4" style="margin-bottom:0.9rem">
  <div class="card"><h2>Version</h2><div class="big" id="g-ver" style="font-size:1.6rem">--</div></div>
  <div class="card"><h2>Uptime</h2><div class="big" id="g-up" style="font-size:1.6rem">--</div></div>
  <div class="card"><h2>Sentences in / s</h2><div class="big" id="g-in">--</div></div>
  <div class="card"><h2>Sentences out / s</h2><div class="big" id="g-out">--</div></div>
</div>

<div class="grid cols-2" id="cards"></div>

<script>
(function () {
  'use strict';

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtUptime(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600),
        m = Math.floor(sec % 3600 / 60), s = sec % 60;
    return (d ? d + 'd ' : '') + String(h).padStart(2, '0') + ':' +
           String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function stateDot(st, enabled) {
    if (!enabled) return '<span class="dot grey"></span>';
    if (st === 'up') return '<span class="dot green"></span>';
    if (st === 'retry' || st === 'connecting') return '<span class="dot amber"></span>';
    return '<span class="dot red"></span>';
  }

  function endpoint(it) {
    if (it.type === 'serial') return esc(it.device || '?') + ' @ ' + esc(it.baud || '?');
    if (it.address !== undefined) return esc(it.address) + ':' + esc(it.port);
    return '';
  }

  function ago(ts) {
    if (!ts) return 'never';
    var s = Math.max(0, Date.now() / 1000 - ts);
    if (s < 60) return Math.floor(s) + 's ago';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    return Math.floor(s / 3600) + 'h ago';
  }

  function card(it) {
    var c = it.counters || {};
    var clients = (it.type === 'tcp_server' && it.clients) ? it.clients.length : null;
    var html = '<div class="card iface-card' + (it.enabled ? '' : ' disabled') + '">';
    html += '<div class="head">' + stateDot(it.state, it.enabled) +
            '<span class="name">' + esc(it.name) + '</span> ' +
            '<span class="badge">' + esc(it.type) + '</span> ' +
            '<span class="badge cyan">' + esc(it.direction) + '</span> ' +
            '<span class="ep">' + endpoint(it) + '</span></div>';
    html += '<div class="nums">' +
      '<div class="n"><div class="v">' + esc(c['in'] || 0) + '</div><div class="k">in</div></div>' +
      '<div class="n"><div class="v">' + esc(c.out || 0) + '</div><div class="k">out</div></div>' +
      '<div class="n"><div class="v">' + esc(c.dropped || 0) + '</div><div class="k">dropped</div></div>' +
      '<div class="n"><div class="v">' + esc(c.checksum_err || 0) + '</div><div class="k">cksum err</div></div>' +
      '<div class="n"><div class="v">' + esc(c.reconnects || 0) + '</div><div class="k">reconn</div></div>' +
      (clients !== null ? '<div class="n"><div class="v">' + clients + '</div><div class="k">clients</div></div>' : '') +
      '</div>';
    if (it.last_error) {
      html += '<div class="err" title="' + esc(it.last_error) + '">' + esc(it.last_error) + '</div>';
    }
    html += '<div class="meta">state: ' + esc(it.state) +
            ' &middot; activity ' + ago(it.last_activity) +
            (it.up_since ? ' &middot; up since ' + new Date(it.up_since * 1000).toLocaleTimeString() : '') +
            (it.queue !== undefined ? ' &middot; queue ' + esc(it.queue) : '') +
            '</div></div>';
    return html;
  }

  async function refresh() {
    var j;
    try {
      var r = await fetch('api.php?action=stats');
      j = await r.json();
    } catch (e) { return; }
    if (!j.ok) {
      document.getElementById('cards').innerHTML =
        '<div class="card"><span class="dot red"></span> Daemon unreachable: ' + esc(j.error || '') + '</div>';
      return;
    }
    document.getElementById('g-ver').textContent = j.version || '--';
    document.getElementById('g-up').textContent = fmtUptime(j.uptime);
    document.getElementById('g-in').textContent = (j.rates && j.rates.in_1s !== undefined) ? j.rates.in_1s.toFixed(1) : '--';
    document.getElementById('g-out').textContent = (j.rates && j.rates.out_1s !== undefined) ? j.rates.out_1s.toFixed(1) : '--';
    var ifaces = j.interfaces || [];
    document.getElementById('cards').innerHTML = ifaces.map(card).join('') ||
      '<div class="card dim">No interfaces configured.</div>';
  }

  refresh();
  setInterval(refresh, 2000);
})();
</script>
<?php
page_footer();
