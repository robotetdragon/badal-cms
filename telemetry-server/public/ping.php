<?php
// =============================================================================
//  ping.php — Endpoint POST pour recevoir les pings de télémétrie Badal
//
//  Reçoit : { id, version, episodes, lang }
//  Stocke chaque installation unique dans data/installations.json
// =============================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Lire le body JSON
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || empty($payload['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload — "id" is required']);
    exit;
}

// Valider et nettoyer les champs
$id       = preg_replace('/[^a-f0-9]/', '', substr($payload['id'], 0, 64));
$version  = isset($payload['version']) ? substr(preg_replace('/[^a-zA-Z0-9.\-]/', '', $payload['version']), 0, 20) : 'unknown';
$episodes = isset($payload['episodes']) ? max(0, (int) $payload['episodes']) : 0;
$lang     = isset($payload['lang']) ? substr(preg_replace('/[^a-zA-Z\-]/', '', $payload['lang']), 0, 10) : 'unknown';

if (strlen($id) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid install ID']);
    exit;
}

// Charger la base existante
$dataFile = __DIR__ . '/../data/installations.json';
$installations = [];

if (file_exists($dataFile)) {
    $installations = json_decode(file_get_contents($dataFile), true) ?: [];
}

// Mettre à jour ou ajouter l'installation
$installations[$id] = [
    'version'    => $version,
    'episodes'   => $episodes,
    'lang'       => $lang,
    'first_seen' => $installations[$id]['first_seen'] ?? date('c'),
    'last_seen'  => date('c'),
    'ping_count' => ($installations[$id]['ping_count'] ?? 0) + 1,
];

// Sauvegarder
file_put_contents($dataFile, json_encode($installations, JSON_PRETTY_PRINT), LOCK_EX);

http_response_code(200);
echo json_encode(['status' => 'ok']);
