<?php
// =============================================================================
//  setup.php — Badal installation
//  Detects the base URL, asks for login/password + language, writes config/config.php
// =============================================================================

// Base URL detection
$proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path    = dirname($_SERVER['SCRIPT_NAME'] ?? '/setup.php');
$baseUrl = rtrim($proto . '://' . $host . rtrim($path, '/'), '/');

$configFile = __DIR__ . '/config/config.php';

// Block if already installed — immediate redirect (not just a warning)
if (file_exists($configFile)) {
    $existing = @include $configFile;
    if (is_array($existing) && !empty($existing['admin_password_hash'])) {
        header('Location: ' . rtrim($baseUrl, '/') . '/admin/');
        exit;
    }
}

// Setup translations
$T = [
    'fr' => [
        'flag' => '🇫🇷',
        'name' => 'Français',
        'title' => 'Installation',
        'subtitle' => 'Créez votre compte administrateur',
        'label_user' => 'Identifiant',
        'label_pass' => 'Mot de passe',
        'label_pass2' => 'Confirmer',
        'hint_pass' => 'Minimum 8 caractères',
        'btn' => 'Installer →',
        'err_user' => 'Identifiant invalide (3-32 caractères alphanumériques).',
        'err_short' => 'Le mot de passe doit faire au moins 8 caractères.',
        'err_match' => 'Les deux mots de passe ne correspondent pas.',
        'err_write' => 'Impossible d\'écrire config/config.php — vérifiez les permissions.',
        'success_title' => 'Installation réussie !',
        'success_desc' => 'Badal est prêt.<br>Connectez-vous et complétez les infos de votre podcast.',
        'btn_admin' => 'Accéder à l\'admin →',
        'note' => 'Pensez à supprimer <code>setup.php</code> du serveur.',
        'already_title' => 'Déjà installé',
        'already_desc' => 'Badal est déjà configuré.<br>Supprimez <code>config/config.php</code> pour réinstaller.',
        'lang_label' => 'Langue de l\'interface',
        'rss_lang' => 'fr-FR',
        'podcast_title' => 'Mon Podcast',
        'podcast_desc' => 'Bienvenue sur mon podcast.',
    ],
    'en' => [
        'flag' => '🇬🇧',
        'name' => 'English',
        'title' => 'Installation',
        'subtitle' => 'Create your admin account',
        'label_user' => 'Username',
        'label_pass' => 'Password',
        'label_pass2' => 'Confirm',
        'hint_pass' => 'Minimum 8 characters',
        'btn' => 'Install →',
        'err_user' => 'Invalid username (3-32 alphanumeric characters).',
        'err_short' => 'Password must be at least 8 characters.',
        'err_match' => 'Passwords do not match.',
        'err_write' => 'Unable to write config/config.php — check permissions.',
        'success_title' => 'Installation successful!',
        'success_desc' => 'Badal is ready.<br>Log in and fill in your podcast info.',
        'btn_admin' => 'Go to admin →',
        'note' => 'Remember to delete <code>setup.php</code> from your server.',
        'already_title' => 'Already installed',
        'already_desc' => 'Badal is already configured.<br>Delete <code>config/config.php</code> to reinstall.',
        'lang_label' => 'Interface language',
        'rss_lang' => 'en-US',
        'podcast_title' => 'My Podcast',
        'podcast_desc' => 'Welcome to my podcast.',
    ],
    'es' => [
        'flag' => '🇪🇸',
        'name' => 'Español',
        'title' => 'Instalación',
        'subtitle' => 'Crea tu cuenta de administrador',
        'label_user' => 'Usuario',
        'label_pass' => 'Contraseña',
        'label_pass2' => 'Confirmar',
        'hint_pass' => 'Mínimo 8 caracteres',
        'btn' => 'Instalar →',
        'err_user' => 'Usuario inválido (3-32 caracteres alfanuméricos).',
        'err_short' => 'La contraseña debe tener al menos 8 caracteres.',
        'err_match' => 'Las contraseñas no coinciden.',
        'err_write' => 'No se puede escribir config/config.php — comprueba los permisos.',
        'success_title' => '¡Instalación correcta!',
        'success_desc' => 'Badal está listo.<br>Inicia sesión y completa la info de tu podcast.',
        'btn_admin' => 'Ir al panel →',
        'note' => 'Recuerda eliminar <code>setup.php</code> del servidor.',
        'already_title' => 'Ya instalado',
        'already_desc' => 'Badal ya está configurado.<br>Elimina <code>config/config.php</code> para reinstalar.',
        'lang_label' => 'Idioma de la interfaz',
        'rss_lang' => 'es-ES',
        'podcast_title' => 'Mi Podcast',
        'podcast_desc' => 'Bienvenido a mi podcast.',
    ],
    'pt' => [
        'flag' => '🇧🇷',
        'name' => 'Português',
        'title' => 'Instalação',
        'subtitle' => 'Crie sua conta de administrador',
        'label_user' => 'Usuário',
        'label_pass' => 'Senha',
        'label_pass2' => 'Confirmar',
        'hint_pass' => 'Mínimo 8 caracteres',
        'btn' => 'Instalar →',
        'err_user' => 'Usuário inválido (3-32 caracteres alfanuméricos).',
        'err_short' => 'A senha deve ter pelo menos 8 caracteres.',
        'err_match' => 'As senhas não coincidem.',
        'err_write' => 'Não foi possível escrever config/config.php — verifique as permissões.',
        'success_title' => 'Instalação concluída!',
        'success_desc' => 'Badal está pronto.<br>Faça login e preencha as informações do seu podcast.',
        'btn_admin' => 'Acessar o painel →',
        'note' => 'Lembre-se de excluir <code>setup.php</code> do servidor.',
        'already_title' => 'Já instalado',
        'already_desc' => 'Badal já está configurado.<br>Exclua <code>config/config.php</code> para reinstalar.',
        'lang_label' => 'Idioma da interface',
        'rss_lang' => 'pt-BR',
        'podcast_title' => 'Meu Podcast',
        'podcast_desc' => 'Bem-vindo ao meu podcast.',
    ],
];
// Config data per language
$LANG_DATA = [
    'fr' => [
        'rss_lang' => 'fr-FR',
        'podcast_title' => 'Mon Podcast',
        'podcast_desc' => 'Bienvenue sur mon podcast.',
    ],
    'en' => [
        'rss_lang' => 'en-US',
        'podcast_title' => 'My Podcast',
        'podcast_desc' => 'Welcome to my podcast.',
    ],
    'es' => [
        'rss_lang' => 'es-ES',
        'podcast_title' => 'Mi Podcast',
        'podcast_desc' => 'Bienvenido a mi podcast.',
    ],
    'pt' => [
        'rss_lang' => 'pt-BR',
        'podcast_title' => 'Meu Podcast',
        'podcast_desc' => 'Bem-vindo ao meu podcast.',
    ],
];

