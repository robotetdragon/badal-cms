<?php
/**
 * PHPUnit bootstrap — initializes constants and the autoloader for tests.
 */

// Output buffering — avoids "headers already sent" for session_start() in tests
ob_start();

// Constants normally defined by core/bootstrap.php
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__));
}
if (!defined('BASE')) {
    define('BASE', '');
}

// PSR-0 autoloader for classes in core/
spl_autoload_register(function (string $class) {
    $file = ROOT_DIR . '/core/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Start the session for tests that need it (LangTest, etc.)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Temporary directory for test fixtures
define('TEST_FIXTURES_DIR', __DIR__ . '/fixtures');
