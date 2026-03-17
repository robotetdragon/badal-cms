<?php
// =============================================================================
//  core/ThemeManager.php — Personnalisation visuelle de la page d'accueil
//
//  Les préférences visuelles sont stockées dans config/theme.json.
//  Ce fichier est créé à la première sauvegarde depuis l'interface admin
//  (Apparence). En son absence, les valeurs par défaut s'appliquent.
//
//  Le ThemeManager expose deux sorties principales pour les vues :
//    - toCssVars()       : bloc de variables CSS à insérer dans <style>:root{}
//    - toGoogleFontsUrl(): URL d'import Google Fonts pour les polices choisies
//
//  Usage :
//      $theme = new ThemeManager($configDir);
//      $t     = $theme->getAll();
//      echo $theme->toCssVars();
// =============================================================================

class ThemeManager {

    /** Chemin absolu du fichier de thème */
    private string $themeFile;

    /** Valeurs fusionnées (defaults + theme.json) */
    private array $theme;

    // =========================================================================
    //  Valeurs par défaut
    //  Documentées ici pour servir de référence et de fallback complet.
    // =========================================================================

    private array $defaults = [

        // ── Couleurs ─────────────────────────────────────────────────────────
        'color_bg'      => '#0d0d0f',   // fond de page
        'color_surface' => '#16161a',   // fond des cartes
        'color_border'  => '#232328',   // bordures
        'color_accent'  => '#e8ff5a',   // couleur d'accentuation (jaune-vert)
        'color_text'    => '#f0ede8',   // texte principal
        'color_muted'   => '#666666',   // texte secondaire / labels

        // ── Typographie ───────────────────────────────────────────────────────
        // Noms correspondant aux clés de fontMap() ci-dessous
        'font_heading'        => 'Syne',
        'font_body'           => 'Instrument Serif',
        'font_weight_heading'  => '800',
        'font_weight_body'     => '400',

        // ── Textes de la page d'accueil ───────────────────────────────────────
        'home_tagline'     => '',                 // sous-titre sous le nom du podcast
        'home_cta_label'   => "S'abonner via RSS",
        'home_cta_url'     => '/rss.xml',
        'home_footer_text' => '',                 // texte libre en pied de page

        // ── Réseaux sociaux ───────────────────────────────────────────────────
        // URL complète ou vide pour masquer le lien
        'social_instagram' => '',
        'social_youtube'   => '',
        'social_spotify'   => '',
        'social_apple'     => '',

        // ── Logo et visuels ───────────────────────────────────────────────────
        'logo_type'   => 'icon',    // 'icon' = icône SVG générique | 'image' = logo uploadé
        'logo_image'  => '',        // nom de fichier dans audio/ (si logo_type = 'image')
        'cover_image' => '',        // image de fond du hero (nom de fichier dans audio/)

        // ── Sections visibles (ordre respecté) ───────────────────────────────
        'sections' => ['header', 'episodes', 'footer'],

        // ── Mise en page ──────────────────────────────────────────────────────
        'layout_width'          => '740',     // largeur max du conteneur en px
        'header_align'          => 'center',  // 'center' | 'left'
        'episodes_style'        => 'list',    // 'list' | 'grid'
        'show_episode_number'   => '1',       // '1' = affiché | '0' = masqué
        'show_episode_date'     => '1',
        'show_episode_duration' => '1',
    ];

    public function __construct(string $configDir) {
        $this->themeFile = rtrim($configDir, '/') . '/theme.json';
        $this->load();
    }

    // =========================================================================
    //  Lecture
    // =========================================================================

    /**
     * Retourne la valeur d'une clé du thème, ou $default si absente.
     * Toujours préférer getAll() dans les templates pour éviter les appels multiples.
     */
    public function get(string $key, $default = null) {
        return $this->theme[$key] ?? $default;
    }

    /** Retourne toutes les valeurs du thème (defaults + overrides de theme.json). */
    public function getAll(): array {
        return $this->theme;
    }

    /** Retourne uniquement les valeurs par défaut (utile pour reset ou documentation). */
    public function getDefaults(): array {
        return $this->defaults;
    }

    // =========================================================================
    //  Écriture
    // =========================================================================