$errors  = [];
$success = false;
$lang    = $_GET['lang'] ?? $_POST['lang'] ?? 'fr';
if (!array_key_exists($lang, $T)) $lang = 'fr';
$t = $T[$lang];
$values  = ['admin_user' => 'admin', 'admin_pass' => '', 'admin_pass2' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['admin_user']  = trim($_POST['admin_user']  ?? '');
    $values['admin_pass']  = $_POST['admin_pass']  ?? '';
    $values['admin_pass2'] = $_POST['admin_pass2'] ?? '';

    if (!$values['admin_user'] || !preg_match('/^[a-zA-Z0-9_\-]{3,32}$/', $values['admin_user'])) {
        $errors[] = $t['err_user'];
    }
    if (strlen($values['admin_pass']) < 8) {
        $errors[] = $t['err_short'];
    }
    if ($values['admin_pass'] !== $values['admin_pass2']) {
        $errors[] = $t['err_match'];
    }

    if (empty($errors)) {
        $hash    = password_hash($values['admin_pass'], PASSWORD_BCRYPT, ['cost' => 12]);
        $ld      = $LANG_DATA[$lang];
        $e       = fn($v) => str_replace("'", "\\'", str_replace("\\", "\\\\", $v));

        // Create directories
        foreach (['content/episodes','content/transcripts','audio','config/ratelimit','config/ratelimit-audio'] as $dir) {
            $p = __DIR__ . '/' . $dir;
            if (!is_dir($p)) mkdir($p, 0755, true);
        }

        // Protection .htaccess
        $deny = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
              . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n";
        foreach (['config','content','core'] as $d) {
            $f = __DIR__ . '/' . $d . '/.htaccess';
            if (!file_exists($f)) file_put_contents($f, $deny);
        }

        $cfg  = "<?php\n// Generated by setup.php on " . date('Y-m-d H:i:s') . "\n\nreturn [\n\n";
        $cfg .= "    'base_url'            => '" . $e($baseUrl) . "',\n\n";
        $cfg .= "    'podcast_title'       => '" . $e($ld['podcast_title']) . "',\n";
        $cfg .= "    'podcast_description' => '" . $e($ld['podcast_desc']) . "',\n";
        $cfg .= "    'author'              => '',\n";
        $cfg .= "    'email'               => '',\n";
        $cfg .= "    'language'            => '" . $e($ld['rss_lang']) . "',\n";
        $cfg .= "    'category'            => 'Technology',\n";
        $cfg .= "    'cover_image'         => '',\n";
        $cfg .= "    'redirect_feed_url'   => '',\n\n";
        $cfg .= "    'admin_username'      => '" . $e($values['admin_user']) . "',\n";
        $cfg .= "    'admin_password_hash' => '" . $hash . "',\n\n";
        $cfg .= "    'content_dir' => dirname(__DIR__) . '/content',\n";
        $cfg .= "    'audio_dir'   => dirname(__DIR__) . '/audio',\n";
        $cfg .= "\n];\n";

        // Write theme.json with the selected language
        $themeFile = __DIR__ . '/config/theme.json';
        if (!file_exists($themeFile)) {
            file_put_contents($themeFile, json_encode(['lang' => $lang], JSON_PRETTY_PRINT));
        }

        // Write lang to session (will be read by Lang::init)
        session_start();
        $_SESSION['lang'] = $lang;

        if (file_put_contents($configFile, $cfg) !== false) {
            $success = true;
        } else {
            $errors[] = $t['err_write'];
        }
    }
}

