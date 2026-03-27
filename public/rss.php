<?php
ob_start();

// =============================================================================
//  public/rss.php — RSS feed endpoint
//
//  Served via .htaccess rule: ^rss\.xml$ → public/rss.php
//  Public URL: https://mypodcast.com/rss.xml
//
//  Fully delegates XML generation to RssGenerator.
//  Caches the response for 1 hour on client and proxy side.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

// ── Parse episodes ───────────────────────────────────────────────────────────

$parser   = new EpisodeParser($config['content_dir']);
$episodes = $parser->getAll();

// ── Enrich with chapters presence (Podcasting 2.0) ──────────────────────────

$cm = new ChaptersManager($config['content_dir']);

foreach ($episodes as &$ep) {
    $ep['has_chapters'] = $cm->exists($ep['slug'] ?? '');
}
unset($ep);

// ── Generate and serve XML ──────────────────────────────────────────────────

$rss = new RssGenerator($config);
$xml = $rss->generate($episodes);

header('Content-Type: application/rss+xml; charset=UTF-8');   // RFC 4287
header('Cache-Control: public, max-age=3600');

echo $xml;