    /**
     * Sauvegarde les valeurs du thème dans theme.json.
     *
     * Les clés inconnues de $data sont ignorées (merge avec defaults en priorité).
     * Les clés manquantes dans $data conservent leur valeur courante.
     *
     * @param  array $data  Paires clé/valeur à persister
     * @return bool         true si l'écriture a réussi
     */
    public function save(array $data): bool {
        $merged      = array_merge($this->defaults, $this->theme, $data);
        $this->theme = $merged;

        return file_put_contents(
            $this->themeFile,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) !== false;
    }

    // =========================================================================
    //  Génération CSS / Google Fonts
    // =========================================================================

    /**
     * Retourne un bloc de variables CSS custom à injecter dans :root { … }.
     *
     * Les polices sont converties en font-stack complet via fontMap()
     * pour garantir le fallback même si Google Fonts est bloqué.
     *
     * Exemple de sortie :
     *   --bg: #0d0d0f;
     *   --accent: #e8ff5a;
     *   --font-heading: 'Syne', sans-serif;
     *   …
     */
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
            "--layout-width: {$this->get('layout_width')}px;",
        ];

        return implode("\n    ", $vars);
    }

    /**
     * Retourne l'URL Google Fonts pour les deux polices configurées.
     * Les doublons sont éliminés (si heading = body, une seule requête).
     *
     * Exemple :
     *   https://fonts.googleapis.com/css2?family=Syne:wght@400;700&family=…&display=swap
     */
    public function toGoogleFontsUrl(): string {
        // Dédupliquer les polices (heading et body peuvent être identiques)
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
    //  Données statiques (listes de référence)
    // =========================================================================

    /**
     * Correspondance police → font-stack CSS complet avec fallback.
     * Utilisé par toCssVars() et par la vue Apparence pour les aperçus.
     *
     * @return array<string, string>
     */
    public static function fontMap(): array {
        return [
            // Sans-serif
            'Syne'              => "'Syne', sans-serif",
            'DM Sans'           => "'DM Sans', sans-serif",
            'Space Grotesk'     => "'Space Grotesk', sans-serif",
            'Cabinet Grotesk'   => "'Cabinet Grotesk', sans-serif",
            'Montserrat'        => "'Montserrat', sans-serif",
            'Open Sans'         => "'Open Sans', sans-serif",
            'Inter'             => "'Inter', sans-serif",
            'Poppins'           => "'Poppins', sans-serif",
            'Lato'              => "'Lato', sans-serif",
            // Serif
            'Instrument Serif'  => "'Instrument Serif', serif",
            'Playfair Display'  => "'Playfair Display', serif",
            'Fraunces'          => "'Fraunces', serif",
            'Libre Baskerville' => "'Libre Baskerville', serif",
            // Multi-script
            'Noto Sans'         => "'Noto Sans', sans-serif",
        ];
    }

    /** Retourne la liste des noms de polices disponibles (pour les <select>). */
    public static function fontList(): array {
        return array_keys(self::fontMap());
    }

    /**
     * Définition des réseaux sociaux supportés.
     * Chaque entrée contient le label affiché et un placeholder d'exemple.
     *
     * @return array<string, array{label: string, placeholder: string}>
     */
    public static function socialNetworks(): array {
        return [
            'instagram' => ['label' => 'Instagram',        'placeholder' => 'https://instagram.com/monpodcast'],
            'youtube'   => ['label' => 'YouTube',          'placeholder' => 'https://youtube.com/@monpodcast'],
            'spotify'   => ['label' => 'Spotify Podcasts', 'placeholder' => 'https://open.spotify.com/show/...'],
            'apple'     => ['label' => 'Apple Podcasts',   'placeholder' => 'https://podcasts.apple.com/...'],
        ];
    }

    // =========================================================================
    //  Privé
    // =========================================================================

    /**
     * Charge theme.json et fusionne avec les defaults.
     * En l'absence du fichier, les defaults sont utilisés directement.
     */
    private function load(): void {
        if (!file_exists($this->themeFile)) {
            $this->theme = $this->defaults;
            return;
        }

        $raw         = file_get_contents($this->themeFile);
        $stored      = json_decode($raw ?: '{}', true);
        $this->theme = array_merge($this->defaults, is_array($stored) ? $stored : []);
    }
}
