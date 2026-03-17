<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$configDir = dirname($config['content_dir']) . '/config';
$security  = new Security($configDir);

// Permission audit
$permChecks = Security::auditPermissions(ROOT_DIR);
$permOk     = array_filter($permChecks, fn($c) => $c['ok']);
$permFail   = array_filter($permChecks, fn($c) => !$c['ok']);

// Auth log (last 50 lines)
$logFile  = $configDir . '/auth.log';
$logLines = [];
if (file_exists($logFile)) {
    $all      = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logLines = array_slice(array_reverse($all), 0, 50);
}

// Rate-limit dir stats
$rlDir     = $configDir . '/ratelimit';
$rlFiles   = is_dir($rlDir) ? glob($rlDir . '/*.json') : [];
$rlActive  = 0;
$rlLocked  = 0;
foreach ($rlFiles as $f) {
    $d = json_decode(file_get_contents($f), true);
    if (empty($d['attempts'])) continue;
    $rlActive++;
    $cutoff = time() - 900;
    $recent = array_filter($d['attempts'], fn($t) => $t >= $cutoff);
    if (count($recent) >= 5) $rlLocked++;
}

$pageTitle = __('sec_title');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>
<style>
  .sec-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.5rem; }
  @media(max-width:800px) { .sec-grid { grid-template-columns:1fr; } }

  .perm-row { display:flex; align-items:center; gap:.7rem; padding:.6rem 1.1rem; border-bottom:1px solid var(--border); font-size:.83rem; }
  .perm-row:last-child { border-bottom:none; }
  .perm-icon { width:20px; height:20px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.7rem; font-weight:800; flex-shrink:0; }
  .ok-ic  { background:rgba(90,255,154,.15); color:#5aff9a; }
  .bad-ic { background:rgba(255,90,90,.15);  color:#ff5a5a; }
  .perm-path { flex:1; font-family:monospace; font-size:.8rem; }
  .perm-cur  { font-family:monospace; font-size:.75rem; font-weight:700; }
  .perm-rec  { font-size:.7rem; color:var(--muted); }

  .hdr-row { display:flex; align-items:flex-start; gap:.75rem; padding:.65rem 1.1rem; border-bottom:1px solid var(--border); font-size:.82rem; }
  .hdr-row:last-child { border-bottom:none; }
  .hdr-name { font-family:monospace; font-size:.78rem; color:var(--accent); min-width:200px; flex-shrink:0; }
  .hdr-val  { color:var(--muted); font-size:.75rem; word-break:break-word; }

  .log-line { font-family:monospace; font-size:.72rem; line-height:1.6; padding:.2rem 0; border-bottom:1px solid rgba(255,255,255,.04); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .log-line:last-child { border:none; }
  .log-ok   { color:#5aff9a; }
  .log-fail { color:#ff5a5a; }
  .log-warn { color:#ffc85a; }
  .log-info { color:var(--muted); }

  .stat-mini { display:flex; gap:1rem; margin-bottom:1.25rem; }
  .sm-tile { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:.9rem 1.1rem; flex:1; }
  .sm-tile .sl { font-size:.65rem; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); margin-bottom:.25rem; }
  .sm-tile .sv { font-size:1.5rem; font-weight:800; letter-spacing:-.03em; }

  .fix-cmd { font-family:monospace; font-size:.75rem; background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:.5rem .85rem; color:var(--accent); display:block; margin-top:.4rem; overflow-x:auto; white-space:nowrap; }
</style>

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <h1><?= __('sec_title') ?></h1>
    <span style="font-size:.78rem;color:var(--muted)">Audit en temps réel</span>
  </div>

  <div class="content">

    <!-- KPIs -->
    <div class="stat-mini">
      <div class="sm-tile">
        <div class="sl">Headers HTTP</div>
        <div class="sv" style="color:#5aff9a">Actifs</div>
      </div>
      <div class="sm-tile">
        <div class="sl">IPs avec tentatives</div>
        <div class="sv <?= $rlActive > 0 ? '' : '' ?>"><?= $rlActive ?></div>
      </div>
      <div class="sm-tile">
        <div class="sl">IPs bloquées</div>
        <div class="sv" style="color:<?= $rlLocked > 0 ? '#ff5a5a' : '#5aff9a' ?>"><?= $rlLocked ?></div>
      </div>
      <div class="sm-tile">
        <div class="sl">Permissions OK</div>
        <div class="sv" style="color:<?= count($permFail) === 0 ? '#5aff9a' : '#ffc85a' ?>"><?= count($permOk) ?>/<?= count($permChecks) ?></div>
      </div>
    </div>

    <div class="sec-grid">

      <!-- Headers -->
      <div class="card" style="overflow:hidden">
        <div style="padding:1rem 1.1rem;border-bottom:1px solid var(--border);font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)">
          <?= __('sec_headers') ?> envoyés
        </div>
        <?php
        $headers = [
            'Content-Security-Policy' => 'Politique de sécurité du contenu (XSS)',
            'X-Frame-Options'         => 'Protection contre le clickjacking',
            'X-Content-Type-Options'  => 'Blocage du MIME sniffing',
            'Referrer-Policy'         => 'Contrôle du référent',
            'Permissions-Policy'      => 'Désactivation des APIs sensibles',
            'Strict-Transport-Security' => 'Forçage HTTPS (si HTTPS actif)',
        ];
        foreach ($headers as $name => $desc): ?>
        <div class="hdr-row">
          <div class="perm-icon ok-ic">✓</div>
          <div style="flex:1">
            <div class="hdr-name"><?= e($name) ?></div>
            <div class="hdr-val"><?= e($desc) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Permissions -->
      <div class="card" style="overflow:hidden">
        <div style="padding:1rem 1.1rem;border-bottom:1px solid var(--border);font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)">
          <?= __('sec_permissions') ?>
        </div>
        <?php foreach ($permChecks as $check): ?>
        <div class="perm-row">
          <div class="perm-icon <?= $check['ok'] ? 'ok-ic' : 'bad-ic' ?>"><?= $check['ok'] ? '✓' : '✗' ?></div>
          <div class="perm-path"><?= e($check['path']) ?></div>
          <div style="text-align:right">
            <div class="perm-cur" style="color:<?= $check['ok'] ? '#5aff9a' : '#ff5a5a' ?>"><?= e($check['current']) ?></div>
            <?php if (!$check['ok']): ?>
              <div class="perm-rec">recommandé : <?= e($check['recommended']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if (count($permFail) > 0): ?>
        <div style="padding:.85rem 1.1rem;border-top:1px solid var(--border)">
          <div style="font-size:.72rem;color:var(--muted);margin-bottom:.35rem">Commandes de correction :</div>
          <?php foreach ($permFail as $check): ?>
            <code class="fix-cmd">chmod <?= e($check['recommended']) ?> <?= e($check['path']) ?></code>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Session info -->
    <div class="card" style="padding:1.25rem;margin-bottom:1.25rem">
      <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Session en cours</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem">
        <?php
        $sess = $_SESSION['badal_auth'] ?? [];
        $items = [
            'Utilisateur'  => $sess['user']    ?? '—',
            'Connecté à'   => isset($sess['time']) ? date('H:i:s', $sess['time']) : '—',
            'IP de session' => $sess['ip'] ?? '—',
            'Expire dans'  => isset($sess['time']) ? gmdate('H\hi\m', max(0, 8*3600 - (time() - $sess['time']))) : '—',
            'Cookie HttpOnly' => ini_get('session.cookie_httponly') ? 'Oui ✓' : 'Non ✗',
            'SameSite'     => ini_get('session.cookie_samesite') ?: 'Non défini',
        ];
        foreach ($items as $k => $v): ?>
          <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:.6rem .9rem">
            <div style="font-size:.65rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:.2rem"><?= e($k) ?></div>
            <div style="font-size:.84rem;font-weight:600;font-family:monospace"><?= e($v) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Auth log -->
    <div class="card" style="overflow:hidden">
      <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
        <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)">
          <?= __('sec_log') ?>
          <span style="font-weight:600;color:var(--text)">(<?= count($logLines) ?> entrées récentes)</span>
        </div>
        <span style="font-size:.73rem;color:var(--muted);font-family:monospace"><?= e($logFile) ?></span>
      </div>
      <div style="padding:.75rem 1.25rem;max-height:320px;overflow-y:auto;font-size:.72rem">
        <?php if (empty($logLines)): ?>
          <div style="color:var(--muted);padding:1rem 0">Aucune entrée dans le journal.</div>
        <?php else: ?>
          <?php foreach ($logLines as $line):
            $cls = 'log-info';
            if ((strpos($line, 'LOGIN_OK') !== false) || (strpos($line, 'LOGOUT') !== false)) $cls = 'log-ok';
            elseif ((strpos($line, 'LOGIN_FAIL') !== false) || (strpos($line, 'LOCKOUT') !== false) || (strpos($line, 'PERM_DENIED') !== false)) $cls = 'log-fail';
            elseif ((strpos($line, 'IP_CHANGE') !== false) || (strpos($line, 'REHASH') !== false)) $cls = 'log-warn';
          ?>
          <div class="log-line <?= $cls ?>" title="<?= e($line) ?>"><?= e($line) ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Rate limit -->
    <?php if (!empty($rlFiles)): ?>
    <div class="card" style="padding:1.25rem;margin-top:1.25rem">
      <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">
        Tentatives de connexion actives (<?= count($rlFiles) ?> IP)
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.8rem">
          <thead>
            <tr>
              <th style="text-align:left;padding:.4rem .6rem;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">IP (anonymisée)</th>
              <th style="text-align:left;padding:.4rem .6rem;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Tentatives</th>
              <th style="text-align:left;padding:.4rem .6rem;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Statut</th>
              <th style="text-align:left;padding:.4rem .6rem;font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Dernière tentative</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rlFiles as $f):
              $d = json_decode(file_get_contents($f), true);
              if (empty($d['attempts'])) continue;
              $cutoff = time() - 900;
              $recent = array_filter($d['attempts'], fn($t) => $t >= $cutoff);
              if (empty($recent)) continue;
              $locked  = count($recent) >= 5;
              $last    = max($recent);
              $fileHash = substr(basename($f, '.json'), 0, 12) . '…';
            ?>
            <tr>
              <td style="padding:.5rem .6rem;border-bottom:1px solid var(--border);font-family:monospace;font-size:.75rem"><?= e($fileHash) ?></td>
              <td style="padding:.5rem .6rem;border-bottom:1px solid var(--border);font-weight:700;color:<?= $locked ? '#ff5a5a' : '#ffc85a' ?>"><?= count($recent) ?>/5</td>
              <td style="padding:.5rem .6rem;border-bottom:1px solid var(--border)">
                <?php if ($locked): ?>
                  <span style="color:#ff5a5a;font-size:.75rem;font-weight:700">🔒 Bloquée</span>
                <?php else: ?>
                  <span style="color:#ffc85a;font-size:.75rem;font-weight:700">⚠ Active</span>
                <?php endif; ?>
              </td>
              <td style="padding:.5rem .6rem;border-bottom:1px solid var(--border);color:var(--muted);font-size:.75rem"><?= date('H:i:s', $last) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
