<?php
// ═══════════════════════════════════════════════════════════════
// TOOLS — Export, Feed Redirect, Delete Podcast
// ═══════════════════════════════════════════════════════════════

ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$configFile = ROOT_DIR . '/config/config.php';
$audioDir   = $config['audio_dir'];
$errors     = [];
$success    = [];


// ═══════════════════════════════════════════════════════════════
// HELPER — Write a key/value into config file
// ═══════════════════════════════════════════════════════════════

function toolsWriteKey(string $file, string $key, string $value): void {
    $content = file_get_contents($file);
    // Escape for PHP single-quoted string: \ → \\, ' → \'
    $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    $count   = 0;
    $content = preg_replace(
        "/('$key'\s*=>\s*)'(?:[^'\\\\]|\\\\.)*'/",
        "'$key' => '$escaped'",
        $content,
        -1,
        $count
    );
    if ($count === 0) {
        $content = preg_replace(
            '/\n\];\s*$/',
            "\n    '$key' => '$escaped',\n\n];\n",
            $content
        );
    }
    file_put_contents($file, $content);
}


// ═══════════════════════════════════════════════════════════════
// ACTION — ZIP Export
// ═══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    csrf_check();
    $zip     = new ZipArchive();
    $tmpFile = tempnam(sys_get_temp_dir(), 'badal_export_') . '.zip';

    if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
        $errors[] = "Impossible de créer l'archive ZIP.";
    } else {
        // Episodes (markdown)
        $episodesDir = $config['content_dir'] . '/episodes';
        if (is_dir($episodesDir)) {
            foreach (glob($episodesDir . '/*.md') as $file) {
                $zip->addFile($file, 'episodes/' . basename($file));
            }
        }

        // Transcripts
        $transcriptsDir = $config['content_dir'] . '/transcripts';
        if (is_dir($transcriptsDir)) {
            foreach (glob($transcriptsDir . '/*') as $file) {
                if (is_file($file)) {
                    $zip->addFile($file, 'transcripts/' . basename($file));
                }
            }
        }

        // Audio & covers
        if (is_dir($audioDir)) {
            $rit = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($audioDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($rit as $file) {
                if ($file->isFile()) {
                    $rel = ltrim(str_replace(realpath($audioDir), '', realpath($file->getPathname())), '/\\');
                    $zip->addFile($file->getPathname(), 'audio/' . str_replace('\\', '/', $rel));
                }
            }
        }

        // Config
        if (file_exists($configFile)) {
            $zip->addFile($configFile, 'config/config.php');
        }

        // Home config
        $homeFile = ROOT_DIR . '/config/home.json';
        if (file_exists($homeFile)) {
            $zip->addFile($homeFile, 'config/home.json');
        }

        // Themes
        $themesDir = ROOT_DIR . '/themes';
        if (is_dir($themesDir)) {
            foreach (glob($themesDir . '/*.json') as $tf) {
                $zip->addFile($tf, 'themes/' . basename($tf));
            }
        }

        // Stats
        $statsFile = ROOT_DIR . '/config/stats.json';
        if (file_exists($statsFile)) {
            $zip->addFile($statsFile, 'config/stats.json');
        }

        $zip->close();

        $podName = preg_replace('/[^a-z0-9_-]/i', '_', $config['podcast_title'] ?? 'badal');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $podName . '_export_' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }
}


// ═══════════════════════════════════════════════════════════════
// ACTION — Feed Redirect
// ═══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'redirect_feed') {
    csrf_check();
    $val = trim($_POST['redirect_feed_url'] ?? '');
    toolsWriteKey($configFile, 'redirect_feed_url', $val);
    $config['redirect_feed_url'] = $val;
    $success[] = $val ? "Redirection activée." : "Redirection désactivée.";
}


