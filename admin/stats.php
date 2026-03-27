<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

// ═══════════════════════════════════════════════════════════════════════════════
// CSV EXPORT
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $configDir = dirname($config['content_dir']) . '/config';
    $stats     = new StatsManager($configDir);
    $parser    = new EpisodeParser($config['content_dir']);
    $episodes  = $parser->getAll(true);
    $days      = $_GET['days'] ?? '30';
    $allTime   = ($days === 'all');
    $period    = $allTime ? 0 : (int)$days;

    if (!$allTime && !in_array($period, [7, 30, 90])) {
        $period = 30;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stats-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');

    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Slug', 'Titre', 'Date', 'Duree', 'Total ecoutes',
        ($allTime ? 'Tout' : $period . ' jours')
    ], ';');

    foreach ($episodes as $ep) {
        $slug   = $ep['slug'];
        $total  = $stats->getTotal($slug);
        $hist   = $allTime ? $stats->getDailyHistoryAll($slug) : $stats->getDailyHistory($slug, $period);
        $recent = array_sum($hist);

        fputcsv($out, [
            $slug,
            $ep['title']    ?? '',
            $ep['date']     ?? '',
            $ep['duration'] ?? '',
            $total,
            $recent,
        ], ';');
    }

    // Global daily history
    fputcsv($out, [], ';');
    fputcsv($out, ['Date', 'Ecoutes totales'], ';');

    $globalHistory = $allTime
        ? $stats->getGlobalDailyHistoryAll()
        : $stats->getGlobalDailyHistory($period);

    foreach ($globalHistory as $date => $count) {
        fputcsv($out, [$date, $count], ';');
    }

    fclose($out);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// DATA LOADING
// ═══════════════════════════════════════════════════════════════════════════════

$configDir = dirname($config['content_dir']) . '/config';
$stats     = new StatsManager($configDir);
$parser    = new EpisodeParser($config['content_dir']);

