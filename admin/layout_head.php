<?php
ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Admin') ?> — Badal</title>
<link rel="icon" type="image/svg+xml" href="<?= url('/audio/badal_favicon.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #0d0d0f;
    --surface: #16161a;
    --surface2: #1e1e24;
    --border: #2a2a30;
    --accent: #e8ff5a;
    --accent-dim: #b8cc3a;
    --text: #f0ede8;
    --muted: #888;
    --danger: #ff5a5a;
    --success: #5aff9a;
    --nav-w: 220px;
  }


  /* ══════════════════════════════════════
     THÈME CLAIR (data-theme="light")
  ══════════════════════════════════════ */
  [data-theme="light"] {
    --bg:         #f5f0e8;
    --surface:    #fdf9f3;
    --surface2:   #ece6d8;
    --border:     #ddd6c4;
    --accent:     #c45c2a;
    --accent-dim: #a34820;
    --text:       #2a2018;
    --muted:      #8a7d6e;
    --danger:     #c03020;
    --success:    #2a7a48;
    color-scheme: light;
  }
  [data-theme="light"] .sidebar { box-shadow: 2px 0 16px rgba(0,0,0,.08); }
  [data-theme="light"] .sidebar-icon { background: var(--accent); }
  [data-theme="light"] .sidebar-icon i { stroke: #fff !important; }
  [data-theme="light"] .btn { color: #fff; }
  [data-theme="light"] .nav-link.active { background: rgba(0,0,0,.06); color: var(--accent); }
  [data-theme="light"] .nav-link.active i { stroke: var(--accent); }

  /* Toggle thème */
  .theme-toggle {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--muted);
    cursor: pointer;
    transition: color .15s, border-color .15s, background .15s;
    flex-shrink: 0;
  }
  .theme-toggle:hover { color: var(--text); border-color: var(--accent); }
  .theme-toggle i { pointer-events: none; }

  html, body { height: 100%; }

  body {
    font-family: 'Syne', sans-serif;
    background: var(--bg);
    color: var(--text);
    display: flex;
  }
  /* Transition : .main seul — sidebar reste fixe et visible */
  .main {
    opacity: 0;
    transform: translateY(10px);
    transition: opacity .25s ease, transform .25s cubic-bezier(.25,.46,.45,.94);
  }
  body.page-ready .main {
    opacity: 1;
    transform: translateY(0);
  }

  /* ══════════════════════════════════════
     SIDEBAR
  ══════════════════════════════════════ */
  .sidebar {
    width: var(--nav-w);
    min-height: 100vh;
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    z-index: 100;
    transition: transform .25s cubic-bezier(.4,0,.2,1);
  }

  .sidebar-brand {
    padding: 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: .75rem;
  }

  .sidebar-logo { height:44px; width:auto; background:var(--accent); transition:opacity .15s; }
  .sidebar-logo:hover { opacity:.8; }
  .sidebar-about { margin-left:auto; color:var(--muted); display:flex; align-items:center; transition:color .15s; }
  .sidebar-about:hover { color:var(--accent); }
  .sidebar-about svg { width:15px; height:15px; }

  /* Close button — mobile only */
  .sidebar-close {
    display: none;
    margin-left: auto;
    background: none; border: none;
    color: var(--muted); cursor: pointer;
    padding: .25rem;
    border-radius: 6px;
    transition: color .15s;
  }
  .sidebar-close:hover { color: var(--text); }
  .sidebar-close svg { width: 18px; height: 18px; display: block; }

  nav { flex: 1; padding: .75rem 0; overflow-y: auto; }

  nav a {
    display: flex; align-items: center; gap: .6rem;
    padding: .6rem 1.2rem;
    color: var(--muted);
    text-decoration: none;
    font-size: .84rem; font-weight: 600; letter-spacing: .02em;
    border-left: 2px solid transparent;
    transition: color .15s, border-color .15s, background .15s;
    white-space: nowrap;
  }
  nav a:hover { color: var(--text); background: rgba(255,255,255,.04); }
  nav a.active { border-left-color: var(--accent); color: var(--accent); background: rgba(232,255,90,.04); }
  nav a svg { width: 15px; height: 15px; flex-shrink: 0; }

  .sidebar-footer {
    padding: .75rem 1.2rem;
    border-top: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    gap: .15rem;
  }
  .sidebar-footer a {
    display: flex; align-items: center; gap: .6rem;
    color: var(--muted); text-decoration: none;
    font-size: .8rem; padding: .45rem .5rem; border-radius: 7px;
    transition: background .12s, color .12s;
  }
  .sidebar-footer a i, .sidebar-footer a svg { width: 15px; height: 15px; flex-shrink: 0; }
  .sidebar-footer a:hover { background: rgba(255,255,255,.06); color: var(--text); }
  .sidebar-footer a.active { background: rgba(232,255,90,.1); color: var(--accent); }
  .sidebar-footer .sidebar-logout:hover { background: rgba(255,80,80,.08); color: #ff7070; }

  /* Overlay — mobile */
  .sidebar-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.6);
    z-index: 99;
    backdrop-filter: blur(2px);
    opacity: 0;
    transition: opacity .25s;
  }
  .sidebar-overlay.visible { display: block; opacity: 1; }

  /* ══════════════════════════════════════
     MAIN AREA
  ══════════════════════════════════════ */
  .main {
    margin-left: var(--nav-w);
    flex: 1;
    min-height: 100vh;
    min-width: 0;
    display: flex;
    flex-direction: column;
  }

  .topbar {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: .75rem;
    position: sticky; top: 0; z-index: 50;
    background: var(--bg);
  }

  /* Hamburger button — mobile only */
  .hamburger {
    display: none;
    background: none; border: 1px solid var(--border);
    border-radius: 8px; color: var(--muted);
    cursor: pointer; padding: .45rem;
    flex-shrink: 0;
    transition: color .15s, border-color .15s;
  }
  .hamburger:hover { color: var(--text); border-color: var(--accent); }
  .hamburger svg { width: 18px; height: 18px; display: block; }

  .topbar h1 {
    font-size: 1.1rem; font-weight: 800; letter-spacing: -.02em;
    flex: 1; min-width: 0;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }

  .topbar-actions { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; }

  .content { padding: 1.5rem; flex: 1; }

  /* ══════════════════════════════════════
     COMPONENTS
  ══════════════════════════════════════ */
  .btn {
    display: inline-flex; align-items: center; gap: .4rem;
    background: var(--accent); color: #0d0d0f;
    border: none; border-radius: 8px;
    font-family: 'Syne', sans-serif;
    font-size: .84rem; font-weight: 700;
    padding: .52rem 1rem;
    cursor: pointer; text-decoration: none;
    white-space: nowrap;
    transition: background .2s, transform .15s;
  }
  .btn:hover { background: var(--accent-dim); transform: translateY(-1px); }
  .btn.btn-ghost {
    background: transparent; color: var(--muted);
    border: 1px solid var(--border);
  }
  .btn.btn-ghost:hover { color: var(--text); background: var(--surface2); transform: none; }
  .btn.btn-danger { background: rgba(255,90,90,.12); color: var(--danger); border: 1px solid rgba(255,90,90,.3); }
  .btn.btn-danger:hover { background: rgba(255,90,90,.22); transform: none; }
  .btn.btn-sm { font-size: .76rem; padding: .35rem .75rem; }

  .badge {
    display: inline-flex; align-items: center;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 20px; font-size: .7rem; font-weight: 600;
    padding: .18rem .6rem; color: var(--muted);
  }

  .alert {
    border-radius: 10px; padding: .85rem 1.1rem;
    margin-bottom: 1.5rem; font-size: .88rem;
  }
  .alert-success { background: rgba(90,255,154,.08); border: 1px solid rgba(90,255,154,.25); color: var(--success); }
  .alert-error   { background: rgba(255,90,90,.08);  border: 1px solid rgba(255,90,90,.25);  color: var(--danger); }

  /* Table */
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table { width: 100%; border-collapse: collapse; min-width: 480px; }
  th {
    text-align: left; font-size: .7rem; font-weight: 600;
    letter-spacing: .08em; text-transform: uppercase;
    color: var(--muted); padding: .7rem 1rem;
    border-bottom: 1px solid var(--border);
  }
  td {
    padding: .85rem 1rem; border-bottom: 1px solid var(--border);
    font-size: .86rem; vertical-align: middle;
  }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: rgba(255,255,255,.02); }

  .card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden;
  }

  /* Form grid */
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
  .form-full  { grid-column: 1 / -1; }

  .field { display: flex; flex-direction: column; gap: .4rem; }
  .field label {
    font-size: .7rem; font-weight: 600;
    letter-spacing: .08em; text-transform: uppercase; color: var(--muted);
  }
  .field input[type="text"],
  .field input[type="date"],
  .field input[type="file"],
  .field select,
  .field textarea {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 8px; color: var(--text);
    font-family: 'Syne', sans-serif; font-size: .88rem;
    padding: .68rem .88rem; outline: none;
    transition: border-color .2s; width: 100%;
  }
  .field textarea { resize: vertical; min-height: 120px; line-height: 1.6; }
  .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent); }
  .field .hint { font-size: .73rem; color: var(--muted); }

  /* Stats */
  .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
  @media (max-width: 900px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 400px)  { .stat-grid { grid-template-columns: 1fr 1fr; gap: .6rem; } }
  .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 1.1rem; }
  .stat-card .stat-label { font-size: .7rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin-bottom: .35rem; }
  .stat-card .stat-value { font-size: 1.9rem; font-weight: 800; letter-spacing: -.03em; }
  .stat-card .stat-value.accent { color: var(--accent); }

  /* Episode row list (dashboard) */
  .ep-list { }
  .ep-row {
    display: flex; align-items: center; gap: .85rem;
    padding: .85rem 1.25rem; border-bottom: 1px solid var(--border);
  }
  .ep-row:last-child { border-bottom: none; }
  .ep-row-info { flex: 1; min-width: 0; }
  .ep-row-title { font-weight: 600; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ep-row-meta { font-size: .75rem; color: var(--muted); margin-top: .2rem; display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
  .ep-row-plays { color: var(--accent); font-weight: 700; }

  /* ── Onboarding guide ─────────────────────────────────── */

  /* ── Tooltips [data-tip] ───────────────────────────────── */
  #ob-tooltip {
    position: absolute; z-index: 9999;
    background: rgba(22,22,28,.97); color: #f0ede8;
    border: 1px solid var(--border);
    border-radius: 7px; padding: .45rem .85rem;
    font-size: .75rem; font-weight: 500; line-height: 1.4;
    max-width: 220px; pointer-events: none;
    opacity: 0; transition: opacity .15s;
    white-space: normal; text-align: center;
    box-shadow: 0 4px 16px rgba(0,0,0,.4);
  }
  #ob-tooltip.visible { opacity: 1; }
  [data-tip] { cursor: help; }

  @media (max-width: 640px) {
    .stat-card { padding: .85rem; }
    .stat-card .stat-value { font-size: 1.4rem; }
    .stat-card .stat-label { font-size: .65rem; }
    .ep-row { padding: .75rem 1rem; gap: .6rem; }
    .ep-row .btn { display: none; } /* hide edit btn on very small screens */
  }

  /* ══════════════════════════════════════
     RESPONSIVE — tablet & mobile
  ══════════════════════════════════════ */

  /* Tablet: compact sidebar */
  @media (max-width: 900px) {
    :root { --nav-w: 190px; }
    .content { padding: 1.25rem; }
    .topbar { padding: .9rem 1.25rem; }
    .stats-grid { grid-template-columns: repeat(2, 1fr) !important; }
  }

  /* Mobile: sidebar becomes off-canvas drawer */
  @media (max-width: 640px) {
    body { display: block; }

    .sidebar {
      transform: translateX(-100%);
      width: min(280px, 85vw);
      box-shadow: 4px 0 32px rgba(0,0,0,.5);
    }
    .sidebar.open { transform: translateX(0); }

    .sidebar-close { display: flex; }
    /* sidebar-overlay visibility handled by JS (.visible class) */

    .main { margin-left: 0; min-height: 100dvh; }

    .hamburger { display: flex; }

    .topbar { padding: .8rem 1rem; gap: .6rem; }
    .topbar h1 { font-size: 1rem; }

    .content { padding: 1rem; }

    /* Stack form grid on mobile */
    .form-grid { grid-template-columns: 1fr; }
    .form-full  { grid-column: 1; }

    /* Smaller buttons on mobile */
    .btn { font-size: .8rem; padding: .48rem .85rem; }

    /* Tables scroll horizontally */
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { min-width: 480px; }

    /* Stats grid 2 cols */
    .stats-grid { grid-template-columns: repeat(2, 1fr) !important; }

    /* Action buttons in topbar: icon-only on very small screens */
    @media (max-width: 380px) {
      .btn-label { display: none; }
      .stats-grid { grid-template-columns: 1fr 1fr !important; }
    }
  }
</style>

<script src="<?= url('/admin/assets/feather.min.js') ?>"></script>
<script>
// Thème — appliqué immédiatement avant render pour éviter le flash
(function() {
  var t = localStorage.getItem('badal_theme') || 'dark';
  if (t !== 'dark') document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Feather icons
  if (window.feather) feather.replace({'stroke-width': 2});

  // Transition page : fade in sur .main, sidebar intacte
  document.body.classList.add('page-ready');

  document.addEventListener('click', function(e) {
    const a = e.target.closest('a[href]');
    if (!a) return;
    // Ignorer si à l'intérieur d'un formulaire avec autosave AJAX
    if (a.closest('form[id]')) return;
    const href = a.getAttribute('href') || '';
    if (a.target === '_blank' || a.download ||
        href.startsWith('http') || href.startsWith('#') ||
        href.startsWith('javascript') || href === '') return;
    e.preventDefault();
    const main = document.querySelector('.main');
    if (main) {
      main.style.transition = 'opacity .18s ease, transform .18s ease';
      main.style.opacity    = '0';
      main.style.transform  = 'translateY(6px)';
    }
    setTimeout(function() { window.location.href = href; }, 180);
  });

  var MOON = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';
  var SUN  = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';

  function svgForTheme(t) { return t === 'dark' ? MOON : SUN; }

  function applyTheme(t) {
    if (t === 'dark') {
      document.documentElement.removeAttribute('data-theme');
    } else {
      document.documentElement.setAttribute('data-theme', t);
    }
    localStorage.setItem('badal_theme', t);
    document.querySelectorAll('.theme-toggle').forEach(function(b) {
      b.innerHTML = svgForTheme(t);
    });
  }

  document.querySelectorAll('.topbar').forEach(function(topbar) {
    if (topbar.querySelector('.theme-toggle')) return;
    var current = localStorage.getItem('badal_theme') || 'dark';
    var btn = document.createElement('button');
    btn.className = 'theme-toggle';
    btn.title = 'Thème clair / sombre';
    btn.innerHTML = svgForTheme(current);
    btn.addEventListener('click', function() {
      var cur = localStorage.getItem('badal_theme') || 'dark';
      applyTheme(cur === 'dark' ? 'light' : 'dark');
    });
    topbar.appendChild(btn);
  });
});
</script><script src="<?= url('/admin/assets/onboarding.js') ?>"></script>
</body>ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Admin') ?> — Badal</title>
<link rel="icon" type="image/svg+xml" href="<?= url('/audio/badal_favicon.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #0d0d0f;
    --surface: #16161a;
    --surface2: #1e1e24;
    --border: #2a2a30;
    --accent: #e8ff5a;
    --accent-dim: #b8cc3a;
    --text: #f0ede8;
    --muted: #888;
    --danger: #ff5a5a;
    --success: #5aff9a;
    --nav-w: 220px;
  }


  /* ══════════════════════════════════════
     THÈME CLAIR (data-theme="light")
  ══════════════════════════════════════ */
  [data-theme="light"] {
    --bg:         #f5f0e8;
    --surface:    #fdf9f3;
    --surface2:   #ece6d8;
    --border:     #ddd6c4;
    --accent:     #c45c2a;
    --accent-dim: #a34820;
    --text:       #2a2018;
    --muted:      #8a7d6e;
    --danger:     #c03020;
    --success:    #2a7a48;
    color-scheme: light;
  }
  [data-theme="light"] .sidebar { box-shadow: 2px 0 16px rgba(0,0,0,.08); }
  [data-theme="light"] .sidebar-icon { background: var(--accent); }
  [data-theme="light"] .sidebar-icon i { stroke: #fff !important; }
  [data-theme="light"] .btn { color: #fff; }
  [data-theme="light"] .nav-link.active { background: rgba(0,0,0,.06); color: var(--accent); }
  [data-theme="light"] .nav-link.active i { stroke: var(--accent); }

  /* Toggle thème */
  .theme-toggle {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--muted);
    cursor: pointer;
    transition: color .15s, border-color .15s, background .15s;
    flex-shrink: 0;
  }
  .theme-toggle:hover { color: var(--text); border-color: var(--accent); }
  .theme-toggle i { pointer-events: none; }

  html, body { height: 100%; }

  body {
    font-family: 'Syne', sans-serif;
    background: var(--bg);
    color: var(--text);
    display: flex;
  }
  /* Transition : .main seul — sidebar reste fixe et visible */
  .main {
    opacity: 0;
    transform: translateY(10px);
    transition: opacity .25s ease, transform .25s cubic-bezier(.25,.46,.45,.94);
  }
  body.page-ready .main {
    opacity: 1;
    transform: translateY(0);
  }

  /* ══════════════════════════════════════
     SIDEBAR
  ══════════════════════════════════════ */
  .sidebar {
    width: var(--nav-w);
    min-height: 100vh;
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    z-index: 100;
    transition: transform .25s cubic-bezier(.4,0,.2,1);
  }

  .sidebar-brand {
    padding: 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: .75rem;
  }

  .sidebar-logo { height:44px; width:auto; background:var(--accent); transition:opacity .15s; }
  .sidebar-logo:hover { opacity:.8; }
  .sidebar-about { margin-left:auto; color:var(--muted); display:flex; align-items:center; transition:color .15s; }
  .sidebar-about:hover { color:var(--accent); }
  .sidebar-about svg { width:15px; height:15px; }

  /* Close button — mobile only */
  .sidebar-close {
    display: none;
    margin-left: auto;
    background: none; border: none;
    color: var(--muted); cursor: pointer;
    padding: .25rem;
    border-radius: 6px;
    transition: color .15s;
  }
  .sidebar-close:hover { color: var(--text); }
  .sidebar-close svg { width: 18px; height: 18px; display: block; }

  nav { flex: 1; padding: .75rem 0; overflow-y: auto; }

  nav a {
    display: flex; align-items: center; gap: .6rem;
    padding: .6rem 1.2rem;
    color: var(--muted);
    text-decoration: none;
    font-size: .84rem; font-weight: 600; letter-spacing: .02em;
    border-left: 2px solid transparent;
    transition: color .15s, border-color .15s, background .15s;
    white-space: nowrap;
  }
  nav a:hover { color: var(--text); background: rgba(255,255,255,.04); }
  nav a.active { border-left-color: var(--accent); color: var(--accent); background: rgba(232,255,90,.04); }
  nav a svg { width: 15px; height: 15px; flex-shrink: 0; }

  .sidebar-footer {
    padding: .75rem 1.2rem;
    border-top: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    gap: .15rem;
  }
  .sidebar-footer a {
    display: flex; align-items: center; gap: .6rem;
    color: var(--muted); text-decoration: none;
    font-size: .8rem; padding: .45rem .5rem; border-radius: 7px;
    transition: background .12s, color .12s;
  }
  .sidebar-footer a i, .sidebar-footer a svg { width: 15px; height: 15px; flex-shrink: 0; }
  .sidebar-footer a:hover { background: rgba(255,255,255,.06); color: var(--text); }
  .sidebar-footer a.active { background: rgba(232,255,90,.1); color: var(--accent); }
  .sidebar-footer .sidebar-logout:hover { background: rgba(255,80,80,.08); color: #ff7070; }

  /* Overlay — mobile */
  .sidebar-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.6);
    z-index: 99;
    backdrop-filter: blur(2px);
    opacity: 0;
    transition: opacity .25s;
  }
  .sidebar-overlay.visible { display: block; opacity: 1; }

  /* ══════════════════════════════════════
     MAIN AREA
  ══════════════════════════════════════ */
  .main {
    margin-left: var(--nav-w);
    flex: 1;
    min-height: 100vh;
    min-width: 0;
    display: flex;
    flex-direction: column;
  }

  .topbar {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: .75rem;
    position: sticky; top: 0; z-index: 50;
    background: var(--bg);
  }

  /* Hamburger button — mobile only */
  .hamburger {
    display: none;
    background: none; border: 1px solid var(--border);
    border-radius: 8px; color: var(--muted);
    cursor: pointer; padding: .45rem;
    flex-shrink: 0;
    transition: color .15s, border-color .15s;
  }
  .hamburger:hover { color: var(--text); border-color: var(--accent); }
  .hamburger svg { width: 18px; height: 18px; display: block; }

  .topbar h1 {
    font-size: 1.1rem; font-weight: 800; letter-spacing: -.02em;
    flex: 1; min-width: 0;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }

  .topbar-actions { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; }

  .content { padding: 1.5rem; flex: 1; }

  /* ══════════════════════════════════════
     COMPONENTS
  ══════════════════════════════════════ */
  .btn {
    display: inline-flex; align-items: center; gap: .4rem;
    background: var(--accent); color: #0d0d0f;
    border: none; border-radius: 8px;
    font-family: 'Syne', sans-serif;
    font-size: .84rem; font-weight: 700;
    padding: .52rem 1rem;
    cursor: pointer; text-decoration: none;
    white-space: nowrap;
    transition: background .2s, transform .15s;
  }
  .btn:hover { background: var(--accent-dim); transform: translateY(-1px); }
  .btn.btn-ghost {
    background: transparent; color: var(--muted);
    border: 1px solid var(--border);
  }
  .btn.btn-ghost:hover { color: var(--text); background: var(--surface2); transform: none; }
  .btn.btn-danger { background: rgba(255,90,90,.12); color: var(--danger); border: 1px solid rgba(255,90,90,.3); }
  .btn.btn-danger:hover { background: rgba(255,90,90,.22); transform: none; }
  .btn.btn-sm { font-size: .76rem; padding: .35rem .75rem; }

  .badge {
    display: inline-flex; align-items: center;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 20px; font-size: .7rem; font-weight: 600;
    padding: .18rem .6rem; color: var(--muted);
  }

  .alert {
    border-radius: 10px; padding: .85rem 1.1rem;
    margin-bottom: 1.5rem; font-size: .88rem;
  }
  .alert-success { background: rgba(90,255,154,.08); border: 1px solid rgba(90,255,154,.25); color: var(--success); }
  .alert-error   { background: rgba(255,90,90,.08);  border: 1px solid rgba(255,90,90,.25);  color: var(--danger); }

  /* Table */
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table { width: 100%; border-collapse: collapse; min-width: 480px; }
  th {
    text-align: left; font-size: .7rem; font-weight: 600;
    letter-spacing: .08em; text-transform: uppercase;
    color: var(--muted); padding: .7rem 1rem;
    border-bottom: 1px solid var(--border);
  }
  td {
    padding: .85rem 1rem; border-bottom: 1px solid var(--border);
    font-size: .86rem; vertical-align: middle;
  }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: rgba(255,255,255,.02); }

  .card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden;
  }

  /* Form grid */
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
  .form-full  { grid-column: 1 / -1; }

  .field { display: flex; flex-direction: column; gap: .4rem; }
  .field label {
    font-size: .7rem; font-weight: 600;
    letter-spacing: .08em; text-transform: uppercase; color: var(--muted);
  }
  .field input[type="text"],
  .field input[type="date"],
  .field input[type="file"],
  .field select,
  .field textarea {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 8px; color: var(--text);
    font-family: 'Syne', sans-serif; font-size: .88rem;
    padding: .68rem .88rem; outline: none;
    transition: border-color .2s; width: 100%;
  }
  .field textarea { resize: vertical; min-height: 120px; line-height: 1.6; }
  .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent); }
  .field .hint { font-size: .73rem; color: var(--muted); }

  /* Stats */
  .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
  @media (max-width: 900px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 400px)  { .stat-grid { grid-template-columns: 1fr 1fr; gap: .6rem; } }
  .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 1.1rem; }
  .stat-card .stat-label { font-size: .7rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin-bottom: .35rem; }
  .stat-card .stat-value { font-size: 1.9rem; font-weight: 800; letter-spacing: -.03em; }
  .stat-card .stat-value.accent { color: var(--accent); }

  /* Episode row list (dashboard) */
  .ep-list { }
  .ep-row {
    display: flex; align-items: center; gap: .85rem;
    padding: .85rem 1.25rem; border-bottom: 1px solid var(--border);
  }
  .ep-row:last-child { border-bottom: none; }
  .ep-row-info { flex: 1; min-width: 0; }
  .ep-row-title { font-weight: 600; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ep-row-meta { font-size: .75rem; color: var(--muted); margin-top: .2rem; display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
  .ep-row-plays { color: var(--accent); font-weight: 700; }

  /* ── Onboarding guide ─────────────────────────────────── */

  /* ── Tooltips [data-tip] ───────────────────────────────── */
  #ob-tooltip {
    position: absolute; z-index: 9999;
    background: rgba(22,22,28,.97); color: #f0ede8;
    border: 1px solid var(--border);
    border-radius: 7px; padding: .45rem .85rem;
    font-size: .75rem; font-weight: 500; line-height: 1.4;
    max-width: 220px; pointer-events: none;
    opacity: 0; transition: opacity .15s;
    white-space: normal; text-align: center;
    box-shadow: 0 4px 16px rgba(0,0,0,.4);
  }
  #ob-tooltip.visible { opacity: 1; }
  [data-tip] { cursor: help; }

  @media (max-width: 640px) {
    .stat-card { padding: .85rem; }
    .stat-card .stat-value { font-size: 1.4rem; }
    .stat-card .stat-label { font-size: .65rem; }
    .ep-row { padding: .75rem 1rem; gap: .6rem; }
    .ep-row .btn { display: none; } /* hide edit btn on very small screens */
  }

  /* ══════════════════════════════════════
     RESPONSIVE — tablet & mobile
  ══════════════════════════════════════ */

  /* Tablet: compact sidebar */
  @media (max-width: 900px) {
    :root { --nav-w: 190px; }
    .content { padding: 1.25rem; }
    .topbar { padding: .9rem 1.25rem; }
    .stats-grid { grid-template-columns: repeat(2, 1fr) !important; }
  }

  /* Mobile: sidebar becomes off-canvas drawer */
  @media (max-width: 640px) {
    body { display: block; }

    .sidebar {
      transform: translateX(-100%);
      width: min(280px, 85vw);
      box-shadow: 4px 0 32px rgba(0,0,0,.5);
    }
    .sidebar.open { transform: translateX(0); }

    .sidebar-close { display: flex; }
    /* sidebar-overlay visibility handled by JS (.visible class) */

    .main { margin-left: 0; min-height: 100dvh; }

    .hamburger { display: flex; }

    .topbar { padding: .8rem 1rem; gap: .6rem; }
    .topbar h1 { font-size: 1rem; }

    .content { padding: 1rem; }

    /* Stack form grid on mobile */
    .form-grid { grid-template-columns: 1fr; }
    .form-full  { grid-column: 1; }

    /* Smaller buttons on mobile */
    .btn { font-size: .8rem; padding: .48rem .85rem; }

    /* Tables scroll horizontally */
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { min-width: 480px; }

    /* Stats grid 2 cols */
    .stats-grid { grid-template-columns: repeat(2, 1fr) !important; }

    /* Action buttons in topbar: icon-only on very small screens */
    @media (max-width: 380px) {
      .btn-label { display: none; }
      .stats-grid { grid-template-columns: 1fr 1fr !important; }
    }
  }
</style>

<script src="<?= url('/admin/assets/feather.min.js') ?>"></script>
<script>
// Thème — appliqué immédiatement avant render pour éviter le flash
(function() {
  var t = localStorage.getItem('badal_theme') || 'dark';
  if (t !== 'dark') document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Feather icons
  if (window.feather) feather.replace({'stroke-width': 2});

  // Transition page : fade in sur .main, sidebar intacte
  document.body.classList.add('page-ready');

  document.addEventListener('click', function(e) {
    const a = e.target.closest('a[href]');
    if (!a) return;
    // Ignorer si à l'intérieur d'un formulaire avec autosave AJAX
    if (a.closest('form[id]')) return;
    const href = a.getAttribute('href') || '';
    if (a.target === '_blank' || a.download ||
        href.startsWith('http') || href.startsWith('#') ||
        href.startsWith('javascript') || href === '') return;
    e.preventDefault();
    const main = document.querySelector('.main');
    if (main) {
      main.style.transition = 'opacity .18s ease, transform .18s ease';
      main.style.opacity    = '0';
      main.style.transform  = 'translateY(6px)';
    }
    setTimeout(function() { window.location.href = href; }, 180);
  });

  var MOON = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';
  var SUN  = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';

  function svgForTheme(t) { return t === 'dark' ? MOON : SUN; }

  function applyTheme(t) {
    if (t === 'dark') {
      document.documentElement.removeAttribute('data-theme');
    } else {
      document.documentElement.setAttribute('data-theme', t);
    }
    localStorage.setItem('badal_theme', t);
    document.querySelectorAll('.theme-toggle').forEach(function(b) {
      b.innerHTML = svgForTheme(t);
    });
  }

  document.querySelectorAll('.topbar').forEach(function(topbar) {
    if (topbar.querySelector('.theme-toggle')) return;
    var current = localStorage.getItem('badal_theme') || 'dark';
    var btn = document.createElement('button');
    btn.className = 'theme-toggle';
    btn.title = 'Thème clair / sombre';
    btn.innerHTML = svgForTheme(current);
    btn.addEventListener('click', function() {
      var cur = localStorage.getItem('badal_theme') || 'dark';
      applyTheme(cur === 'dark' ? 'light' : 'dark');
    });
    topbar.appendChild(btn);
  });
});
</script>
