<?php
ob_start();
// =============================================================================
//  admin/login.php — Formulaire de connexion admin
//
//  Flux de traitement :
//    - GET  : affiche le formulaire (avec compte à rebours si IP bloquée)
//    - POST : tente la connexion via Auth::login()
//              → succès : redirect vers /admin/
//              → échec  : affiche le message d'erreur + tentatives restantes
//
//  Le rate-limiting est géré par Auth / Security (5 tentatives / 15 min).
//  En cas de blocage, un compte à rebours JavaScript s'affiche.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';
// Session déjà démarrée par bootstrap → Lang::init()

$auth = new Auth($config);

// Déjà connecté → aller directement à l'admin
if ($auth->isLoggedIn()) {
    redirect(url('/admin/'));
}

$error    = '';
$lockInfo = $auth->getRateLimitStatus(); // état initial du rate-limit

// ── Traitement du POST ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Re-vérifier le rate-limit en POST pour éviter les race conditions
    $lockInfo = $auth->getRateLimitStatus();

    if ($lockInfo['locked']) {
        $wait  = (int) ceil($lockInfo['wait_seconds'] / 60);
        $error = __('login_too_many', ['%min%' => $wait]);
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password']     ?? '';

        if ($auth->login($username, $password)) {
            redirect(url('/admin/'));
        } else {
            // Rafraîchir l'état après l'échec (le compteur vient d'être incrémenté)
            $lockInfo  = $auth->getRateLimitStatus();
            $remaining = $lockInfo['remaining'];

            $error = $remaining > 0
                ? __('login_error_creds', ['%remaining%' => $remaining])
                : __('login_error_locked');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __('login_title') ?> — Badal</title>
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

  /* Dégradés décoratifs en arrière-plan */
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

  /* ── En-tête marque ─────────────────────────────────────────────────────── */
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

  /* ── Carte formulaire ───────────────────────────────────────────────────── */
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

  input[type="text"],
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

  .error {
    background: rgba(255,80,80,.1);
    border: 1px solid rgba(255,80,80,.3);
    border-radius: 8px;
    color: #ff8080;
    font-size: .85rem;
    padding: .75rem 1rem;
    margin-bottom: 1.25rem;
    text-align: center;
  }
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
    <p><?= __('login_subtitle') ?></p>
  </div>

  <div class="card">

    <?php if ($lockInfo['locked']): ?>
      <!-- Compte à rebours JavaScript affiché quand l'IP est bloquée -->
      <div class="error" style="display:flex;align-items:center;gap:.5rem">
        <span>🔒</span>
        <span><?= __('login_locked') ?> <strong id="countdown"></strong></span>
      </div>
      <script>
        let secs = <?= (int) $lockInfo['wait_seconds'] ?>;
        const el = document.getElementById('countdown');
        (function tick() {
          if (secs <= 0) {
            el.closest('.error').textContent = __('login_retry');
            return;
          }
          const m = Math.floor(secs / 60), s = secs % 60;
          el.textContent = (m > 0 ? m + 'min ' : '') + s + 's';
          secs--;
          setTimeout(tick, 1000);
        })();
      </script>

    <?php elseif ($error): ?>
      <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/admin/login.php') ?>">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

      <div class="field">
        <label for="username"><?= __('login_username') ?></label>
        <input type="text" id="username" name="username"
               autocomplete="username" autofocus required>
      </div>

      <div class="field">
        <label for="password"><?= __('login_password') ?></label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
      </div>

      <button type="submit" class="btn" <?= $lockInfo['locked'] ? 'disabled' : '' ?>>
        <?= __('login_submit') ?>
      </button>
    </form>

    <a href="<?= url('/admin/forgot_password.php') ?>" style="display:block;text-align:center;margin-top:1.25rem;font-size:.82rem;color:var(--muted);text-decoration:none;transition:color .15s" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--muted)'"><?= __('forgot_link') ?></a>

  </div>
</div>


<script>document.addEventListener('DOMContentLoaded',function(){if(window.feather)feather.replace({'stroke-width':2});});</script>
</body>
</html>
