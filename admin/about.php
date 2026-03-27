<?php
ob_start();
// ═══════════════════════════════════════════════════════════════════════════════
//  admin/about.php — About page
//
//  Displays project info: etymology, feature list, and credits.
// ═══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->requireLogin();

// ═══ LAYOUT ══════════════════════════════════════════════════════════════════
$pageTitle = __('about_title');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<div class="main">

    <!-- ═══ TOP BAR ═══════════════════════════════════════════════════════ -->
    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
            <i data-feather="menu"></i>
        </button>
        <h1><?= __('about_title') ?></h1>
        <div class="topbar-actions"></div>
    </div>

    <div class="content">
        <div>

            <!-- ═══ LOGO ══════════════════════════════════════════════════ -->
            <div style="margin-bottom:2.5rem; text-align:center">
                <div style="height:48px; aspect-ratio:541/183; margin:0 auto 1rem;
                            background:var(--accent);
                            -webkit-mask: url('<?= url('/audio/badal_logo.svg') ?>') center/contain no-repeat;
                            mask:         url('<?= url('/audio/badal_logo.svg') ?>') center/contain no-repeat"></div>
                <div style="font-size:.78rem; color:var(--muted)">
                    <?= __('about_version') ?> <?= Version::current() ?>
                </div>
            </div>

            <!-- ═══ ETYMOLOGY ═════════════════════════════════════════════ -->
            <div class="card" style="padding:1.75rem; margin-bottom:1.25rem">
                <h2 style="font-size:1.1rem; font-weight:800; letter-spacing:-.02em; margin-bottom:.75rem">
                    <?= __('about_etymology') ?>
                </h2>
                <p style="font-size:.9rem; color:var(--muted); line-height:1.75; margin-bottom:1rem">
                    <?= __('about_etym_p1') ?>
                </p>
                <p style="font-size:.9rem; color:var(--muted); line-height:1.75; margin-bottom:1rem">
                    <?= __('about_etym_p2') ?>
                </p>
                <div style="background:var(--bg); border:1px solid var(--border);
                            border-radius:10px; padding:1.1rem 1.25rem; margin-top:.5rem">
                    <p style="font-size:.85rem; line-height:1.75; color:var(--text)">
                        <?= __('about_etym_p3') ?>
                        <strong style="color:var(--accent)"><?= __('about_etym_p3_bold') ?></strong>
                    </p>
                    <p style="font-size:.85rem; line-height:1.75; color:var(--text); margin-top:.6rem">
                        <?= __('about_etym_p4') ?>
                    </p>
                </div>
            </div>

            <!-- ═══ FEATURES ══════════════════════════════════════════════ -->
            <div class="card" style="padding:1.75rem; margin-bottom:1.25rem">
                <h2 style="font-size:1.1rem; font-weight:800; letter-spacing:-.02em; margin-bottom:1rem">
                    <?= __('about_features') ?>
                </h2>

                <?php
                $features = [
                    ['mic',         __('about_feat_episodes'),   __('about_feat_episodes_desc')],
                    ['rss',         __('about_feat_rss'),        __('about_feat_rss_desc')],
                    ['bar-chart-2', __('about_feat_stats'),      __('about_feat_stats_desc')],
                    ['layers',      __('about_feat_themes'),     __('about_feat_themes_desc')],
                    ['sliders',     __('about_feat_custom'),     __('about_feat_custom_desc')],
                    ['move',        __('about_feat_reorder'),    __('about_feat_reorder_desc')],
                    ['file-text',   __('about_feat_transcript'), __('about_feat_transcript_desc')],
                    ['search',      __('about_feat_seo'),        __('about_feat_seo_desc')],
                    ['repeat',      __('about_feat_redirect'),   __('about_feat_redirect_desc')],
                    ['shield',      __('about_feat_security'),   __('about_feat_security_desc')],
                    ['hard-drive',  __('about_feat_flatfile'),   __('about_feat_flatfile_desc')],
                    ['tool',        __('about_feat_tools'),      __('about_feat_tools_desc')],
                    ['globe',       __('about_feat_i18n'),       __('about_feat_i18n_desc')],
                ];

                foreach ($features as [$icon, $title, $desc]):
                ?>
                <div style="display:flex; gap:.85rem; align-items:flex-start;
                            <?= $icon !== 'mic' ? 'margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border);' : '' ?>">
                    <div style="width:32px; height:32px; min-width:32px; border-radius:8px;
                                background:rgba(<?php
                                    $hex = ltrim($config['color_accent'] ?? '#e8ff5a', '#');
                                    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                                    echo hexdec(substr($hex,0,2)) . ',' . hexdec(substr($hex,2,2)) . ',' . hexdec(substr($hex,4,2));
                                ?>,.1);
                                display:flex; align-items:center; justify-content:center">
                        <i data-feather="<?= $icon ?>"
                           style="width:15px; height:15px; color:var(--accent)"></i>
                    </div>
                    <div>
                        <div style="font-size:.88rem; font-weight:700; margin-bottom:.2rem">
                            <?= e($title) ?>
                        </div>
                        <div style="font-size:.82rem; color:var(--muted); line-height:1.6">
                            <?= e($desc) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ═══ CREDITS ═══════════════════════════════════════════════ -->
            <div class="card" style="padding:1.75rem">
                <div style="text-align:center">
                    <div style="font-size:.72rem; font-weight:600; letter-spacing:.08em;
                                text-transform:uppercase; color:var(--muted); margin-bottom:1rem">
                        <?= __('about_made_by') ?>
                    </div>
                    <a href="https://robotetdragon.com"
                       target="_blank" rel="noopener"
                       style="display:inline-block; transition:opacity .15s"
                       class="rd-logo-link">
                        <img src="<?= url('/admin/assets/rd_logo.svg') ?>"
                             alt="Robot &amp; Dragon"
                             style="height:72px; width:auto"
                             class="rd-logo-dark">
                        <img src="<?= url('/admin/assets/rd_logo_black.svg') ?>"
                             alt="Robot &amp; Dragon"
                             style="height:72px; width:auto; display:none"
                             class="rd-logo-light">
                    </a>
                </div>
            </div>

            <!-- ── Theme-aware logo switcher ─────────────────────────────── -->
            <script>
                (function() {
                    function updateRdLogo() {
                        var light = document.documentElement.getAttribute('data-theme') === 'light';
                        document.querySelectorAll('.rd-logo-dark').forEach(function(el) {
                            el.style.display = light ? 'none' : '';
                        });
                        document.querySelectorAll('.rd-logo-light').forEach(function(el) {
                            el.style.display = light ? '' : 'none';
                        });
                    }
                    updateRdLogo();
                    new MutationObserver(updateRdLogo).observe(
                        document.documentElement,
                        { attributes: true, attributeFilter: ['data-theme'] }
                    );
                })();
            </script>

        </div>
    </div>
</div>

</body>
</html>
