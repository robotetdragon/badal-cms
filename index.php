<?php
// =============================================================================
//  index.php — Routeur frontal (fallback sans mod_rewrite)
//
//  Gère les URLs propres si le .htaccess n'est pas lu (AllowOverride None).
//  Si mod_rewrite fonctionne, ce fichier n'est jamais appelé pour les URLs
//  propres (le .htaccess prend la main en premier).
//
//  Routes gérées :
//    /                          → public/index.php
//    /episodes/<slug>           → public/episode.php?slug=<slug>
//    /rss.xml                   → public/rss.php
//    /sitemap.xml               → public/sitemap.php
//    /admin/                    → admin/index.php  (et toutes les sous-pages)
//    /audio/<file>              → public/audio.php?file=<file>
// =============================================================================

// Charger la config pour connaître BASE avant le bootstrap complet
// (bootstrap sera chargé par la page incluse)
$_rootDir = __DIR__;

// Si la config n'existe pas, rediriger vers l'installation
if (!file_exists($_rootDir . '/config/config.php')) {
    header('Location: ' . dirname($_SERVER['SCRIPT_NAME']) . '/setup.php');
    exit;
}

$_cfg     = require $_rootDir . '/config/config.php';
$_base    = rtrim(parse_url($_cfg['base_url'] ?? '', PHP_URL_PATH) ?? '', '/');

$uri = strtok($_SERVER['REQUEST_URI'], '?');
$uri = '/' . trim($uri, '/');

// Retirer le préfixe de sous-dossier basé sur base_url (fiable)
// Ex: base_url='https://robotetdragon.com/betapodcast' → strip '/betapodcast'
if ($_base !== '' && (strpos($uri, $_base) === 0)) {
    $uri = substr($uri, strlen($_base)) ?: '/';
}

// ── Routes ────────────────────────────────────────────────────────────────────

// Fichier statique existant → le servir directement
$docRoot = __DIR__;
$file    = $docRoot . $uri;
if ($uri !== '/' && file_exists($file) && is_file($file)) {
    return false; // laisser le serveur web gérer
}

// Episode
if (preg_match('#^/episodes/([a-z0-9-]+)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/public/episode.php';
    exit;
}

// RSS
if ($uri === '/rss.xml') {
    require __DIR__ . '/public/rss.php';
    exit;
}

// Sitemap
if ($uri === '/sitemap.xml') {
    require __DIR__ . '/public/sitemap.php';
    exit;
}

// Audio
if (preg_match('#^/audio/(.+)$#', $uri, $m)) {
    $_GET['file'] = $m[1];
    require __DIR__ . '/public/audio.php';
    exit;
}

// Admin
if ((strpos($uri, '/admin') === 0)) {
    $page = ltrim(str_replace('/admin', '', $uri), '/') ?: 'index.php';
    $path = __DIR__ . '/admin/' . $page;
    if (file_exists($path)) {
        require $path;
        exit;
    }
}

// Homepage (/ ou fallback)
require __DIR__ . '/public/index.php';
