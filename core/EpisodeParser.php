<?php
// =============================================================================
//  core/EpisodeParser.php — Lecture et écriture des épisodes Markdown
//
//  Chaque épisode est un fichier .md dans content/episodes/ avec un frontmatter
//  YAML minimaliste (implémenté sans dépendance externe) suivi du corps Markdown.
//
//  Format d'un fichier épisode :
//  ┌─────────────────────────────────┐
//  │ ---                             │
//  │ title: Mon épisode              │
//  │ date: 2026-03-15                │
//  │ episode: 12                     │
//  │ duration: 45:30                 │
//  │ description: Résumé court       │
//  │ audio: mon-episode.mp3          │
//  │ ---                             │
//  │                                 │
//  │ ## Show notes en Markdown       │
//  └─────────────────────────────────┘
//
//  Le slug est dérivé du nom de fichier (sans l'extension .md).
// =============================================================================

class EpisodeParser {

    /** Chemin absolu vers content/ (sans slash final) */
    private string $contentDir;

    public function __construct(string $contentDir) {
        $this->contentDir = rtrim($contentDir, '/');
    }

    // =========================================================================
    //  API publique — lecture
    // =========================================================================

    /**
     * Retourne tous les épisodes triés du plus récent au plus ancien.
     * Les fichiers illisibles ou mal formés sont silencieusement ignorés.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array {
        $files = glob($this->contentDir . '/episodes/*.md') ?: [];

        $episodes = array_filter(
            array_map([$this, 'parseFile'], $files)
        );

        // Tri anti-chronologique par date de publication
        usort($episodes, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        return array_values($episodes);
    }

    /**
     * Retourne un épisode par son slug, ou null si introuvable.
     *
     * @param  string      $slug  Identifiant URL (ex: "mon-episode-12")
     * @return array|null         Tableau de métadonnées + body + content_html
     */
    public function getBySlug(string $slug): ?array {
        $file = $this->contentDir . '/episodes/' . $slug . '.md';
        return file_exists($file) ? $this->parseFile($file) : null;
    }

    /**
     * Parse un fichier .md et retourne ses données structurées.
     * Ajoute automatiquement :
     *   - 'slug'         : nom du fichier sans extension
     *   - 'content_html' : corps Markdown converti en HTML
     *
     * @return array|null  null si le fichier est illisible
     */
    public function parseFile(string $filepath): ?array {
        $raw = file_get_contents($filepath);
        if ($raw === false) {
            return null;
        }

        $data           = $this->parseFrontmatter($raw);
        $data['slug']   = basename($filepath, '.md');
        $data['content_html'] = $this->markdownToHtml($data['body'] ?? '');

        return $data;
    }

    // =========================================================================
    //  API publique — écriture
    // =========================================================================

    /**
     * Sauvegarde (crée ou écrase) un épisode sur le disque.
     *
     * Le contenu est sérialisé avec frontmatter YAML puis corps Markdown.
     * Le slug est sanitisé avant écriture (caractères autorisés : a-z 0-9 -).
     *
     * @param  string $slug  Identifiant unique de l'épisode
     * @param  array  $meta  Paires clé/valeur pour le frontmatter YAML
     * @param  string $body  Corps Markdown de l'épisode (show notes)
     * @return bool          true si l'écriture a réussi
     */
    public function save(string $slug, array $meta, string $body): bool {
        $slug     = $this->sanitizeSlug($slug);
        $filepath = $this->contentDir . '/episodes/' . $slug . '.md';

        // Sérialisation : frontmatter YAML + saut de ligne + corps
        $content = "---\n";
        foreach ($meta as $key => $value) {
            $content .= $key . ': ' . $this->yamlValue((string) $value) . "\n";
        }
        $content .= "---\n\n" . $body;

        return file_put_contents($filepath, $content, LOCK_EX) !== false;
    }