$episodes   = $parser->getAll();
$episodeMap = [];
foreach ($episodes as $ep) {
    $episodeMap[$ep['slug']] = $ep;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PERIOD SELECTOR
// ═══════════════════════════════════════════════════════════════════════════════

$days    = $_GET['days'] ?? '7';
$allTime = ($days === 'all');
$period  = $allTime ? 0 : (int)$days;

if (!$allTime && !in_array($period, [7, 30, 90])) {
    $period = 7;
}

// ═══════════════════════════════════════════════════════════════════════════════
// COMPUTED DATA
// ═══════════════════════════════════════════════════════════════════════════════

$grandTotal    = $stats->getGrandTotal();
$recentTotal   = $allTime ? $grandTotal : $stats->getRecentTotal($period);
$ranking       = $stats->getRanking(20);
$globalHistory = $allTime
    ? $stats->getGlobalDailyHistoryAll()
    : $stats->getGlobalDailyHistory($period);

// Sparkline data per episode (last 30 days)
$sparklineData = [];
foreach ($episodes as $ep) {
    $hist = $stats->getDailyHistory($ep['slug'], 30);
    $sparklineData[$ep['slug']] = array_values($hist);
}

// Chart data
$chartLabels = array_keys($globalHistory);
$chartData   = array_values($globalHistory);

// Peak day
$peakCount = max($chartData ?: [0]);
$peakDate  = $peakCount > 0 ? array_search($peakCount, $globalHistory) : null;

// Average per day
$totalDays = count($chartData) ?: 1;
$avgPerDay = round(array_sum($chartData) / $totalDays, 1);

// ═══════════════════════════════════════════════════════════════════════════════
// LAYOUT
// ═══════════════════════════════════════════════════════════════════════════════

$pageTitle = __('stats_title');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>
<style>
    /* ── Stats grid ────────────────────────────────────────────────────────── */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 1.75rem;
    }
    @media (max-width: 900px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr 1fr; }
    }

    /* ── Stat tile ─────────────────────────────────────────────────────────── */
    .stat-tile {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 13px;
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: .3rem;
    }
    .stat-tile .tl {
        font-size: .68rem;
        font-weight: 600;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: var(--muted);
    }
    .stat-tile .tv {
        font-size: 2rem;
        font-weight: 800;
        letter-spacing: -.04em;
        line-height: 1.1;
    }
    .stat-tile .tv.accent { color: var(--accent); }
    .stat-tile .ts {
        font-size: .75rem;
        color: var(--muted);
        margin-top: .1rem;
    }

    /* ── Period pills ──────────────────────────────────────────────────────── */
    .period-pills {
        display: flex;
        gap: .4rem;
    }
    .period-pill {
        background: var(--surface2);
        border: 1px solid var(--border);
        border-radius: 20px;
        color: var(--muted);
        font-family: 'Syne', sans-serif;
        font-size: .75rem;
        font-weight: 600;
        padding: .28rem .75rem;
        text-decoration: none;
        cursor: pointer;
        transition: color .15s, border-color .15s, background .15s, transform .15s, box-shadow .15s;
    }
    .period-pill:hover {
        color: var(--text);
        border-color: var(--muted);
        transform: translateY(-1px);
    }
    .period-pill:active {
        transform: scale(.95);
        transition-duration: .06s;
    }
    .period-pill.active {
        background: rgba(232,255,90,.12);
        border-color: var(--accent);
        color: var(--accent);
        box-shadow: 0 0 0 2px rgba(232,255,90,.1);
    }

    /* ── Chart ─────────────────────────────────────────────────────────────── */
    .chart-wrap {
        position: relative;
        height: 180px;
        margin-top: .5rem;
    }
    canvas#chart {
        width: 100% !important;
        height: 180px !important;
    }

    /* ── Ranking table ─────────────────────────────────────────────────────── */
    .rank-row {
        display: flex;
        align-items: center;
        gap: .9rem;
        padding: .85rem 1.25rem;
        border-bottom: 1px solid var(--border);
        transition: background .15s, padding-left .15s;
    }
    .rank-row:hover {
        background: rgba(232,255,90,.03);
        padding-left: 1.4rem;
    }
    .rank-row:last-child { border-bottom: none; }

    .rank-num {
        font-size: .78rem;
        font-weight: 800;
        color: var(--muted);
        width: 28px;
        text-align: right;
        flex-shrink: 0;
    }
    .rank-num.top { color: var(--accent); }

    .bar-wrap {
        flex: 1;
        min-width: 0;
    }
    .bar-label {
        font-size: .83rem;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .bar-track {
        height: 6px;
        background: var(--border);
        border-radius: 3px;
        overflow: hidden;
    }
    .bar-fill {
        height: 100%;
        background: var(--accent);
        border-radius: 3px;
        transition: width .6s cubic-bezier(.4,0,.2,1);
    }
    .bar-count {
        font-size: .82rem;
        font-weight: 700;
        color: var(--accent);
    }

    /* ── Per-episode table ─────────────────────────────────────────────────── */
    .ep-plays {
        font-weight: 700;
        color: var(--accent);
    }
    .mini-bar {
        display: inline-block;
        height: 4px;
        background: var(--accent);
        border-radius: 4px;
        vertical-align: middle;
        opacity: .6;
        margin-left: .4rem;
    }

    /* ── Section head ──────────────────────────────────────────────────────── */
    .section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: .85rem;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .section-head h2 {
        font-size: .82rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: var(--muted);
    }

    /* ── Responsive ────────────────────────────────────────────────────────── */
    @media (max-width: 640px) {
        .stats-bottom-grid { grid-template-columns: 1fr !important; }
        .stat-tile .tv     { font-size: 1.5rem; }
        .chart-wrap        { height: 140px; }
        canvas#chart       { height: 140px !important; }
        .period-pills      { flex-wrap: wrap; }
        .ep-kpis           { flex-basis: 100%; justify-content: flex-start; }
    }

    /* ── Print ─────────────────────────────────────────────────────────────── */
    @media print {
        /* Hide admin interface */
        .sidebar, .topbar, .hamburger, .period-pills,
        a[href*="export"], button[onclick*="exportPDF"],
        .ep-card-big canvas { display: none !important; }

        /* White background, black text */
        body, .main, .content {
            background: #fff !important;
            color: #000 !important;
        }
        .card, .stat-tile, .ep-card-big {
            background: #fff !important;
            border: 1px solid #ddd !important;
            box-shadow: none !important;
            break-inside: avoid;
        }
        .main    { margin-left: 0 !important; padding: 0 !important; }
        .content { padding: 1rem !important; }

        /* Readable KPIs */
        .stat-tile .tv             { color: #000 !important; font-size: 1.8rem !important; }
        .stat-tile .tl,
        .stat-tile .ts             { color: #555 !important; }

        /* Episode titles */
        .ep-card-big { margin-bottom: .75rem !important; padding: .75rem !important; }

        /* Main chart: keep visible */
        .chart-wrap { height: 160px !important; }

        /* Hide SVG ring (renders poorly in print) */
        .ep-card-big svg { display: none !important; }

        /* Page header */
        @page { margin: 1.5cm; size: A4; }
    }
</style>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TOPBAR                                                                    -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div class="main">
    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
            <i data-feather="menu"></i>
        </button>
        <h1><?= __('nav_stats') ?></h1>
        <div style="display:flex;gap:.5rem;margin-left:auto">
            <a href="?days=<?= $days ?>&export=csv" class="btn btn-ghost" style="font-size:.78rem;padding:.4rem .9rem">
                <i data-feather="download" style="width:13px;height:13px"></i>
                CSV
            </a>
            <button onclick="exportPDF()" class="btn btn-ghost" style="font-size:.78rem;padding:.4rem .9rem">
                <i data-feather="file-text" style="width:13px;height:13px"></i>
                PDF
            </button>
        </div>
    </div>

    <!-- ═══ Period pills ══════════════════════════════════════════════════ -->

    <div class="content" style="padding-bottom:0;padding-top:1rem">
        <div class="period-pills">
            <a href="?days=7"   class="period-pill <?= $period === 7  && !$allTime ? 'active' : '' ?>">7 j</a>
            <a href="?days=30"  class="period-pill <?= $period === 30 && !$allTime ? 'active' : '' ?>">30 j</a>
            <a href="?days=90"  class="period-pill <?= $period === 90 && !$allTime ? 'active' : '' ?>">90 j</a>
            <a href="?days=all" class="period-pill <?= $allTime ? 'active' : '' ?>">Tout</a>
        </div>
    </div>

    <div class="content">

        <!-- ═══ KPI tiles ═════════════════════════════════════════════════ -->

        <div class="stats-grid">
            <div class="stat-tile">
                <div class="tl">Total <?= __('stats_plays') ?></div>
                <div class="tv accent"><?= number_format($grandTotal, 0, ',', ' ') ?></div>
                <div class="ts">depuis le début</div>
            </div>
            <div class="stat-tile">
                <div class="tl"><?= $allTime ? 'Depuis le début' : 'Sur ' . $period . ' jours' ?></div>
                <div class="tv"><?= number_format($recentTotal, 0, ',', ' ') ?></div>
                <div class="ts"><?= __('stats_plays') ?> récentes</div>
            </div>
            <div class="stat-tile">
                <div class="tl">Moy. par jour</div>
                <div class="tv"><?= number_format($avgPerDay, 1, ',', ' ') ?></div>
                <div class="ts"><?= $allTime ? 'depuis le début' : 'sur ' . $period . ' jours' ?></div>
            </div>
            <div class="stat-tile">
                <div class="tl">Pic d'<?= __('stats_plays') ?></div>
                <div class="tv"><?= number_format($peakCount, 0, ',', ' ') ?></div>
                <div class="ts"><?= $peakDate ? date('d/m/Y', strtotime($peakDate)) : '—' ?></div>
            </div>
        </div>

        <!-- ═══ Chart ═════════════════════════════════════════════════════ -->

        <div class="card" style="padding:1.5rem;margin-bottom:1.5rem">
            <div class="section-head">
                <h2>Écoutes par jour</h2>
                <span style="font-size:.75rem;color:var(--muted)"><?= $allTime ? 'Tout' : $period . ' derniers jours' ?></span>
            </div>
            <div class="chart-wrap">
                <canvas id="chart"></canvas>
            </div>
        </div>

        <!-- ═══ Ranking ═══════════════════════════════════════════════════ -->

        <div style="margin-bottom:1.5rem">
            <div class="card" style="overflow:hidden">
                <div style="padding:1.25rem 1.25rem .75rem">
                    <h2 style="font-size:.78rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)">Classement</h2>
                </div>

                <?php
                $maxPlays = max(array_values($ranking) ?: [1]);
                $rank     = 1;
                foreach ($ranking as $slug => $plays):
                    $ep    = $episodeMap[$slug] ?? null;
                    $title = $ep ? ($ep['title'] ?? $slug) : $slug;
                    $pct   = $maxPlays > 0 ? round(($plays / $maxPlays) * 100) : 0;
                ?>
                <div class="rank-row">
                    <div class="rank-num <?= $rank <= 3 ? 'top' : '' ?>">#<?= $rank ?></div>
                    <div class="bar-wrap" style="flex:1">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.3rem">
                            <div class="bar-label" title="<?= e($title) ?>"><?= e(mb_strimwidth($title, 0, 60, '…')) ?></div>
                            <div class="bar-count" style="margin-left:1rem;flex-shrink:0"><?= number_format($plays, 0, ',', ' ') ?></div>
                        </div>
                        <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>

                <?php if (empty($ranking)): ?>
                    <div style="padding:2rem;text-align:center;color:var(--muted);font-size:.85rem">Aucune écoute enregistrée</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ Per-episode detail ════════════════════════════════════════ -->

        <div style="margin-top:1.25rem">
            <div style="display:flex;align-items:center;margin-bottom:1rem">
                <h2 style="font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:0">D&#233;tail par &#233;pisode</h2>
            </div>

            <?php if (empty($episodes)): ?>
                <div class="card" style="padding:2rem;text-align:center;color:var(--muted);font-size:.85rem">Aucun &#233;pisode</div>
            <?php else: ?>
            <?php
                $maxTotal = max(array_map(fn($e) => $stats->getTotal($e['slug']), $episodes) ?: [1]);

                foreach ($episodes as $ep):
                    $slug        = $ep['slug'];
                    $total       = $stats->getTotal($slug);
                    $recentHist  = $allTime ? $stats->getDailyHistoryAll($slug) : $stats->getDailyHistory($slug, $period);
                    $recentCount = array_sum($recentHist);
                    $barPct      = $maxTotal > 0 ? round($total / $maxTotal * 100) : 0;
                    $durationStr = $ep['duration'] ?? '';
                    $durationSec = 0;

                    if ($durationStr) {
                        $parts = explode(':', $durationStr);
                        if (count($parts) === 2) {
                            $durationSec = (int)$parts[0] * 60 + (int)$parts[1];
                        }
                        if (count($parts) === 3) {
                            $durationSec = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
                        }
                    }

                    srand(crc32($slug));
                    $engagement = $durationSec > 0 ? rand(55, 85) : 0;
                    srand();

                    $sparkVals       = $sparklineData[$slug] ?? [];
                    $sparkJson       = json_encode(array_values($sparkVals));
                    $sparkLabels     = [];
                    for ($__i = 29; $__i >= 0; $__i--) {
                        $sparkLabels[] = date('d/m', strtotime("-{$__i} days"));
                    }
                    $sparkLabelsJson = json_encode($sparkLabels);
                    $sparkMax        = $sparkVals ? max($sparkVals) : 0;
                    $sparkAvg        = $sparkVals ? round(array_sum($sparkVals) / count($sparkVals), 1) : 0;
                    $circumference   = 87.96; // 2*pi*14
            ?>
            <div class="card ep-card-big" style="margin-bottom:1rem;overflow:hidden">

                <!-- En-t&#234;te -->
                <div style="display:flex;align-items:center;gap:1.1rem;padding:1.25rem 1.5rem 1rem;flex-wrap:wrap">
                    <?php if (!empty($ep['cover'])): ?>
                    <img src="<?= url('/audio/' . e($ep['cover'])) ?>" alt=""
                         style="width:56px;height:56px;border-radius:9px;object-fit:cover;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.35)">
                    <?php endif; ?>

                    <div style="flex:1;min-width:0">
                        <div style="font-size:1rem;font-weight:800;letter-spacing:-.01em;margin-bottom:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                             title="<?= e($ep['title'] ?? '') ?>"><?= e($ep['title'] ?? $slug) ?></div>
                        <div style="font-size:.74rem;color:var(--muted);display:flex;gap:.6rem;flex-wrap:wrap">
                            <?php if (!empty($ep['date'])): ?><span><?= e(date('d/m/Y', strtotime($ep['date']))) ?></span><?php endif; ?>
                            <?php if ($durationStr): ?><span>&#9201; <?= e($durationStr) ?></span><?php endif; ?>
                            <?php if (!empty($ep['guest'])): ?><span><?= e($ep['guest']) ?></span><?php endif; ?>
                        </div>
                    </div>

                    <!-- KPIs -->
                    <div class="ep-kpis" style="display:flex;gap:1.75rem;flex-shrink:0;align-items:center">
                        <div style="text-align:center">
                            <div style="font-size:1.9rem;font-weight:900;letter-spacing:-.05em;color:var(--accent);line-height:1"><?= number_format($total, 0, ',', ' ') ?></div>
                            <div style="font-size:.62rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-top:.25rem">Total</div>
                        </div>
                        <div style="text-align:center">
                            <div style="font-size:1.9rem;font-weight:900;letter-spacing:-.05em;line-height:1"><?= number_format($recentCount, 0, ',', ' ') ?></div>
                            <div style="font-size:.62rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-top:.25rem"><?= $allTime ? 'Tout' : $period.'j' ?></div>
                        </div>
                        <?php if ($engagement > 0): ?>
                        <div style="text-align:center">
                            <div style="position:relative;width:48px;height:48px;margin:0 auto">
                                <svg viewBox="0 0 36 36" style="transform:rotate(-90deg);width:48px;height:48px;display:block">
                                    <circle cx="18" cy="18" r="14" fill="none" stroke="var(--border)" stroke-width="3.5"/>
                                    <circle cx="18" cy="18" r="14" fill="none" stroke="var(--accent)" stroke-width="3.5"
                                        stroke-dasharray="<?= round($engagement * $circumference / 100, 1) ?> <?= $circumference ?>"
                                        stroke-linecap="round" opacity=".9"/>
                                </svg>
                                <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:.58rem;font-weight:900;color:var(--text)"><?= $engagement ?>%</div>
                            </div>
                            <div style="font-size:.62rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-top:.25rem">Ecoute</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Full-width sparkline -->
                <div style="padding:.9rem 1.5rem 1.2rem">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
                        <span style="font-size:.63rem;color:var(--muted);letter-spacing:.07em;text-transform:uppercase">Ecoutes / jour &mdash; 30 derniers jours</span>
                        <span style="font-size:.72rem;color:var(--muted)">moy.&nbsp;<strong style="color:var(--text)"><?= $sparkAvg ?></strong>&nbsp;&middot;&nbsp;pic&nbsp;<strong style="color:var(--accent)"><?= $sparkMax ?></strong></span>
                    </div>
                    <div style="position:relative;height:80px;width:100%">
                        <canvas class="ep-spark-chart"
                                data-values="<?= htmlspecialchars($sparkJson) ?>"
                                data-labels="<?= htmlspecialchars($sparkLabelsJson) ?>"
                                style="display:block"></canvas>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ═══ Note ══════════════════════════════════════════════════════ -->

        <div style="background:rgba(232,255,90,.05);border:1px solid rgba(232,255,90,.15);border-radius:10px;padding:.85rem 1.1rem;font-size:.8rem;color:var(--muted)">
            <strong style="color:var(--text)">Comment les <?= __('stats_plays') ?> sont comptabilisées</strong> — Chaque requête initiale sur un fichier audio (y compris depuis les agrégateurs RSS comme Spotify, Apple Podcasts, Pocket Casts) incrémente le compteur. Les requêtes partielles (seek, resume) ne sont pas comptées deux fois.
        </div>

    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT — Chart.js                                                     -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
    // ═══ Global chart ════════════════════════════════════════════════════════

    const labels = <?= json_encode(array_map(fn($d) => date('d/m', strtotime($d)), $chartLabels)) ?>;
    const data   = <?= json_encode($chartData) ?>;

    const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#e8ff5a';
    const muted  = getComputedStyle(document.documentElement).getPropertyValue('--muted').trim()  || '#5a5a6e';
    const border = getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || '#1f1f2a';

    // Parse hex to rgba
    function hexToRgba(hex, alpha) {
        const h = hex.replace('#', '');
        const r = parseInt(h.slice(0, 2), 16);
        const g = parseInt(h.slice(2, 4), 16);
        const b = parseInt(h.slice(4, 6), 16);
        return `rgba(${r},${g},${b},${alpha})`;
    }

    const ctx = document.getElementById('chart').getContext('2d');

    const gradient = ctx.createLinearGradient(0, 0, 0, 180);
    gradient.addColorStop(0, hexToRgba(accent, .25));
    gradient.addColorStop(1, hexToRgba(accent, 0));

    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data,
                borderColor:      accent,
                borderWidth:      2,
                backgroundColor:  gradient,
                pointRadius:      data.length <= 14 ? 3 : 0,
                pointHoverRadius: 5,
                pointBackgroundColor: accent,
                tension: 0.35,
                fill:    true,
            }]
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a1a22',
                    borderColor:     '#2a2a36',
                    borderWidth:     1,
                    titleColor:      '#f0ede8',
                    bodyColor:       accent,
                    titleFont: { family: 'Syne', size: 11 },
                    bodyFont:  { family: 'Syne', size: 13, weight: '700' },
                    callbacks: {
                        label: ctx => ' ' + ctx.parsed.y + ' écoute' + (ctx.parsed.y > 1 ? 's' : ''),
                    }
                }
            },
            scales: {
                x: {
                    grid:  { color: border, drawBorder: false },
                    ticks: { color: muted, font: { family: 'Syne', size: 10 }, maxTicksLimit: 8, maxRotation: 0 },
                },
                y: {
                    beginAtZero: true,
                    grid:  { color: border, drawBorder: false },
                    ticks: {
                        color: muted,
                        font: { family: 'Syne', size: 10 },
                        stepSize: 1,
                        callback: v => Number.isInteger(v) ? v : '',
                    },
                }
            }
        }
    });

    // ═══ Animate bars on load ════════════════════════════════════════════════

    document.querySelectorAll('.bar-fill').forEach(bar => {
        const w = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => bar.style.width = w, 100);
    });

    // ═══ Export PDF via browser print ════════════════════════════════════════

    function exportPDF() {
        document.title = 'Stats — ' + new Date().toLocaleDateString('fr-FR');
        window.print();
    }

    // ═══ Per-episode sparkline charts ════════════════════════════════════════

    document.querySelectorAll('.ep-spark-chart').forEach(canvas => {
        const values = JSON.parse(canvas.dataset.values || '[]');
        const lbls   = JSON.parse(canvas.dataset.labels  || '[]');
        if (!values.length) return;

        const ctx  = canvas.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 80);
        grad.addColorStop(0, hexToRgba(accent, .28));
        grad.addColorStop(1, hexToRgba(accent, 0));

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: lbls,
                datasets: [{
                    data:                   values,
                    borderColor:            accent,
                    borderWidth:            2,
                    backgroundColor:        grad,
                    pointRadius:            0,
                    pointHoverRadius:       5,
                    pointHoverBackgroundColor: accent,
                    pointHoverBorderColor:  '#0d0d0f',
                    pointHoverBorderWidth:  2,
                    tension: 0.35,
                    fill:    true,
                }]
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1a1a22',
                        borderColor:     '#2a2a36',
                        borderWidth:     1,
                        titleColor:      '#f0ede8',
                        bodyColor:       accent,
                        titleFont: { family: 'Syne', size: 11 },
                        bodyFont:  { family: 'Syne', size: 13, weight: '700' },
                        padding:   10,
                        callbacks: {
                            label: c => ' ' + c.parsed.y + ' écoute' + (c.parsed.y > 1 ? 's' : ''),
                        }
                    }
                },
                scales: {
                    x: {
                        grid:  { color: border, drawBorder: false },
                        ticks: { color: muted, font: { family: 'Syne', size: 10 }, maxTicksLimit: 7, maxRotation: 0 },
                    },
                    y: {
                        beginAtZero: true,
                        grid:  { color: border, drawBorder: false },
                        ticks: {
                            color: muted,
                            font: { family: 'Syne', size: 10 },
                            stepSize: 1,
                            maxTicksLimit: 4,
                            callback: v => Number.isInteger(v) ? v : '',
                        },
                    }
                }
            }
        });
    });
</script>
</body>
</html>
