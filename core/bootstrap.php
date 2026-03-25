<?php
ob_start(); // Buffer all output — prevents "headers already sent"
// =============================================================================
//  core/bootstrap.php — Single entry point of the application
//
//  Included as the first line of every page (admin and public).
//  Responsibilities:
//    1. Define ROOT_DIR (absolute path to the project root)
//    2. Register the simplified PSR-0 autoloader for core/ classes
//    3. Load the configuration from config/config.php
//    4. Send appropriate HTTP security headers (admin vs public)
//    5. Configure PHP sessions securely
//    6. Expose global utility functions
// =============================================================================

// Absolute project root (one level above core/)
define('ROOT_DIR', dirname(__DIR__));

// -----------------------------------------------------------------------------
//  Application base path
//  If the site is installed in a subdirectory (e.g. /betapodcast/),
//  BASE_PATH equals '/betapodcast'. At the root, equals ''.
//  Automatically derived from base_url in config.php.
// -----------------------------------------------------------------------------
// We cannot use $config here (not yet loaded), so we define it
// after loading the config, via a second pass below.

// -----------------------------------------------------------------------------
//  Autoloader — automatically loads core/ classes on demand.
//  Convention: the class name corresponds exactly to the file name.
//  E.g.: new EpisodeParser(...) → core/EpisodeParser.php
// -----------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $file = ROOT_DIR . '/core/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// -----------------------------------------------------------------------------
//  Configuration — associative array returned by config/config.php.
//  Distributed read-only to all classes via their constructor.
// -----------------------------------------------------------------------------
// If the config doesn't exist, redirect to setup
if (!file_exists(ROOT_DIR . '/config/config.php')) {
    $setupPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    // Go up one level if we are in public/ or admin/
    if (preg_match('#/(public|admin)$#', $setupPath)) {
        $setupPath = dirname($setupPath);
    }
    header('Location: ' . $setupPath . '/setup.php');
    exit;
}

$config = require ROOT_DIR . '/config/config.php';

// -----------------------------------------------------------------------------
//  BASE — subdirectory prefix extracted from base_url.
//  E.g.: 'https://robotetdragon.com/betapodcast' → BASE = '/betapodcast'
//        'https://monpodcast.com'                → BASE = ''
// -----------------------------------------------------------------------------
$_parsed = parse_url($config['base_url'] ?? '');
define('BASE', rtrim($_parsed['path'] ?? '', '/'));
unset($_parsed);

// -----------------------------------------------------------------------------
//  Secure session  ← BEFORE any header output
//  session_set_cookie_params() must be called before session_start()
//  and before any header() — otherwise PHP generates warnings.
// -----------------------------------------------------------------------------
Security::configureSession();

// -----------------------------------------------------------------------------
//  Interface language  ← starts the session via session_start()
// -----------------------------------------------------------------------------
Lang::init();

// -----------------------------------------------------------------------------
//  HTTP security headers  ← AFTER session (headers sent here)
// -----------------------------------------------------------------------------
$_configDir = dirname($config['content_dir']) . '/config';
$_security  = new Security($_configDir);

if ((bool) preg_match('#/admin(/|$)#', $_SERVER['REQUEST_URI'] ?? '')) {
    $_security->sendAdminHeaders();
} else {
    $_security->sendPublicHeaders();
}

header_remove('X-Powered-By');

// =============================================================================
//  Global utility functions
//  Pure functions, without side effects, available in all views.
// =============================================================================

/**
 * Redirects to a URL and stops execution.
 * Wrapper around header('Location:') to never forget exit.
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Generates an absolute URL by prepending BASE if necessary.
 * Use for all internal links instead of hardcoded paths.
 *
 * url('/admin/')          → '/betapodcast/admin/'  (subdirectory)
 * url('/episodes/slug')   → '/betapodcast/episodes/slug'
 * url('/rss.xml')         → '/betapodcast/rss.xml'
 */
function url(string $path): string {
    return BASE . '/' . ltrim($path, '/');
}

/**
 * Escapes a value for HTML display.
 * Must be used systematically for any data displayed in a view.
 * Accepts mixed to avoid manual casts in templates.
 */
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates or returns the CSRF token for the current session.
 * The token is a 32-byte random nonce encoded in hexadecimal,
 * renewed with each new session.
 */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies that the POST CSRF token matches the session token.
 * Call at the beginning of every POST handler. Stops execution if invalid.
 * Uses hash_equals() to protect against timing attacks.
 */
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submitted = $_POST['csrf_token'] ?? '';
        if (!hash_equals(csrf_token(), $submitted)) {
            http_response_code(403);
            die('Token CSRF invalide.');
        }
    }
}

/**
 * Translates an interface key into the active language.
 * Global shortcut for Lang::get().
 *
 * Examples:
 *   <?= __('save') ?>                        → "Enregistrer" / "Save"
 *   <?= __('stats_period', ['%days%' => 30]) ?>  → "Listens (30 days)"
 *
 * @param  string $key     Key defined in Lang::strings()
 * @param  array  $replace Optional substitutions in the string
 * @return string          Translated string, HTML-safe not applied (use e() if needed)
 */
function __(string $key, array $replace = []): string {
    return Lang::get($key, $replace);
}
