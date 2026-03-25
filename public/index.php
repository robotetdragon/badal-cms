<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';

$configDir = dirname($config['content_dir']) . '/config';
$themesDir = ROOT_DIR . '/themes';

$parser   = new EpisodeParser($config['content_dir']);
$episodes = $parser->getAll();
$home     = new HomeManager($configDir);
$theme    = new ThemeManager($themesDir);
$theme->loadActive($home->get('active_theme', 'sombre'));

$t            = $theme->getAll();  // couleurs + typo
$h            = $home->getAll();   // config home
$podcastTitle = e($config['podcast_title']);
$podcastDesc  = e($config['podcast_description']);
$author       = e($config['author']);
$sm           = new StatsManager($configDir);
$epTotals     = $sm->getAllTotals();
$baseUrl      = $config['base_url'];

$tagline     = !empty($h['home_tagline']) ? e($h['home_tagline']) : $podcastDesc;
$ctaLabel    = e($h['home_cta_label'] ?: __('pub_rss_subscribe'));
$ctaRaw  = $h['home_cta_url'] ?? '';
// If the stored URL is a relative path (starts with /), prefix with base_url
if ($ctaRaw && $ctaRaw[0] === '/') {
    $ctaUrl = e(url($ctaRaw));
} elseif ($ctaRaw) {
    $ctaUrl = e($ctaRaw);
} else {
    $ctaUrl = e(url('/rss.xml'));
}
$footerText  = !empty($h['home_footer_text']) ? e($h['home_footer_text']) : null;
$sections    = is_array($h['sections']) ? $h['sections'] : ['header','episodes','footer'];
$headerAlign = $h['header_align'] === 'left' ? 'left' : 'center';
$epStyle     = $h['episodes_style'] === 'grid' ? 'grid' : 'list';
$showNum     = ($h['show_episode_number']   ?? '1') !== '0';
$showDate    = ($h['show_episode_date']     ?? '1') !== '0';
$showDur     = ($h['show_episode_duration'] ?? '1') !== '0';
$logoType    = $h['logo_type'] ?? 'icon';
$logoImage   = $h['logo_image'] ?? '';
$coverImage  = $h['cover_image'] ?: ($config['cover_image'] ?? '');

$socials = ThemeManager::socialNetworks();
$socialLinks = [];
foreach ($socials as $net => $info) {
    $url = trim($h['social_' . $net] ?? '');
    if ($url) $socialLinks[$net] = ['url' => $url, 'label' => $info['label']];
}

$cssVars  = $theme->toCssVars();
$fontsUrl = $theme->toGoogleFontsUrl();

// Latest episode for the hero
$latestEp = !empty($episodes) ? $episodes[0] : null;

$coverStyle = '';
if ($coverImage) {
    $coverStyle = "background-image:url('" . url('/audio/' . e($coverImage)) . "');background-size:cover;background-position:center;";
}

// Accent color → RGB for rgba()
$hex = ltrim($t['color_accent'] ?? '#e8ff5a', '#');
if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
$accentRgb = hexdec(substr($hex,0,2)) . ',' . hexdec(substr($hex,2,2)) . ',' . hexdec(substr($hex,4,2));

