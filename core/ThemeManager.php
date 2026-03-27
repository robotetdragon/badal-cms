<?php
// =============================================================================
//  core/ThemeManager.php — Visual theme management (colors + typography)
//
//  Themes are stored in the themes/ directory at the CMS root.
//  Each theme is a JSON file containing colors and typography.
//  The active theme is referenced in config/home.json (key "active_theme").
//
//  ThemeManager exposes two main outputs for views:
//    - toCssVars()       : CSS variables block to insert in <style>:root{}
//    - toGoogleFontsUrl(): Google Fonts import URL for the chosen fonts
//
//  Usage:
//      $theme = new ThemeManager($themesDir);
//      $theme->loadActive('sombre');   // loads the active theme
//      echo $theme->toCssVars();
// =============================================================================

class ThemeManager
{
    // =========================================================================
    //  Properties
    // =========================================================================

    /** Absolute path to the themes/ directory */
    private string $themesDir;

    /** Slug of the loaded theme */
    private string $activeSlug = '';

    /** Merged values (defaults + theme file) */
    private array $theme;

    // =========================================================================
    //  Default values (fallback if the theme file is incomplete)
    // =========================================================================

    private array $defaults = [
        'name'                => 'Sombre',
        'color_bg'            => '#0d0d0f',
        'color_surface'       => '#16161a',
        'color_border'        => '#232328',
        'color_accent'        => '#e8ff5a',
        'color_text'          => '#f0ede8',
        'color_muted'         => '#666666',
        'font_heading'        => 'Syne',
        'font_body'           => 'Instrument Serif',
        'font_weight_heading' => '800',
        'font_weight_body'    => '400',
    ];

    // =========================================================================
    //  Constructor
    // =========================================================================

    /**
     * Create a new ThemeManager instance.
     *
     * @param string $themesDir  Absolute path to the themes/ directory.
     */
    public function __construct(string $themesDir)
    {
        $this->themesDir = rtrim($themesDir, '/');
        $this->theme     = $this->defaults;
    }

    // =========================================================================
    //  Loading
    // =========================================================================

    /**
     * Loads a theme by its slug (file name without .json).
     * If the file doesn't exist, defaults apply.
     *
     * @param string $slug  Theme slug (e.g. "sombre").
     * @return void
     */
    public function loadActive(string $slug): void
    {
        $this->activeSlug = $slug;
        $file             = $this->themesDir . '/' . $slug . '.json';

        if (!file_exists($file)) {
            $this->theme = $this->defaults;
            return;
        }

        $raw         = file_get_contents($file);
        $stored      = json_decode($raw ?: '{}', true);
        $this->theme = array_merge($this->defaults, is_array($stored) ? $stored : []);
    }

    /**
     * Loads a theme directly from an array (useful for admin preview).
     *
     * @param array $data  Theme key-value pairs.
     * @return void
     */
    public function loadFromArray(array $data): void
    {
        $this->theme = array_merge($this->defaults, $data);
    }

    // =========================================================================
    //  Reading
    // =========================================================================

    /**
     * Returns a single theme value by key.
     *
     * @param  string $key      Theme key name.
     * @param  mixed  $default  Fallback value if key is missing.
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->theme[$key] ?? $default;
    }

    /**
     * Returns all merged theme values.
     *
     * @return array
     */
    public function getAll(): array
    {
        return $this->theme;
    }

    /**
     * Returns the slug of the currently loaded theme.
     *
     * @return string
     */
    public function getActiveSlug(): string
    {
        return $this->activeSlug;
    }

    /**
     * Returns the default theme values.
     *
     * @return array
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    // =========================================================================
    //  Available themes list
    // =========================================================================

    /**
     * Returns all available themes in the themes/ directory.
     * Each entry: ['slug' => '...', 'name' => '...', 'color_accent' => '...', ...]
     *
     * @return array
     */
    public function listThemes(): array
    {
        $themes = [];
        $files  = glob($this->themesDir . '/*.json');

        if (!$files) {
            return $themes;
        }

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $raw  = file_get_contents($file);
            $data = json_decode($raw ?: '{}', true);

            if (!is_array($data)) {
                continue;
            }

            $themes[$slug] = array_merge($this->defaults, $data, ['slug' => $slug]);
        }

