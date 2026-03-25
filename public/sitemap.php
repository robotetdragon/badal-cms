<?php
ob_start();
// =============================================================================
//  public/sitemap.php — XML sitemap endpoint
//
//  Served via .htaccess rule: ^sitemap\.xml$ → public/sitemap.php
//  Public URL: https://mypodcast.com/sitemap.xml
//
//  Update strategy:
//    - SitemapGenerator::generate() is called after each episode modification
//      (create / update / delete), keeping sitemap.xml up to date in real time.
//    - This file regenerates the sitemap if the file is missing or too old
//      (> 1 hour), as a safety net.
//    - If regeneration fails (e.g. disk full), a 503 response is returned
//      rather than an empty or corrupted XML.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$sitemapFile = ROOT_DIR . '/sitemap.xml';
$maxAge      = 3600; // 1 hour in seconds

// Regenerate if missing or too old
$needsRegen = !file_exists($sitemapFile)
           || (time() - filemtime($sitemapFile)) > $maxAge;

if ($needsRegen) {
    $parser    = new EpisodeParser($config['content_dir']);
    $generator = new SitemapGenerator($config['base_url'], ROOT_DIR);
    $generator->generate($parser->getAll());
}

// Serve the file or return a clear error
if (file_exists($sitemapFile)) {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    readfile($sitemapFile);
} else {
    // 503 Service Unavailable: crawlers will retry later
    http_response_code(503);
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0"?><error>Sitemap temporairement indisponible.</error>';
}
