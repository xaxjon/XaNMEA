<?php
declare(strict_types=1);

/**
 * login.php: session login against config users[] via password_verify().
 * If no usable users exist, shows the first-run screen that creates the
 * initial admin account (bcrypt hash written into the shared config file).
 */

require __DIR__ . '/lib/common.php';

$error = '';
$cfg = xan_load_raw_config();
$firstRun = !xan_has_valid_users($cfg);

// Already logged in? Go home (but still allow first-run to proceed).
if (!$firstRun && xan_current_user() !== null) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Session expired - try again';
    } elseif ($firstRun) {
        // First-run: create the initial admin user.
        $username = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $username)) {
            $error = 'Username: 1-32 chars of [A-Za-z0-9_.-]';
        } elseif (strlen($pass) < 8) {
            $error = 'Password must be at least 8 characters';
        } elseif ($pass !== $pass2) {
            $error = 'Passwords do not match';
        } else {
            $cfg['users'] = [[
                'username' => $username,
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                'role' => 'admin',
            ]];
            $res = xan_save_config($cfg);
            if ($res !== true) {
                $error = 'Cannot write config: ' . $res;
            } else {
                session_regenerate_id(true);
                $_SESSION['xan_user'] = $username;
                $_SESSION['xan_role'] = 'admin';
                header('Location: index.php');
                exit;
            }
        }
    } else {
        // Normal login.
        $username = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $ok = false;
        foreach ($cfg['users'] ?? [] as $u) {
            if (!is_array($u)) {
                continue;
            }
            if (strcasecmp((string)($u['username'] ?? ''), $username) === 0
                && password_verify($pass, (string)($u['password_hash'] ?? ''))) {
                session_regenerate_id(true);
                $_SESSION['xan_user'] = (string)$u['username'];
                $_SESSION['xan_role'] = in_array($u['role'] ?? '', ['admin', 'viewer'], true) ? $u['role'] : 'viewer';
                $ok = true;
                break;
            }
        }
        if ($ok) {
            header('Location: index.php');
            exit;
        }
        $error = 'Invalid username or password';
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>XaNMEA - Login</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-box">
  <a class="login-logo" href="https://www.xaxero.com" target="_blank" rel="noopener" title="Xaxero Software Engineering">
    <img src="assets/xaxero-logo.png" alt="Xaxero">
  </a>
  <div class="brandbig">Xa<span>NMEA</span></div>
  <?php if ($firstRun): ?>
    <h1>First run - create admin account</h1>
    <p class="dim">No usable users found in the config. Create the initial
    administrator account; it is written to the shared daemon config file.</p>
    <?php if ($error !== ''): ?><div class="form-msg err"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label class="f" for="u">Username</label>
      <input type="text" id="u" name="username" autocomplete="username" required>
      <label class="f" for="p">Password</label>
      <input type="password" id="p" name="password" autocomplete="new-password" required>
      <label class="f" for="p2">Password (repeat)</label>
      <input type="password" id="p2" name="password2" autocomplete="new-password" required>
      <button class="btn primary" type="submit">Create admin</button>
    </form>
  <?php else: ?>
    <h1>Sign in</h1>
    <?php if ($error !== ''): ?><div class="form-msg err"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label class="f" for="u">Username</label>
      <input type="text" id="u" name="username" autocomplete="username" autofocus required>
      <label class="f" for="p">Password</label>
      <input type="password" id="p" name="password" autocomplete="current-password" required>
      <button class="btn primary" type="submit">Sign in</button>
    </form>
  <?php endif; ?>
</div>
<footer class="page-footer">&copy; <?= date('Y') ?> <a href="https://www.xaxero.com" target="_blank" rel="noopener">Xaxero Marine Software Engineering</a></footer>
</body>
</html>