// ═══════════════════════════════════════════════════════════════
// ACTION — Delete Podcast
// ═══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_podcast') {
    csrf_check();

    // Remove episodes
    $episodesDir = $config['content_dir'] . '/episodes';
    if (is_dir($episodesDir)) {
        foreach (glob($episodesDir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    // Remove transcripts
    $transcriptsDir = $config['content_dir'] . '/transcripts';
    if (is_dir($transcriptsDir)) {
        foreach (glob($transcriptsDir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    // Remove audio directory contents
    if (is_dir($audioDir)) {
        $rit = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($audioDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rit as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }

    // Remove config files
    $statsFile = ROOT_DIR . '/config/stats.json';
    if (file_exists($statsFile)) @unlink($statsFile);

    $homeFile = ROOT_DIR . '/config/home.json';
    if (file_exists($homeFile)) @unlink($homeFile);

    if (file_exists($configFile)) @unlink($configFile);

    session_destroy();
    header('Location: ' . url('/setup.php'));
    exit;
}


// ═══════════════════════════════════════════════════════════════
// RENDER — Layout
// ═══════════════════════════════════════════════════════════════

$pageTitle = 'Outils';
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<div class="main">
    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
            <i data-feather="menu"></i>
        </button>
        <h1>Outils</h1>
    </div>

    <div class="content">

        <!-- ═══ Flash Messages ═══ -->
        <?php if ($errors): ?>
            <div style="background:rgba(255,80,80,.1); border:1px solid rgba(255,80,80,.3); border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.5rem; color:#ff7070; font-size:.85rem">
                <?php foreach ($errors as $e): ?>
                    <div><?= e($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div style="background:rgba(90,255,154,.08); border:1px solid rgba(90,255,154,.25); border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.5rem; color:#5aff9a; font-size:.85rem">
                <?php foreach ($success as $s): ?>
                    <div><?= e($s) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ═══ Tool Cards Grid ═══ -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1.25rem">

            <!-- ── Feed Redirect Card ── -->
            <div class="card" style="padding:1.5rem">
                <div style="font-size:.72rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); margin-bottom:1rem">
                    Redirection du flux RSS
                </div>
                <form method="POST" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="redirect_feed">

                    <?php $redirectUrl = $config['redirect_feed_url'] ?? ''; ?>

                    <div class="field" style="margin-bottom:.75rem">
                        <input type="text" name="redirect_feed_url"
                               value="<?= e($redirectUrl) ?>"
                               placeholder="https://nouveau-domaine.com/rss.xml">
                    </div>

                    <?php if ($redirectUrl): ?>
                        <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.75rem; padding:.5rem .75rem; background:rgba(255,180,50,.08); border:1px solid rgba(255,180,50,.25); border-radius:8px; font-size:.78rem; color:#ffb432">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            Redirection active — les agrégateurs seront redirigés vers le nouveau flux.
                        </div>
                    <?php endif; ?>

                    <div style="display:flex; gap:.75rem; align-items:center">
                        <button type="submit" class="btn btn-ghost" style="gap:.5rem">
                            <i data-feather="save"></i> Enregistrer
                        </button>
                        <?php if ($redirectUrl): ?>
                            <button type="submit" name="redirect_feed_url" value="" class="btn btn-ghost" style="gap:.5rem; color:var(--muted)">
                                <i data-feather="x"></i> Désactiver
                            </button>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:1rem; padding:1rem 1.1rem; background:var(--bg); border:1px solid var(--border); border-radius:10px; font-size:.82rem; line-height:1.7; color:var(--muted)">
                        <div style="font-weight:700; color:var(--text); margin-bottom:.5rem">
                            Comment rediriger votre flux RSS
                        </div>
                        <ol style="margin:0; padding-left:1.25rem">
                            <li><strong>Installez Badal sur le nouveau domaine</strong> et importez vos épisodes (via l'import RSS ou l'archive ZIP).</li>
                            <li><strong>Vérifiez que le nouveau flux fonctionne</strong> en ouvrant l'URL <code>https://nouveau-domaine.com/rss.xml</code> dans un navigateur.</li>
                            <li><strong>Collez l'URL du nouveau flux</strong> dans le champ ci-dessus et cliquez sur <em>Enregistrer</em>. Badal ajoute automatiquement les balises <code>&lt;itunes:new-feed-url&gt;</code> et <code>&lt;podcast:previousUrl&gt;</code> au flux RSS actuel.</li>
                            <li><strong>Attendez la propagation</strong> — les agrégateurs (Apple Podcasts, Spotify, etc.) détectent ces balises lors de leur prochain passage et mettent à jour l'adresse du flux. Comptez 24 à 72 heures.</li>
                            <li><strong>Gardez l'ancien flux actif</strong> avec la redirection pendant au moins 4 semaines. Certains agrégateurs mettent plus longtemps à se mettre à jour.</li>
                            <li><strong>Désactivez la redirection</strong> une fois que tous vos abonnés sont migrés en cliquant sur <em>Désactiver</em>.</li>
                        </ol>
                    </div>
                </form>
            </div>

            <!-- ── Export Card ── -->
            <div class="card" style="padding:1.5rem">
                <div style="font-size:.72rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); margin-bottom:1rem">
                    Exporter le podcast
                </div>
                <p style="font-size:.85rem; color:var(--muted); line-height:1.6; margin-bottom:1rem">
                    Génère une archive ZIP contenant les épisodes, fichiers audio, pochettes, transcriptions, thèmes et configuration.
                </p>
                <form method="POST" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="btn btn-ghost" style="gap:.5rem; white-space:normal; text-align:left">
                        <i data-feather="download" style="flex-shrink:0"></i> Télécharger l'archive ZIP
                    </button>
                </form>
            </div>

        </div>

        <!-- ═══ Danger Zone ═══ -->
        <div style="margin-top:3rem; padding-top:2rem; border-top:1px solid var(--border)">
            <div style="font-size:.72rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--danger); margin-bottom:1.25rem">
                Zone dangereuse
            </div>

            <div class="card" style="padding:1.5rem; border-color:rgba(255,90,90,.25)">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap">
                    <div>
                        <div style="font-weight:700; font-size:.9rem; margin-bottom:.25rem">Supprimer le podcast</div>
                        <div style="font-size:.8rem; color:var(--muted)">Supprime tous les épisodes, fichiers audio, pochettes, transcriptions et la configuration. Irréversible.</div>
                    </div>
                    <button type="button" class="btn" id="btnDeletePodcast" style="background:var(--danger); gap:.5rem; flex-shrink:0">
                        <i data-feather="trash-2"></i> Supprimer
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══ Delete Modal — Step 1 ═══ -->
        <div id="deleteModal1" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.6); backdrop-filter:blur(4px); align-items:center; justify-content:center">
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:2rem; max-width:420px; width:90%; text-align:center">
                <div style="width:48px; height:48px; border-radius:50%; background:rgba(255,90,90,.12); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem">
                    <i data-feather="alert-triangle" style="color:var(--danger); width:24px; height:24px"></i>
                </div>
                <h3 style="font-size:1.05rem; font-weight:700; margin-bottom:.75rem">Êtes-vous certain ?</h3>
                <p style="font-size:.85rem; color:var(--muted); line-height:1.6; margin-bottom:1.5rem">
                    Cette action supprimera <strong style="color:var(--text)">tous les épisodes, fichiers audio, pochettes, transcriptions et la configuration</strong> de votre podcast. Cette action est irréversible.
                </p>
                <div style="display:flex; gap:.75rem; justify-content:center">
                    <button type="button" class="btn btn-ghost" onclick="closeDeleteModal(1)">Annuler</button>
                    <button type="button" class="btn" style="background:var(--danger)" onclick="showDeleteModal(2)">Oui, supprimer</button>
                </div>
            </div>
        </div>

        <!-- ═══ Delete Modal — Step 2 ═══ -->
        <div id="deleteModal2" style="display:none; position:fixed; inset:0; z-index:10000; background:rgba(0,0,0,.7); backdrop-filter:blur(4px); align-items:center; justify-content:center">
            <div style="background:var(--surface); border:1px solid var(--danger); border-radius:14px; padding:2rem; max-width:420px; width:90%; text-align:center">
                <div style="width:48px; height:48px; border-radius:50%; background:rgba(255,90,90,.2); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem">
                    <i data-feather="trash-2" style="color:var(--danger); width:24px; height:24px"></i>
                </div>
                <h3 style="font-size:1.05rem; font-weight:700; color:var(--danger); margin-bottom:.75rem">Êtes-vous vraiment certain ?</h3>
                <p style="font-size:.85rem; color:var(--muted); line-height:1.6; margin-bottom:1.5rem">
                    Il n'y aura <strong style="color:var(--danger)">aucun moyen de récupérer</strong> vos données après cette suppression. Pensez à exporter votre podcast avant.
                </p>
                <div style="display:flex; gap:.75rem; justify-content:center">
                    <button type="button" class="btn btn-ghost" onclick="closeDeleteModal(2)">Annuler</button>
                    <form method="POST" style="margin:0; display:inline">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="delete_podcast">
                        <button type="submit" class="btn" style="background:var(--danger)">Supprimer définitivement</button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- SCRIPTS                                                       -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<script>
    document.getElementById('btnDeletePodcast').addEventListener('click', () => showDeleteModal(1));

    function showDeleteModal(n) {
        if (n === 2) document.getElementById('deleteModal1').style.display = 'none';
        const m = document.getElementById('deleteModal' + n);
        m.style.display = 'flex';
        feather.replace(m);
    }

    function closeDeleteModal(n) {
        document.getElementById('deleteModal' + n).style.display = 'none';
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeDeleteModal(1);
            closeDeleteModal(2);
        }
    });
</script>

</body>
</html>
