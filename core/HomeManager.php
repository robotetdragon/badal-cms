<?php
// =============================================================================
//  core/HomeManager.php — Configuration de la page d'accueil
//
//  Gère config/home.json : textes, réseaux sociaux, logo, cover, layout.
//  Séparé du ThemeManager qui ne gère que les couleurs et la typographie.
//
//  Usage :
//      $home = new HomeManager($configDir);
//      $h    = $home->getAll();
// =============================================================================

class HomeManager {

    /** Chemin absolu du fichier home.json */
    private string $homeFile;

    /** Valeurs fusionnées (defaults + home.json) */
    private array $data;

    // =========================================================================
    //  Valeurs par défaut
    // =========================================================================

    private array $defaults = [

        // ── Thème actif ──────────────────────────────────────────────────────
        'active_theme' => 'sombre',

        // ── Textes de la page d'accueil ─────────────────────────────────────
        'home_tagline'     => '',
        'home_cta_label'   => "S'abonner via RSS",
        'home_cta_url'     => '/rss.xml',
        'home_footer_text' => '',

        // ── Réseaux sociaux ─────────────────────────────────────────────────
        'social_website'    => '',
        'social_instagram'  => '',
        'social_youtube'    => '',
        'social_spotify'    => '',
        'social_apple'      => '',
        'social_linkedin'   => '',
        'social_tiktok'     => '',
        'social_pocketcast' => '',

        // ── Logo et visuels ─────────────────────────────────────────────────
        'logo_type'   => 'svg',
        'logo_image'  => 'badal_logo.svg',
        'cover_image' => '',

        // ── Sections visibles ───────────────────────────────────────────────
        'sections' => ['header', 'episodes', 'footer'],

        // ── Mise en page ────────────────────────────────────────────────────
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
    //  Lecture
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
    //  Écriture
    // =========================================================================

    /**
     * Sauvegarde les valeurs home dans home.json.
     * Les clés inconnues sont ignorées.
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
    //  Privé
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
