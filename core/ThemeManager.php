<?php
// =============================================================================
//  core/ThemeManager.php — Gestion des thèmes visuels (couleurs + typographie)
//
//  Les thèmes sont stockés dans le dossier themes/ à la racine du CMS.
//  Chaque thème est un fichier JSON contenant couleurs et typographie.
//  Le thème actif est référencé dans config/home.json (clé "active_theme").
//
//  Le ThemeManager expose deux sorties principales pour les vues :
//    - toCssVars()       : bloc de variables CSS à insérer dans <style>:root{}
//    - toGoogleFontsUrl(): URL d'import Google Fonts pour les polices choisies
//
//  Usage :
//      $theme = new ThemeManager($themesDir);
//      $theme->loadActive('sombre');   // charge le thème actif
//      echo $theme->toCssVars();
// =============================================================================

class ThemeManager {

    /** Chemin absolu du dossier themes/ */
    private string $themesDir;

    /** Slug du thème chargé */
    private string $activeSlug = '';

    /** Valeurs fusionnées (defaults + theme file) */
    private array $theme;

    // =========================================================================
    //  Valeurs par défaut (fallback si le fichier thème est incomplet)
    // =========================================================================

    private array $defaults = [
        'name'          => 'Sombre',
        'color_bg'      => '#0d0d0f',
        'color_surface' => '#16161a',
        'color_border'  => '#232328',
        'color_accent'  => '#e8ff5a',
        'color_text'    => '#f0ede8',
        'color_muted'   => '#666666',
        'font_heading'        => 'Syne',
        'font_body'           => 'Instrument Serif',
        'font_weight_heading' => '800',
        'font_weight_body'    => '400',
    ];

    public function __construct(string $themesDir) {
        $this->themesDir = rtrim($themesDir, '/');
        $this->theme     = $this->defaults;
    }

    // =========================================================================
    //  Chargement
    // =========================================================================

    /**
     * Charge un thème par son slug (nom de fichier sans .json).
     * Si le fichier n'existe pas, les defaults s'appliquent.
     */
    public function loadActive(string $slug): void {
        $this->activeSlug = $slug;
        $file = $this->themesDir . '/' . $slug . '.json';

        if (!file_exists($file)) {
            $this->theme = $this->defaults;
            return;
        }

        $raw    = file_get_contents($file);
        $stored = json_decode($raw ?: '{}', true);
        $this->theme = array_merge($this->defaults, is_array($stored) ? $stored : []);
    }

    /**
     * Charge un thème directement depuis un tableau (utile pour la preview admin).
     */
    public function loadFromArray(array $data): void {
        $this->theme = array_merge($this->defaults, $data);
    }

    // =========================================================================
    //  Lecture
    // =========================================================================

    public function get(string $key, $default = null) {
        return $this->theme[$key] ?? $default;
    }

    public function getAll(): array {
        return $this->theme;
    }

    public function getActiveSlug(): string {
        return $this->activeSlug;
    }

    public function getDefaults(): array {
        return $this->defaults;
    }

    // =========================================================================
    //  Liste des thèmes disponibles
    // =========================================================================

    /**
     * Retourne tous les thèmes disponibles dans le dossier themes/.
     * Chaque entrée : ['slug' => '...', 'name' => '...', 'color_accent' => '...', ...]
     */
    public function listThemes(): array {
        $themes = [];
        $files  = glob($this->themesDir . '/*.json');

        if (!$files) return $themes;

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $raw  = file_get_contents($file);
            $data = json_decode($raw ?: '{}', true);
            if (!is_array($data)) continue;

            $themes[$slug] = array_merge($this->defaults, $data, ['slug' => $slug]);
        }

        return $themes;
    }

    // =========================================================================
    //  Écriture
    // =========================================================================

    /**
     * Sauvegarde un thème dans themes/{slug}.json.
     * Si le slug n'existe pas encore, un nouveau thème est créé.
     */
    public function save(string $slug, array $data): bool {
        // Ne garder que les clés de thème (couleurs + typo)
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
     * Supprime un fichier thème. Refuse de supprimer 'sombre' (thème par défaut).
     */
    public function delete(string $slug): bool {
        if ($slug === 'sombre') return false;

        $file = $this->themesDir . '/' . $slug . '.json';
        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }

    // =========================================================================
    //  Génération CSS / Google Fonts
    // =========================================================================

    public function toCssVars(): string {
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

    public function toGoogleFontsUrl(): string {
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
    //  Données statiques
    // =========================================================================

    public static function fontMap(): array {
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

    public static function fontList(): array {
        return array_keys(self::fontMap());
    }

    public static function socialNetworks(): array {
        return [
            'website'    => ['label' => 'Site web',          'placeholder' => 'https://monsite.com'],
            'instagram'  => ['label' => 'Instagram',         'placeholder' => 'https://instagram.com/monpodcast'],
            'youtube'    => ['label' => 'YouTube',           'placeholder' => 'https://youtube.com/@monpodcast'],
            'spotify'    => ['label' => 'Spotify Podcasts',  'placeholder' => 'https://open.spotify.com/show/...'],
            'apple'      => ['label' => 'Apple Podcasts',    'placeholder' => 'https://podcasts.apple.com/...'],
            'linkedin'   => ['label' => 'LinkedIn',          'placeholder' => 'https://linkedin.com/in/...'],
            'tiktok'     => ['label' => 'TikTok',            'placeholder' => 'https://tiktok.com/@monpodcast'],
            'pocketcast' => ['label' => 'Pocket Casts',      'placeholder' => 'https://pca.st/...'],
        ];
    }
}
