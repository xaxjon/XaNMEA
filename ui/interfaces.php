<?php
declare(strict_types=1);

/**
 * interfaces.php: interface list + type-aware add/edit form with a kplex-style
 * filter rule builder. All mutations go through api.php (admin only).
 */

require __DIR__ . '/lib/common.php';
$user = requireLogin();
$cfg = xan_load_raw_config();
$ifaces = $cfg['interfaces'];
$isAdmin = $user['role'] === 'admin';

page_header('Interfaces', 'interfaces');
?>
<h1>Interfaces</h1>

<div class="card">
<table class="tbl" id="iflist">
  <thead><tr>
    <th>Name</th><th>Type</th><th>Dir</th><th>Endpoint</th>
    <th>In filter</th><th>Out filter</th><th>Enabled</th>
    <?php if ($isAdmin): ?><th></th><?php endif; ?>
  </tr></thead>
  <tbody></tbody>
</table>
</div>

<?php if ($isAdmin): ?>
<div class="card mt" id="formcard">
  <h2 id="formtitle">Add interface</h2>
  <input type="hidden" id="f-orig">
  <div class="flex">
    <div class="grow">
      <label class="f" for="f-name">Name</label>
      <input type="text" id="f-name" maxlength="32" placeholder="gps-serial">
      <label class="f" for="f-type">Type</label>
      <select id="f-type">
        <option value="serial">serial</option>
        <option value="tcp_server">tcp_server</option>
        <option value="tcp_client">tcp_client</option>
        <option value="udp">udp</option>
      </select>
      <label class="f" for="f-direction">Direction</label>
      <select id="f-direction">
        <option value="in">in</option>
        <option value="out">out</option>
        <option value="both">both</option>
      </select>
      <label class="f"><input type="checkbox" id="f-enabled" checked> Enabled</label>
      <label class="f" for="f-comment">Comment</label>
      <input type="text" id="f-comment" placeholder="optional note">
    </div>

    <div class="grow">
      <!-- serial -->
      <div class="typed" data-type="serial">
        <label class="f" for="f-device">Device</label>
        <input type="text" id="f-device" placeholder="/dev/ttyUSB0">
        <label class="f" for="f-baud">Baud</label>
        <select id="f-baud">
          <?php foreach ([4800, 9600, 19200, 38400, 57600, 115200, 230400, 460800] as $b): ?>
          <option value="<?= $b ?>"><?= $b ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- tcp_server -->
      <div class="typed" data-type="tcp_server">
        <label class="f" for="f-ts-addr">Listen address</label>
        <input type="text" id="f-ts-addr" placeholder="0.0.0.0">
        <label class="f" for="f-ts-port">Port</label>
        <input type="number" id="f-ts-port" min="1" max="65535" value="10110">
      </div>
      <!-- tcp_client -->
      <div class="typed" data-type="tcp_client">
        <label class="f" for="f-tc-addr">Remote address</label>
        <input type="text" id="f-tc-addr" placeholder="192.168.1.1">
        <label class="f" for="f-tc-port">Port</label>
        <input type="number" id="f-tc-port" min="1" max="65535" value="10110">
        <label class="f"><input type="checkbox" id="f-tc-persist" checked> Persist (reconnect)</label>
        <label class="f" for="f-tc-retry">Retry interval (s)</label>
        <input type="number" id="f-tc-retry" min="1" value="10">
        <label class="f"><input type="checkbox" id="f-tc-nodelay" checked> TCP_NODELAY</label>
        <label class="f"><input type="checkbox" id="f-tc-keepalive" checked> Keepalive</label>
        <label class="f" for="f-tc-preamble">Preamble (raw line sent on connect)</label>
        <input type="text" id="f-tc-preamble">
      </div>
      <!-- udp -->
      <div class="typed" data-type="udp">
        <label class="f" for="f-udp-addr">Address</label>
        <input type="text" id="f-udp-addr" placeholder="255.255.255.255">
        <div class="hint" id="udp-mode-hint"></div>
        <label class="f" for="f-udp-port">Port</label>
        <input type="number" id="f-udp-port" min="1" max="65535" value="10110">
        <label class="f"><input type="checkbox" id="f-udp-coalesce"> Coalesce output datagrams</label>
      </div>
    </div>
  </div>

  <div class="flex">
    <div class="grow">
      <label class="f">Input filter (ifilter)</label>
      <select class="fmode" id="ifilter-mode">
        <option value="pass_all">Pass all (default)</option>
        <option value="rules">Rules — first match wins, no match = drop</option>
      </select>
      <div class="frules" id="ifilter-rules"></div>
      <button class="btn small" type="button" data-add="ifilter-rules">+ add rule</button>
      <div class="sub" id="ifilter-note"></div>
    </div>
    <div class="grow">
      <label class="f">Output filter (ofilter)</label>
      <select class="fmode" id="ofilter-mode">
        <option value="pass_all">Pass all (default)</option>
        <option value="rules">Rules — first match wins, no match = drop</option>
      </select>
      <div class="frules" id="ofilter-rules"></div>
      <button class="btn small" type="button" data-add="ofilter-rules">+ add rule</button>
      <div class="sub" id="ofilter-note"></div>
    </div>
  </div>

  <details class="adv">
    <summary>Advanced options</summary>
    <div class="flex">
      <div class="grow">
        <label class="f" for="f-checksum">Checksum enforcement</label>
        <select id="f-checksum">
          <option value="">(inherit daemon)</option>
          <option value="1">yes</option>
          <option value="0">no</option>
        </select>
        <label class="f" for="f-strict">Strict parsing</label>
        <select id="f-strict">
          <option value="">(inherit daemon)</option>
          <option value="1">yes</option>
          <option value="0">no</option>
        </select>
        <label class="f" for="f-srctag">Source tag</label>
        <select id="f-srctag">
          <option value="no">no</option>
          <option value="yes">yes</option>
          <option value="input">input (copy from source)</option>
        </select>
      </div>
      <div class="grow">
        <label class="f" for="f-timestamp">Timestamp</label>
        <select id="f-timestamp">
          <option value="no">no</option>
          <option value="s">seconds</option>
          <option value="ms">milliseconds</option>
        </select>
        <label class="f" for="f-qsize">Queue size (0 = inherit)</label>
        <input type="number" id="f-qsize" min="0" value="0">
        <label class="f"><input type="checkbox" id="f-loopback"> Allow loopback (re-send sentences back out this interface)</label>
        <label class="f"><input type="checkbox" id="f-optional" checked> Optional (keep daemon running if open fails)</label>
      </div>
    </div>
  </details>

  <div>
    <button class="btn primary" id="f-save" type="button">Save &amp; reload</button>
    <button class="btn" id="f-cancel" type="button">Cancel</button>
  </div>
  <div class="form-msg" id="f-msg"></div>
