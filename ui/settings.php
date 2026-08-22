<?php
declare(strict_types=1);

/**
 * settings.php: units info, daemon settings editor (admin), change own
 * password, add user (admin), config backup / restore.
 */

require __DIR__ . '/lib/common.php';
$user = requireLogin();
$cfg = xan_load_raw_config();
$d = is_array($cfg['daemon']) ? $cfg['daemon'] : [];
$isAdmin = $user['role'] === 'admin';

function dval(array $d, string $k, $def) { return $d[$k] ?? $def; }

page_header('Settings', 'settings');
?>
<h1>Settings</h1>

<div class="grid cols-2">

  <div class="card">
    <h2>Units</h2>
    <p class="dim">Fixed in v1: speed in knots (kn), temperature in &deg;C,
    pressure in millibar (mbar), depth in metres (m), distance in nautical miles (NM).</p>
    <h2>Config file</h2>
    <p class="mono dim" style="word-break:break-all"><?= h(xan_config_path()) ?></p>
  </div>

  <div class="card">
    <h2>Change my password</h2>
    <label class="f" for="pw-cur">Current password</label>
    <input type="password" id="pw-cur" autocomplete="current-password">
    <label class="f" for="pw-new">New password (min 8 chars)</label>
    <input type="password" id="pw-new" autocomplete="new-password">
    <button class="btn" id="pw-save" type="button">Change password</button>
    <div class="form-msg" id="pw-msg"></div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="card">
    <h2>Daemon settings</h2>
    <div class="flex">
      <div class="grow">
        <label class="f" for="d-log">Log level</label>
        <select id="d-log">
          <?php foreach (['debug', 'info', 'notice', 'warning', 'error'] as $lv): ?>
          <option value="<?= $lv ?>"<?= dval($d, 'log_level', 'info') === $lv ? ' selected' : '' ?>><?= $lv ?></option>
          <?php endforeach; ?>
        </select>
        <label class="f"><input type="checkbox" id="d-checksum"<?= dval($d, 'checksum', true) ? ' checked' : '' ?>> Enforce checksums</label>
        <label class="f"><input type="checkbox" id="d-strict"<?= dval($d, 'strict', false) ? ' checked' : '' ?>> Strict parsing</label>
        <label class="f"><input type="checkbox" id="d-syslog"<?= dval($d, 'syslog', false) ? ' checked' : '' ?>> Log to syslog</label>
      </div>
      <div class="grow">
        <label class="f" for="d-qsize">Output queue size (&ge;10)</label>
        <input type="number" id="d-qsize" min="10" value="<?= (int)dval($d, 'qsize', 200) ?>">
        <label class="f" for="d-sqsize">Stream queue size (&ge;50)</label>
        <input type="number" id="d-sqsize" min="50" value="<?= (int)dval($d, 'stream_qsize', 500) ?>">
        <label class="f" for="d-aimax">AIS max targets (&ge;10)</label>
        <input type="number" id="d-aimax" min="10" value="<?= (int)dval($d, 'ais_max_targets', 500) ?>">
        <label class="f" for="d-aistale">AIS stale after seconds (&ge;10)</label>
        <input type="number" id="d-aistale" min="10" value="<?= (int)dval($d, 'ais_stale_sec', 60) ?>">
        <label class="f" for="d-aidrop">AIS drop after seconds (&ge;60)</label>
        <input type="number" id="d-aidrop" min="60" value="<?= (int)dval($d, 'ais_drop_sec', 600) ?>">
      </div>
    </div>
    <button class="btn primary" id="d-save" type="button">Save &amp; reload</button>
    <button class="btn" id="d-reload" type="button">Reload daemon</button>
    <div class="form-msg" id="d-msg"></div>
  </div>

  <div class="card">
    <h2>Add user</h2>
    <label class="f" for="nu-name">Username</label>
    <input type="text" id="nu-name" autocomplete="off">
    <label class="f" for="nu-pass">Password (min 8 chars)</label>
    <input type="password" id="nu-pass" autocomplete="new-password">
    <label class="f" for="nu-role">Role</label>
    <select id="nu-role">
      <option value="viewer">viewer (read-only)</option>
      <option value="admin">admin</option>
    </select>
    <button class="btn" id="nu-save" type="button">Add user</button>
    <div class="form-msg" id="nu-msg"></div>
  </div>

  <div class="card">
    <h2>Backup &amp; restore</h2>
    <p class="dim">Download the current shared config, or upload a previously
    saved one. Restore validates the file, writes it atomically and asks the
    daemon to reload.</p>
    <a class="btn" href="api.php?action=backup">Download config</a>
    <label class="f" for="restore-file">Restore from file</label>
    <input type="file" id="restore-file" accept=".json,application/json">
    <button class="btn danger" id="restore-btn" type="button">Restore &amp; reload</button>
    <div class="form-msg" id="restore-msg"></div>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  'use strict';

  function msg(id, text, cls) {
    var el = document.getElementById(id);
    el.textContent = text;
    el.className = 'form-msg ' + (cls || '');
  }

  document.getElementById('pw-save').addEventListener('click', function () {
    msg('pw-msg', 'Working...');
    window.XAN.post('change_password', {
      current: document.getElementById('pw-cur').value,
      'new': document.getElementById('pw-new').value
    }).then(function (r) {
      msg('pw-msg', r.ok ? (r.note || 'Done.') : (r.error || 'Failed'), r.ok ? 'ok' : 'err');
    });
  });

  <?php if ($isAdmin): ?>
  document.getElementById('d-save').addEventListener('click', function () {
    msg('d-msg', 'Saving...');
    window.XAN.post('save_settings', {
      daemon: {
        log_level: document.getElementById('d-log').value,
        checksum: document.getElementById('d-checksum').checked,
        strict: document.getElementById('d-strict').checked,
        syslog: document.getElementById('d-syslog').checked,
        qsize: parseInt(document.getElementById('d-qsize').value, 10),
        stream_qsize: parseInt(document.getElementById('d-sqsize').value, 10),
        ais_max_targets: parseInt(document.getElementById('d-aimax').value, 10),
        ais_stale_sec: parseInt(document.getElementById('d-aistale').value, 10),
        ais_drop_sec: parseInt(document.getElementById('d-aidrop').value, 10)
      }
    }).then(function (r) {
      msg('d-msg', r.ok ? (r.note || 'Saved.') : (r.error || 'Failed'), r.ok ? 'ok' : 'err');
    });
  });

  document.getElementById('d-reload').addEventListener('click', function () {
    msg('d-msg', 'Reloading...');
    window.XAN.post('reload', {}).then(function (r) {
      msg('d-msg', r.ok ? (r.note || 'Reload scheduled.') : (r.error || 'Failed'), r.ok ? 'ok' : 'err');
    });
  });

  document.getElementById('nu-save').addEventListener('click', function () {
    msg('nu-msg', 'Working...');
    window.XAN.post('add_user', {
      username: document.getElementById('nu-name').value,
      password: document.getElementById('nu-pass').value,
      role: document.getElementById('nu-role').value
    }).then(function (r) {
      msg('nu-msg', r.ok ? (r.note || 'Done.') : (r.error || 'Failed'), r.ok ? 'ok' : 'err');
    });
  });

  document.getElementById('restore-btn').addEventListener('click', function () {
    var f = document.getElementById('restore-file').files[0];
    if (!f) { msg('restore-msg', 'Choose a file first', 'err'); return; }
    if (!confirm('Restore config from "' + f.name + '"? This overwrites the current config.')) return;
    var reader = new FileReader();
    reader.onload = function () {
      msg('restore-msg', 'Uploading...');
      window.XAN.post('restore_config', { config: String(reader.result) }).then(function (r) {
        msg('restore-msg', r.ok ? (r.note || 'Restored.') : (r.error || 'Failed'), r.ok ? 'ok' : 'err');
      });
    };
    reader.readAsText(f);
  });
  <?php endif; ?>
})();
</script>
<?php
page_footer();
