<?php
ob_start();
// =============================================================================
//  admin/forgot_password.php — Demande de réinitialisation de mot de passe
//
//  Flux :
//    - GET  : affiche le formulaire (champ email)
//    - POST : vérifie l'email, crée un token, envoie un lien par email
//
//  Sécurité :
//    - Rate-limit : 3 demandes par IP par heure
//    - Message identique que l'email soit valide ou non (anti-énumération)
//    - Token SHA-256 hashé, durée de vie 30 minutes
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);

// Déjà connecté → aller à l'admin
if ($auth->isLoggedIn()) {
    redirect(url('/admin/'));
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message     = __('forgot_invalid');
        $messageType = 'error';
    } else {
        // Rate-limit
        $rateCheck = $auth->checkResetRateLimit();
        if (!$rateCheck['allowed']) {
            $message     = __('forgot_too_many', ['%min%' => $rateCheck['wait_minutes']]);
            $messageType = 'error';
        } else {
            // Toujours afficher le même message (anti-énumération)
            $message     = __('forgot_sent');
            $messageType = 'success';

            // Vérifier si l'email correspond à celui du config
            $configEmail = $config['email'] ?? '';
            if ($configEmail && strtolower($email) === strtolower($configEmail)) {
                $token    = $auth->createResetToken();
                $baseUrl  = rtrim($config['base_url'] ?? '', '/');
                $resetUrl = $baseUrl . '/admin/reset_password.php?token=' . $token;

                $subject = __('reset_email_subject');
                $podcastName = $config['podcast_title'] ?? 'Badal';

                $body  = "$podcastName\n";
                $body .= str_repeat('─', 40) . "\n\n";
                $body .= __('reset_subtitle') . "\n\n";
                $body .= $resetUrl . "\n\n";
                $body .= str_repeat('─', 40) . "\n";
                $body .= "⏱ " . __('reset_invalid') . "\n";

                $headers  = "From: noreply@" . parse_url($baseUrl, PHP_URL_HOST) . "\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $headers .= "X-Mailer: Badal CMS\r\n";

                @mail($email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __('forgot_title') ?> — Badal</title>
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

  input[type="email"] {
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
    <p><?= __('forgot_subtitle') ?></p>
  </div>

  <div class="card">

    <?php if ($message): ?>
      <div class="msg msg-<?= $messageType ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($messageType !== 'success'): ?>
      <form method="POST" action="<?= url('/admin/forgot_password.php') ?>">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="field">
          <label for="email"><?= __('forgot_email') ?></label>
          <input type="email" id="email" name="email"
                 autocomplete="email" autofocus required
                 placeholder="contact@example.com">
        </div>

        <button type="submit" class="btn">
          <?= __('forgot_submit') ?>
        </button>
      </form>
    <?php endif; ?>

  </div>

  <a href="<?= url('/admin/login.php') ?>" class="back-link"><?= __('forgot_back') ?></a>

</div>

<script>document.addEventListener('DOMContentLoaded',function(){if(window.feather)feather.replace({'stroke-width':2});});</script>
</body>
</html>
