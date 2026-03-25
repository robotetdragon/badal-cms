<?php
// =============================================================================
//  public/chapters.php — Chapters JSON endpoint (Podcasting 2.0)
//
//  URL: /chapters/<slug>.json
//  Returns JSON in Podcasting 2.0 chapters format.
//  Referenced in the RSS feed via <podcast:chapters>.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$slug = $_GET['slug'] ?? '';
if (!$slug || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
    http_response_code(404);
    echo '{"error":"not found"}';
    exit;
}

$cm = new ChaptersManager($config['content_dir']);
if (!$cm->exists($slug)) {
    http_response_code(404);
    echo '{"error":"not found"}';
    exit;
}

header('Content-Type: application/json+chapters; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $cm->toJson($slug);