        return $themes;
    }

    // =========================================================================
    //  Writing
    // =========================================================================

    /**
     * Saves a theme to themes/{slug}.json.
     * If the slug doesn't exist yet, a new theme is created.
     *
     * @param  string $slug  Theme slug.
     * @param  array  $data  Theme key-value pairs to save.
     * @return bool
     */
    public function save(string $slug, array $data): bool
    {
        // Keep only theme keys (colors + typography)
        $allowed = array_keys($this->defaults);
        $clean   = [];

        foreach ($allowed as $key) {
            if (isset($data[$key])) {
                $clean[$key] = $data[$key];
            }
        }

        $merged = array_merge($this->defaults, $clean);
        $file   = $this->themesDir . '/' . $slug . '.json';

        $ok = file_put_contents(
            $file,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) !== false;

        if ($ok) {
            $this->activeSlug = $slug;
            $this->theme      = $merged;
        }

        return $ok;
    }

    /**
     * Deletes a theme file. Refuses to delete 'sombre' (default theme).
     *
     * @param  string $slug  Theme slug to delete.
     * @return bool
     */
    public function delete(string $slug): bool
    {
        if ($slug === 'sombre') {
            return false;
        }

        $file = $this->themesDir . '/' . $slug . '.json';

        if (file_exists($file)) {
            return unlink($file);
        }

        return false;
    }

    // =========================================================================
    //  CSS / Google Fonts generation
    // =========================================================================

    /**
     * Generates a CSS custom-properties block for the current theme.
     *
     * @return string
     */
    public function toCssVars(): string
    {
        $fontMap      = self::fontMap();
        $headingStack = $fontMap[$this->get('font_heading')] ?? "'Syne', sans-serif";
        $bodyStack    = $fontMap[$this->get('font_body')]    ?? "'Instrument Serif', serif";

        $vars = [
            "--bg: {$this->get('color_bg')};",
            "--surface: {$this->get('color_surface')};",
            "--border: {$this->get('color_border')};",
            "--accent: {$this->get('color_accent')};",
            "--text: {$this->get('color_text')};",
            "--muted: {$this->get('color_muted')};",
            "--font-heading: {$headingStack};",
            "--font-body: {$bodyStack};",
            "--font-weight-heading: {$this->get('font_weight_heading')};",
            "--font-weight-body: {$this->get('font_weight_body')};",
        ];

        return implode("\n    ", $vars);
    }

    /**
     * Builds the Google Fonts import URL for the active fonts.
     *
     * @return string
     */
    public function toGoogleFontsUrl(): string
    {
        $needed   = array_unique([$this->get('font_heading'), $this->get('font_body')]);
        $families = [];

        foreach ($needed as $font) {
            $fontMap = [
                'Syne'              => 'Syne:wght@300;400;500;600;700;800',
                'Instrument Serif'  => 'Instrument+Serif:ital,wght@0,400;0,700;1,400',
                'Playfair Display'  => 'Playfair+Display:ital,wght@0,400;0,500;0,700;1,400',
                'DM Sans'           => 'DM+Sans:wght@300;400;500;600;700',
                'Space Grotesk'     => 'Space+Grotesk:wght@300;400;500;600;700',
                'Fraunces'          => 'Fraunces:ital,wght@0,300;0,400;0,700;1,400',
                'Cabinet Grotesk'   => 'Cabinet+Grotesk:wght@400;500;700;800',
                'Libre Baskerville' => 'Libre+Baskerville:ital,wght@0,400;0,700;1,400',
                'Montserrat'        => 'Montserrat:wght@300;400;500;600;700;800;900',
                'Open Sans'         => 'Open+Sans:wght@300;400;500;600;700;800',
                'Noto Sans'         => 'Noto+Sans:wght@300;400;500;600;700;800',
                'Inter'             => 'Inter:wght@300;400;500;600;700;800;900',
                'Poppins'           => 'Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400',
                'Lato'              => 'Lato:ital,wght@0,300;0,400;0,700;0,900;1,400',
            ];

            $families[] = $fontMap[$font] ?? (urlencode($font) . ':wght@300;400;500;600;700;800');
        }

        return 'https://fonts.googleapis.com/css2?family='
             . implode('&family=', $families)
             . '&display=swap';
    }

    // =========================================================================
    //  Static data
    // =========================================================================

    /**
     * Returns a map of font names to CSS font-family stacks.
     *
     * @return array<string, string>
     */
    public static function fontMap(): array
    {
        return [
            'Syne'              => "'Syne', sans-serif",
            'DM Sans'           => "'DM Sans', sans-serif",
            'Space Grotesk'     => "'Space Grotesk', sans-serif",
            'Cabinet Grotesk'   => "'Cabinet Grotesk', sans-serif",
            'Montserrat'        => "'Montserrat', sans-serif",
            'Open Sans'         => "'Open Sans', sans-serif",
            'Inter'             => "'Inter', sans-serif",
            'Poppins'           => "'Poppins', sans-serif",
            'Lato'              => "'Lato', sans-serif",
            'Instrument Serif'  => "'Instrument Serif', serif",
            'Playfair Display'  => "'Playfair Display', serif",
            'Fraunces'          => "'Fraunces', serif",
            'Libre Baskerville' => "'Libre Baskerville', serif",
            'Noto Sans'         => "'Noto Sans', sans-serif",
        ];
    }

    /**
     * Returns a flat list of available font names.
     *
     * @return array<int, string>
     */
    public static function fontList(): array
    {
        return array_keys(self::fontMap());
    }

    /**
     * Returns the supported social-network definitions.
     *
     * @return array<string, array{label: string, placeholder: string}>
     */
    public static function socialNetworks(): array
    {
        return [
            'website'    => ['label' => 'Site web',         'placeholder' => 'https://monsite.com'],
            'instagram'  => ['label' => 'Instagram',        'placeholder' => 'https://instagram.com/monpodcast'],
            'youtube'    => ['label' => 'YouTube',          'placeholder' => 'https://youtube.com/@monpodcast'],
            'spotify'    => ['label' => 'Spotify Podcasts', 'placeholder' => 'https://open.spotify.com/show/...'],
            'apple'      => ['label' => 'Apple Podcasts',   'placeholder' => 'https://podcasts.apple.com/...'],
            'linkedin'   => ['label' => 'LinkedIn',         'placeholder' => 'https://linkedin.com/in/...'],
            'tiktok'     => ['label' => 'TikTok',           'placeholder' => 'https://tiktok.com/@monpodcast'],
            'pocketcast' => ['label' => 'Pocket Casts',     'placeholder' => 'https://pca.st/...'],
            'email'      => ['label' => 'Email',            'placeholder' => 'contact@monpodcast.com'],
        ];
    }
}
