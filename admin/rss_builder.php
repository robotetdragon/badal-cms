<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

// ═══════════════════════════════════════════════════════════════════════════════
// DATA LOADING
// ═══════════════════════════════════════════════════════════════════════════════

$parser   = new EpisodeParser($config['content_dir']);
$episodes = $parser->getAll();

// ═══════════════════════════════════════════════════════════════════════════════
// VALIDATION CHECKS
// ═══════════════════════════════════════════════════════════════════════════════

$checks = [];
$score  = 0;

// ── Required channel fields ─────────────────────────────────────────────────

$required = [
    'podcast_title'       => ['label' => 'Titre du podcast',       'val' => $config['podcast_title'] ?? ''],
    'podcast_description' => ['label' => 'Description',            'val' => $config['podcast_description'] ?? ''],
    'base_url'            => ['label' => 'URL de base',            'val' => $config['base_url'] ?? ''],
    'author'              => ['label' => 'Auteur',                 'val' => $config['author'] ?? ''],
    'email'               => ['label' => 'Email (itunes:email)',   'val' => $config['email'] ?? ''],
    'language'            => ['label' => 'Langue (ex: fr-FR)',     'val' => $config['language'] ?? ''],
    'category'            => ['label' => 'Catégorie iTunes',       'val' => $config['category'] ?? ''],
];

foreach ($required as $key => $info) {
    $ok       = !empty($info['val']);
    $checks[] = ['label' => $info['label'], 'ok' => $ok, 'critical' => true, 'val' => $info['val']];
    if ($ok) {
        $score++;
    }
}

// ── Cover image: check that the file exists and is >= 3000px ────────────────

$coverFile = $config['cover_image'] ?? '';
$coverPath = $config['audio_dir'] . '/' . $coverFile;
$coverOk   = false;
$coverVal  = 'requis';

if ($coverFile && file_exists($coverPath)) {
    $dims = @getimagesize($coverPath);
    if ($dims && $dims[0] >= 3000 && $dims[1] >= 3000) {
        $coverOk  = true;
        $coverVal = $dims[0] . '×' . $dims[1] . 'px';
    } elseif ($dims) {
        $coverVal = $dims[0] . '×' . $dims[1] . 'px (min 3000×3000)';
    } else {
        $coverVal = 'fichier illisible';
    }
}

$checks[] = ['label' => 'Image de couverture (3000×3000px)', 'ok' => $coverOk, 'critical' => true, 'val' => $coverVal];
if ($coverOk) {
    $score++;
}

// ── Episode checks ──────────────────────────────────────────────────────────

$epWithoutAudio    = 0;
$epWithoutDesc     = 0;
$epWithoutDuration = 0;

foreach ($episodes as $ep) {
    if (empty($ep['audio']))       { $epWithoutAudio++; }
    if (empty($ep['description'])) { $epWithoutDesc++; }
    if (empty($ep['duration']))    { $epWithoutDuration++; }
}

$totalEp = count($episodes);

$checks[] = ['label' => 'Épisodes avec fichier audio',    'ok' => $epWithoutAudio === 0,    'critical' => true,  'val' => $totalEp - $epWithoutAudio . '/' . $totalEp];
$checks[] = ['label' => 'Épisodes avec description',      'ok' => $epWithoutDesc === 0,     'critical' => false, 'val' => $totalEp - $epWithoutDesc . '/' . $totalEp];
$checks[] = ['label' => 'Épisodes avec durée (HH:MM:SS)', 'ok' => $epWithoutDuration === 0, 'critical' => false, 'val' => $totalEp - $epWithoutDuration . '/' . $totalEp];

if ($epWithoutAudio === 0)    { $score++; }
if ($epWithoutDesc === 0)     { $score++; }
if ($epWithoutDuration === 0) { $score++; }

// ── Score calculation ───────────────────────────────────────────────────────

$rssUrl      = rtrim($config['base_url'] ?? '', '/') . '/rss.xml';
$totalChecks = count($checks);
$pct         = $totalChecks > 0 ? round($score / $totalChecks * 100) : 0;

