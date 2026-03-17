<?php
ob_start();
// =============================================================================
//  public/rss.php — Endpoint du flux RSS
//
//  Servi via la règle .htaccess : ^rss\.xml$ → public/rss.php
//  URL publique : https://monpodcast.com/rss.xml
//
//  Délègue entièrement la génération XML à RssGenerator.
//  Met en cache la réponse 1 heure côté client et proxy.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$parser   = new EpisodeParser($config['content_dir']);
$episodes = $parser->getAll();

$rss = new RssGenerator($config);
$xml = $rss->generate($episodes);

// application/rss+xml est le type MIME officiel (RFC 4287)
header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

echo $xml;
