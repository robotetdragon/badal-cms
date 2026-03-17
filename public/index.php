<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';

$parser = new EpisodeParser($config['content_dir']);
$episodes = $parser->getAll();
$theme = new ThemeManager(dirname($config['content_dir']) . '/config');

$t            = $theme->getAll();
$podcastTitle = e($config['podcast_title']);
$podcastDesc  = e($config['podcast_description']);
$author       = e($config['author']);
$sm           = new StatsManager($config['content_dir'] . '/../config');
$epTotals     = $sm->getAllTotals();
$baseUrl      = $config['base_url'];

$tagline     = !empty($t['home_tagline']) ? e($t['home_tagline']) : $podcastDesc;
$ctaLabel    = e($t['home_cta_label'] ?: __('pub_rss_subscribe'));
$ctaRaw  = $t['home_cta_url'] ?? '';
// Si l'URL stockée est un chemin relatif (commence par /), on préfixe base_url
if ($ctaRaw && $ctaRaw[0] === '/') {
    $ctaUrl = e(url($ctaRaw));
} elseif ($ctaRaw) {
    $ctaUrl = e($ctaRaw);
} else {
    $ctaUrl = e(url('/rss.xml'));
}
$footerText  = !empty($t['home_footer_text']) ? e($t['home_footer_text']) : null;
$sections    = is_array($t['sections']) ? $t['sections'] : ['header','episodes','footer'];
$headerAlign = $t['header_align'] === 'left' ? 'left' : 'center';
$epStyle     = $t['episodes_style'] === 'grid' ? 'grid' : 'list';
$showNum     = ($t['show_episode_number']   ?? '1') !== '0';
$showDate    = ($t['show_episode_date']     ?? '1') !== '0';
$showDur     = ($t['show_episode_duration'] ?? '1') !== '0';
$logoType    = $t['logo_type'] ?? 'icon';
$logoImage   = $t['logo_image'] ?? '';
$coverImage  = $t['cover_image'] ?? '';

$socials = ThemeManager::socialNetworks();
$socialLinks = [];
foreach ($socials as $net => $info) {
    $url = trim($t['social_' . $net] ?? '');
    if ($url) $socialLinks[$net] = ['url' => $url, 'label' => $info['label']];
}

$cssVars  = $theme->toCssVars();
$fontsUrl = $theme->toGoogleFontsUrl();

// Dernier épisode pour le hero
$latestEp = !empty($episodes) ? $episodes[0] : null;

$coverStyle = '';
if ($coverImage) {
    $coverStyle = "background-image:url('/audio/" . e($coverImage) . "');background-size:cover;background-position:center;";
}

// Accent color → RGB for rgba()
$hex = ltrim($t['color_accent'] ?? '#e8ff5a', '#');
if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
$accentRgb = hexdec(substr($hex,0,2)) . ',' . hexdec(substr($hex,2,2)) . ',' . hexdec(substr($hex,4,2));

