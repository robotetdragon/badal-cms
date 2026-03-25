<?php
ob_start();
// =============================================================================
//  admin/reset_password.php — New password form
//
//  Flow:
//    - GET  : verifies the token, displays the form if valid
//    - POST : verifies the token + fields, updates the hash, consumes the token
//
//  Security:
//    - Token verified via SHA-256 + constant-time comparison
//    - Single-use token (deleted after use)
//    - All sessions are invalidated after the change
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);

// Already logged in → go to admin
if ($auth->isLoggedIn()) {
    redirect(url('/admin/'));
}

$token   = $_GET['token'] ?? $_POST['token'] ?? '';
$error   = '';
$success = '';

// Verify that the token is valid (GET and POST)
$tokenValid = $auth->verifyResetToken($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    csrf_check();

    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_new']  ?? '';

    if (strlen($newPass) < 8) {
        $error = __('reset_too_short');
    } elseif ($newPass !== $confirm) {
        $error = __('reset_mismatch');
    } else {
        // Update the password
        $configFile = ROOT_DIR . '/config/config.php';
        $newHash    = Auth::hashPassword($newPass);

        $content = file_get_contents($configFile);
        $content = preg_replace(
            "/('admin_password_hash'\s*=>\s*)'[^']*'/",
            "'admin_password_hash' => '" . addslashes($newHash) . "'",
            $content
        );
        file_put_contents($configFile, $content);

        // Consume the token
        $auth->consumeResetToken();

        // Destroy all active sessions
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $success = __('reset_success');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __('reset_title') ?> — Badal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:         #0d0d0f;
    --surface:    #16161a;
    --border:     #2a2a30;
    --accent:     #e8ff5a;
    --accent-dim: #b8cc3a;
    --text:       #f0ede8;
    --muted:      #888;
  }

  body {
    font-family: 'Syne', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
  }

  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
      radial-gradient(ellipse 60% 50% at 20% 80%, rgba(232,255,90,.06) 0%, transparent 60%),
      radial-gradient(ellipse 40% 40% at 80% 20%, rgba(232,255,90,.04) 0%, transparent 60%);
    pointer-events: none;
  }

  .login-wrap {
    width: 100%;
    max-width: 400px;
    padding: 2rem;
    animation: fadeUp .5s ease both;
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .brand { text-align: center; margin-bottom: 2.5rem; }

  .brand-icon {
    width: 56px; height: 56px;
    background: var(--accent);
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
  }

  .brand-icon svg { width: 28px; height: 28px; }
  .brand h1 { font-size: 1.6rem; font-weight: 800; letter-spacing: -.02em; }
  .brand p  { font-size: .85rem; color: var(--muted); margin-top: .3rem; font-family: 'Instrument Serif', serif; font-style: italic; }

  .card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 2rem; }

  .field { margin-bottom: 1.25rem; }

  label {
    display: block;
    font-size: .75rem;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: .5rem;
  }

  input[type="password"] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-family: 'Syne', sans-serif;
    font-size: 1rem;
    padding: .75rem 1rem;
    outline: none;
    transition: border-color .2s;
  }

  input:focus { border-color: var(--accent); }

  .btn {
    width: 100%;
    background: var(--accent);
    color: #0d0d0f;
    border: none;
    border-radius: 8px;
    font-family: 'Syne', sans-serif;
    font-size: .95rem;
    font-weight: 700;
    padding: .85rem;
    cursor: pointer;
    margin-top: .5rem;
    transition: background .2s, transform .15s;
  }

  .btn:hover  { background: var(--accent-dim); transform: translateY(-1px); }
  .btn:active { transform: translateY(0); }

  .msg {
    border-radius: 8px;
    font-size: .85rem;
    padding: .75rem 1rem;
    margin-bottom: 1.25rem;
    text-align: center;
  }

  .msg-error {
    background: rgba(255,80,80,.1);
    border: 1px solid rgba(255,80,80,.3);
    color: #ff8080;
  }

  .msg-success {
    background: rgba(90,255,154,.08);
    border: 1px solid rgba(90,255,154,.25);
    color: #5aff9a;
  }

  .back-link {
    display: block;
    text-align: center;
    margin-top: 1.5rem;
    font-size: .85rem;
    color: var(--muted);
    text-decoration: none;
    transition: color .15s;
  }

  .back-link:hover { color: var(--accent); }
</style>
<script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body>

<div class="login-wrap">

  <div class="brand">
    <div class="brand-icon">
      <i data-feather="sun" style="width:20px;height:20px;stroke:#0d0d0f;stroke-width:2.5"></i>
    </div>
    <h1>Badal</h1>
    <p><?= __('reset_subtitle') ?></p>
  </div>

  <div class="card">

    <?php if ($success): ?>
      <div class="msg msg-success"><?= e($success) ?></div>

    <?php elseif (!$tokenValid): ?>
      <div class="msg msg-error"><?= e(__('reset_invalid')) ?></div>

    <?php else: ?>

      <?php if ($error): ?>
        <div class="msg msg-error"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="<?= url('/admin/reset_password.php') ?>">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="field">
          <label for="new_password"><?= __('reset_password') ?></label>
          <input type="password" id="new_password" name="new_password"
                 autocomplete="new-password" autofocus required
                 placeholder="••••••••" minlength="8">
        </div>

        <div class="field">
          <label for="confirm_new"><?= __('reset_confirm') ?></label>
          <input type="password" id="confirm_new" name="confirm_new"
                 autocomplete="new-password" required
                 placeholder="••••••••">
        </div>

        <button type="submit" class="btn">
          <?= __('reset_submit') ?>
        </button>
      </form>

    <?php endif; ?>

  </div>

  <a href="<?= url('/admin/login.php') ?>" class="back-link"><?= __('forgot_back') ?></a>

</div>

<script>document.addEventListener('DOMContentLoaded',function(){if(window.feather)feather.replace({'stroke-width':2});});</script>
</body>
</html>