// ═══════════════════════════════════════════════════════════════════════════════
// ITUNES CATEGORIES
// ═══════════════════════════════════════════════════════════════════════════════

$itunesCategories = [
    'Arts'                  => ['Books','Design','Fashion & Beauty','Food','Performing Arts','Visual Arts'],
    'Business'              => ['Careers','Entrepreneurship','Investing','Management','Marketing','Non-Profit'],
    'Comedy'                => ['Comedy Interviews','Improv','Stand-Up'],
    'Education'             => ['Courses','How To','Language Learning','Self-Improvement'],
    'Fiction'               => ['Comedy Fiction','Drama','Science Fiction'],
    'Government'            => [],
    'History'               => [],
    'Health & Fitness'      => ['Alternative Health','Fitness','Medicine','Mental Health','Nutrition','Sexuality'],
    'Kids & Family'         => ['Education for Kids','Parenting','Pets & Animals','Stories for Kids'],
    'Leisure'               => ['Animation & Manga','Automotive','Aviation','Crafts','Games','Hobbies','Home & Garden','Video Games'],
    'Music'                 => ['Music Commentary','Music History','Music Interviews'],
    'News'                  => ['Business News','Daily News','Entertainment News','News Commentary','Politics','Sports News','Tech News'],
    'Religion & Spirituality' => ['Buddhism','Christianity','Hinduism','Islam','Judaism','Religion','Spirituality'],
    'Science'               => ['Astronomy','Chemistry','Earth Sciences','Life Sciences','Mathematics','Natural Sciences','Nature','Physics','Social Sciences'],
    'Society & Culture'     => ['Documentary','Personal Journals','Philosophy','Places & Travel','Relationships'],
    'Sports'                => ['Baseball','Basketball','Cricket','Fantasy Sports','Football','Golf','Hockey','Rugby','Soccer','Swimming','Tennis','Volleyball','Wilderness','Wrestling'],
    'Technology'            => [],
    'True Crime'            => [],
    'TV & Film'             => ['After Shows','Film History','Film Interviews','Film Reviews','TV Reviews'],
];

// ═══════════════════════════════════════════════════════════════════════════════
// LAYOUT
// ═══════════════════════════════════════════════════════════════════════════════

