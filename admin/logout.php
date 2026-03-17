<?php
ob_start();
// =============================================================================
//  admin/logout.php — Déconnexion de l'interface admin
//
//  Détruit la session, logue l'événement via Auth::logout(),
//  puis redirige vers la page de login.
//  Pas de vérification CSRF : une déconnexion forcée est sans danger.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->logout();

redirect(url('/admin/login.php'));
