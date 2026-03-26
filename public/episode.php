<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';

$slug   = $_GET['slug'] ?? basename($_SERVER['REQUEST_URI'], '.php');
$parser = new EpisodeParser($config['content_dir']);
$ep     = $parser->getBySlug($slug);

if (!$ep || ($ep['status'] ?? 'published') === 'draft') { http_response_code(404); echo '<h1>' . __('pub_not_found') . '</h1>'; exit; }

// Scheduling: hide if not yet published
$today       = date('Y-m-d');

$tm          = new TranscriptManager($config['content_dir']);
$hasTranscript = $tm->exists($slug);
$transcriptHtml = $hasTranscript ? $tm->toHtml($slug) : '';

$cm          = new ChaptersManager($config['content_dir']);
$hasChapters = $cm->exists($slug);
$chaptersHtml = $hasChapters ? $cm->toHtml($slug) : '';

$configDir    = dirname($config['content_dir']) . '/config';
$home         = new HomeManager($configDir);
$theme        = new ThemeManager(ROOT_DIR . '/themes');
$theme->loadActive($home->get('active_theme', 'sombre'));
$cssVars      = $theme->toCssVars();
$fontsUrl     = $theme->toGoogleFontsUrl();
$podcastTitle = $config['podcast_title'];
$baseUrl      = $config['base_url'];
$title        = $ep['title'] ?? __('pub_untitled');
$audioUrl     = !empty($ep['audio']) ? url('/audio/' . $ep['audio']) : '';

// Other episodes (all except this one, max 4)
$allEpisodes  = $parser->getAll();
$otherEps     = array_filter($allEpisodes, fn($e) => ($e['slug'] ?? '') !== $slug);
$otherEps     = array_slice(array_values($otherEps), 0, 4);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — <?= e($podcastTitle) ?></title>
<?php
$epDesc    = e($ep['description'] ?? $podcastTitle);
$epCover   = !empty($ep['cover']) ? e(rtrim($baseUrl, '/') . '/audio/' . $ep['cover']) : '';
$epUrl     = e(rtrim($baseUrl, '/') . '/episodes/' . $slug);
?>
<meta name="description" content="<?= $epDesc ?>">
<meta name="generator" content="Badal CMS <?= Version::current() ?>">
<meta property="og:type" content="article">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= $epDesc ?>">
<meta property="og:url" content="<?= $epUrl ?>">
<meta property="og:site_name" content="<?= e($podcastTitle) ?>">
<?php if ($epCover): ?><meta property="og:image" content="<?= $epCover ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $epCover ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= $epDesc ?>">
<?php if ($epCover): ?><meta name="twitter:image" content="<?= $epCover ?>">
<?php endif; ?>
<link rel="icon" type="image/svg+xml" href="<?= url('/audio/badal_favicon.svg') ?>">
<link rel="alternate" type="application/rss+xml" href="<?= url('/rss.xml') ?>">
<?php
// ── JSON-LD: Google rich results ─────────────────────────────────────────
$bUrl = rtrim($baseUrl, '/');
$epFullUrl = $bUrl . '/episodes/' . $slug;

// 1) Article + AudioObject — rich result detected by Google
$ldArticle = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Article',
    'headline'      => $ep['title'] ?? '',
    'description'   => $ep['description'] ?? '',
    'url'           => $epFullUrl,
    'datePublished' => $ep['date'] ?? '',
    'dateModified'  => $ep['date'] ?? '',
    'author'        => [
        '@type' => 'Person',
        'name'  => $config['author'] ?? '',
    ],
    'publisher'     => [
        '@type' => 'Organization',
        'name'  => $podcastTitle,
    ],
    'mainEntityOfPage' => $epFullUrl,
];
if ($epCover) {
    $ldArticle['image'] = $epCover;
    $ldArticle['publisher']['logo'] = ['@type' => 'ImageObject', 'url' => $epCover];
}
if ($audioUrl) {
    $ldAudio = [
        '@type'      => 'AudioObject',
        'contentUrl' => $audioUrl,
        'name'       => $ep['title'] ?? '',
    ];
    if (!empty($ep['duration'])) {
        $dParts = array_map('intval', explode(':', $ep['duration']));
        if (count($dParts) === 3) {
            $ldAudio['duration'] = sprintf('PT%dH%dM%dS', $dParts[0], $dParts[1], $dParts[2]);
        } elseif (count($dParts) === 2) {
            $ldAudio['duration'] = sprintf('PT%dM%dS', $dParts[0], $dParts[1]);
        }
    }
    if (!empty($ep['description'])) {
        $ldAudio['description'] = $ep['description'];
    }
    if (!empty($ep['date'])) {
        $ldAudio['uploadDate'] = $ep['date'];
    }
    $ldArticle['audio'] = $ldAudio;
}