$pageTitle = __('rss_title');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>
<style>
    /* ── Check rows ────────────────────────────────────────────────────────── */
    .check-row {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .6rem 0;
        border-bottom: 1px solid var(--border);
        font-size: .84rem;
    }
    .check-row:last-child { border: none; }

    .check-icon {
        flex-shrink: 0;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .7rem;
        font-weight: 700;
    }
    .check-ok   { background: rgba(90,255,154,.15);  color: #5aff9a; }
    .check-fail { background: rgba(255,90,90,.15);   color: #ff5a5a; }
    .check-warn { background: rgba(255,200,90,.15);  color: #ffc85a; }

    .check-label { flex: 1; }
    .check-val {
        font-size: .75rem;
        color: var(--muted);
        font-weight: 600;
    }
    .check-crit {
        font-size: .65rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #ff5a5a;
        background: rgba(255,90,90,.1);
        border-radius: 20px;
        padding: .1rem .4rem;
        flex-shrink: 0;
    }

    /* ── Score ring ────────────────────────────────────────────────────────── */
    .score-ring {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .score-ring svg { transform: rotate(-90deg); }
    .score-ring .score-label {
        position: absolute;
        font-size: .92rem;
        font-weight: 800;
        letter-spacing: -.03em;
    }
    .score-ring .score-sub {
        position: absolute;
        top: 58%;
        font-size: .6rem;
        color: var(--muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
    }

    /* ── RSS URL box ───────────────────────────────────────────────────────── */
    .rss-url-box {
        display: flex;
        align-items: center;
        gap: .6rem;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: .65rem 1rem;
    }
    .rss-url-box code {
        font-size: .82rem;
        color: var(--accent);
        font-family: monospace;
        flex: 1;
        word-break: break-all;
    }
    .copy-btn {
        background: var(--surface2);
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--muted);
        font-family: 'Syne', sans-serif;
        font-size: .73rem;
        font-weight: 600;
        padding: .28rem .6rem;
        cursor: pointer;
        white-space: nowrap;
        transition: color .15s;
    }
    .copy-btn:hover { color: var(--accent); }

    /* ── Submission cards ──────────────────────────────────────────────────── */
    .submission-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 1.25rem;
        display: flex;
        gap: 1.1rem;
        align-items: flex-start;
    }
    .submission-card .logo {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 1.3rem;
    }
    .submission-card h3 {
        font-size: .9rem;
        font-weight: 700;
        margin-bottom: .2rem;
    }
    .submission-card p {
        font-size: .78rem;
        color: var(--muted);
        line-height: 1.5;
        margin-bottom: .6rem;
    }

    .sub-link {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        font-size: .78rem;
        font-weight: 700;
        color: var(--accent);
        text-decoration: none;
    }
    .sub-link:hover { text-decoration: underline; }

    /* ── Config form ───────────────────────────────────────────────────────── */
    .form-config {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    .rss-two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    @media (max-width: 640px) {
        .form-config  { grid-template-columns: 1fr; }
        .rss-two-col  { grid-template-columns: 1fr; }
    }
    .field-full { grid-column: 1 / -1; }
</style>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TOPBAR                                                                    -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div class="main">
    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
            <i data-feather="menu"></i>
        </button>
        <h1>Constructeur RSS &amp; Distribution</h1>
        <a href="<?= e($rssUrl) ?>" target="_blank" class="btn">Voir le flux ↗</a>
    </div>

    <div class="content">

        <!-- ═══ Score + URL ═══════════════════════════════════════════════ -->

        <div style="display:grid;grid-template-columns:1fr auto;gap:1.5rem;align-items:start;margin-bottom:1.5rem">

            <!-- RSS URL card -->
            <div class="card" style="padding:1.35rem;flex:1">
                <div style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem">Votre URL de flux RSS</div>
                <div class="rss-url-box">
                    <code id="rss-url"><?= e($rssUrl) ?></code>
                    <button class="copy-btn" onclick="copyRss()"><?= __('copy') ?></button>
                </div>
                <div style="font-size:.75rem;color:var(--muted);margin-top:.55rem">
                    Soumettez cette URL à Apple Podcasts, Spotify, Pocket Casts, etc.
                </div>
            </div>

            <!-- Score ring card -->
            <div class="card" style="padding:1.35rem;text-align:center">
                <div style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:.85rem">Score RSS</div>
                <div class="score-ring">
                    <svg width="80" height="80" viewBox="0 0 80 80">
                        <circle cx="40" cy="40" r="34" fill="none" stroke="var(--border)" stroke-width="6"/>
                        <circle cx="40" cy="40" r="34" fill="none"
                            stroke="<?= $pct >= 80 ? '#5aff9a' : ($pct >= 50 ? '#ffc85a' : '#ff5a5a') ?>"
                            stroke-width="6" stroke-linecap="round"
                            stroke-dasharray="<?= round(2 * M_PI * 34, 1) ?>"
                            stroke-dashoffset="<?= round(2 * M_PI * 34 * (1 - $pct / 100), 1) ?>"/>
                    </svg>
                    <div class="score-label" style="color:<?= $pct >= 80 ? '#5aff9a' : ($pct >= 50 ? '#ffc85a' : '#ff5a5a') ?>"><?= $pct ?>%</div>
                </div>
            </div>
        </div>

        <!-- ═══ Validation Apple Podcasts ═════════════════════════════════ -->

        <div class="card" style="overflow:hidden;margin-bottom:1.5rem">
            <div style="padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)">
                Validation Apple Podcasts
            </div>
            <div style="padding:.25rem 1.25rem">
                <?php foreach ($checks as $c): ?>
                <div class="check-row">
                    <div class="check-icon <?= $c['ok'] ? 'check-ok' : ($c['critical'] ? 'check-fail' : 'check-warn') ?>">
                        <?= $c['ok'] ? '✓' : ($c['critical'] ? '✗' : '!') ?>
                    </div>
                    <div class="check-label"><?= e($c['label']) ?></div>
                    <?php if (!empty($c['val'])): ?>
                        <div class="check-val"><?= e(mb_strimwidth($c['val'], 0, 24, '…')) ?></div>
                    <?php endif; ?>
                    <?php if (!$c['ok'] && $c['critical']): ?>
                        <div class="check-crit">requis</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ═══ Config + Live RSS ═════════════════════════════════════════ -->

        <div class="rss-two-col">

            <!-- Config form -->
            <div class="card" style="padding:1.25rem">
                <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">
                    Métadonnées du flux — config/config.php
                </div>
                <div class="form-config">
                    <div class="field">
                        <label>Langue</label>
                        <input type="text" value="<?= e($config['language'] ?? 'fr-FR') ?>" placeholder="fr-FR" readonly>
                    </div>
                    <div class="field">
                        <label>Type (itunes:type)</label>
                        <select disabled>
                            <option>episodic</option>
                            <option>serial</option>
                        </select>
                    </div>
                    <div class="field field-full">
                        <label>Catégorie principale</label>
                        <select id="cat-main" onchange="updateSubcat()">
                            <?php foreach ($itunesCategories as $cat => $subs): ?>
                                <option <?= ($config['category'] ?? '') === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field field-full">
                        <label>Sous-catégorie</label>
                        <select id="cat-sub">
                            <option value="">— aucune —</option>
                        </select>
                    </div>
                    <div class="field field-full">
                        <label>URL image de couverture</label>
                        <input type="url" value="<?= e($config['cover_image_url'] ?? '') ?>" placeholder="https://…/cover.jpg">
                        <span class="hint">JPEG ou PNG, 1400×1400 à 3000×3000 px</span>
                    </div>
                    <div class="field field-full">
                        <label>Email contact (itunes:email)</label>
                        <input type="email" value="<?= e($config['email'] ?? '') ?>" placeholder="contact@monpodcast.com">
                    </div>
                    <div class="field field-full">
                        <label>Contenu explicite</label>
                        <select disabled>
                            <option>false (clean)</option>
                            <option>true (explicit)</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:1rem;font-size:.75rem;color:var(--muted);background:rgba(232,255,90,.04);border:1px solid rgba(232,255,90,.12);border-radius:7px;padding:.65rem .85rem">
                    Ces champs sont définis dans <code style="color:var(--accent)">config/config.php</code>. Modifiez ce fichier pour les mettre à jour.
                </div>
            </div>

            <!-- Live RSS feed preview -->
            <div class="card" style="padding:1.25rem">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;gap:1rem;flex-wrap:wrap">
                    <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)">Flux RSS — contenu réel</div>
                    <div style="display:flex;gap:.5rem">
                        <button onclick="copyRss()" class="btn btn-ghost copy-btn" style="font-size:.75rem;padding:.3rem .7rem">Copier l'URL</button>
                        <a href="<?= e($rssUrl) ?>" target="_blank" class="btn btn-ghost" style="font-size:.75rem;padding:.3rem .7rem">Ouvrir ↗</a>
                    </div>
                </div>
                <?php
                $rss        = new RssGenerator($config);
                $xmlContent = $rss->generate($episodes);

                $dom = new DOMDocument('1.0');
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput       = true;

                if (@$dom->loadXML($xmlContent)) {
                    $prettyXml = $dom->saveXML();
                } else {
                    $prettyXml = $xmlContent;
                }
                ?>
                <pre id="rss-preview" style="font-size:.72rem;line-height:1.55;color:#bbb;overflow:auto;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:1rem;max-height:460px;tab-size:2"><?= htmlspecialchars($prettyXml) ?></pre>
            </div>
        </div>

        <!-- ═══ Distribution / Submission ═════════════════════════════════ -->

        <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:.85rem">
            Soumettre votre podcast
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1.5rem">

            <!-- Apple Podcasts -->
            <div class="submission-card">
                <div class="logo" style="background:#fc3c44;">🎵</div>
                <div>
                    <h3>Apple Podcasts</h3>
                    <p>Connectez-vous à Podcast Connect et soumettez votre URL RSS. Délai de validation : 24–72 h.</p>
                    <a class="sub-link" href="https://podcastsconnect.apple.com/" target="_blank" rel="noopener">
                        Ouvrir Podcast Connect ↗
                    </a>
                </div>
            </div>

            <!-- Spotify -->
            <div class="submission-card">
                <div class="logo" style="background:#1db954;">🎧</div>
                <div>
                    <h3>Spotify for Podcasters</h3>
                    <p>Importez votre flux RSS dans le tableau de bord Spotify. Approbation quasi-instantanée.</p>
                    <a class="sub-link" href="https://podcasters.spotify.com/" target="_blank" rel="noopener">
                        Ouvrir Spotify for Podcasters ↗
                    </a>
                </div>
            </div>

            <!-- Amazon Music -->
            <div class="submission-card">
                <div class="logo" style="background:#003a6c;">📻</div>
                <div>
                    <h3>Amazon Music / Audible</h3>
                    <p>Soumettez via le portail Amazon Music for Podcasters.</p>
                    <a class="sub-link" href="https://podcasters.amazon.com/" target="_blank" rel="noopener">
                        Ouvrir Amazon Music ↗
                    </a>
                </div>
            </div>

            <!-- Pocket Casts -->
            <div class="submission-card">
                <div class="logo" style="background:#e8404a;">🎙️</div>
                <div>
                    <h3>Pocket Casts / Podcast Index</h3>
                    <p>Soumettez à Podcast Index, référencé automatiquement par Pocket Casts, Fountain et d'autres.</p>
                    <a class="sub-link" href="https://api.podcastindex.org/developer_docs" target="_blank" rel="noopener">
                        Podcast Index ↗
                    </a>
                </div>
            </div>

            <!-- Google / YouTube -->
            <div class="submission-card">
                <div class="logo" style="background:#4a90e2;">📡</div>
                <div>
                    <h3>Google Podcasts / YouTube</h3>
                    <p>Importez votre RSS dans YouTube Studio → Podcasts pour une présence sur Google Search.</p>
                    <a class="sub-link" href="https://studio.youtube.com/" target="_blank" rel="noopener">
                        YouTube Studio ↗
                    </a>
                </div>
            </div>

            <!-- Podchaser -->
            <div class="submission-card">
                <div class="logo" style="background:#4a4a4a;">🔍</div>
                <div>
                    <h3>Podchaser</h3>
                    <p>Base de données de podcasts très utilisée par les outils d'analyse et les annuaires.</p>
                    <a class="sub-link" href="https://www.podchaser.com/creators/dashboard" target="_blank" rel="noopener">
                        Podchaser ↗
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT                                                                -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<script>
    // ═══ iTunes subcategory updater ══════════════════════════════════════════

    const cats = <?= json_encode($itunesCategories) ?>;

    function updateSubcat() {
        const main = document.getElementById('cat-main').value;
        const sub  = document.getElementById('cat-sub');
        sub.innerHTML = '<option value="">— aucune —</option>';
        (cats[main] || []).forEach(s => {
            const o     = document.createElement('option');
            o.value     = s;
            o.textContent = s;
            sub.appendChild(o);
        });
    }
    updateSubcat();

    // ═══ Copy RSS URL ════════════════════════════════════════════════════════

    function copyRss() {
        const url = document.getElementById('rss-url').textContent;
        navigator.clipboard.writeText(url).then(() => {
            const btn       = document.querySelector('.copy-btn');
            btn.textContent = '✓ Copié !';
            setTimeout(() => btn.textContent = 'Copier', 1500);
        });
    }
</script>
</body>
</html>
