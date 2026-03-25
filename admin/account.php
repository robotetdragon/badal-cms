<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$configFile = ROOT_DIR . '/config/config.php';
$errors   = [];
$success  = [];

// ── Form processing ─────────────────────────────────────────────────────────
$configDir = dirname($config['content_dir']) . '/config';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'telemetry') {
        $enabled = isset($_POST['telemetry_optin']);
        Telemetry::setOptIn($configDir, $enabled);
        $success[] = $enabled ? "Télémétrie activée. Merci !" : "Télémétrie désactivée.";
    }

    // ── Change username ───────────────────────────────────────────────────────
    if ($action === 'change_username') {
        $newUser = trim($_POST['new_username'] ?? '');
        $curPass = $_POST['confirm_password'] ?? '';

        if (!$newUser) {
            $errors[] = "Le nom d'utilisateur ne peut pas être vide.";
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]{3,32}$/', $newUser)) {
            $errors[] = "Nom d'utilisateur invalide (3-32 caractères alphanumériques).";
        } elseif (!password_verify($curPass, $config['admin_password_hash'])) {
            $errors[] = "Mot de passe actuel incorrect.";
        } else {
            writeConfigKey($configFile, 'admin_username', $newUser);
            $config['admin_username'] = $newUser;
            $success[] = "Nom d'utilisateur mis à jour.";
        }
    }

    // ── Change password ───────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $curPass  = $_POST['current_password'] ?? '';
        $newPass  = $_POST['new_password']     ?? '';
        $confirm  = $_POST['confirm_new']      ?? '';

        if (!password_verify($curPass, $config['admin_password_hash'])) {
            $errors[] = "Mot de passe actuel incorrect.";
        } elseif (strlen($newPass) < 8) {
            $errors[] = "Le nouveau mot de passe doit faire au moins 8 caractères.";
        } elseif ($newPass !== $confirm) {
            $errors[] = "Les deux mots de passe ne correspondent pas.";
        } else {
            $newHash = Auth::hashPassword($newPass);
            writeConfigKey($configFile, 'admin_password_hash', $newHash);
            $config['admin_password_hash'] = $newHash;

            // Invalidate current session → revokes any stolen cookie
            $currentUser = $_SESSION['badal_auth']['user'] ?? 'admin';
            session_unset();
            session_destroy();
            session_start();
            session_regenerate_id(true);
            $_SESSION['badal_auth'] = [
                'user'    => $currentUser,
                'time'    => time(),
                'ip'      => Security::clientIp(),
                'ua_hash' => md5($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ];

            $success[] = "Mot de passe mis à jour. Session régénérée.";
        }
    }
}

/**
 * Rewrites a key in config.php while preserving the rest of the file.
 */
function writeConfigKey(string $file, string $key, string $value): void {
    $content = file_get_contents($file);
    $escaped = addslashes($value);
    // Replace the line 'key' => '...'
    $content = preg_replace(
        "/('$key'\s*=>\s*)'[^']*'/",
        "'$key' => '$escaped'",
        $content
    );
    file_put_contents($file, $content);
}

$currentUsername = $config['admin_username'] ?? 'admin';
$_currentLang    = Lang::current();
$_langLabels     = Lang::LABELS;
$_langSupported  = Lang::SUPPORTED_LANGS;
$_switchBase     = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

