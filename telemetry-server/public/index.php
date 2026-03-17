<?php
// =============================================================================
//  index.php — Dashboard de télémétrie Badal
//
//  Affiche les statistiques des installations Badal CMS :
//  - Nombre total d'installations
//  - Répartition par version, langue
//  - Activité récente
//  - Liste détaillée des installations
// =============================================================================

$dataFile = __DIR__ . '/../data/installations.json';
$installations = [];

if (file_exists($dataFile)) {
    $installations = json_decode(file_get_contents($dataFile), true) ?: [];
}

$total = count($installations);

// --- Calcul des statistiques ---

$now = time();
$active7d  = 0;  // vu dans les 7 derniers jours
$active30d = 0;  // vu dans les 30 derniers jours
$inactive  = 0;  // pas vu depuis 30+ jours

$byVersion  = [];
$byLang     = [];
$totalEpisodes = 0;

foreach ($installations as $id => $inst) {
    $lastSeen = strtotime($inst['last_seen'] ?? '2000-01-01');
    $age = $now - $lastSeen;

    if ($age < 7 * 86400) $active7d++;
    if ($age < 30 * 86400) $active30d++;
    else $inactive++;

    $v = $inst['version'] ?? 'unknown';
    $byVersion[$v] = ($byVersion[$v] ?? 0) + 1;

    $l = $inst['lang'] ?? 'unknown';
    $byLang[$l] = ($byLang[$l] ?? 0) + 1;

    $totalEpisodes += $inst['episodes'] ?? 0;
}

arsort($byVersion);
arsort($byLang);

// Trier les installations par last_seen (les plus récentes en premier)
uasort($installations, function ($a, $b) {
    return strtotime($b['last_seen'] ?? '2000-01-01') - strtotime($a['last_seen'] ?? '2000-01-01');
});

// Helper : statut d'activité
function activityStatus(string $lastSeen): array {
    $age = time() - strtotime($lastSeen);
    if ($age < 7 * 86400)  return ['Actif',   'dot-active'];
    if ($age < 30 * 86400) return ['Récent',   'dot-stale'];
    return ['Inactif', 'dot-inactive'];
}

// Helper : temps relatif
function timeAgo(string $date): string {
    $diff = time() - strtotime($date);
    if ($diff < 60)         return 'à l\'instant';
    if ($diff < 3600)       return floor($diff / 60) . ' min';
    if ($diff < 86400)      return floor($diff / 3600) . ' h';
    if ($diff < 30 * 86400) return floor($diff / 86400) . ' j';
    return floor($diff / (30 * 86400)) . ' mois';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Badal Telemetry Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="header">
    <h1><span>Badal</span> Telemetry</h1>
    <div class="meta">Dernière mise à jour : <?= date('d/m/Y H:i') ?></div>
</div>

<div class="container">

    <!-- Cartes statistiques principales -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Installations totales</div>
            <div class="value"><?= $total ?></div>
            <div class="sub"><?= $active7d ?> actives cette semaine</div>
        </div>
        <div class="stat-card">
            <div class="label">Actives (30 j)</div>
            <div class="value"><?= $active30d ?></div>
            <div class="sub"><?= $inactive ?> inactive<?= $inactive > 1 ? 's' : '' ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Episodes publiés</div>
            <div class="value"><?= number_format($totalEpisodes, 0, ',', ' ') ?></div>
            <div class="sub">sur toutes les instances</div>
        </div>
        <div class="stat-card">
            <div class="label">Versions actives</div>
            <div class="value"><?= count($byVersion) ?></div>
            <div class="sub"><?= $total > 0 ? array_key_first($byVersion) . ' la plus utilisée' : '-' ?></div>
        </div>
    </div>

    <?php if ($total === 0): ?>
    <div class="section empty">
        <div class="icon">📡</div>
        <p>Aucune installation n'a encore envoyé de ping.<br>
        Les données apparaîtront ici quand des instances Badal avec la télémétrie activée enverront leur premier ping.</p>
    </div>
    <?php else: ?>

    <!-- Répartition par version -->
    <div class="section">
        <h2>Répartition par version</h2>
        <div class="bar-chart">
            <?php foreach ($byVersion as $ver => $count): ?>
            <div class="bar-row">
                <div class="bar-label"><?= htmlspecialchars($ver) ?></div>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?= round($count / $total * 100) ?>%"></div>
                </div>
                <div class="bar-value"><?= $count ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Répartition par langue -->
    <div class="section">
        <h2>Répartition par langue</h2>
        <div class="bar-chart">
            <?php foreach ($byLang as $lang => $count): ?>
            <div class="bar-row">
                <div class="bar-label"><?= htmlspecialchars($lang) ?></div>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?= round($count / $total * 100) ?>%"></div>
                </div>
                <div class="bar-value"><?= $count ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Liste des installations -->
    <div class="section">
        <h2>Installations (<?= $total ?>)</h2>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Statut</th>
                    <th>ID</th>
                    <th>Version</th>
                    <th>Episodes</th>
                    <th>Langue</th>
                    <th>Premier ping</th>
                    <th>Dernier ping</th>
                    <th>Pings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($installations as $id => $inst):
                    [$statusLabel, $dotClass] = activityStatus($inst['last_seen']);
                ?>
                <tr>
                    <td><span class="dot <?= $dotClass ?>"></span><?= $statusLabel ?></td>
                    <td><code style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars(substr($id, 0, 12)) ?>…</code></td>
                    <td><span class="badge badge-accent"><?= htmlspecialchars($inst['version']) ?></span></td>
                    <td><?= $inst['episodes'] ?></td>
                    <td><?= htmlspecialchars($inst['lang']) ?></td>
                    <td style="color:var(--muted);font-size:.78rem"><?= date('d/m/Y', strtotime($inst['first_seen'])) ?></td>
                    <td style="font-size:.78rem"><?= timeAgo($inst['last_seen']) ?></td>
                    <td><?= $inst['ping_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php endif; ?>

</div>

</body>
</html>