function socialIcon(string $net): string {
    $icons = [
        'website'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
        'twitter'    => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.741l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        'instagram'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>',
        'youtube'    => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58A2.78 2.78 0 0 0 3.41 19.6C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.95A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58zM9.75 15.02V8.98L15.5 12l-5.75 3.02z"/></svg>',
        'spotify'    => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.622.622 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.622.622 0 0 1-.277-1.215c3.809-.87 7.077-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.72a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 0 1-.973-.519.781.781 0 0 1 .519-.973c3.632-1.102 8.147-.568 11.234 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 0 1-.582-1.781c3.532-1.155 9.404-.932 13.115 1.338a.936.936 0 0 1 .084 1.602z"/></svg>',
        'apple'      => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/></svg>',
        'linkedin'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
        'tiktok'     => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
        'pocketcast' => '<svg viewBox="0 0 24 24" fill="currentColor" width="15" height="15"><path d="M12 0C5.372 0 0 5.372 0 12s5.372 12 12 12 12-5.372 12-12S18.628 0 12 0zm0 3.6c4.636 0 8.4 3.764 8.4 8.4 0 4.636-3.764 8.4-8.4 8.4-4.636 0-8.4-3.764-8.4-8.4 0-4.636 3.764-8.4 8.4-8.4zm0 2.4a6 6 0 1 0 0 12 6 6 0 0 0 0-12zm0 2.4a3.6 3.6 0 1 1 0 7.2 3.6 3.6 0 0 1 0-7.2z"/></svg>',
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
<meta name="generator" content="Badal CMS <?= Version::current() ?>">
<?php $ogCover = $config['cover_image'] ?? $coverImage; $ogImage = $ogCover ? e(rtrim($baseUrl, '/') . '/audio/' . $ogCover) : ''; ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $podcastTitle ?>">
<meta property="og:description" content="<?= $tagline ?>">
<meta property="og:url" content="<?= e($baseUrl) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= $ogImage ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= $podcastTitle ?>">
<meta name="twitter:description" content="<?= $tagline ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= $ogImage ?>">
<?php endif; ?>
<link rel="icon" type="image/svg+xml" href="<?= url('/audio/badal_favicon.svg') ?>">
<link rel="alternate" type="application/rss+xml" title="<?= $podcastTitle ?> RSS" href="<?= url('/rss.xml') ?>">
<?php
// ── JSON-LD: Google rich results ─────────────────────────────────────────
$bUrl = rtrim($baseUrl, '/');

// 1) WebSite — sitelinks in SERPs
$ldWebSite = [
    '@context' => 'https://schema.org',
    '@type'    => 'WebSite',
    'name'     => $config['podcast_title'],
    'url'      => $bUrl,
];

// 2) ItemList — episode carousel in SERPs
$ldItems = [];
foreach (array_slice($episodes, 0, 10) as $i => $ep) {
    $ldItems[] = [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'url'      => $bUrl . '/episodes/' . ($ep['slug'] ?? ''),
    ];
}
$ldItemList = [
    '@context'        => 'https://schema.org',
    '@type'           => 'ItemList',
    'itemListElement' => $ldItems,
];

// 3) BreadcrumbList — breadcrumb
$ldBreadcrumb = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [[
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => $config['podcast_title'],
        'item'     => $bUrl,
    ]],
];
?>
<script type="application/ld+json"><?= json_encode($ldWebSite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/ld+json"><?= json_encode($ldItemList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/ld+json"><?= json_encode($ldBreadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="<?= $fontsUrl ?>" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root { <?= $cssVars ?> --layout-width: <?= (int)$h['layout_width'] ?>px; }

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
    padding: 4rem <?= $headerAlign === 'left' ? '1.5rem' : '0' ?> 3rem;
    text-align: <?= $headerAlign ?>;
    position: relative;
    <?= $coverStyle ?>
  }
  <?php if ($coverStyle): ?>
  header::after { content:''; position:absolute; inset:0; background:linear-gradient(to bottom, rgba(0,0,0,.7) 0%, var(--bg) 100%); pointer-events:none; }
  header > * { position:relative; z-index:1; }
  <?php endif; ?>

  .logo-wrap { display:<?= $headerAlign === 'center' ? 'inline-flex' : 'flex' ?>; align-items:center; margin-bottom:1.5rem; }
  .logo-icon { width:64px; height:64px; background:var(--bg); border-radius:16px; display:flex; align-items:center; justify-content:center; }
  .logo-icon-svg { width:36px; height:36px; background:var(--accent); -webkit-mask:url('<?= url('/audio/badal_favicon.svg') ?>') center/contain no-repeat; mask:url('<?= url('/audio/badal_favicon.svg') ?>') center/contain no-repeat; }
  .logo-icon svg { width:30px; height:30px; }
  .logo-img { width:72px; height:72px; border-radius:16px; object-fit:cover; }
  .footer-logo { height:36px; width:auto; filter:none; opacity:.7; margin-bottom:.75rem; transition:opacity .2s; }
  .footer-logo-wrap { height:36px; width:auto; display:inline-block; background:var(--text); -webkit-mask:url('<?= url('/audio/badal_logo.svg') ?>') center/contain no-repeat; mask:url('<?= url('/audio/badal_logo.svg') ?>') center/contain no-repeat; aspect-ratio:541/183; opacity:.7; transition:opacity .2s; }
  .footer-logo-wrap:hover { opacity:1; }
  .footer-logo:hover { opacity:1; }

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
     EPISODE CARDS — "ZOE" design
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
    cursor: pointer;
  }
  .episode-card-list:hover { border-color: rgba(<?= $accentRgb ?>,.4); box-shadow: 0 2px 16px rgba(0,0,0,.15); }
  .episode-card-list:hover .ep-title { color: var(--accent); }

  /* Rounded cover */
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

  /* Text body */
  .ep-list-body {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column;
    gap: .18rem;
  }

  /* Circular play button */
  .ep-play-btn {
    width: 38px; height: 38px; min-width: 38px;
    border-radius: 50%;
    background: var(--accent);
    border: none;
    color: #0d0d0f;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    cursor: pointer;
    transition: opacity .15s, transform .15s;
  }
  .ep-play-btn:hover { opacity: .88; transform: translateY(-1px); }
  .ep-play-btn svg { stroke: none; fill: #0d0d0f; }
  .episode-card-list.is-playing .ep-play-btn svg { fill: #0d0d0f; }
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

  /* ── COMMON ──────────────────────────────────────── */
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

    header { padding: 2.5rem <?= $headerAlign === 'left' ? '1rem' : '0' ?> 2rem; }
    header h1 { font-size: clamp(1.6rem, 8vw, 2.4rem); }
    .tagline { font-size: 1rem; }
    .logo-icon { width: 52px; height: 52px; }
    .logo-icon-svg { width: 28px; height: 28px; }
    .logo-img  { width: 56px; height: 56px; }
    .footer-logo { height: 28px; }

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
  .share-btn {
    display: inline-flex; align-items: center; justify-content: center;
    background: transparent; border: none;
    color: var(--muted); padding: .4rem;
    cursor: pointer; transition: color .2s;
  }
  .share-btn:hover { color: var(--accent); }
  .share-btn svg { width: 16px; height: 16px; }
  .share-toast {
    position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(20px);
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
    padding: .6rem 1.2rem; font-size: .82rem; color: var(--accent);
    opacity: 0; transition: opacity .25s, transform .25s; pointer-events: none; z-index: 9999;
  }
  .share-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
  /* Push bell — same style as .share-btn */
  .push-bell { display:inline-flex;align-items:center;justify-content:center;background:transparent;border:none;color:var(--muted);padding:.4rem;cursor:pointer;transition:color .2s; }
  .push-bell:hover { color:var(--accent); }
  .push-bell--active { color:var(--accent); }
  .push-bell svg { width:16px;height:16px; }
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
          <div class="logo-icon-svg"></div>
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
      <button class="share-btn" onclick="shareContent('<?= $podcastTitle ?>', '<?= e(rtrim($baseUrl, '/')) ?>')" aria-label="<?= __('pub_share') ?>" title="<?= __('pub_share') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
      </button>
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
      <div class="featured-label"><?= __('pub_latest_episode') ?></div>
      <div class="featured-title"><?= e($latestEp['title'] ?? __('pub_untitled')) ?></div>
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
          <span id="feat-play-label"><?= __('pub_listen') ?></span>
        </button>
        <?php endif; ?>
        <a href="<?= url('/episodes/' . e($latestEp['slug'])) ?>" class="featured-listen-link">
          <?= __('pub_view_episode') ?>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="episodes-section">
    <div class="section-label"><?= count($episodes) > 1 ? __('pub_episodes_count', ['%count%' => count($episodes)]) : __('pub_episode_count', ['%count%' => count($episodes)]) ?></div>
    <?php if (empty($episodes)): ?>
      <div class="empty-state"><div class="icon">🎙️</div><p><?= __('pub_coming_soon') ?></p></div>
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
              <div class="ep-title"><?= e($ep['title'] ?? __('pub_untitled')) ?></div>
              <div class="ep-meta-row">
                <?php if ($showDur && !empty($ep['duration'])): ?>
                  <span><?= e($ep['duration']) ?></span>
                <?php endif; ?>
                <?php $plays = $epTotals[$ep['slug']] ?? 0; if ($plays > 0): ?>
                  <span class="ep-meta-sep">·</span>
                  <span class="ep-plays"><?= $plays >= 1000 ? round($plays/1000,1).'k' : $plays ?> <?= __('pub_listens') ?></span>
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
             data-title="<?= e($ep['title'] ?? __('pub_untitled')) ?>"
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
            <div class="ep-title"><?= e($ep['title'] ?? __('pub_untitled')) ?></div>
            <div class="ep-meta-row">
              <?php if ($showDur && !empty($ep['duration'])): ?><span><?= e($ep['duration']) ?></span><?php endif; ?>
              <?php $plays = $epTotals[$ep['slug']] ?? 0; if ($plays > 0): ?>
                <span class="ep-meta-sep">·</span>
                <span class="ep-plays"><?= $plays >= 1000 ? round($plays/1000,1).'k' : $plays ?> <?= __('pub_listens') ?></span>
              <?php endif; ?>
            </div>
          </div>
          <button class="ep-play-btn" onclick="playEpisode(event,this)" aria-label="<?= __('pub_listen') ?>">
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
    <a href="<?= url('/') ?>"><div class="footer-logo-wrap" role="img" aria-label="<?= $podcastTitle ?>"></div></a>
    <?php if ($footerText): ?>
      <p><?= $footerText ?></p>
    <?php else: ?>
      <p><?= $podcastTitle ?> · <?= $author ?></p>
    <?php endif; ?>
    <div style="display:flex;align-items:center;justify-content:center;gap:1.25rem;margin-top:.6rem">
      <a href="<?= url('/rss.xml') ?>" title="Flux RSS" style="display:flex;align-items:center;gap:.4rem;color:var(--muted);text-decoration:none;font-size:.78rem;transition:color .15s" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>
        RSS
      </a>
      <a href="<?= url('/admin/') ?>" title="Administration" style="display:flex;align-items:center;gap:.4rem;color:var(--muted);text-decoration:none;font-size:.78rem;transition:color .15s" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
        Admin
      </a>
      <a href="https://github.com/robotetdragon/badal-cms" target="_blank" rel="noopener" title="GitHub" style="display:flex;align-items:center;gap:.4rem;color:var(--muted);text-decoration:none;font-size:.78rem;transition:color .15s" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/></svg>
        GitHub
      </a>
    </div>

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
  // If the click comes from the play button, don't navigate
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

  // Switch card
  if (currentCard && currentCard !== card) {
    currentCard.classList.remove('is-playing');
  }
  currentCard = card;
  card.classList.add('is-playing');

  // Show the player
  const title = card.dataset.title;
  const cover = card.dataset.cover;
  document.getElementById('hp-title').textContent = title;
  const img = document.getElementById('hp-cover');
  if (cover) { img.src = cover; img.style.display = ''; }
  else { img.style.display = 'none'; }
  player.style.display = 'flex';
  syncBodyPadding();

  // Load and play
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
    label.textContent = '<?= __('pub_listen') ?>';
    return;
  }

  // Deactivate any active card
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
  label.textContent = '<?= __('pub_pause') ?>';
  document.getElementById('hp-play-icon').style.display  = 'none';
  document.getElementById('hp-pause-icon').style.display = '';
}

function resetFeatured() {
  const pi = document.getElementById('feat-play-icon');
  const pa = document.getElementById('feat-pause-icon');
  const lb = document.getElementById('feat-play-label');
  if (pi) pi.style.display  = '';
  if (pa) pa.style.display  = 'none';
  if (lb) lb.textContent    = '<?= __('pub_listen') ?>';
}

audio.addEventListener('ended', () => { resetFeatured(); });

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

function shareContent(title, url) {
  if (navigator.share) {
    navigator.share({ title: title, url: url }).catch(function(){});
  } else {
    navigator.clipboard.writeText(url).then(function() { showToast('<?= __('pub_link_copied') ?>'); });
  }
}
function showToast(msg) {
  var t = document.getElementById('share-toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(function() { t.classList.remove('show'); }, 2000);
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
