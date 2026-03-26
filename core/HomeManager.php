<?php
// =============================================================================
//  core/HomeManager.php — Home page configuration
//
//  Manages config/home.json: texts, social networks, logo, cover, layout.
//  Separated from ThemeManager which only handles colors and typography.
//
//  Usage:
//      $home = new HomeManager($configDir);
//      $h    = $home->getAll();
// =============================================================================

class HomeManager {

    /** Absolute path to the home.json file */
    private string $homeFile;

    /** Merged values (defaults + home.json) */
    private array $data;

    // =========================================================================
    //  Default values
    // =========================================================================

    private array $defaults = [

        // ── Active theme ──────────────────────────────────────────────────────
        'active_theme' => 'sombre',

        // ── Home page texts ─────────────────────────────────────────────────
        'home_tagline'     => '',
        'home_cta_label'   => "S'abonner via RSS",
        'home_cta_url'     => '/rss.xml',
        'home_footer_text' => '',

        // ── Social networks ─────────────────────────────────────────────────
        'social_website'    => '',
        'social_instagram'  => '',
        'social_youtube'    => '',
        'social_spotify'    => '',
        'social_apple'      => '',
        'social_linkedin'   => '',
        'social_tiktok'     => '',
        'social_pocketcast' => '',
        'social_email'      => '',

        // ── Logo and visuals ─────────────────────────────────────────────────
        'logo_type'   => 'svg',
        'logo_image'  => 'badal_logo.svg',
        'cover_image' => '',

        // ── Visible sections ───────────────────────────────────────────────
        'sections' => ['header', 'episodes', 'footer'],

        // ── Layout ────────────────────────────────────────────────────────
        'layout_width'          => '740',
        'header_align'          => 'center',
        'episodes_style'        => 'list',
        'show_episode_number'   => '1',
        'show_episode_date'     => '1',
        'show_episode_duration' => '1',
    ];

    public function __construct(string $configDir) {
        $this->homeFile = rtrim($configDir, '/') . '/home.json';
        $this->load();
    }

    // =========================================================================
    //  Reading
    // =========================================================================

    public function get(string $key, $default = null) {
        return $this->data[$key] ?? $default;
    }

    public function getAll(): array {
        return $this->data;
    }

    public function getDefaults(): array {
        return $this->defaults;
    }

    // =========================================================================
    //  Writing
    // =========================================================================

    /**
     * Saves home values to home.json.
     * Unknown keys are ignored.
     */
    public function save(array $newData): bool {
        $merged     = array_merge($this->defaults, $this->data, $newData);
        $this->data = $merged;

        return file_put_contents(
            $this->homeFile,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) !== false;
    }

    // =========================================================================
    //  Private
    // =========================================================================

    private function load(): void {
        if (!file_exists($this->homeFile)) {
            $this->data = $this->defaults;
            return;
        }

        $raw         = file_get_contents($this->homeFile);
        $stored      = json_decode($raw ?: '{}', true);
        $this->data  = array_merge($this->defaults, is_array($stored) ? $stored : []);
    }
}