$pageTitle = 'Mon compte';
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <h1>Mon compte</h1>
  </div>

  <div class="content">

    <?php if ($errors): ?>
      <div style="background:rgba(255,80,80,.1);border:1px solid rgba(255,80,80,.3);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;color:#ff7070;font-size:.85rem">
        <?php foreach ($errors as $e): ?><div>⚠ <?= e($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div style="background:rgba(90,255,154,.08);border:1px solid rgba(90,255,154,.25);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;color:#5aff9a;font-size:.85rem">
        <?php foreach ($success as $s): ?><div>✓ <?= e($s) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">

      <!-- ── Username ──────────────────────────────────────────────────── -->
      <div class="card" style="padding:1.5rem">
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.25rem">
          <div style="width:32px;height:32px;border-radius:8px;background:rgba(232,255,90,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i data-feather="user" style="width:15px;height:15px;color:var(--accent)"></i>
          </div>
          <div>
            <div style="font-size:.78rem;font-weight:700;letter-spacing:.04em">Nom d'utilisateur</div>
            <div style="font-size:.72rem;color:var(--muted)">Identifiant de connexion</div>
          </div>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="change_username">
          <div class="field" style="margin-bottom:.85rem">
            <label for="new_username">Nouveau nom</label>
            <input type="text" id="new_username" name="new_username"
                   value="<?= e($currentUsername) ?>"
                   placeholder="admin" autocomplete="username">
          </div>
          <div class="field" style="margin-bottom:1rem">
            <label for="confirm_password_u">Confirmer avec votre mot de passe</label>
            <input type="password" id="confirm_password_u" name="confirm_password"
                   placeholder="••••••••" autocomplete="current-password">
          </div>
          <button type="submit" class="btn" style="width:100%">Mettre à jour</button>
        </form>
      </div>

      <!-- ── Password ──────────────────────────────────────────────────── -->
      <div class="card" style="padding:1.5rem">
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.25rem">
          <div style="width:32px;height:32px;border-radius:8px;background:rgba(232,255,90,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i data-feather="lock" style="width:15px;height:15px;color:var(--accent)"></i>
          </div>
          <div>
            <div style="font-size:.78rem;font-weight:700;letter-spacing:.04em">Mot de passe</div>
            <div style="font-size:.72rem;color:var(--muted)">Minimum 8 caractères</div>
          </div>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="change_password">
          <div class="field" style="margin-bottom:.85rem">
            <label for="current_password">Mot de passe actuel</label>
            <input type="password" id="current_password" name="current_password"
                   placeholder="••••••••" autocomplete="current-password">
          </div>
          <div class="field" style="margin-bottom:.85rem">
            <label for="new_password">Nouveau mot de passe</label>
            <input type="password" id="new_password" name="new_password"
                   placeholder="••••••••" autocomplete="new-password">
          </div>
          <div class="field" style="margin-bottom:1rem">
            <label for="confirm_new">Confirmer</label>
            <input type="password" id="confirm_new" name="confirm_new"
                   placeholder="••••••••" autocomplete="new-password">
          </div>
          <button type="submit" class="btn" style="width:100%">Changer le mot de passe</button>
        </form>
      </div>

    </div>

    <!-- ── Interface language ───────────────────────────────────────────────── -->
    <div class="card" style="padding:1.5rem;margin-bottom:1.25rem">
      <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.25rem">
        <div style="width:32px;height:32px;border-radius:8px;background:rgba(232,255,90,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i data-feather="globe" style="width:15px;height:15px;color:var(--accent)"></i>
        </div>
        <div>
          <div style="font-size:.78rem;font-weight:700;letter-spacing:.04em">Langue de l'interface</div>
          <div style="font-size:.72rem;color:var(--muted)">Affichage de l'admin et du site public</div>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <?php foreach ($_langSupported as $code): ?>
          <a href="<?= htmlspecialchars($_switchBase . '?lang=' . $code) ?>"
             style="display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1.1rem;border-radius:8px;border:1px solid <?= $code === $_currentLang ? 'var(--accent)' : 'var(--border)' ?>;background:<?= $code === $_currentLang ? 'rgba(232,255,90,.1)' : 'var(--surface2,#1e1e22)' ?>;color:<?= $code === $_currentLang ? 'var(--accent)' : 'var(--muted)' ?>;text-decoration:none;font-size:.82rem;font-weight:<?= $code === $_currentLang ? '700' : '500' ?>;transition:border-color .15s,color .15s">
            <?= $code === $_currentLang ? '✓ ' : '' ?><?= e($_langLabels[$code] ?? $code) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Telemetry ──────────────────────────────────────────────────────────── -->
    <div class="card" style="padding:1.5rem;margin-bottom:1.25rem">
      <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem">
        <div style="width:32px;height:32px;border-radius:8px;background:rgba(232,255,90,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i data-feather="activity" style="width:15px;height:15px;color:var(--accent)"></i>
        </div>
        <div>
          <div style="font-size:.78rem;font-weight:700;letter-spacing:.04em">Statistiques anonymes</div>
          <div style="font-size:.72rem;color:var(--muted)">Aide à améliorer Badal</div>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="telemetry">
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:1rem 1.1rem;margin-bottom:1rem">
          <div class="toggle-wrap" style="margin-bottom:.75rem">
            <label class="toggle">
              <input type="checkbox" name="telemetry_optin" <?= Telemetry::isOptIn($configDir) ? 'checked' : '' ?> onchange="this.form.submit()">
              <span class="slider"></span>
            </label>
            <span style="font-size:.85rem">Partager des statistiques anonymes d'utilisation</span>
          </div>
          <div style="font-size:.75rem;color:var(--muted);line-height:1.6;padding-left:3rem">
            Envoie une fois par jour : version de Badal, nombre d'épisodes, langue.<br>
            <strong style="color:var(--text)">Aucune donnée personnelle.</strong>
            Un ID aléatoire est généré localement — jamais lié à votre identité.
          </div>
        </div>
      </form>
    </div>

    <!-- ── Session ──────────────────────────────────────────────────────────── -->
    <div class="card" style="padding:1.5rem">
      <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.25rem">
        <div style="width:32px;height:32px;border-radius:8px;background:rgba(255,80,80,.08);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i data-feather="log-out" style="width:15px;height:15px;color:#ff7070"></i>
        </div>
        <div>
          <div style="font-size:.78rem;font-weight:700;letter-spacing:.04em">Session</div>
          <div style="font-size:.72rem;color:var(--muted)">Connecté en tant que <strong style="color:var(--text)"><?= e($currentUsername) ?></strong></div>
        </div>
      </div>
      <a href="<?= url('/admin/logout.php') ?>" class="btn" style="background:rgba(255,80,80,.1);border-color:rgba(255,80,80,.3);color:#ff7070">
        <i data-feather="log-out" style="width:14px;height:14px"></i>
        Se déconnecter
      </a>
    </div>

  </div>
</div>

</body>
</html>
