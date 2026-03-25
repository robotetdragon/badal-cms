<?php
ob_start();
// =============================================================================
//  public/audio.php — Audio streaming proxy with listen count tracking
//
//  This file intercepts all requests to /audio/<file> via the following
//  .htaccess rule:
//      RewriteRule ^audio/(.+)$ public/audio.php?file=$1 [L,QSA]
//
//  Responsibilities:
//    1. Validate the filename (anti path-traversal)
//    2. Record a listen in StatsManager (only once per playback)
//    3. Serve the audio file with byte-range request support
//       (essential for seeking in HTML5 players)
//
//  The count is only triggered on the initial request for a file,
//  not on subsequent Range requests (stream continuation).
//  Criterion: Range header absent, or Range: bytes=0-…
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

// ── Audio rate-limiting ──────────────────────────────────────────────────────
// Limit: 120 requests per IP over a 60-second sliding window.
// Blocks bandwidth hammering/scraping without interfering with normal usage
// (HTML5 seek = ~5-10 Range requests per listen, podcatcher = 1 req).
//
// Storage: one JSON file per IP in config/ratelimit-audio/
// We reuse the same directory as the login rate-limit for simplicity.
(function() use ($config): void {
    $ip      = Security::clientIp();
    $hash    = md5($ip);  // never store the IP in plaintext in the filename
    $dir     = dirname($config['content_dir']) . '/config/ratelimit-audio';
    $file    = $dir . '/' . $hash . '.json';
    $window  = 60;          // seconds
    $maxReqs = 120;         // requests per window (allows intensive seeking without blocking)

    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $now  = time();
    $data = [];
    if (file_exists($file)) {
        $raw  = @file_get_contents($file);
        $data = $raw ? json_decode($raw, true) : [];
    }

    // Purge timestamps outside the window
    $data = array_values(array_filter($data ?? [], fn($t) => $t >= $now - $window));

    if (count($data) >= $maxReqs) {
        http_response_code(429);
        header('Retry-After: ' . $window);
        header('Content-Type: text/plain');
        exit('Too Many Requests');
    }

    $data[] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
})();

// ── File parameter validation ────────────────────────────────────────────────

$filename = $_GET['file'] ?? '';

// Allow slug/file.ext (1 level) — reject path traversal
if (!$filename
    || strpos($filename, '..') !== false
    || strpos($filename, '\\') !== false
    || substr_count($filename, '/') > 1
) {
    http_response_code(400);
    exit('Bad request.');
}

$filepath = $config['audio_dir'] . '/' . $filename;

if (!file_exists($filepath) || !is_file($filepath)) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

// ── Listen counting ─────────────────────────────────────────────────────────
//
// We detect whether this is an initial request by checking the Range header:
//   - Absent              → playback from the start → we count
//   - bytes=0-…           → playback from the start → we count
//   - bytes=X-… with X>0  → continuation or seek    → we don't count

$isInitialRequest = true;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d+)-/', $_SERVER['HTTP_RANGE'], $m) && (int) $m[1] > 0) {
        $isInitialRequest = false;
    }
}

$ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$audioExts = ['mp3','ogg','oga','m4a','aac','wav','flac','opus','mp4'];
if ($isInitialRequest && in_array($ext, $audioExts, true)) {
    // The slug is the parent folder if structure is slug/audio.ext, otherwise the name without extension
    $slug = str_contains($filename, '/') ? explode('/', $filename)[0] : pathinfo($filename, PATHINFO_FILENAME);
    $configDir = dirname($config['content_dir']) . '/config';
    (new StatsManager($configDir))->recordPlay($slug);
}

// ── Response headers preparation ─────────────────────────────────────────────
$mimeMap  = [
    'mp3'  => 'audio/mpeg',
    'ogg'  => 'audio/ogg',
    'oga'  => 'audio/ogg',
    'm4a'  => 'audio/x-m4a',
    'aac'  => 'audio/aac',
    'wav'  => 'audio/wav',
    'flac' => 'audio/flac',
    'opus' => 'audio/opus',
    'mp4'  => 'audio/mp4',
    // Images (logos, covers)
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];
$mime     = $mimeMap[$ext] ?? 'application/octet-stream';
$filesize = filesize($filepath);

// Tell the client that byte-range requests are supported
header('Accept-Ranges: bytes');
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');   // 24h cache
header('Content-Disposition: inline; filename="' . basename($filename) . '"');

// ── Byte-range request handling ──────────────────────────────────────────────
//
// HTML5 audio players and mobile apps send Range requests to: play from a
// specific position (seek), download in chunks, or resume after a network
// interruption.
//
// RFC 7233 — header format: Range: bytes=<start>-<end>

$start = 0;
$end   = $filesize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = $m[1] !== '' ? (int) $m[1] : 0;
        $end   = $m[2] !== '' ? (int) $m[2] : $filesize - 1;
    }

    // Validate the requested range
    if ($start > $end || $start >= $filesize || $end >= $filesize) {
        http_response_code(416); // 416 Range Not Satisfiable
        header('Content-Range: bytes */' . $filesize);
        exit;
    }

    http_response_code(206); // 206 Partial Content
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);
    header('Content-Length: ' . ($end - $start + 1));
} else {
    http_response_code(200);
    header('Content-Length: ' . $filesize);
}

// ── File streaming ──────────────────────────────────────────────────────────
//
// Read in 64 KB chunks to avoid loading the entire file into memory.
// Stop if the client connection is dropped (connection_aborted()).

// Flush all output buffers (bootstrap + audio.php) before streaming
// Otherwise the entire file is buffered in RAM → memory_limit exceeded / hang
while (ob_get_level()) {
    ob_end_clean();
}

$fp        = fopen($filepath, 'rb');
$chunkSize = 64 * 1024; // 64 KB

fseek($fp, $start);
$remaining = $end - $start + 1;

while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
    $bytes      = fread($fp, min($chunkSize, $remaining));
    $remaining -= strlen($bytes);
    echo $bytes;
    flush();
}

fclose($fp);
