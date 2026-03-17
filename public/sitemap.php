<?php
ob_start();
// =============================================================================
//  public/sitemap.php — Endpoint du sitemap XML
//
//  Servi via la règle .htaccess : ^sitemap\.xml$ → public/sitemap.php
//  URL publique : https://monpodcast.com/sitemap.xml
//
//  Stratégie de mise à jour :
//    - SitemapGenerator::generate() est appelé après chaque modification
//      d'épisode (create / update / delete), ce qui maintient sitemap.xml
//      à jour en temps réel.
//    - Ce fichier régénère le sitemap si le fichier est absent ou trop vieux
//      (> 1 heure), comme filet de sécurité.
//    - Si la régénération échoue (ex: disque plein), une réponse 503 est
//      retournée plutôt qu'un XML vide ou corrompu.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$sitemapFile = ROOT_DIR . '/sitemap.xml';
$maxAge      = 3600; // 1 heure en secondes

// Régénérer si absent ou trop ancien
$needsRegen = !file_exists($sitemapFile)
           || (time() - filemtime($sitemapFile)) > $maxAge;

if ($needsRegen) {
    $parser    = new EpisodeParser($config['content_dir']);
    $generator = new SitemapGenerator($config['base_url'], ROOT_DIR);
    $generator->generate($parser->getAll());
}

// Servir le fichier ou retourner une erreur claire
if (file_exists($sitemapFile)) {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    readfile($sitemapFile);
} else {
    // 503 Service Unavailable : les crawlers réessaieront plus tard
    http_response_code(503);
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0"?><error>Sitemap temporairement indisponible.</error>';
}
