#!/usr/bin/env php
<?php
// =============================================================================
//  tools/check_permissions.php — Audit des permissions fichiers
//
//  Outil CLI qui vérifie les permissions des fichiers et dossiers sensibles,
//  et peut les corriger automatiquement avec l'option --fix.
//
//  Usage :
//      php tools/check_permissions.php           → audit seul (lecture)
//      php tools/check_permissions.php --fix     → audit + correction auto
//
//  Ce script ne doit pas être accessible depuis le web.
//  Il est protégé par une vérification SAPI en première ligne.
// =============================================================================

// Bloquer l'exécution depuis le web
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('ROOT_DIR', dirname(__DIR__));

// Charger uniquement Security (pas besoin du reste du bootstrap)
require_once ROOT_DIR . '/core/Security.php';

// Codes ANSI pour la lisibilité du terminal
$red    = "\033[0;31m";
$green  = "\033[0;32m";
$yellow = "\033[1;33m";
$reset  = "\033[0m";
$bold   = "\033[1m";

$fix    = in_array('--fix', $argv ?? [], true);
$checks = Security::auditPermissions(ROOT_DIR);

// ── Affichage du rapport ──────────────────────────────────────────────────────

echo "\n{$bold}Badal — Audit des permissions{$reset}\n";
echo str_repeat('─', 60) . "\n\n";

$allOk = true;

foreach ($checks as $check) {
    $icon  = $check['ok'] ? "{$green}✓{$reset}" : "{$red}✗{$reset}";
    $curr  = $check['ok']
           ? "{$green}{$check['current']}{$reset}"
           : "{$red}{$check['current']}{$reset}";

    printf("  %s  %-40s  %s", $icon, $check['path'], $curr);

    if (!$check['ok']) {
        $allOk = false;
        printf("  {$yellow}→ %s recommandé{$reset}", $check['recommended']);

        // ── Mode --fix ────────────────────────────────────────────────────────
        if ($fix) {
            // Résoudre le chemin absolu depuis le label relatif
            $pathMap = [
                'config/'           => ROOT_DIR . '/config',
                'config/config.php' => ROOT_DIR . '/config/config.php',
                'config/auth.log'   => ROOT_DIR . '/config/auth.log',
                'content/'          => ROOT_DIR . '/content',
                'audio/'            => ROOT_DIR . '/audio',
                '.htaccess'         => ROOT_DIR . '/.htaccess',
            ];

            $absPath = $pathMap[$check['path']] ?? null;

            if ($absPath && file_exists($absPath)) {
                $mode = octdec($check['recommended']);
                if (chmod($absPath, $mode)) {
                    echo "  {$green}[CORRIGÉ]{$reset}";
                } else {
                    echo "  {$red}[ÉCHEC chmod]{$reset}";
                }
            }
        }
    }

    echo "\n";
}

// ── Résumé final ─────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 60) . "\n";

if ($allOk) {
    echo "{$green}{$bold}✓ Toutes les permissions sont correctes.{$reset}\n\n";
    exit(0);
}

// Afficher les commandes manuelles si --fix n'a pas été passé
if (!$fix) {
    echo "{$yellow}Corrigez automatiquement avec :{$reset}\n";
    echo "  php tools/check_permissions.php --fix\n\n";
    echo "{$yellow}Ou manuellement :{$reset}\n";
    foreach ($checks as $check) {
        if (!$check['ok']) {
            echo "  chmod {$check['recommended']} " . ROOT_DIR . "/{$check['path']}\n";
        }
    }
    echo "\n";
}

// Code de sortie non-zéro → utilisable dans des scripts CI/CD
exit(1);