</div>

<button class="btn primary" id="btn-add" type="button">Add interface</button>
<?php endif; ?>

<script>
(function () {
  'use strict';

  var IFACES = <?= json_encode($ifaces, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]' ?>;
  var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function $(id) { return document.getElementById(id); }

  // ---------------- list ----------------

  function endpoint(it) {
    if (it.type === 'serial') return (it.device || '?') + ' @ ' + (it.baud || '?');
    return (it.address || '') + ':' + (it.port || '');
  }

  function renderList() {
    var tb = document.querySelector('#iflist tbody');
    var html = '';
    IFACES.forEach(function (it) {
      html += '<tr>' +
        '<td><b>' + esc(it.name) + '</b>' + (it.comment ? '<br><span class="dim">' + esc(it.comment) + '</span>' : '') + '</td>' +
        '<td>' + esc(it.type) + '</td>' +
        '<td>' + esc(it.direction || '') + '</td>' +
        '<td>' + esc(endpoint(it)) + '</td>' +
        '<td>' + esc(filterSummary(it.ifilter)) + '</td>' +
        '<td>' + esc(filterSummary(it.ofilter)) + '</td>' +
        '<td>' + (it.enabled === false ? '<span class="badge red">off</span>' : '<span class="badge green">on</span>') + '</td>' +
        (IS_ADMIN ? '<td class="right">' +
          '<button class="btn small" data-edit="' + esc(it.name) + '">Edit</button> ' +
          '<button class="btn small" data-toggle="' + esc(it.name) + '">' + (it.enabled === false ? 'Enable' : 'Disable') + '</button> ' +
          '<button class="btn small danger" data-del="' + esc(it.name) + '">Delete</button>' +
        '</td>' : '') +
        '</tr>';
    });
    tb.innerHTML = html || '<tr><td colspan="8" class="dim">No interfaces configured.</td></tr>';
  }

  // ---------------- filter rule builder ----------------
  // Structured rules (OPNsense-style): top-down, first match wins; a
  // sentence matching NO rule is DROPPED (implicit deny). No rules at
  // all = Pass All. Legacy kplex strings from older configs are
  // converted to rules on load (with a note to review before saving).

  var FILTER_CLASSES = ['all', 'navigation', 'ais', 'weather', 'alarms', 'custom'];

  function modeSel(container) { return $(container.id.replace('-rules', '-mode')); }
  function noteEl(container) { return $(container.id.replace('-rules', '-note')); }

  function legacyToRules(str) {
    var rows = [], toks = String(str || '').split(':');
    toks.forEach(function (tok, idx) {
      tok = tok.trim();
      if (!tok) return;
      var op = tok[0], rest = tok.slice(1), limit = 0;
      if (op !== '+' && op !== '-' && op !== '~') return;
      if (op === '~') {
        var sl = rest.lastIndexOf('/');
        if (sl < 0) return;
        limit = parseInt(rest.slice(sl + 1), 10) || 0;
        rest = rest.slice(0, sl);
      }
      var src = '';
      var pc = rest.indexOf('%');
      if (pc >= 0) { src = rest.slice(pc + 1); rest = rest.slice(0, pc); }
      var isAll = rest.toLowerCase() === 'all';
      // a trailing "-all" is redundant under implicit deny
      if (op === '-' && isAll && idx === toks.length - 1) return;
      rows.push({
        action: op === '-' ? 'drop' : 'pass',
        cls: isAll ? 'all' : 'custom',
        match: isAll ? '' : rest.toUpperCase(),
        src: src,
        limit_s: op === '~' ? limit : 0
      });
    });
    return rows;
  }

  function ruleRow(container, row) {
    var div = document.createElement('div');
    div.className = 'frule';
    var clsOpts = FILTER_CLASSES.map(function (c) {
      return '<option value="' + c + '">' + c + '</option>';
    }).join('');
    div.innerHTML =
      '<select class="faction">' +
        '<option value="pass">pass</option>' +
        '<option value="drop">drop</option>' +
      '</select>' +
      '<select class="fcls">' + clsOpts + '</select>' +
      '<input type="text" class="fmatch" maxlength="5" placeholder="GP***" style="display:none">' +
      '<input type="text" class="fsrc" placeholder="source (opt)">' +
      '<input type="number" class="flimit" min="0" placeholder="every N sec" title="Rate limit: pass at most one matching sentence per N seconds (pass rules only)">' +
      '<button class="btn small" type="button" data-up title="Move up">&#8593;</button>' +
      '<button class="btn small" type="button" data-down title="Move down">&#8595;</button>' +
      '<button class="btn small danger" type="button" data-rm title="Remove">&times;</button>';
    div.querySelector('.faction').value = row.action || 'pass';
    div.querySelector('.fcls').value = row.cls || 'navigation';
    div.querySelector('.fmatch').value = row.match || '';
    div.querySelector('.fsrc').value = row.src || '';
    div.querySelector('.flimit').value = row.limit_s || '';
    function syncMatch() {
      div.querySelector('.fmatch').style.display =
        div.querySelector('.fcls').value === 'custom' ? '' : 'none';
    }
    div.querySelector('.fcls').addEventListener('change', syncMatch);
    syncMatch();
    div.querySelector('[data-rm]').addEventListener('click', function () { div.remove(); });
    div.querySelector('[data-up]').addEventListener('click', function () {
      if (div.previousElementSibling) container.insertBefore(div, div.previousElementSibling);
    });
    div.querySelector('[data-down]').addEventListener('click', function () {
      if (div.nextElementSibling) container.insertBefore(div.nextElementSibling, div);
    });
    container.appendChild(div);
  }

  function syncMode(container) {
    var rules = modeSel(container).value === 'rules';
    container.style.display = rules ? '' : 'none';
    document.querySelector('[data-add="' + container.id + '"]').style.display = rules ? '' : 'none';
  }

  function setFilter(container, spec) {
    container.innerHTML = '';
    noteEl(container).textContent = '';
    var rules = [];
    if (typeof spec === 'string' && spec.trim() !== '') {
      rules = legacyToRules(spec);
      noteEl(container).textContent = 'Converted from legacy filter "' + spec + '" - review, then Save.';
    } else if (spec && Array.isArray(spec.rules)) {
      rules = spec.rules.map(function (r) {
        return {
          action: r.action || 'pass',
          cls: r['class'] || 'all',
          match: r.match || '',
          src: r.src || '',
          limit_s: r.limit_s || 0
        };
      });
    }
    modeSel(container).value = rules.length ? 'rules' : 'pass_all';
    rules.forEach(function (row) { ruleRow(container, row); });
    syncMode(container);
  }

  function gatherFilter(container) {
    if (modeSel(container).value !== 'rules') return null; // pass all
    var rules = [];
    container.querySelectorAll('.frule').forEach(function (div) {
      var cls = div.querySelector('.fcls').value;
      var match = div.querySelector('.fmatch').value.trim().toUpperCase();
      if (cls === 'custom' && !match) return; // incomplete custom rule: skip
      var r = { action: div.querySelector('.faction').value, 'class': cls };
      if (cls === 'custom') r.match = match;
      var src = div.querySelector('.fsrc').value.trim();
      if (src) r.src = src;
      var lim = parseInt(div.querySelector('.flimit').value, 10);
      if (r.action === 'pass' && lim > 0) r.limit_s = lim;
      rules.push(r);
    });
    return rules.length ? { rules: rules } : null;
  }

  function filterSummary(v) {
    if (v === null || v === undefined || v === '') return 'Pass All';
    if (typeof v === 'string') return 'Legacy rules';
    if (v && Array.isArray(v.rules)) return v.rules.length ? 'Rules (' + v.rules.length + ')' : 'Pass All';
    return 'Rules';
  }

  document.querySelectorAll('[data-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      ruleRow($(btn.getAttribute('data-add')), { action: 'pass', cls: 'navigation', match: '', src: '', limit_s: 0 });
    });
  });
  document.querySelectorAll('.fmode').forEach(function (sel) {
    sel.addEventListener('change', function () {
      syncMode($(sel.id.replace('-mode', '-rules')));
    });
  });

  // ---------------- type-aware form ----------------

  function syncType() {
    var t = $('f-type').value;
    document.querySelectorAll('.typed').forEach(function (el) {
      el.style.display = el.getAttribute('data-type') === t ? '' : 'none';
    });
    if (t === 'tcp_server' && $('f-direction').value === 'in') $('f-direction').value = 'both';
    udpHint();
  }

  function udpHint() {
    var el = $('udp-mode-hint');
    if (!el) return;
    var addr = ($('f-udp-addr').value || '').trim();
    var parts = addr.split('.').map(Number);
    var mode = '';
    if (parts.length === 4 && parts.every(function (p) { return p >= 0 && p <= 255; })) {
      if (parts[0] >= 224 && parts[0] <= 239) mode = 'multicast';
      else if (addr === '255.255.255.255' || parts[3] === 255) mode = 'broadcast';
      else mode = 'unicast';
    }
    el.textContent = mode ? 'Address classified as: ' + mode : '';
  }

  function blankForm() {
    $('f-orig').value = '';
    $('formtitle').textContent = 'Add interface';
    $('f-name').value = ''; $('f-name').disabled = false;
    $('f-type').value = 'serial';
    $('f-direction').value = 'in';
    $('f-enabled').checked = true;
    $('f-comment').value = '';
    $('f-device').value = ''; $('f-baud').value = '4800';
    $('f-ts-addr').value = '0.0.0.0'; $('f-ts-port').value = '10110';
    $('f-tc-addr').value = ''; $('f-tc-port').value = '10110';
    $('f-tc-persist').checked = true; $('f-tc-retry').value = '10';
    $('f-tc-nodelay').checked = true; $('f-tc-keepalive').checked = true;
    $('f-tc-preamble').value = '';
    $('f-udp-addr').value = ''; $('f-udp-port').value = '10110'; $('f-udp-coalesce').checked = false;
    $('f-checksum').value = ''; $('f-strict').value = '';
    $('f-srctag').value = 'no'; $('f-timestamp').value = 'no';
    $('f-qsize').value = '0';
    $('f-loopback').checked = false; $('f-optional').checked = true;
    setFilter($('ifilter-rules'), null);
    setFilter($('ofilter-rules'), null);
    $('f-msg').textContent = '';
    syncType();
  }

  function fillForm(it) {
    blankForm();
    $('f-orig').value = it.name;
    $('formtitle').textContent = 'Edit interface "' + it.name + '"';
    $('f-name').value = it.name;
    $('f-type').value = it.type;
    $('f-direction').value = it.direction || (it.type === 'tcp_server' ? 'both' : 'in');
    $('f-enabled').checked = it.enabled !== false;
    $('f-comment').value = it.comment || '';
    if (it.type === 'serial') {
      $('f-device').value = it.device || '';
      $('f-baud').value = String(it.baud || 4800);
    } else if (it.type === 'tcp_server') {
      $('f-ts-addr').value = it.address || '0.0.0.0';
      $('f-ts-port').value = it.port || 10110;
    } else if (it.type === 'tcp_client') {
      $('f-tc-addr').value = it.address || '';
      $('f-tc-port').value = it.port || 10110;
      $('f-tc-persist').checked = it.persist !== false;
      $('f-tc-retry').value = it.retry || 10;
      $('f-tc-nodelay').checked = it.nodelay !== false;
      $('f-tc-keepalive').checked = it.keepalive !== false;
      $('f-tc-preamble').value = it.preamble || '';
    } else if (it.type === 'udp') {
      $('f-udp-addr').value = it.address || '';
      $('f-udp-port').value = it.port || 10110;
      $('f-udp-coalesce').checked = !!it.coalesce;
    }
    $('f-checksum').value = it.checksum === undefined || it.checksum === null ? '' : (it.checksum ? '1' : '0');
    $('f-strict').value = it.strict === undefined || it.strict === null ? '' : (it.strict ? '1' : '0');
    $('f-srctag').value = it.srctag || 'no';
    $('f-timestamp').value = it.timestamp || 'no';
    $('f-qsize').value = it.qsize || 0;
    $('f-loopback').checked = !!it.loopback;
    $('f-optional').checked = it.optional !== false;
    setFilter($('ifilter-rules'), it.ifilter);
    setFilter($('ofilter-rules'), it.ofilter);
    syncType();
    $('formcard').scrollIntoView({ behavior: 'smooth' });
  }

  function gather() {
    var t = $('f-type').value;
    var def = {
      name: $('f-name').value.trim(),
      type: t,
      direction: $('f-direction').value,
      enabled: $('f-enabled').checked,
      optional: $('f-optional').checked,
      loopback: $('f-loopback').checked,
      comment: $('f-comment').value.trim(),
      qsize: parseInt($('f-qsize').value, 10) || 0,
      srctag: $('f-srctag').value,
      timestamp: $('f-timestamp').value,
      ifilter: gatherFilter($('ifilter-rules')),
      ofilter: gatherFilter($('ofilter-rules'))
    };
    if ($('f-checksum').value !== '') def.checksum = $('f-checksum').value === '1';
    if ($('f-strict').value !== '') def.strict = $('f-strict').value === '1';
    if (t === 'serial') {
      def.device = $('f-device').value.trim();
      def.baud = parseInt($('f-baud').value, 10);
    } else if (t === 'tcp_server') {
      def.address = $('f-ts-addr').value.trim();
      def.port = parseInt($('f-ts-port').value, 10);
    } else if (t === 'tcp_client') {
      def.address = $('f-tc-addr').value.trim();
      def.port = parseInt($('f-tc-port').value, 10);
      def.persist = $('f-tc-persist').checked;
      def.retry = parseInt($('f-tc-retry').value, 10) || 10;
      def.nodelay = $('f-tc-nodelay').checked;
      def.keepalive = $('f-tc-keepalive').checked;
      def.preamble = $('f-tc-preamble').value;
    } else if (t === 'udp') {
      def.address = $('f-udp-addr').value.trim();
      def.port = parseInt($('f-udp-port').value, 10);
      def.coalesce = $('f-udp-coalesce').checked;
    }
    return def;
  }

  function msg(text, cls) {
    var el = $('f-msg');
    el.textContent = text;
    el.className = 'form-msg ' + (cls || '');
  }

  // ---------------- events ----------------

  if (IS_ADMIN) {
    $('f-type').addEventListener('change', syncType);
    $('f-udp-addr').addEventListener('input', udpHint);
    $('btn-add').addEventListener('click', function () {
      blankForm();
      $('formcard').scrollIntoView({ behavior: 'smooth' });
      $('f-name').focus();
    });
    $('f-cancel').addEventListener('click', blankForm);
    $('f-save').addEventListener('click', function () {
      msg('Saving...');
      window.XAN.post('save_interface', {
        orig_name: $('f-orig').value,
        interface: gather()
      }).then(function (r) {
        if (r.ok) { msg(r.note || 'Saved.', 'ok'); setTimeout(function () { location.reload(); }, 800); }
        else msg(r.error || 'Save failed', 'err');
      }).catch(function () { msg('Request failed', 'err'); });
    });
  }

  document.querySelector('#iflist').addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t.getAttribute) return;
    var edit = t.getAttribute('data-edit'), del = t.getAttribute('data-del'), tog = t.getAttribute('data-toggle');
    if (edit) {
      var it = IFACES.filter(function (x) { return x.name === edit; })[0];
      if (it) fillForm(JSON.parse(JSON.stringify(it)));
    } else if (del) {
      if (!confirm('Delete interface "' + del + '"?')) return;
      window.XAN.post('delete_interface', { name: del }).then(function (r) {
        if (r.ok) location.reload();
        else alert(r.error || 'Delete failed');
      });
    } else if (tog) {
      var cur = IFACES.filter(function (x) { return x.name === tog; })[0];
      window.XAN.post('toggle_interface', { name: tog, enabled: !cur || cur.enabled === false }).then(function (r) {
        if (r.ok) location.reload();
        else alert(r.error || 'Toggle failed');
      });
    }
  });

  renderList();
  if (IS_ADMIN) { blankForm(); }
})();
</script>
<?php
page_footer();
