<?php

// =============================================================================
//  public/push-notification.php — Latest notification JSON
//
//  Called by the Service Worker upon receiving a push.
//  Returns the title, body, URL and icon of the latest notification.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

// ── Fetch and serve notification ─────────────────────────────────────────────

$configDir = dirname($config['content_dir']) . '/config';
$wp        = new WebPush($configDir);
$notif     = $wp->getNotification();

if (empty($notif)) {
    echo json_encode([
        'title' => $config['podcast_title'] ?? 'Nouveau contenu',
        'body'  => '',
        'url'   => $config['base_url'] ?? './',
        'icon'  => '',
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode($notif, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