// 2) BreadcrumbList — breadcrumb
$ldBreadcrumb = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        [
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => $podcastTitle,
            'item'     => $bUrl,
        ],
        [
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => $ep['title'] ?? '',
            'item'     => $epFullUrl,
        ],
    ],
];
?>
<script type="application/ld+json"><?= json_encode($ldArticle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/ld+json"><?= json_encode($ldBreadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="<?= $fontsUrl ?>" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  <?= $cssVars ?>
  --muted2: #999;
  --player-h: 76px;
}
html { scroll-padding-bottom: calc(var(--player-h) + 12px); }
body { font-family: var(--font-heading); background: var(--bg); color: var(--text); line-height: 1.7; padding-bottom: calc(var(--player-h) + 16px); opacity: 0; }
  body.page-ready { opacity: 1; transition: opacity .22s ease; }
  .container { animation: pageIn .28s cubic-bezier(.25,.46,.45,.94) both; }
  @keyframes pageIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
  }
.container { max-width: 700px; margin: 0 auto; padding: 0 1.5rem; }
.back-link { display:inline-flex; align-items:center; gap:.4rem; color:var(--muted); text-decoration:none; font-size:.82rem; padding:1.5rem 0; transition:color .2s; }
.back-link:hover { color:var(--text); }
.ep-header { padding:1.5rem 0; border-bottom:1px solid var(--border); margin-bottom:2rem; }
.ep-header-inner { display:flex; gap:2rem; align-items:flex-start; }
.ep-cover-wrap { flex-shrink:0; }
.ep-cover-wrap img { width:220px; height:220px; object-fit:cover; border-radius:14px; box-shadow:0 12px 40px rgba(0,0,0,.5); display:block; }
.ep-header-body { flex:1; min-width:0; }
.ep-play-big { display:inline-flex; align-items:center; gap:.6rem; background:var(--accent); color:#0d0d0f; border:none; border-radius:10px; padding:.75rem 1.5rem; font-family:inherit; font-size:.92rem; font-weight:800; cursor:pointer; margin-top:1rem; transition:opacity .15s; }
.ep-play-big:hover { opacity:.85; }
.ep-play-big svg { flex-shrink:0; }
.ep-share-btn {
  display:inline-flex; align-items:center; justify-content:center;
  background:transparent; border:none;
  color:var(--muted); padding:.5rem;
  cursor:pointer; margin-top:1rem; transition:color .2s;
}
.ep-share-btn:hover { color:var(--accent); }
.ep-share-btn svg { width:18px; height:18px; }
.share-toast {
  position:fixed; bottom:2rem; left:50%; transform:translateX(-50%) translateY(20px);
  background:var(--surface); border:1px solid var(--border); border-radius:10px;
  padding:.6rem 1.2rem; font-size:.82rem; color:var(--accent);
  opacity:0; transition:opacity .25s, transform .25s; pointer-events:none; z-index:9999;
}
.share-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
@media(max-width:560px) {
  .ep-header-inner { flex-direction:column; gap:1.25rem; }
  .ep-cover-wrap img { width:100%; height:auto; aspect-ratio:1; }
}
.ep-badge { display:inline-flex; align-items:center; gap:.4rem; background:rgba(232,255,90,.1); color:var(--accent); border-radius:20px; font-size:.75rem; font-weight:700; padding:.25rem .75rem; margin-bottom:1rem; }
h1 { font-size:clamp(1.6rem,5vw,2.4rem); font-weight:var(--font-weight-heading); letter-spacing:-.03em; line-height:1.15; margin-bottom:.75rem; }
.ep-desc { font-family:'Instrument Serif',serif; font-style:italic; font-size:1.05rem; color:var(--muted2); margin-bottom:1rem; }
.ep-meta { display:flex; gap:1.25rem; font-size:.8rem; color:var(--muted); flex-wrap:wrap; }
.ep-meta span { display:flex; align-items:center; gap:.35rem; }

/* Tabs (transcript / show notes) */
.ep-tabs { display:flex; border-bottom:1px solid var(--border); margin-bottom:1.75rem; }
.ep-tab { background:none; border:none; color:var(--muted); font-family:'Syne',sans-serif; font-size:.82rem; font-weight:600; padding:.65rem 1rem; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; transition:color .15s,border-color .15s; }
.ep-tab.active { color:var(--accent); border-bottom-color:var(--accent); }
.ep-pane { display:none; }
.ep-pane.active { display:block; }

.content-body { padding-bottom:1.5rem; }
.content-body h1,.content-body h2,.content-body h3 { font-weight:700; letter-spacing:-.02em; margin:2rem 0 .75rem; }
.content-body h2 { font-size:1.25rem; }
.content-body h3 { font-size:1.05rem; }
.content-body p { margin-bottom:1.25rem; color:#ccc; font-size:.95rem; }
.content-body strong { color:var(--text); font-weight:700; }
.content-body a { color:var(--accent); text-decoration:none; }
.content-body a:hover { text-decoration:underline; }

/* Chapters */
.chapters-body { padding-bottom:1.5rem; }
.chapters-list { list-style:none; padding:0; margin:0; }
.chapter-item { border-bottom:1px solid var(--border); }
.chapter-item:last-child { border-bottom:none; }
.chapter-link { display:flex; align-items:center; gap:.75rem; padding:.75rem .5rem; text-decoration:none; color:inherit; border-radius:6px; transition:background .15s; }
.chapter-link:hover { background:rgba(232,255,90,.06); }
.chapter-ts { font-family:monospace; font-size:.8rem; color:var(--accent); min-width:52px; flex-shrink:0; }
.chapter-title { font-size:.92rem; font-weight:600; }
.chapter-ext-link { font-size:.78rem; color:var(--muted); text-decoration:none; margin-left:.25rem; }
.chapter-ext-link:hover { color:var(--accent); }

/* Transcript */
.transcript-body { padding-bottom:1.5rem; }
.transcript-body p { font-size:.9rem; color:#bbb; line-height:1.8; margin-bottom:.6rem; }
.transcript-body .speaker { color:var(--text); font-family:'Syne',sans-serif; font-size:.82rem; letter-spacing:.04em; text-transform:uppercase; }
.transcript-body .ts-link { color:var(--accent); text-decoration:none; font-size:.78rem; font-family:monospace; margin-right:.25rem; }
.transcript-body .ts-link:hover { text-decoration:underline; }

footer { text-align:center; padding:2rem 0; border-top:1px solid var(--border); font-size:.78rem; color:var(--muted); }
footer a { color:var(--muted); text-decoration:none; }
footer a:hover { color:var(--text); }

/* ── Persistent player ────────────────────────────────────── */
.persist-player {
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 100;
  background: rgba(18,18,22,.95);
  backdrop-filter: blur(20px) saturate(1.4);
  border-top: 1px solid var(--border);
  height: var(--player-h);
  display: flex; align-items: center; gap: 1rem;
  padding: 0 1.25rem;
  transform: translateY(100%);
  transition: transform .35s cubic-bezier(.4,0,.2,1);
}
.persist-player.visible { transform: translateY(0); }

.pp-thumb {
  width: 44px; height: 44px; border-radius: 8px;
  background: rgba(232,255,90,.1);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; font-size: 1.1rem;
}
.pp-info { flex: 1; min-width: 0; }
.pp-title { font-size: .8rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pp-ep    { font-size: .7rem; color: var(--muted); }

.pp-controls { display: flex; align-items: center; gap: .6rem; flex-shrink: 0; }
.pp-btn {
  width: 36px; height: 36px; border-radius: 50%;
  background: none; border: 1px solid var(--border);
  color: var(--muted2); cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: color .15s, border-color .15s, background .15s;
  flex-shrink: 0;
}
.pp-btn:hover { color: var(--text); border-color: var(--muted); }
.pp-btn.play { background: var(--accent); border-color: var(--accent); color: #0d0d0f; width: 40px; height: 40px; }
.pp-btn.play:hover { background: #c8df3a; }

.pp-progress { flex: 1; min-width: 80px; max-width: 320px; }
.pp-bar { height: 3px; background: var(--border); border-radius: 3px; cursor: pointer; position: relative; margin-bottom: .3rem; }
.pp-fill { height: 100%; background: var(--accent); border-radius: 3px; width: 0%; transition: width .5s linear; }
.pp-times { display: flex; justify-content: space-between; font-size: .65rem; color: var(--muted); font-family: monospace; }

.pp-speed {
  background: none; border: 1px solid var(--border); border-radius: 5px;
  color: var(--muted); font-family: 'Syne', sans-serif; font-size: .7rem; font-weight: 700;
  padding: .22rem .45rem; cursor: pointer; flex-shrink: 0;
  transition: color .15s, border-color .15s;
}
.pp-speed:hover { color: var(--accent); border-color: var(--accent); }
.pp-close {
  background: none; border: none; color: var(--muted); cursor: pointer;
  font-size: .9rem; padding: .25rem; flex-shrink: 0; line-height: 1;
  transition: color .15s;
}
.pp-close:hover { color: var(--text); }

/* ── Other episodes ──────────────────────────────── */
.other-eps { margin: 2.5rem 0 1rem; }
.other-eps-label {
  font-size: .7rem; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: var(--muted);
  padding-bottom: .75rem; margin-bottom: 1.25rem;
  border-bottom: 1px solid var(--border);
}
.other-eps-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: .75rem;
}
.oe-card {
  display: flex; align-items: stretch;
  border-radius: 10px; overflow: hidden;
  border: 1px solid var(--border);
  background: var(--surface);
  text-decoration: none; color: inherit;
  transition: border-color .2s, transform .2s;
}
.oe-card:hover { border-color: var(--accent); transform: translateY(-1px); }
.oe-card:hover .oe-title { color: var(--accent); }
.oe-cover {
  width: 72px; min-width: 72px; height: 72px;
  object-fit: cover;
  border-right: 1px solid var(--border);
}
.oe-cover-placeholder {
  width: 72px; min-width: 72px; height: 72px;
  background: rgba(232,255,90,.07);
  border-right: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.oe-cover-placeholder svg { opacity: .25; width: 20px; height: 20px; }
.oe-body {
  padding: .6rem .75rem;
  display: flex; flex-direction: column; justify-content: center;
  min-width: 0;
}
.oe-num {
  font-size: .62rem; font-weight: 700; color: var(--accent);
  text-transform: uppercase; letter-spacing: .08em;
  margin-bottom: .15rem;
}
.oe-title {
  font-size: .82rem; font-weight: 700;
  line-height: 1.3; transition: color .2s;
  display: -webkit-box; -webkit-line-clamp: 2;
  -webkit-box-orient: vertical; overflow: hidden;
}
.oe-dur {
  font-size: .68rem; color: var(--muted);
  margin-top: .25rem;
}

@media (max-width: 640px) {
  .container { padding: 0 1rem; }
  h1 { font-size: 1.3rem; }
  .ep-header { padding: 1.5rem 0 1rem; }
  .ep-header-inner { gap: 1.25rem; }
  .ep-cover-wrap img { width: 160px; height: 160px; }
  .ep-meta { flex-wrap: wrap; gap: .5rem; }
  .back-link { margin-top: 1rem; }

  /* Other episodes: vertical cards in 2 columns */
  .other-eps-grid { grid-template-columns: repeat(2, 1fr); gap: .6rem; }
  .oe-card { flex-direction: column; align-items: stretch; }
  .oe-cover {
    width: 100%; min-width: unset; height: auto; aspect-ratio: 1;
    border-right: none; border-bottom: 1px solid var(--border);
  }
  .oe-cover-placeholder {
    width: 100%; min-width: unset; height: auto; aspect-ratio: 1;
    border-right: none; border-bottom: 1px solid var(--border);
  }
  .oe-body { padding: .5rem .6rem .6rem; }
  .oe-title { font-size: .78rem; }
  .oe-dur { font-size: .64rem; }
}
@media (max-width: 560px) {
  .ep-cover-wrap img { width: 100%; height: auto; aspect-ratio: 1; }
  .pp-progress { display: none; }
  .persist-player { gap: .75rem; padding: 0 .85rem; }
  .pp-speed { display: none; }
  .pp-time { font-size: .68rem; }
  h1 { font-size: 1.15rem; }
}
@media (max-width: 400px) {
  .other-eps-grid { gap: .45rem; }
  .oe-body { padding: .4rem .5rem .5rem; }
  .oe-title { font-size: .72rem; -webkit-line-clamp: 2; }
  .oe-num { font-size: .58rem; }
}
/* Push bell — same style as .ep-share-btn */
.push-bell { display:inline-flex;align-items:center;justify-content:center;background:transparent;border:none;color:var(--muted);padding:.5rem;cursor:pointer;margin-top:1rem;transition:color .2s; }
.push-bell:hover { color:var(--accent); }
.push-bell--active { color:var(--accent); }
.push-bell svg { width:18px;height:18px; }
</style>
<script src="<?= url('/admin/assets/feather.min.js') ?>"></script>
</head>
<body>

<div class="container">
  <a href="<?= url('/') ?>" class="back-link">
    <i data-feather="arrow-left" style="width:14px;height:14px"></i>
    Tous les épisodes
  </a>

  <div class="ep-header">
    <div class="ep-header-inner">

      <?php if (!empty($ep['cover'])): ?>
      <div class="ep-cover-wrap">
        <img src="<?= url('/audio/' . e($ep['cover'])) ?>" alt="<?= e($title) ?>">
      </div>
      <?php endif; ?>

      <div class="ep-header-body">
        <?php if (!empty($ep['episode'])): ?>
          <div class="ep-badge">Épisode <?= e($ep['episode']) ?></div>
        <?php endif; ?>
        <h1><?= e($title) ?></h1>
        <?php if (!empty($ep['description'])): ?>
          <p class="ep-desc"><?= e($ep['description']) ?></p>
        <?php endif; ?>
        <div class="ep-meta">
          <?php if (!empty($ep['date'])): ?>
            <span>
              <i data-feather="calendar" style="width:12px;height:12px"></i>
              <?= e(date('d/m/Y', strtotime($ep['date']))) ?>
            </span>
          <?php endif; ?>
          <?php if (!empty($ep['duration'])): ?>
            <span>
              <i data-feather="clock" style="width:12px;height:12px"></i>
              <?= e($ep['duration']) ?>
            </span>
          <?php endif; ?>
        </div>
        <?php if ($audioUrl): ?>
          <button onclick="togglePlayer()" class="ep-play-big" id="epPlayBtn">
            <svg width="16" height="16" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3" fill="currentColor" stroke="none"/></svg>
            <?= __('pub_listen') ?>
          </button>
        <?php endif; ?>
        <button class="ep-share-btn" onclick="shareEpisode()" aria-label="<?= __('pub_share') ?>" title="<?= __('pub_share') ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
        </button>
      </div>

    </div>
  </div>

  <!-- Content tabs -->
  <?php if ($hasChapters || $hasTranscript): ?>
  <div class="ep-tabs">
    <button class="ep-tab active" onclick="switchEpTab('shownotes',this)"><?= __('pub_show_notes') ?></button>
    <?php if ($hasChapters): ?>
    <button class="ep-tab" onclick="switchEpTab('chapters',this)"><?= __('pub_chapters') ?></button>
    <?php endif; ?>
    <?php if ($hasTranscript): ?>
    <button class="ep-tab" onclick="switchEpTab('transcript',this)"><?= __('pub_transcript') ?></button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div id="ep-shownotes" class="ep-pane active content-body">
    <?php if (!empty($ep['content_html'])): ?>
      <?= $ep['content_html'] ?>
    <?php else: ?>
      <p style="color:var(--muted);font-style:italic">Aucun show notes pour cet épisode.</p>
    <?php endif; ?>
  </div>

  <?php if ($hasChapters): ?>
  <div id="ep-chapters" class="ep-pane chapters-body">
    <?= $chaptersHtml ?>
  </div>
  <?php endif; ?>

  <?php if ($hasTranscript): ?>
  <div id="ep-transcript" class="ep-pane transcript-body">
    <?= $transcriptHtml ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($otherEps)): ?>
  <div class="other-eps">
    <div class="other-eps-label">Autres épisodes</div>
    <div class="other-eps-grid">
      <?php foreach ($otherEps as $oe): ?>
        <a href="<?= url('/episodes/' . e($oe['slug'])) ?>" class="oe-card">
          <?php if (!empty($oe['cover'])): ?>
            <img src="<?= url('/audio/' . e($oe['cover'])) ?>" alt="<?= e($oe['title'] ?? '') ?>" class="oe-cover">
          <?php else: ?>
            <div class="oe-cover-placeholder">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            </div>
          <?php endif; ?>
          <div class="oe-body">
            <?php if (!empty($oe['episode'])): ?>
              <div class="oe-num">Ep. <?= e($oe['episode']) ?></div>
            <?php endif; ?>
            <div class="oe-title"><?= e($oe['title'] ?? __('pub_untitled')) ?></div>
            <?php if (!empty($oe['duration'])): ?>
              <div class="oe-dur"><?= e($oe['duration']) ?></div>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <footer>
    <a href="<?= url('/') ?>"><?= e($podcastTitle) ?></a> · <a href="<?= url('/rss.xml') ?>">RSS</a> · <a href="/sitemap.xml">Sitemap</a>
  </footer>
</div>

<!-- ── Persistent Player ───────────────────────────────────── -->
<?php if ($audioUrl): ?>
<div class="persist-player" id="persist-player">
  <div class="pp-thumb">🎙️</div>

  <div class="pp-info">
    <div class="pp-title"><?= e($title) ?></div>
    <?php if (!empty($ep['episode'])): ?>
      <div class="pp-ep">Épisode <?= e($ep['episode']) ?></div>
    <?php endif; ?>
  </div>

  <div class="pp-controls">
    <button class="pp-btn" onclick="skip(-15)" title="−15s">
      <i data-feather="rotate-ccw" style="width:14px;height:14px;stroke-width:2.5"></i><span style="font-size:.6rem;font-weight:700">15</span>
    </button>

    <button class="pp-btn play" id="pp-play" onclick="togglePlayback()">
      <i id="pp-play-icon" data-feather="play" style="width:16px;height:16px;fill:currentColor;stroke:none"></i>
    </button>

    <button class="pp-btn" onclick="skip(30)" title="+30s">
      <i data-feather="rotate-cw" style="width:14px;height:14px;stroke-width:2.5"></i><span style="font-size:.6rem;font-weight:700">30</span>
    </button>
  </div>

  <div class="pp-progress">
    <div class="pp-bar" id="pp-bar" onclick="seekBar(event)">
      <div class="pp-fill" id="pp-fill"></div>
    </div>
    <div class="pp-times">
      <span id="pp-cur">0:00</span>
      <span id="pp-dur"><?= e($ep['duration'] ?? '—') ?></span>
    </div>
  </div>

  <button class="pp-speed" id="pp-speed" onclick="cycleSpeed()">1×</button>
  <button class="pp-close" onclick="closePlayer()" title="Fermer">✕</button>
</div>

<audio id="audio-el" preload="metadata">
  <source src="<?= e($audioUrl) ?>">
</audio>
<?php endif; ?>

<script>
// ── Tabs ──────────────────────────────────────────────────────────────
function switchEpTab(name, btn) {
  document.querySelectorAll('.ep-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.ep-tab').forEach(b => b.classList.remove('active'));
  document.getElementById('ep-' + name).classList.add('active');
  btn.classList.add('active');
}

// ── Timestamp deep-link ───────────────────────────────────────────────
document.querySelectorAll('.ts-link').forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    const secs = parseInt(a.dataset.secs);
    const audio = document.getElementById('audio-el');
    if (!audio) return;
    audio.currentTime = secs;
    showPlayer();
    audio.play();
  });
});

// ── Persistent player ─────────────────────────────────────────────────
const audio    = document.getElementById('audio-el');
const player   = document.getElementById('persist-player');
const fill     = document.getElementById('pp-fill');
const curEl    = document.getElementById('pp-cur');
const speeds   = [1, 1.25, 1.5, 1.75, 2];
let   speedIdx = 0;

function showPlayer() {
  player?.classList.add('visible');
}
function closePlayer() {
  player?.classList.remove('visible');
  audio?.pause();
  updateIcon();
}
function togglePlayer() {
  if (!player?.classList.contains('visible')) {
    showPlayer();
    audio?.play();
    updateIcon();
  } else {
    togglePlayback();
  }
}
function togglePlayback() {
  if (!audio) return;
  if (audio.paused) { audio.play(); } else { audio.pause(); }
  updateIcon();
}
function skip(s) {
  if (!audio) return;
  audio.currentTime = Math.max(0, Math.min(audio.duration || 0, audio.currentTime + s));
}
function cycleSpeed() {
  speedIdx = (speedIdx + 1) % speeds.length;
  if (audio) audio.playbackRate = speeds[speedIdx];
  document.getElementById('pp-speed').textContent = speeds[speedIdx] + '×';
}
function seekBar(e) {
  if (!audio) return;
  const rect = document.getElementById('pp-bar').getBoundingClientRect();
  const pct  = (e.clientX - rect.left) / rect.width;
  audio.currentTime = pct * (audio.duration || 0);
}
function fmt(s) {
  const m = Math.floor(s / 60), sec = Math.floor(s % 60);
  return m + ':' + String(sec).padStart(2, '0');
}
function updateIcon() {
  if (!audio) return;
  var btn = document.getElementById('pp-play');
  if (!btn) return;
  // After feather.replace(), the icon is an SVG — we replace the button content
  var icon = audio.paused ? 'play' : 'pause';
  btn.innerHTML = '<i data-feather="' + icon + '" style="width:16px;height:16px;fill:currentColor;stroke:none"></i>';
  if (window.feather) feather.replace();
}

if (audio) {
  audio.addEventListener('timeupdate', () => {
    if (!audio.duration) return;
    const pct = (audio.currentTime / audio.duration) * 100;
    if (fill) fill.style.width = pct + '%';
    if (curEl) curEl.textContent = fmt(audio.currentTime);
  });
  audio.addEventListener('play',  () => { showPlayer(); updateIcon(); });
  audio.addEventListener('pause', updateIcon);
  audio.addEventListener('ended', () => {
    updateIcon();
    fill && (fill.style.width = '0%');
    curEl && (curEl.textContent = '0:00');
  });
  // Save/restore position
  const key = 'ep-pos-<?= e($slug) ?>';
  const saved = parseFloat(localStorage.getItem(key) || '0');
  if (saved > 5) audio.currentTime = saved;
  setInterval(() => {
    if (!audio.paused && audio.currentTime > 0)
      localStorage.setItem(key, audio.currentTime);
  }, 5000);
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  if (window.feather) feather.replace({'stroke-width': 2});

  // Page transitions
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

function shareEpisode() {
  var epTitle = <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>;
  var epUrl   = <?= json_encode(rtrim($baseUrl, '/') . '/episodes/' . $slug, JSON_UNESCAPED_UNICODE) ?>;
  if (navigator.share) {
    navigator.share({ title: epTitle, url: epUrl }).catch(function(){});
  } else {
    navigator.clipboard.writeText(epUrl).then(function() {
      var t = document.getElementById('share-toast');
      if (!t) return;
      t.textContent = '<?= __('pub_link_copied') ?>';
      t.classList.add('show');
      setTimeout(function() { t.classList.remove('show'); }, 2000);
    });
  }
}
</script>
<div id="share-toast" class="share-toast"></div>
<?php
$wpConfigDir = dirname($config['content_dir']) . '/config';
$wpInstance  = new WebPush($wpConfigDir);
if ($wpInstance->isConfigured()):
?>
<script src="<?= url('/public/push.js') ?>"
  data-vapid="<?= e($wpInstance->getPublicKey()) ?>"
  data-subscribe-url="<?= url('/push-subscribe') ?>"></script>
<?php endif; ?>
</body>
</html>
