<?php

// =============================================================================
//  core/Lang.php — Interface language management
//
//  Translations live in lang/{code}.php (plain PHP arrays).
//  To add a language: create lang/xx.php and add it to SUPPORTED_LANGS.
// =============================================================================

class Lang
{
    // =========================================================================
    //  Constants
    // =========================================================================

    public const SUPPORTED_LANGS = ['fr', 'en', 'es', 'pt'];

    public const LABELS = [
        'fr' => '🇫🇷 Français',
        'en' => '🇬🇧 English',
        'es' => '🇪🇸 Español',
        'pt' => '🇧🇷 Português',
    ];

    public const DEFAULT_LANG = 'fr';

    // =========================================================================
    //  Properties
    // =========================================================================

    /** @var array Cache of already loaded translation arrays. */
    private static $cache = [];

    // =========================================================================
    //  Public methods
    // =========================================================================

    /**
     * Initialises the language from the session or query parameter.
     *
     * If a ?lang= parameter is present on an admin page, it is stored in the
     * session and the browser is redirected to the same URL without the param.
     *
     * @return void
     */
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $isAdmin    = (bool) preg_match('#/admin(/|$)#', $requestUri);

        if ($isAdmin && isset($_GET['lang'])) {
            $requested = strtolower(preg_replace('/[^a-z]/', '', $_GET['lang']));
            if (in_array($requested, self::SUPPORTED_LANGS, true)) {
                $_SESSION['lang'] = $requested;
            }

            $clean = strtok($_SERVER['REQUEST_URI'], '?');
            $query = $_GET;
            unset($query['lang']);
            $qs = $query ? '?' . http_build_query($query) : '';

            header('Location: ' . $clean . $qs);
            exit;
        }

        if (empty($_SESSION['lang'])) {
            $_SESSION['lang'] = self::DEFAULT_LANG;
        }
    }

    /**
     * Returns the current language code.
     *
     * @return string
     */
    public static function current(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $lang = $_SESSION['lang'] ?? self::DEFAULT_LANG;

        return in_array($lang, self::SUPPORTED_LANGS, true)
            ? $lang
            : self::DEFAULT_LANG;
    }

    /**
     * Returns a translated string for the given key, with optional placeholders.
     *
     * @param  string $key     Translation key
     * @param  array  $replace Associative array of placeholder => value pairs
     * @return string
     */
    public static function get(string $key, array $replace = []): string
    {
        $strings = self::load(self::current());
        $str     = $strings[$key] ?? $key;

        foreach ($replace as $placeholder => $value) {
            $str = str_replace($placeholder, (string) $value, $str);
        }

        return $str;
    }

    // =========================================================================
    //  Private helpers
    // =========================================================================

    /**
     * Loads and caches the translation array for a given language.
     *
     * @param  string $lang
     * @return array
     */
    private static function load(string $lang): array
    {
        if (isset(self::$cache[$lang])) {
            return self::$cache[$lang];
        }

        $file = ROOT_DIR . '/lang/' . $lang . '.php';

        if (file_exists($file)) {
            $data               = require $file;
            self::$cache[$lang] = is_array($data) ? $data : [];
        } else {
            self::$cache[$lang] = ($lang !== self::DEFAULT_LANG)
                ? self::load(self::DEFAULT_LANG)
                : [];
        }

        return self::$cache[$lang];
    }
}
