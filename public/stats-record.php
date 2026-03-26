<?php
// =============================================================================
//  public/stats-record.php — Record a listen via JS (on actual play)
//
//  Called by the frontend audio player when the user actually presses play.
//  Deduplication: max 1 listen per IP per episode per 24 hours.
//
//  POST /public/stats-record.php  { slug: "episode-slug" }
//  Returns JSON: { "ok": true } or { "ok": false, "reason": "..." }
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'Method not allowed']);
    exit;
}

// Read slug from POST body (JSON or form-encoded)
$slug = '';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true);
    $slug = trim($body['slug'] ?? '');
} else {
    $slug = trim($_POST['slug'] ?? '');
}

if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'Invalid slug']);
    exit;
}

// ── IP-based deduplication (1 listen per IP per episode per 24h) ─────────
$configDir = dirname($config['content_dir']) . '/config';
$dedupeDir = $configDir . '/stats-dedupe';

if (!is_dir($dedupeDir)) {
    mkdir($dedupeDir, 0750, true);
}

$ip   = Security::clientIp();
$hash = md5($ip . '|' . $slug);
$file = $dedupeDir . '/' . $hash . '.json';

if (file_exists($file)) {
    $ts = (int) @file_get_contents($file);
    if (time() - $ts < 86400) {
        // Already counted within the last 24h
        echo json_encode(['ok' => true, 'reason' => 'already_counted']);
        exit;
    }
}

// Record the timestamp
file_put_contents($file, (string) time(), LOCK_EX);

// Record the play
(new StatsManager($configDir))->recordPlay($slug);

// Probabilistic cleanup of expired dedupe files (1% chance)
if (random_int(1, 100) === 1) {
    StatsManager::purgeDedupeFiles($configDir);
}

echo json_encode(['ok' => true]);
