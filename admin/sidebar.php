<?php
ob_start();
// =============================================================================
//  admin/sidebar.php — Navigation latérale de l'interface admin
//
//  À inclure après layout_head.php dans chaque page admin.
//  Responsabilités :
//    - Rendre l'overlay mobile (fond semi-transparent derrière la sidebar)
//    - Rendre la sidebar avec logo, liens de navigation, et bouton déconnexion
//    - Mettre en surbrillance le lien actif (comparaison avec PHP_SELF)
//    - Exposer les fonctions JS openSidebar() / closeSidebar() pour le hamburger
//
//  La sidebar est fixe sur desktop (>640px) et se transforme en tiroir
//  off-canvas sur mobile, déclenché par le bouton hamburger de chaque page.
// =============================================================================

// Identifier la page courante pour la mise en surbrillance du lien actif
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>

<!-- Off-canvas overlay (mobile) -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo" style="-webkit-mask:url('<?= url('/audio/badal_logo.svg') ?>') center/contain no-repeat;mask:url('<?= url('/audio/badal_logo.svg') ?>') center/contain no-repeat;aspect-ratio:541/183" aria-label="Badal"></div>
    <a href="<?= url('/admin/about.php') ?>" class="sidebar-about"><i data-feather="info"></i></a>
    <button class="sidebar-close" onclick="closeSidebar()" aria-label="Fermer le menu">
      <i data-feather="x"></i>
    </button>
  </div>

  <nav>
    <a href="<?= url('/') ?>" target="_blank" rel="noopener">
      <i data-feather="home"></i>
      <?= __('nav_view_site') ?> ↗
    </a>

    <a href="<?= url('/admin/') ?>" class="<?= $currentPage === 'index' ? 'active' : '' ?>">
      <i data-feather="grid"></i>
      <?= __('nav_dashboard') ?>
    </a>

    <a href="<?= url('/admin/podcast.php') ?>" class="<?= $currentPage === 'podcast' ? 'active' : '' ?>">
      <i data-feather="mic"></i>
      Mon podcast
    </a>

    <a href="<?= url('/admin/episodes.php') ?>" class="<?= $currentPage === 'episodes' ? 'active' : '' ?>">
      <i data-feather="play"></i>
      <?= __('nav_episodes') ?>
    </a>

    <a href="<?= url('/admin/episode_new.php') ?>" class="<?= $currentPage === 'episode_new' ? 'active' : '' ?>" style="display:none">
      <i data-feather="plus-circle"></i>
      <?= __('nav_new_episode') ?>
    </a>

    <?php
    // Lien import visible seulement s'il n'y a aucun épisode
    $sidebarParser   = new EpisodeParser($config['content_dir']);
    $sidebarEpisodes = $sidebarParser->getAll();
    if (empty($sidebarEpisodes)): ?>
    <a href="<?= url('/admin/import.php') ?>" class="<?= $currentPage === 'import' ? 'active' : '' ?>" style="color:var(--accent)">
      <i data-feather="download-cloud"></i>
      Importer un podcast
    </a>
    <?php endif; ?>

    <a href="<?= url('/admin/stats.php') ?>" class="<?= $currentPage === 'stats' ? 'active' : '' ?>">
      <i data-feather="bar-chart-2"></i>
      <?= __('nav_stats') ?>
    </a>

    <a href="<?= url('/admin/style.php') ?>" class="<?= $currentPage === 'appearance' ? 'active' : '' ?>">
      <i data-feather="sliders"></i>
      <?= __('nav_appearance') ?>
    </a>

    <a href="<?= url('/admin/security.php') ?>" class="<?= $currentPage === 'security' ? 'active' : '' ?>">
      <i data-feather="shield"></i>
      <?= __('nav_security') ?>
    </a>

    <a href="<?= url('/admin/rss_builder.php') ?>" class="<?= $currentPage === 'rss_builder' ? 'active' : '' ?>">
      <i data-feather="rss"></i>
      <?= __('nav_rss') ?>
    </a>

    <a href="<?= url('/admin/push.php') ?>" class="<?= $currentPage === 'push' ? 'active' : '' ?>">
      <i data-feather="bell"></i>
      <?= __('nav_push') ?>
    </a>

    <a href="<?= url('/admin/tools.php') ?>" class="<?= $currentPage === 'tools' ? 'active' : '' ?>">
      <i data-feather="tool"></i>
      Outils
    </a>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= url('/admin/account.php') ?>" class="<?= $currentPage === 'account' ? 'active' : '' ?>">
      <i data-feather="user"></i>
      Mon compte
    </a>
    <a href="<?= url('/admin/logout.php') ?>" class="sidebar-logout">
      <i data-feather="log-out"></i>
      <?= __('nav_logout') ?>
    </a>
    <a href="https://github.com/robotetdragon/badal-cms" target="_blank" rel="noopener" style="margin-top:.25rem">
      <i data-feather="github"></i>
      GitHub
    </a>
  </div>
</aside>

<script>
  const sidebar  = document.getElementById('sidebar');
  const overlay  = document.getElementById('sidebar-overlay');

  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('visible');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('visible');
    document.body.style.overflow = '';
  }

  // Close on Escape
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

  // Expose openSidebar globally for the hamburger button in each page's topbar
  window.openSidebar = openSidebar;
</script>
