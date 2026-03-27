<?php
// =============================================================================
//  index.php -- Front Router (fallback without mod_rewrite)
//
//  Handles clean URLs when .htaccess is not read (AllowOverride None).
//  If mod_rewrite works, this file is never called for clean URLs
//  (.htaccess takes over first).
//
//  Handled routes:
//    /                          -> public/index.php
//    /episodes/<slug>           -> public/episode.php?slug=<slug>
//    /rss.xml                   -> public/rss.php
//    /sitemap.xml               -> public/sitemap.php
//    /admin/                    -> admin/index.php  (and all sub-pages)
//    /audio/<file>              -> public/audio.php?file=<file>
//    /chapters/<slug>.json      -> public/chapters.php
//    /push-notification.json    -> public/push-notification.php
//    /push-subscribe            -> public/push-subscribe.php
// =============================================================================


// -----------------------------------------------------------------------------
//  Bootstrap -- Load config & resolve base path
// -----------------------------------------------------------------------------

$_rootDir = __DIR__;

// If config doesn't exist, redirect to the installer
if (!file_exists($_rootDir . '/config/config.php')) {
    header('Location: ' . dirname($_SERVER['SCRIPT_NAME']) . '/setup.php');
    exit;
}

$_cfg  = require $_rootDir . '/config/config.php';
$_base = rtrim(parse_url($_cfg['base_url'] ?? '', PHP_URL_PATH) ?? '', '/');


// -----------------------------------------------------------------------------
//  Parse & Normalise the Request URI
// -----------------------------------------------------------------------------

$uri = strtok($_SERVER['REQUEST_URI'], '?');
$uri = '/' . trim($uri, '/');

// Remove the subdirectory prefix based on base_url (reliable).
// E.g.: base_url='https://example.com/betapodcast' -> strip '/betapodcast'
if ($_base !== '' && (strpos($uri, $_base) === 0)) {
    $uri = substr($uri, strlen($_base)) ?: '/';
}


// =============================================================================
//  Route Matching
// =============================================================================


// -- Static files -- serve directly -------------------------------------------

$docRoot = __DIR__;
$file    = $docRoot . $uri;

if ($uri !== '/' && file_exists($file) && is_file($file)) {
    return false; // let the web server handle it
}


// -- Push notifications -------------------------------------------------------

if ($uri === '/push-notification.json') {
    require __DIR__ . '/public/push-notification.php';
    exit;
}

if ($uri === '/push-subscribe') {
    require __DIR__ . '/public/push-subscribe.php';
    exit;
}


// -- Chapters JSON (Podcasting 2.0) -------------------------------------------

if (preg_match('#^/chapters/([a-z0-9-]+)\.json$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/public/chapters.php';
    exit;
}


// -- Episode ------------------------------------------------------------------

if (preg_match('#^/episodes/([a-z0-9-]+)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/public/episode.php';
    exit;
}


// -- RSS Feed -----------------------------------------------------------------

if ($uri === '/rss.xml') {
    require __DIR__ . '/public/rss.php';
    exit;
}


// -- Sitemap ------------------------------------------------------------------

if ($uri === '/sitemap.xml') {
    require __DIR__ . '/public/sitemap.php';
    exit;
}


// -- Audio proxy --------------------------------------------------------------

if (preg_match('#^/audio/(.+)$#', $uri, $m)) {
    $_GET['file'] = $m[1];
    require __DIR__ . '/public/audio.php';
    exit;
}


// -- Admin panel --------------------------------------------------------------

if ((strpos($uri, '/admin') === 0)) {
    $page = ltrim(str_replace('/admin', '', $uri), '/') ?: 'index.php';
    $path = __DIR__ . '/admin/' . $page;

    if (file_exists($path)) {
        require $path;
        exit;
    }
}


// -- Homepage (/ or fallback) -------------------------------------------------

require __DIR__ . '/public/index.php';
