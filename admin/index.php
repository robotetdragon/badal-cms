<?php
ob_start();
// =============================================================================
//  admin/index.php — Tableau de bord
//
//  Vue d'ensemble du podcast :
//    - 4 compteurs rapides (épisodes, écoutes totales, écoutes 7j, stockage)
//    - Lien vers les statistiques détaillées
//    - Dernier épisode publié
//    - Tableau des 10 épisodes les plus récents avec compteur d'écoutes
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->requireLogin();

// Update checker (asynchrone, 1 fois/24h)
$configDir    = dirname($config['content_dir']) . '/config';
$versionCache = $configDir . '/version_cache.json';
$updateInfo   = Version::check($versionCache);

// Télémétrie opt-in (1 fois/24h)
$_parser2  = new EpisodeParser($config['content_dir']);
$_epCount2 = count($_parser2->getAll());
Telemetry::maybeSend($config, $configDir, $_epCount2);

// Nettoyage probabiliste (1 visite sur 10) des fichiers rate-limit audio expirés
if (mt_rand(1, 10) === 1) {
    $rlAudioDir = dirname($config['content_dir']) . '/config/ratelimit-audio';
    if (is_dir($rlAudioDir)) {
        foreach (glob($rlAudioDir . '/*.json') ?: [] as $f) {
            $data = json_decode(@file_get_contents($f), true) ?? [];
            // Supprimer le fichier si toutes les entrées sont expirées depuis > 5 min
            if (empty(array_filter($data, fn($t) => $t >= time() - 300))) {
                @unlink($f);
            }
        }
    }
}

// ── Données épisodes ──────────────────────────────────────────────────────────
$parser        = new EpisodeParser($config['content_dir']);
$episodes      = $parser->getAll();
$totalEpisodes = count($episodes);
$latestEpisode = $episodes[0] ?? null;   // le premier après tri anti-chrono

// ── Données statistiques ──────────────────────────────────────────────────────
$configDir   = dirname($config['content_dir']) . '/config';
$stats       = new StatsManager($configDir);
$grandTotal  = $stats->getGrandTotal();
$recentTotal = $stats->getRecentTotal(7);   // écoutes sur les 7 derniers jours

// ── Taille totale des fichiers audio ─────────────────────────────────────────
$totalSize = 0;
foreach (glob($config['audio_dir'] . '/*') ?: [] as $file) {
    if (is_file($file)) {
        $totalSize += filesize($file);
    }
}
$totalSizeMb = round($totalSize / 1024 / 1024, 1);

$pageTitle = __('nav_dashboard');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <h1><?= __('nav_dashboard') ?></h1>
    <?php if (!empty($updateInfo['has_update'])): ?>
    <a href="<?= e($updateInfo['url'] ?? '#') ?>" target="_blank" rel="noopener"
       style="display:inline-flex;align-items:center;gap:.5rem;margin-left:auto;background:rgba(232,255,90,.12);border:1px solid rgba(232,255,90,.3);color:var(--accent);border-radius:8px;padding:.4rem .9rem;font-size:.75rem;font-weight:700;text-decoration:none">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 1 1-.5-5.5"/></svg>
      v<?= e($updateInfo['latest']) ?> disponible
    </a>
    <?php endif; ?>
    <a href="<?= url('/admin/episode_new.php') ?>" class="btn">
      <i data-feather="plus" style="width:14px;height:14px;stroke-width:2.5"></i>
      <?= __('nav_new_episode') ?>
    </a>
  </div>

  <div class="content">

    <!-- ── Compteurs rapides ───────────────────────────────────────────────── -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-label">Épisodes publiés</div>
        <div class="stat-value accent"><?= $totalEpisodes ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Écoutes totales</div>
        <div class="stat-value"><?= number_format($grandTotal, 0, ',', ' ') ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Écoutes (7 jours)</div>
        <div class="stat-value accent"><?= number_format($recentTotal, 0, ',', ' ') ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Audio stocké</div>
        <div class="stat-value">
          <?= $totalSizeMb ?>
          <span style="font-size:1rem;font-weight:400;color:var(--muted)">Mo</span>
        </div>
      </div>
    </div>

    <!-- Lien vers les statistiques complètes -->
    <div style="margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
      <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)">
        <?= __('stats_title') ?>
      </div>
      <a href="<?= url('/admin/stats.php') ?>" class="btn btn-ghost" style="font-size:.78rem;padding:.35rem .75rem">
        Voir les stats →
      </a>
    </div>

    <!-- ── Dernier épisode publié ──────────────────────────────────────────── -->
    <?php if ($latestEpisode): ?>
    <div style="margin-bottom:2rem">
      <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:.75rem">
        Dernier épisode
      </div>
      <div class="card" style="padding:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <div>
          <div style="font-weight:700;margin-bottom:.25rem">
            <?= e($latestEpisode['title'] ?? 'Sans titre') ?>
          </div>
          <div style="font-size:.82rem;color:var(--muted)">
            <?= e($latestEpisode['date'] ?? '') ?>
            <?php if (!empty($latestEpisode['duration'])): ?>
              · <?= e($latestEpisode['duration']) ?>
            <?php endif; ?>
          </div>
        </div>
        <a href="<?= url('/admin/episode_edit.php?slug=' . urlencode($latestEpisode['slug'])) ?>"
           class="btn btn-ghost">
          Modifier
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Liste des 10 épisodes les plus récents ──────────────────────────── -->
    <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:.75rem">
      Épisodes récents
    </div>

    <div class="card">
      <?php if (empty($episodes)): ?>
        <div style="padding:2rem;text-align:center;color:var(--muted)">
          <?= __('dash_no_episodes') ?>
          <a href="<?= url('/admin/episode_new.php') ?>" style="color:var(--accent)"><?= __('dash_create_first') ?></a>
        </div>
      <?php else: ?>
        <div class="ep-list">
          <?php foreach (array_slice($episodes, 0, 10) as $i => $ep): ?>
          <div class="ep-row">
            <span class="badge" style="flex-shrink:0"><?= e($ep['episode'] ?? ($totalEpisodes - $i)) ?></span>
            <div class="ep-row-info">
              <div class="ep-row-title"><?= e($ep['title'] ?? 'Sans titre') ?></div>
              <div class="ep-row-meta">
                <?= e($ep['date'] ?? '') ?>
                <?php if (!empty($ep['duration'])): ?> · <?= e($ep['duration']) ?><?php endif; ?>
                <span class="ep-row-plays"><?= number_format($stats->getTotal($ep['slug']), 0, ',', ' ') ?> écoutes</span>
              </div>
            </div>
            <a href="<?= url('/admin/episode_edit.php?slug=' . urlencode($ep['slug'])) ?>"
               class="btn btn-ghost" style="font-size:.78rem;padding:.35rem .75rem;flex-shrink:0">
              Modifier
            </a>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

</body>
</html>
