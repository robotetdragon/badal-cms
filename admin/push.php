<?php
// ═══════════════════════════════════════════════════════════════
// PUSH — Web Push notifications
// ═══════════════════════════════════════════════════════════════

ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$configDir = dirname($config['content_dir']) . '/config';
$wp        = new WebPush($configDir);

$errors    = [];
$success   = '';
$result    = null;


// ═══════════════════════════════════════════════════════════════
// INIT — Generate VAPID keys if needed
// ═══════════════════════════════════════════════════════════════

if (!$wp->isConfigured()) {
    try {
        $wp->ensureVapidKeys();
    } catch (\Exception $e) {
        $errors[] = 'VAPID key generation failed: ' . $e->getMessage();
    }
}


// ═══════════════════════════════════════════════════════════════
// ACTION — Send push notification
// ═══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body']  ?? '');
    $url   = trim($_POST['url']   ?? $config['base_url']);

    if (empty($title)) {
        $errors[] = __('push_title_required');
    }

    if (empty($errors)) {
        $icon   = rtrim($config['base_url'], '/') . '/audio/badal_favicon.svg';
        $wp->setNotification($title, $body, $url, $icon);

        $email  = $config['email'] ?? 'noreply@example.com';
        $result = $wp->sendAll($email);

        if ($result['sent'] > 0) {
            $success = __('push_sent', ['%sent%' => $result['sent']]);
        }
        if ($result['cleaned'] > 0) {
            $success .= ' ' . __('push_cleaned', ['%n%' => $result['cleaned']]);
        }
        if ($result['failed'] > 0 && $result['sent'] === 0) {
            $errors[] = __('push_failed', ['%n%' => $result['failed']]);
        }
    }
}


// ═══════════════════════════════════════════════════════════════
// DATA — Subscriber count & VAPID public key
// ═══════════════════════════════════════════════════════════════

$subCount  = $wp->getCount();
$vapidPub  = $wp->isConfigured() ? $wp->getPublicKey() : '';


// ═══════════════════════════════════════════════════════════════
// RENDER — Layout
// ═══════════════════════════════════════════════════════════════

$pageTitle = __('nav_push');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<div class="main">
    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
            <i data-feather="menu"></i>
        </button>
        <h1><?= __('nav_push') ?></h1>
    </div>

    <div class="content">

        <!-- ═══ Flash Messages ═══ -->
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?= implode('<br>', array_map('e', $errors)) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <!-- ═══ Subscriber Stats Card ═══ -->
        <div class="card" style="padding:1.75rem; margin-bottom:1.25rem">
            <div class="section-label" style="margin-bottom:1rem">
                <?= __('push_subscribers') ?>
            </div>
            <div style="display:flex; align-items:baseline; gap:.75rem">
                <span style="font-size:2.5rem; font-weight:800; color:var(--accent)">
                    <?= $subCount ?>
                </span>
                <span style="color:var(--muted); font-size:.85rem">
                    <?= __('push_subscribers_label') ?>
                </span>
            </div>
            <?php if ($vapidPub): ?>
                <div style="margin-top:1rem; font-size:.72rem; color:var(--muted); word-break:break-all">
                    VAPID: <code style="color:var(--accent)"><?= e(substr($vapidPub, 0, 20)) ?>…</code>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══ Send Notification Card ═══ -->
        <div class="card" style="padding:1.75rem">
            <div class="section-label" style="margin-bottom:1.25rem">
                <?= __('push_send') ?>
            </div>

            <?php if ($subCount === 0): ?>
                <p style="color:var(--muted); font-size:.88rem">
                    <?= __('push_no_subscribers') ?>
                </p>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="field" style="margin-bottom:1rem">
                        <label><?= __('push_notif_title') ?></label>
                        <input type="text" name="title"
                               value="<?= e($_POST['title'] ?? '') ?>"
                               placeholder="<?= e(__('push_title_placeholder')) ?>">
                    </div>

                    <div class="field" style="margin-bottom:1rem">
                        <label><?= __('push_notif_body') ?></label>
                        <textarea name="body" rows="2"
                                  placeholder="<?= e(__('push_body_placeholder')) ?>"><?= e($_POST['body'] ?? '') ?></textarea>
                    </div>

                    <div class="field" style="margin-bottom:1.5rem">
                        <label><?= __('push_notif_url') ?></label>
                        <input type="url" name="url"
                               value="<?= e($_POST['url'] ?? $config['base_url']) ?>"
                               placeholder="https://...">
                        <span class="hint"><?= __('push_url_hint') ?></span>
                    </div>

                    <button type="submit" class="btn"
                            onclick="return confirm('<?= __('push_confirm', ['%n%' => $subCount]) ?>')">
                        <?= __('push_send_btn', ['%n%' => $subCount]) ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>