    /**
     * Supprime le fichier Markdown d'un épisode.
     * Ne supprime pas le fichier audio ni la transcription associée.
     *
     * @return bool  true si la suppression a réussi (ou si le fichier n'existait pas)
     */
    public function delete(string $slug): bool {
        $filepath = $this->contentDir . '/episodes/' . $slug . '.md';
        return !file_exists($filepath) || unlink($filepath);
    }

    // =========================================================================
    //  Parsing du frontmatter YAML
    // =========================================================================

    /**
     * Sépare le frontmatter YAML du corps Markdown.
     *
     * Implémentation minimaliste (sans dépendance) qui gère :
     *   - Les valeurs simples   : key: value
     *   - Les valeurs citées    : key: "value with: colons"
     *   - L'absence de frontmatter (retourne le contenu brut dans 'body')
     *
     * @return array<string, string>  Métadonnées + clé 'body' pour le Markdown
     */
    private function parseFrontmatter(string $content): array {
        $result = ['body' => $content];

        // Le frontmatter doit commencer dès le premier caractère du fichier
        if (!(strpos($content, '---') === 0)) {
            return $result;
        }

        // Découpage sur les séparateurs "---" en début de ligne
        $parts = preg_split('/^---\s*$/m', $content, 3);
        if (count($parts) < 3) {
            return $result;
        }

        $result['body'] = trim($parts[2]);

        // Parse ligne par ligne : "clé: valeur"
        foreach (explode("\n", $parts[1]) as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', trim($line), $m)) {
                $result[$m[1]] = trim($m[2], '"\'');
            }
        }

        return $result;
    }

    // =========================================================================
    //  Convertisseur Markdown → HTML (léger, sans dépendance)
    // =========================================================================

    /**
     * Convertit un sous-ensemble de Markdown en HTML.
     *
     * Éléments supportés :
     *   - Titres      : # ## ###
     *   - Gras        : **texte**
     *   - Italique    : *texte*
     *   - Liens       : [texte](url)
     *   - Paragraphes : blocs séparés par une ligne vide
     *   - Sauts de ligne : \n dans un paragraphe → <br>
     *
     * Le Markdown est d'abord échappé HTML pour neutraliser tout XSS,
     * puis les balises autorisées sont injectées via regex.
     */
    private function markdownToHtml(string $markdown): string {
        // Étape 1 : échapper pour neutraliser tout HTML brut dans la source
        $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        // Étape 2 : titres (du plus précis au moins précis, pour éviter les faux positifs)
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $html);

        // Étape 3 : emphase inline
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/',     '<em>$1</em>',         $html);

        // Étape 4 : liens
        $html = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $html);

        // Étape 5 : paragraphes (blocs séparés par ≥2 sauts de ligne)
        $blocks = preg_split('/\n{2,}/', $html);
        $html   = implode('', array_map(function (string $block): string {
            $block = trim($block);
            if ($block === '') {
                return '';
            }
            // Ne pas envelopper les blocs qui sont déjà des balises de bloc
            if (preg_match('/^<(h[1-6]|ul|ol|li|blockquote)/', $block)) {
                return $block;
            }
            return '<p>' . nl2br($block) . '</p>';
        }, $blocks));

        return $html;
    }

    // =========================================================================
    //  Utilitaires internes
    // =========================================================================

    /**
     * Normalise un slug : minuscules, tirets, caractères ASCII uniquement.
     * Ex: "Mon Épisode #12!" → "mon-episode-12"
     */
    private function sanitizeSlug(string $slug): string {
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);       // tirets multiples → un seul
        return trim($slug, '-');
    }

    /**
     * Sérialise une valeur pour le frontmatter YAML.
     * Ajoute des guillemets doubles si la valeur contient des caractères
     * qui pourraient être mal interprétés par un parser YAML standard.
     */
    private function yamlValue(string $value): string {
        // Caractères nécessitant des guillemets en YAML
        if (preg_match('/[:#\[\]{}|>&*!,]/', $value) || (strpos($value, "\n") !== false)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
}
