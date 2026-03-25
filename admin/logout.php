<?php
ob_start();
// =============================================================================
//  admin/logout.php — Admin interface logout
//
//  Destroys the session, logs the event via Auth::logout(),
//  then redirects to the login page.
//  No CSRF check: a forced logout is harmless.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->logout();

redirect(url('/admin/login.php'));