$adminUrl = $baseUrl . '/admin/';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="<?= $h($lang) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Installation — Badal</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d0d0f;font-family:'Inter',sans-serif;color:#f0ede8;padding:2rem 1rem}
    .card{background:#16161a;border:1px solid #232328;border-radius:18px;padding:2.75rem 3rem;width:100%;max-width:560px;box-shadow:0 24px 64px rgba(0,0,0,.5)}
    .logo-wrap{display:flex;align-items:center;gap:.75rem;justify-content:center;margin-bottom:2rem}
    .logo-dot{width:40px;height:40px;border-radius:10px;background:#e8ff5a;display:flex;align-items:center;justify-content:center}
    .logo-name{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;letter-spacing:-.03em}
    h1{font-family:'Syne',sans-serif;font-size:1.25rem;font-weight:800;text-align:center;margin-bottom:.35rem}
    .subtitle{font-size:.82rem;color:#555;text-align:center;margin-bottom:1.5rem}
    .url-pill{background:rgba(232,255,90,.05);border:1px solid rgba(232,255,90,.15);border-radius:8px;padding:.55rem .9rem;margin-bottom:1.75rem;font-size:.75rem;color:#666;text-align:center}
    .url-pill strong{color:#c8e850;font-family:monospace}
    .field{margin-bottom:.95rem}
    label{display:block;font-size:.7rem;font-weight:600;color:#555;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em}
    input{width:100%;background:#0d0d0f;border:1px solid #2a2a30;border-radius:8px;padding:.7rem 1rem;font-size:.88rem;color:#f0ede8;font-family:'Inter',sans-serif;outline:none;transition:border-color .15s}
    input:focus{border-color:#e8ff5a}
    .hint{font-size:.7rem;color:#3a3a40;margin-top:.25rem;display:block}
    /* Language selector */
    .lang-pick{display:flex;gap:.5rem;flex-wrap:wrap;justify-content:center;margin-bottom:1.75rem}
    .lang-pick a{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:8px;border:1px solid #232328;background:#0d0d0f;color:#555;text-decoration:none;font-size:.8rem;font-weight:500;transition:all .15s}
    .lang-pick a:hover{border-color:#444;color:#f0ede8}
    .lang-pick a.active{border-color:#e8ff5a;background:rgba(232,255,90,.08);color:#e8ff5a;font-weight:700}
    .lang-section-label{font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:#333;text-align:center;margin-bottom:.65rem}
    .btn{display:block;width:100%;margin-top:1.5rem;padding:.85rem;background:#e8ff5a;color:#0d0d0f;border:none;border-radius:10px;font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;cursor:pointer;text-align:center;text-decoration:none;transition:opacity .15s}
    .btn:hover{opacity:.85}
    .errors{background:rgba(255,90,90,.06);border:1px solid rgba(255,90,90,.18);border-radius:10px;padding:.85rem 1rem;margin-bottom:1.25rem}
    .errors p{font-size:.8rem;color:#ff7070;line-height:1.8}
    .state-icon{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem}
    .ok{background:rgba(90,230,140,.1);border:1.5px solid rgba(90,230,140,.25)}
    .warn{background:rgba(255,200,0,.1);border:1.5px solid rgba(255,200,0,.25)}
    .state-title{font-family:'Syne',sans-serif;font-size:1.25rem;font-weight:800;text-align:center;margin-bottom:.5rem}
    .state-desc{font-size:.83rem;color:#555;text-align:center;line-height:1.75;margin-bottom:1.75rem}
    code{background:#0d0d0f;border:1px solid #2a2a30;border-radius:4px;padding:.1em .4em;font-size:.82em;color:#777}
    .note{font-size:.72rem;color:#2a2a30;text-align:center;margin-top:1rem}
    .divider{border:none;border-top:1px solid #1e1e22;margin:1.5rem 0}
  </style>
</head>
<body><div class="card">

<?php if ($success): ?>
  <!-- Animated logo (progressive drawing) -->
  <div style="text-align:center;margin-bottom:1.5rem">
    <svg xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" width="591" height="200" viewBox="0 0 591 200" style="width:100%;max-width:320px;height:auto">
      <g transform="translate(295.5, 100) translate(-270.5, -91.5)">
        <path
          stroke="#e8ff5a"
          stroke-linecap="round"
          stroke-linejoin="round"
          fill="none"
          stroke-width="14"
          stroke-opacity="1"
          pathLength="1"
          stroke-dasharray="1"
          stroke-dashoffset="1"
          d=" M9.88 58.34 C68.19,-7.93 141.51,1.64 141.51,33.89 C141.51,66.13 53.76,114.28 53.76,99.04 C53.76,83.8 130.47,94.85 130.47,140.34 C130.47,183.48 37.49,180.61 30.31,165.19 C7.59,116.37 222.08,108.5 208.53,104.97 C175.56,96.37 139.33,145.31 166.27,160.32 C199.73,178.98 232,113.53 218.61,111.3 C215.96,110.86 206.02,152.09 231.2,160.92 C301.34,185.53 323.04,110.08 311.48,101.7 C279.93,78.84 210.27,136.76 258.82,162.1 C304.85,186.11 373.01,13.98 363.84,9.45 C354.67,4.92 300.53,113.98 342.05,155.51 C383.57,197.03 458.84,125.77 420.15,101.14 C387.76,80.53 353.06,136.1 373.16,156.2 C393.26,176.31 446.15,114.94 441.07,109.87 C435.99,104.8 411.5,152.75 437.83,164.64 C474.35,181.13 555.42,35.81 528.92,9.3 C502.42,-17.22 449.87,134.93 519.37,163.2 ">
          <animate attributeName="stroke-dashoffset" from="1" to="0" dur="2.5s" fill="freeze" calcMode="spline" keyTimes="0;1" keySplines="0.25 0.1 0.25 1"/>
        </path>
      </g>
    </svg>
  </div>

  <!-- Success content (appears after the logo animation) -->
  <div style="opacity:0;animation:fadeInUp .6s ease forwards;animation-delay:2s">
    <div class="state-icon ok">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="rgba(90,230,140,.9)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <div class="state-title"><?= $h($t['success_title']) ?></div>
    <div class="state-desc"><?= $t['success_desc'] ?></div>
    <a href="<?= $h($adminUrl) ?>" class="btn"><?= $h($t['btn_admin']) ?></a>
    <p class="note"><?= $t['note'] ?></p>
  </div>
  <style>
    @keyframes fadeInUp {
      from { opacity:0; transform:translateY(12px); }
      to   { opacity:1; transform:translateY(0); }
    }
  </style>

<?php else: ?>
  <div class="logo-wrap">
    <div class="logo-dot">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0d0d0f" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
    </div>
    <span class="logo-name">Badal</span>
  </div>
  <h1><?= $h($t['title']) ?></h1>
  <p class="subtitle"><?= $h($t['subtitle']) ?></p>

  <!-- Language selector -->
  <div class="lang-section-label"><?= $h($t['lang_label']) ?></div>
  <div class="lang-pick">
    <?php foreach ($T as $code => $tl): ?>
      <a href="?lang=<?= $h($code) ?>" class="<?= $code === $lang ? 'active' : '' ?>">
        <?= $tl['flag'] ?? '' ?> <?= $h($tl['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <hr class="divider">

  <div class="url-pill">🌐 <strong><?= $h($baseUrl) ?></strong></div>

  <?php if ($errors): ?>
    <div class="errors"><?php foreach ($errors as $err): ?><p>⚠ <?= $h($err) ?></p><?php endforeach; ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="lang" value="<?= $h($lang) ?>">
    <div class="field">
      <label><?= $h($t['label_user']) ?></label>
      <input type="text" name="admin_user" value="<?= $h($values['admin_user']) ?>" placeholder="admin" autocomplete="username" required>
    </div>
    <div class="field">
      <label><?= $h($t['label_pass']) ?></label>
      <input type="password" name="admin_pass" placeholder="········" autocomplete="new-password" required>
      <span class="hint"><?= $h($t['hint_pass']) ?></span>
    </div>
    <div class="field">
      <label><?= $h($t['label_pass2']) ?></label>
      <input type="password" name="admin_pass2" placeholder="········" autocomplete="new-password" required>
    </div>
    <button type="submit" class="btn"><?= $h($t['btn']) ?></button>
  </form>
<?php endif; ?>

</div></body></html>
