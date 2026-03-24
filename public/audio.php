<?php
ob_start();
// =============================================================================
//  public/audio.php — Proxy de streaming audio avec comptage des écoutes
//
//  Ce fichier intercepte toutes les requêtes vers /audio/<fichier> via la règle
//  .htaccess suivante :
//      RewriteRule ^audio/(.+)$ public/audio.php?file=$1 [L,QSA]
//
//  Responsabilités :
//    1. Valider le nom de fichier (anti path-traversal)
//    2. Enregistrer une écoute dans StatsManager (une seule fois par lecture)
//    3. Servir le fichier audio avec support des byte-range requests
//       (indispensable pour le seek dans les lecteurs HTML5)
//
//  Le comptage n'est déclenché que sur la requête initiale d'un fichier,
//  pas sur les requêtes Range suivantes (continuation du stream).
//  Critère : Range header absent, ou Range: bytes=0-…
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

// ── Rate-limiting audio ───────────────────────────────────────────────────────
// Limite : 120 requêtes par IP sur une fenêtre glissante de 60 secondes.
// Bloque le hammering/scraping de la bande passante sans gêner l'usage normal
// (seek HTML5 = ~5-10 requêtes Range par écoute, podcatcher = 1 req).
//
// Stockage : un fichier JSON par IP dans config/ratelimit-audio/
// On réutilise le même répertoire que le rate-limit login pour simplifier.
(function() use ($config): void {
    $ip      = Security::clientIp();
    $hash    = md5($ip);  // ne jamais stocker l'IP en clair dans le nom de fichier
    $dir     = dirname($config['content_dir']) . '/config/ratelimit-audio';
    $file    = $dir . '/' . $hash . '.json';
    $window  = 60;          // secondes
    $maxReqs = 120;         // requêtes par fenêtre (permet seek intensif sans bloquer)

    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $now  = time();
    $data = [];
    if (file_exists($file)) {
        $raw  = @file_get_contents($file);
        $data = $raw ? json_decode($raw, true) : [];
    }

    // Purger les timestamps hors fenêtre
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

// ── Validation du paramètre fichier ──────────────────────────────────────────

$filename = $_GET['file'] ?? '';

// Autoriser slug/fichier.ext (1 niveau) — rejeter path traversal
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

// ── Comptage de l'écoute ──────────────────────────────────────────────────────
//
// On détecte si c'est une requête initiale en regardant le header Range :
//   - Absent              → lecture depuis le début → on compte
//   - bytes=0-…           → lecture depuis le début → on compte
//   - bytes=X-… avec X>0  → continuation ou seek   → on ne compte pas

$isInitialRequest = true;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d+)-/', $_SERVER['HTTP_RANGE'], $m) && (int) $m[1] > 0) {
        $isInitialRequest = false;
    }
}

$ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$audioExts = ['mp3','ogg','oga','m4a','aac','wav','flac','opus','mp4'];
if ($isInitialRequest && in_array($ext, $audioExts, true)) {
    // Le slug est le dossier parent si structure slug/audio.ext, sinon le nom sans extension
    $slug = str_contains($filename, '/') ? explode('/', $filename)[0] : pathinfo($filename, PATHINFO_FILENAME);
    $configDir = dirname($config['content_dir']) . '/config';
    (new StatsManager($configDir))->recordPlay($slug);
}

// ── Préparation des en-têtes de réponse ──────────────────────────────────────
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

// Signaler au client que les byte-range requests sont supportées
header('Accept-Ranges: bytes');
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');   // mise en cache 24h
header('Content-Disposition: inline; filename="' . basename($filename) . '"');

// ── Gestion des byte-range requests ──────────────────────────────────────────
//
// Les lecteurs audio HTML5 et les applications mobiles envoient des requêtes
// Range pour : lire depuis une position précise (seek), télécharger par blocs,
// ou reprendre après une interruption réseau.
//
// RFC 7233 — format du header : Range: bytes=<start>-<end>

$start = 0;
$end   = $filesize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = $m[1] !== '' ? (int) $m[1] : 0;
        $end   = $m[2] !== '' ? (int) $m[2] : $filesize - 1;
    }

    // Valider la plage demandée
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

// ── Envoi du fichier en streaming ─────────────────────────────────────────────
//
// Lecture par blocs de 64 Ko pour éviter de charger tout le fichier en mémoire.
// On s'arrête si la connexion est coupée côté client (connection_aborted()).

// Vider tous les output buffers (bootstrap + audio.php) avant de streamer
// Sinon le fichier entier est bufferisé en RAM → memory_limit dépassé / blocage
while (ob_get_level()) {
    ob_end_clean();
}

$fp        = fopen($filepath, 'rb');
$chunkSize = 64 * 1024; // 64 Ko

fseek($fp, $start);
$remaining = $end - $start + 1;

while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
    $bytes      = fread($fp, min($chunkSize, $remaining));
    $remaining -= strlen($bytes);
    echo $bytes;
    flush();
}

fclose($fp);
