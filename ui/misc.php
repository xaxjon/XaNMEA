<?php
declare(strict_types=1);

/**
 * misc.php: auto-generated cards from the STATE misc section plus the full
 * sentence registry table. STATE snapshot + SSE deltas.
 */

require __DIR__ . '/lib/common.php';
requireLogin();
page_header('Misc', 'misc');
?>
<h1>Misc decoded sentences</h1>

<div class="grid cols-3" id="cards"></div>

<div class="card mt">
  <h2>Sentence registry</h2>
  <table class="tbl" id="registry">
    <thead><tr>
      <th>Talker</th><th>Type</th><th>Count</th><th>Avg /min</th>
      <th>First seen</th><th>Last seen</th><th>Status</th>
    </tr></thead>
    <tbody></tbody>
  </table>
</div>

<script>
(function () {
  'use strict';

  var misc = {}, sentences = {};

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function ago(ts) {
    if (!ts) return '--';
    var s = Math.max(0, Date.now() / 1000 - ts);
    if (s < 60) return Math.floor(s) + 's ago';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    return Math.floor(s / 3600) + 'h ago';
  }

  function fieldRows(fields) {
    var html = '';
    Object.keys(fields || {}).forEach(function (k) {
      var v = fields[k];
      if (Array.isArray(v)) {
        if (k === 'sats') v = v.length + ' sats (see Dashboard sky plot)';
        else v = '[' + v.length + ' entries]';
      } else if (typeof v === 'object' && v !== null) {
        v = JSON.stringify(v);
      }
      html += '<tr><td class="dim">' + esc(k) + '</td><td>' + esc(v) + '</td></tr>';
    });
    return html;
  }

  function renderCards() {
    var keys = Object.keys(misc).sort();
    var html = '';
    keys.forEach(function (k) {
      var e = misc[k];
      html += '<div class="card">' +
        '<h2>' + esc(k) + ' <span class="dim" style="text-transform:none">&times;' + esc(e.count || 0) + '</span></h2>' +
        '<table class="tbl">' + fieldRows(e.fields) + '</table>' +
        '<div class="sub">' + ago(e.ts) + '</div></div>';
    });
    document.getElementById('cards').innerHTML = html ||
      '<div class="card dim">No miscellaneous sentences decoded yet.</div>';
  }

  function renderRegistry() {
    var keys = Object.keys(sentences).sort();
    var now = Date.now() / 1000;
    var html = '';
    keys.forEach(function (k) {
      var s = sentences[k];
      var span = Math.max(1, now - (s.first_seen || now));
      var rate = (s.count || 0) / (span / 60);
      var badge = s.status === 'decoded' ? 'green' : (s.status === 'failed' ? 'red' : 'amber');
      html += '<tr>' +
        '<td>' + esc(s.talker) + '</td>' +
        '<td>' + esc(s.type) + '</td>' +
        '<td>' + esc(s.count) + '</td>' +
        '<td>' + rate.toFixed(1) + '</td>' +
        '<td>' + ago(s.first_seen) + '</td>' +
        '<td>' + ago(s.last_seen) + '</td>' +
        '<td><span class="badge ' + badge + '">' + esc(s.status || '?') + '</span></td>' +
        '</tr>';
    });
    document.querySelector('#registry tbody').innerHTML = html ||
      '<tr><td colspan="7" class="dim">Nothing seen yet.</td></tr>';
  }

  function render() { renderCards(); renderRegistry(); }
  setInterval(renderRegistry, 5000); // age columns tick over

  async function boot() {
    try {
      var r = await fetch('api.php?action=state&section=all');
      var j = await r.json();
      if (j.ok && j.state) {
        misc = j.state.misc || {};
        sentences = j.state.sentences || {};
      }
    } catch (e) { }
    render();

    var es = new EventSource('sse.php?stream=state');
    es.onmessage = function (ev) {
      var msg;
      try { msg = JSON.parse(ev.data); } catch (e) { return; }
      if (!msg || !msg.d) return;
      var dirty = false;
      if (msg.d.misc) {
        Object.keys(msg.d.misc).forEach(function (k) {
          if (msg.d.misc[k] === null) { delete misc[k]; return; }
          misc[k] = Object.assign(misc[k] || {}, msg.d.misc[k]);
        });
        dirty = true;
      }
      if (msg.d.sentences) {
        Object.keys(msg.d.sentences).forEach(function (k) {
          sentences[k] = Object.assign(sentences[k] || {}, msg.d.sentences[k]);
        });
        dirty = true;
      }
      if (dirty) render();
    };
  }

  boot();
})();
</script>
<?php
page_footer();