function socialIcon(string $net): string {
    $icons = [
        'twitter'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.741l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        'instagram' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>',
        'youtube'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58A2.78 2.78 0 0 0 3.41 19.6C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.95A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58zM9.75 15.02V8.98L15.5 12l-5.75 3.02z"/></svg>',
        'spotify'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.622.622 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.622.622 0 0 1-.277-1.215c3.809-.87 7.077-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.72a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 0 1-.973-.519.781.781 0 0 1 .519-.973c3.632-1.102 8.147-.568 11.234 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 0 1-.582-1.781c3.532-1.155 9.404-.932 13.115 1.338a.936.936 0 0 1 .084 1.602z"/></svg>',
        'apple'     => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/></svg>',
    ];
    return $icons[$net] ?? '🔗';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $podcastTitle ?></title>
<meta name="description" content="<?= $tagline ?>">
<link rel="alternate" type="application/rss+xml" title="<?= $podcastTitle ?> RSS" href="<?= url('/rss.xml') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="<?= $fontsUrl ?>" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root { <?= $cssVars ?> }

  body {
    font-family: var(--font-heading);
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    opacity: 0;
  }
  body.page-ready { opacity: 1; transition: opacity .22s ease; }
  .container { animation: pageIn .28s cubic-bezier(.25,.46,.45,.94) both; }
  @keyframes pageIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  body::before {
    content: ''; position: fixed; inset: 0;
    background: radial-gradient(ellipse 70% 50% at 50% -10%, rgba(<?= $accentRgb ?>,.07) 0%, transparent 60%);
    pointer-events: none;
  }

  .container { max-width: var(--layout-width); margin: 0 auto; padding: 0 1.5rem; }

  /* Header */
  header {
    padding: 4rem 0 3rem;
    text-align: <?= $headerAlign ?>;
    position: relative;
    <?= $coverStyle ?>
  }
  <?php if ($coverStyle): ?>
  header::after { content:''; position:absolute; inset:0; background:linear-gradient(to bottom, rgba(<?= $accentRgb ?>,.04), var(--bg)); pointer-events:none; }
  header > * { position:relative; z-index:1; }
  <?php endif; ?>

  .logo-wrap { display:<?= $headerAlign === 'center' ? 'inline-flex' : 'flex' ?>; align-items:center; margin-bottom:1.5rem; }
  .logo-icon { width:64px; height:64px; background:var(--accent); border-radius:16px; display:flex; align-items:center; justify-content:center; }
  .logo-icon svg { width:30px; height:30px; }
  .logo-img { width:72px; height:72px; border-radius:16px; object-fit:cover; }

  header h1 {
    font-family: var(--font-heading);
    font-size: clamp(2rem, 6vw, 3.2rem);
    font-weight: var(--font-weight-heading); letter-spacing: -.04em; line-height: 1.1; margin-bottom: .75rem;
  }
  .tagline {
    font-family: var(--font-body);
    font-style: italic; font-size: 1.1rem; color: var(--muted);
    max-width: 480px; margin: 0 <?= $headerAlign==='center'?'auto':'0' ?> 1.5rem;
  }

  .header-actions { display:flex; align-items:center; justify-content:<?= $headerAlign==='center'?'center':'flex-start' ?>; gap:.75rem; flex-wrap:wrap; }
  .cta-btn {
    display:inline-flex; align-items:center; gap:.5rem;
    background:transparent; border:1px solid var(--border); border-radius:20px;
    color:var(--muted); text-decoration:none; font-size:.82rem; padding:.4rem 1rem;
    transition:color .2s, border-color .2s;
  }
  .cta-btn:hover { color:var(--accent); border-color:var(--accent); }
  .cta-btn svg { width:12px; height:12px; }

  .social-links { display:flex; align-items:center; justify-content:<?= $headerAlign==='center'?'center':'flex-start' ?>; gap:.5rem; flex-wrap:wrap; margin-top:.75rem; }
  .social-link {
    display:inline-flex; align-items:center; justify-content:center;
    width:32px; height:32px; border-radius:8px; border:1px solid var(--border);
    color:var(--muted); text-decoration:none; transition:color .2s, border-color .2s, background .2s;
  }
  .social-link:hover { color:var(--accent); border-color:var(--accent); background:rgba(<?= $accentRgb ?>,.08); }

  /* Episodes */
  .episodes-section { padding-bottom:5rem; }
  .section-label { font-size:.72rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:var(--muted); margin-bottom:1.5rem; padding-bottom:.75rem; border-bottom:1px solid var(--border); }

  /* ════════════════════════════════════════════════════
     CARDS ÉPISODES — design "ZOE"
     ════════════════════════════════════════════════════ */

  /* ── LIST MODE ───────────────────────────────────── */
  .episodes-list { display: flex; flex-direction: column; gap: .5rem; }

  .episode-card-list {
    display: flex; align-items: center; gap: 0;
    border-radius: 16px;
    border: 1px solid var(--border);
    background: var(--surface);
    text-decoration: none; color: inherit;
    overflow: hidden;
    transition: border-color .18s, box-shadow .18s;
    padding: .75rem;
    gap: .9rem;
  }
  .episode-card-list:hover { border-color: rgba(<?= $accentRgb ?>,.4); box-shadow: 0 2px 16px rgba(0,0,0,.15); }
  .episode-card-list:hover .ep-title { color: var(--accent); }

  /* Cover arrondie */
  .ep-cover-list {
    width: 72px; height: 72px; min-width: 72px;
    border-radius: 10px; object-fit: cover;
    box-shadow: 0 2px 8px rgba(0,0,0,.3);
    flex-shrink: 0;
  }
  .ep-cover-list-placeholder {
    width: 72px; height: 72px; min-width: 72px;
    border-radius: 10px; flex-shrink: 0;
    background: rgba(<?= $accentRgb ?>,.1);
    display: flex; align-items: center; justify-content: center;
  }
  .ep-cover-list-placeholder svg { opacity: .3; width: 24px; height: 24px; }

  /* Corps texte */
  .ep-list-body {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column;
    gap: .18rem;
  }

  /* Bouton play circulaire */
  .ep-play-btn {
    width: 38px; height: 38px; min-width: 38px;
    border-radius: 50%;
    background: var(--surface2, #222);
    border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: background .15s, border-color .15s;
  }
  .episode-card-list:hover .ep-play-btn {
    background: var(--accent);
    border-color: var(--accent);
  }
  .episode-card-list:hover .ep-play-btn svg { stroke: #0d0d0f; }
  .ep-play-btn svg { transition: stroke .15s; }
  .episode-card-list.is-playing .ep-play-btn { background: var(--accent); border-color: var(--accent); }
  .episode-card-list.is-playing .ep-play-btn svg { stroke: #0d0d0f; }
  .episode-card-list.is-playing .icon-play  { display: none !important; }
  .episode-card-list.is-playing .icon-pause { display: block !important; }

  /* ── GRID MODE ───────────────────────────────────── */
  .episodes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }

  .episode-card-grid {
    display: flex; flex-direction: column;
    border-radius: 16px; overflow: hidden;
    border: 1px solid var(--border);
    background: var(--surface);
    text-decoration: none; color: inherit;
    transition: border-color .18s, transform .2s, box-shadow .2s;
  }
  .episode-card-grid:hover {
    border-color: rgba(<?= $accentRgb ?>,.4);
    transform: translateY(-3px);
    box-shadow: 0 8px 32px rgba(0,0,0,.22);
  }
  .episode-card-grid:hover .ep-title { color: var(--accent); }

  .ep-cover-wrap {
    position: relative; aspect-ratio: 1/1; overflow: hidden;
    background: rgba(<?= $accentRgb ?>,.06); flex-shrink: 0;
  }
  .ep-cover-wrap img {
    width: 100%; height: 100%; object-fit: cover; display: block;
    transition: transform .35s ease;
  }
  .episode-card-grid:hover .ep-cover-wrap img { transform: scale(1.04); }
  .ep-cover-wrap-placeholder {
    display: flex; align-items: center; justify-content: center;
  }
  .ep-cover-wrap-placeholder svg { opacity: .2; width: 40px; height: 40px; }
  .ep-num-badge {
    position: absolute; top: 10px; left: 10px;
    background: rgba(0,0,0,.6); backdrop-filter: blur(6px);
    color: #fff; font-size: .65rem; font-weight: 800;
    letter-spacing: .06em; border-radius: 6px; padding: .2rem .5rem;
  }
  .ep-grid-body { display: flex; flex-direction: column; padding: .9rem 1rem .85rem; flex: 1; gap: .2rem; }

  /* ── COMMUN ──────────────────────────────────────── */
  .ep-eyebrow {
    font-size: .65rem; font-weight: 700; color: var(--accent);
    text-transform: uppercase; letter-spacing: .08em;
  }
  .ep-title {
    font-family: var(--font-heading); font-size: .97rem;
    font-weight: var(--font-weight-heading); letter-spacing: -.01em;
    line-height: 1.3; transition: color .2s;
  }
  .ep-guest {
    font-size: .78rem; color: var(--muted2, #999);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ep-meta-row {
    display: flex; align-items: center; gap: .35rem;
    font-size: .72rem; color: var(--muted);
    margin-top: .15rem;
    white-space: nowrap; overflow: hidden;
  }
  .ep-meta-sep { opacity: .4; }
  .ep-plays { color: var(--muted); }

  footer { text-align:center; padding:2rem 0; border-top:1px solid var(--border); font-size:.78rem; color:var(--muted); }
  footer a { color:var(--muted); text-decoration:none; }
  footer a:hover { color:var(--text); }
  .empty-state { text-align:center; padding:4rem 0; color:var(--muted); }
  .empty-state .icon { font-size:3rem; margin-bottom:1rem; }

  /* ═══════════════════════════════════
     RESPONSIVE
  ═══════════════════════════════════ */
  @media (max-width: 640px) {
    .container { padding: 0 1rem; }

    header { padding: 2.5rem 0 2rem; }
    header h1 { font-size: clamp(1.6rem, 8vw, 2.4rem); }
    .tagline { font-size: 1rem; }
    .logo-icon { width: 52px; height: 52px; }
    .logo-img  { width: 56px; height: 56px; }

    .header-actions { gap: .5rem; }
    .cta-btn { font-size: .78rem; padding: .35rem .85rem; }

    .episode-card-list { border-radius: 12px; padding: .6rem; gap: .7rem; }
    .ep-cover-list, .ep-cover-list-placeholder { width: 60px; height: 60px; min-width: 60px; border-radius: 8px; }
    .ep-play-btn { width: 32px; height: 32px; min-width: 32px; }

    .episodes-grid { grid-template-columns: repeat(2, 1fr); gap: .6rem; }
    .ep-cover-wrap, .ep-cover-wrap-placeholder { aspect-ratio: 1/1; }
    .ep-grid-body { padding: .7rem .75rem .65rem; }

    .ep-title { font-size: .88rem; }
    .ep-guest { font-size: .72rem; }
    .ep-meta-row { font-size: .68rem; }
  }

  @media (max-width: 400px) {
    .ep-play { font-size: .7rem; padding: .3rem .7rem; }
  }

  /* ── Featured episode hero ─────────────────────────────────────── */
  .featured-ep {
    display: flex; gap: 2rem; align-items: center;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 2rem;
    margin-bottom: 2.5rem;
    position: relative;
    overflow: hidden;
  }
  .featured-ep::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse 60% 80% at 0% 50%, rgba(<?= $accentRgb ?>,.06) 0%, transparent 70%);
    pointer-events: none;
  }
  .featured-cover {
    flex-shrink: 0;
    width: 180px; height: 180px;
    border-radius: 12px;
    object-fit: cover;
    box-shadow: 0 12px 40px rgba(0,0,0,.45);
  }
  .featured-cover-placeholder {
    flex-shrink: 0;
    width: 180px; height: 180px;
    border-radius: 12px;
    background: var(--bg);
    border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
  }
  .featured-body { flex: 1; min-width: 0; }
  .featured-label {
    font-size: .65rem; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: var(--accent); margin-bottom: .6rem;
  }
  .featured-title {
    font-family: var(--font-heading);
    font-size: clamp(1.2rem, 3vw, 1.75rem);
    font-weight: var(--font-weight-heading, 800);
    letter-spacing: -.02em;
    line-height: 1.2;
    margin-bottom: .6rem;
    color: var(--text);
  }
  .featured-desc {
    font-size: .88rem;
    color: var(--muted);
    line-height: 1.6;
    margin-bottom: 1.1rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .featured-meta {
    display: flex; gap: 1rem; align-items: center;
    font-size: .78rem; color: var(--muted);
    margin-bottom: 1.25rem; flex-wrap: wrap;
  }
  .featured-play {
    display: inline-flex; align-items: center; gap: .6rem;
    background: var(--accent); color: #0d0d0f;
    border: none; border-radius: 50px;
    padding: .75rem 1.5rem;
    font-family: var(--font-heading); font-size: .88rem; font-weight: 800;
    cursor: pointer; text-decoration: none;
    transition: opacity .15s, transform .15s;
  }
  .featured-play:hover { opacity: .88; transform: translateY(-1px); }
  .featured-listen-link {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .82rem; color: var(--muted); text-decoration: none;
    margin-left: .75rem; transition: color .15s;
  }
  .featured-listen-link:hover { color: var(--text); }
  @media (max-width: 768px) {
    .featured-ep { flex-direction: column; gap: 1.25rem; padding: 1.25rem; }
    .featured-cover, .featured-cover-placeholder { width: 100%; height: auto; aspect-ratio: 1; }
    .featured-title { font-size: clamp(1.1rem, 4vw, 1.4rem); }
    .featured-play { padding: .65rem 1.25rem; font-size: .82rem; }
  }
</style>
<script src="<?= url('/admin/assets/feather.min.js') ?>"></script>
</head>
<body>
<div class="container">

<?php foreach ($sections as $section):
  switch ($section):
  case 'header': ?>
  <header>
    <div class="logo-wrap">
      <?php if ($logoType === 'image' && $logoImage): ?>
        <img src="<?= url('/audio/' . e($logoImage)) ?>" alt="<?= $podcastTitle ?>" class="logo-img">
      <?php else: ?>
        <div class="logo-icon">
          <i data-feather="mic" style="width:18px;height:18px;stroke:#0d0d0f;stroke-width:2.5"></i>
        </div>
      <?php endif; ?>
    </div>
    <h1><?= $podcastTitle ?></h1>
    <p class="tagline"><?= $tagline ?></p>
    <div class="header-actions">
      <a href="<?= $ctaUrl ?>" class="cta-btn">
        <i data-feather="rss"></i>
        <?= $ctaLabel ?>
      </a>
    </div>
    <?php if ($socialLinks): ?>
    <div class="social-links">
      <?php foreach ($socialLinks as $net => $info): ?>
        <a href="<?= e($info['url']) ?>" target="_blank" rel="noopener" class="social-link" title="<?= e($info['label']) ?>">
          <?= socialIcon($net) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </header>
  <?php break;

  case 'episodes': ?>
  <?php if ($latestEp): ?>
  <div class="featured-ep" id="featured-ep"
       data-audio="<?= !empty($latestEp['audio']) ? url('/audio/' . e($latestEp['audio'])) : '' ?>"
       data-title="<?= e($latestEp['title'] ?? '') ?>"
       data-cover="<?= !empty($latestEp['cover']) ? url('/audio/' . e($latestEp['cover'])) : '' ?>">

    <?php if (!empty($latestEp['cover'])): ?>
      <img src="<?= url('/audio/' . e($latestEp['cover'])) ?>" alt="<?= e($latestEp['title'] ?? '') ?>" class="featured-cover">
    <?php else: ?>
      <div class="featured-cover-placeholder">
        <i data-feather="mic" style="width:40px;height:40px;opacity:.3"></i>
      </div>
    <?php endif; ?>

    <div class="featured-body">
      <div class="featured-label">Dernier épisode</div>
      <div class="featured-title"><?= e($latestEp['title'] ?? 'Sans titre') ?></div>
      <?php if (!empty($latestEp['description'])): ?>
        <div class="featured-desc"><?= e($latestEp['description']) ?></div>
      <?php endif; ?>
      <div class="featured-meta">
        <?php if (!empty($latestEp['date'])): ?>
          <span><?= e(date('d F Y', strtotime($latestEp['date']))) ?></span>
        <?php endif; ?>
        <?php if (!empty($latestEp['duration'])): ?>
          <span>·</span><span><?= e($latestEp['duration']) ?></span>
        <?php endif; ?>
        <?php if (!empty($latestEp['guest'])): ?>
          <span>·</span><span><?= e($latestEp['guest']) ?></span>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.5rem">
        <?php if (!empty($latestEp['audio'])): ?>
        <button class="featured-play" onclick="featuredPlay(this)">
          <svg id="feat-play-icon" width="16" height="16" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3" fill="currentColor"/></svg>
          <svg id="feat-pause-icon" width="16" height="16" viewBox="0 0 24 24" style="display:none"><rect x="6" y="4" width="4" height="16" fill="currentColor"/><rect x="14" y="4" width="4" height="16" fill="currentColor"/></svg>
          <span id="feat-play-label">Écouter</span>
        </button>
        <?php endif; ?>
        <a href="<?= url('/episodes/' . e($latestEp['slug'])) ?>" class="featured-listen-link">
          Voir l'épisode
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="episodes-section">
    <div class="section-label"><?= count($episodes) ?> épisode<?= count($episodes) > 1 ? 's' : '' ?></div>
    <?php if (empty($episodes)): ?>
      <div class="empty-state"><div class="icon">🎙️</div><p>Les épisodes arrivent bientôt !</p></div>
    <?php elseif ($epStyle === 'grid'): ?>
      <div class="episodes-grid">
        <?php foreach ($episodes as $ep): ?>
          <a href="<?= url('/episodes/' . e($ep['slug'])) ?>" class="episode-card-grid">
            <div class="ep-cover-wrap<?= empty($ep['cover']) ? ' ep-cover-wrap-placeholder' : '' ?>">
              <?php if (!empty($ep['cover'])): ?>
                <img src="<?= url('/audio/' . e($ep['cover'])) ?>" alt="<?= e($ep['title'] ?? '') ?>">
              <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
              <?php endif; ?>
              <?php if ($showNum && !empty($ep['episode'])): ?>
                <span class="ep-num-badge">Ep. <?= e($ep['episode']) ?></span>
              <?php endif; ?>
            </div>
            <div class="ep-grid-body">
              <?php if (!empty($ep['guest'])): ?>
                <div class="ep-guest"><?= e($ep['guest']) ?></div>
              <?php endif; ?>
              <div class="ep-title"><?= e($ep['title'] ?? 'Sans titre') ?></div>
              <div class="ep-meta-row">
                <?php if ($showDur && !empty($ep['duration'])): ?>
                  <span><?= e($ep['duration']) ?></span>
                <?php endif; ?>
                <?php $plays = $epTotals[$ep['slug']] ?? 0; if ($plays > 0): ?>
                  <span class="ep-meta-sep">·</span>
                  <span class="ep-plays"><?= $plays >= 1000 ? round($plays/1000,1).'k' : $plays ?> écoutes</span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="episodes-list">
      <?php foreach ($episodes as $ep): ?>
        <div class="episode-card-list"
             data-href="<?= url('/episodes/' . e($ep['slug'])) ?>"
             data-audio="<?= !empty($ep['audio']) ? url('/audio/' . e($ep['audio'])) : '' ?>"
             data-title="<?= e($ep['title'] ?? 'Sans titre') ?>"
             data-cover="<?= !empty($ep['cover']) ? url('/audio/' . e($ep['cover'])) : '' ?>"
             onclick="cardClick(event,this)">
          <?php if (!empty($ep['cover'])): ?>
            <img src="<?= url('/audio/' . e($ep['cover'])) ?>" alt="<?= e($ep['title'] ?? '') ?>" class="ep-cover-list">
          <?php else: ?>
            <div class="ep-cover-list-placeholder">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            </div>
          <?php endif; ?>
          <div class="ep-list-body">
            <?php if (!empty($ep['guest'])): ?>
              <div class="ep-guest"><?= e($ep['guest']) ?></div>
            <?php endif; ?>
            <div class="ep-title"><?= e($ep['title'] ?? 'Sans titre') ?></div>
            <div class="ep-meta-row">
              <?php if ($showDur && !empty($ep['duration'])): ?><span><?= e($ep['duration']) ?></span><?php endif; ?>
              <?php $plays = $epTotals[$ep['slug']] ?? 0; if ($plays > 0): ?>
                <span class="ep-meta-sep">·</span>
                <span class="ep-plays"><?= $plays >= 1000 ? round($plays/1000,1).'k' : $plays ?> écoutes</span>
              <?php endif; ?>
            </div>
          </div>
          <button class="ep-play-btn" onclick="playEpisode(event,this)" aria-label="Écouter">
            <svg class="icon-play" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3" fill="currentColor" stroke="none"/></svg>
            <svg class="icon-pause" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" style="display:none"><rect x="6" y="4" width="4" height="16" fill="currentColor"/><rect x="14" y="4" width="4" height="16" fill="currentColor"/></svg>
          </button>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php break;

  case 'footer': ?>
  <footer>
    <?php if ($footerText): ?>
      <p><?= $footerText ?></p>
    <?php else: ?>
      <p><?= $podcastTitle ?> · <?= $author ?></p>
      <div style="display:flex;align-items:center;justify-content:center;gap:1.25rem;margin-top:.6rem">
        <a href="<?= url('/rss.xml') ?>" title="Flux RSS" style="display:flex;align-items:center;gap:.4rem;color:var(--muted);text-decoration:none;font-size:.78rem;transition:color .15s" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>
          RSS
        </a>
        <a href="<?= url('/admin/') ?>" title="Administration" style="display:flex;align-items:center;gap:.4rem;color:var(--muted);text-decoration:none;font-size:.78rem;transition:color .15s" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
          Admin
        </a>
      </div>
    <?php endif; ?>

  </footer>
  <?php break;
  endswitch;
endforeach; ?>

</div>

<!-- Mini player home -->
<div id="home-player" style="
  display:none; position:fixed; bottom:0; left:0; right:0; z-index:1000;
  background:var(--surface); border-top:1px solid var(--border);
  padding:.75rem 1.25rem; gap:1rem;
  align-items:center;
  box-shadow: 0 -4px 24px rgba(0,0,0,.4);
">
  <img id="hp-cover" src="" style="width:40px;height:40px;border-radius:6px;object-fit:cover;display:none">
  <div style="flex:1;min-width:0">
    <div id="hp-title" style="font-size:.82rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
    <div style="display:flex;align-items:center;gap:.5rem;margin-top:.3rem">
      <span id="hp-cur" style="font-size:.68rem;color:var(--muted);white-space:nowrap">0:00</span>
      <div id="hp-bar" onclick="hpSeek(event)" style="flex:1;height:4px;background:var(--border);border-radius:2px;cursor:pointer;position:relative">
        <div id="hp-fill" style="height:100%;background:var(--accent);border-radius:2px;width:0%;transition:width .1s linear"></div>
      </div>
      <span id="hp-dur" style="font-size:.68rem;color:var(--muted);white-space:nowrap">—</span>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0">
    <button onclick="hpSkip(-15)" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:.68rem;display:flex;flex-direction:column;align-items:center;gap:1px;padding:.3rem">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.5 15a9 9 0 1 0 .5-5.5"/></svg><span>15</span>
    </button>
    <button id="hp-playpause" onclick="hpToggle()" style="width:38px;height:38px;border-radius:50%;background:var(--accent);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center">
      <svg id="hp-play-icon" width="14" height="14" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3" fill="#0d0d0f"/></svg>
      <svg id="hp-pause-icon" width="14" height="14" viewBox="0 0 24 24" style="display:none"><rect x="6" y="4" width="4" height="16" fill="#0d0d0f"/><rect x="14" y="4" width="4" height="16" fill="#0d0d0f"/></svg>
    </button>
    <button onclick="hpSkip(30)" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:.68rem;display:flex;flex-direction:column;align-items:center;gap:1px;padding:.3rem">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 1 1-.5-5.5"/></svg><span>30</span>
    </button>
    <button onclick="hpClose()" style="background:none;border:none;color:var(--muted);cursor:pointer;padding:.3rem">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
</div>

<audio id="home-audio"></audio>

<script>
const audio = document.getElementById('home-audio');
const player = document.getElementById('home-player');
let currentCard = null;

function syncBodyPadding() {
  if (player.style.display !== 'none' && player.style.display !== '') {
    document.body.style.paddingBottom = (player.offsetHeight + 8) + 'px';
  }
}
window.addEventListener('resize', syncBodyPadding);

function fmtTime(s) {
  if (!isFinite(s)) return '—';
  const m = Math.floor(s/60), sec = Math.floor(s%60);
  return m + ':' + String(sec).padStart(2,'0');
}

function cardClick(e, card) {
  // Si le click vient du bouton play, ne pas naviguer
  if (e.target.closest('.ep-play-btn')) return;
  window.location.href = card.dataset.href;
}

function playEpisode(e, btn) {
  e.stopPropagation();
  const card = btn.closest('.episode-card-list');
  const src  = card.dataset.audio;
  if (!src) { window.location.href = card.dataset.href; return; }

  if (currentCard === card && !audio.paused) {
    // Pause
    audio.pause();
    card.classList.remove('is-playing');
    document.getElementById('hp-play-icon').style.display  = '';
    document.getElementById('hp-pause-icon').style.display = 'none';
    return;
  }

  // Changer de carte
  if (currentCard && currentCard !== card) {
    currentCard.classList.remove('is-playing');
  }
  currentCard = card;
  card.classList.add('is-playing');

  // Afficher le player
  const title = card.dataset.title;
  const cover = card.dataset.cover;
  document.getElementById('hp-title').textContent = title;
  const img = document.getElementById('hp-cover');
  if (cover) { img.src = cover; img.style.display = ''; }
  else { img.style.display = 'none'; }
  player.style.display = 'flex';
  syncBodyPadding();

  // Charger et jouer
  if (audio.src !== src) {
    audio.src = src;
    audio.load();
  }
  audio.play().catch(() => {});

  document.getElementById('hp-play-icon').style.display  = 'none';
  document.getElementById('hp-pause-icon').style.display = '';
}

audio.addEventListener('timeupdate', () => {
  const pct = audio.duration ? (audio.currentTime / audio.duration * 100) : 0;
  document.getElementById('hp-fill').style.width = pct + '%';
  document.getElementById('hp-cur').textContent  = fmtTime(audio.currentTime);
  document.getElementById('hp-dur').textContent  = fmtTime(audio.duration);
});

audio.addEventListener('ended', () => {
  if (currentCard) currentCard.classList.remove('is-playing');
  document.getElementById('hp-play-icon').style.display  = '';
  document.getElementById('hp-pause-icon').style.display = 'none';
});

function hpToggle() {
  if (audio.paused) {
    audio.play();
    if (currentCard) currentCard.classList.add('is-playing');
    document.getElementById('hp-play-icon').style.display  = 'none';
    document.getElementById('hp-pause-icon').style.display = '';
  } else {
    audio.pause();
    if (currentCard) currentCard.classList.remove('is-playing');
    document.getElementById('hp-play-icon').style.display  = '';
    document.getElementById('hp-pause-icon').style.display = 'none';
  }
}

function hpSkip(s) { audio.currentTime = Math.max(0, audio.currentTime + s); }

function hpSeek(e) {
  const bar = document.getElementById('hp-bar');
  const pct = (e.clientX - bar.getBoundingClientRect().left) / bar.offsetWidth;
  if (audio.duration) audio.currentTime = pct * audio.duration;
}

function hpClose() {
  audio.pause();
  if (currentCard) currentCard.classList.remove('is-playing');
  player.style.display = 'none';
  document.body.style.paddingBottom = '';
  currentCard = null;
  // Reset featured button
  resetFeatured();
}

function featuredPlay(btn) {
  const ep    = document.getElementById('featured-ep');
  const src   = ep ? ep.dataset.audio : '';
  const title = ep ? ep.dataset.title  : '';
  const cover = ep ? ep.dataset.cover  : '';
  if (!src) return;

  const playIcon  = document.getElementById('feat-play-icon');
  const pauseIcon = document.getElementById('feat-pause-icon');
  const label     = document.getElementById('feat-play-label');

  if (!audio.paused && audio.src.endsWith(src.split('/').pop())) {
    audio.pause();
    playIcon.style.display  = '';
    pauseIcon.style.display = 'none';
    label.textContent = 'Écouter';
    return;
  }

  // Désactiver toute card active
  if (currentCard) currentCard.classList.remove('is-playing');
  currentCard = null;

  document.getElementById('hp-title').textContent = title;
  const img = document.getElementById('hp-cover');
  if (cover) { img.src = cover; img.style.display = ''; }
  else { img.style.display = 'none'; }
  player.style.display = 'flex';
  syncBodyPadding();

  if (audio.src !== src) { audio.src = src; audio.load(); }
  audio.play().catch(() => {});

  playIcon.style.display  = 'none';
  pauseIcon.style.display = '';
  label.textContent = 'Pause';
  document.getElementById('hp-play-icon').style.display  = 'none';
  document.getElementById('hp-pause-icon').style.display = '';
}

function resetFeatured() {
  const pi = document.getElementById('feat-play-icon');
  const pa = document.getElementById('feat-pause-icon');
  const lb = document.getElementById('feat-play-label');
  if (pi) pi.style.display  = '';
  if (pa) pa.style.display  = 'none';
  if (lb) lb.textContent    = 'Écouter';
}

audio.addEventListener('ended', () => { resetFeatured(); });

document.addEventListener('DOMContentLoaded', function() {
  if (window.feather) feather.replace({'stroke-width': 2});

  // Transitions de page
  document.body.classList.add('page-ready');
  document.addEventListener('click', function(e) {
    const a = e.target.closest('a[href]');
    if (!a) return;
    const href = a.getAttribute('href') || '';
    if (a.target === '_blank' || a.download ||
        href.startsWith('http') || href.startsWith('#') ||
        href.startsWith('javascript') || href === '') return;
    e.preventDefault();
    document.body.style.transition = 'opacity .18s ease';
    document.body.style.opacity    = '0';
    setTimeout(function() { window.location.href = href; }, 180);
  });
});
</script>
</body>
</html>
