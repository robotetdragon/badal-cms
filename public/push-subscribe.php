<?php
// =============================================================================
//  public/push-subscribe.php — API endpoint pour abonnements push
//
//  POST avec JSON body :
//    { "action": "subscribe",   "subscription": { endpoint, keys: {p256dh, auth} } }
//    { "action": "unsubscribe", "endpoint": "https://..." }
//
//  Retourne JSON : { "ok": true/false }
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false,"error":"POST only"}';
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['action'])) {
    http_response_code(400);
    echo '{"ok":false,"error":"Invalid JSON"}';
    exit;
}

$configDir = dirname($config['content_dir']) . '/config';
$wp = new WebPush($configDir);

$action = $input['action'];

if ($action === 'subscribe') {
    $sub = $input['subscription'] ?? [];
    if (empty($sub['endpoint'])) {
        http_response_code(400);
        echo '{"ok":false,"error":"Missing endpoint"}';
        exit;
    }
    $ok = $wp->subscribe($sub);
    echo json_encode(['ok' => $ok]);

} elseif ($action === 'unsubscribe') {
    $endpoint = $input['endpoint'] ?? '';
    $ok = $wp->unsubscribe($endpoint);
    echo json_encode(['ok' => $ok]);

} else {
    http_response_code(400);
    echo '{"ok":false,"error":"Unknown action"}';
}
