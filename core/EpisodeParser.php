<?php
// =============================================================================
//  core/EpisodeParser.php — Reading and writing Markdown episodes
//
//  Each episode is a .md file in content/episodes/ with a minimalist YAML
//  frontmatter (implemented without external dependency) followed by the
//  Markdown body.
//
//  Episode file format:
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
//  The slug is derived from the file name (without the .md extension).
// =============================================================================

class EpisodeParser {

    /** Absolute path to content/ (without trailing slash) */
    private string $contentDir;

    public function __construct(string $contentDir) {
        $this->contentDir = rtrim($contentDir, '/');
    }

    // =========================================================================
    //  Public API — reading
    // =========================================================================

    /**
     * Returns all episodes sorted according to the custom order
     * (episodes_order.json) or by reverse chronological date by default.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param bool $includeDrafts  If false (default), episodes with status=draft are excluded.
     */
    public function getAll(bool $includeDrafts = false): array {
        $files = glob($this->contentDir . '/episodes/*.md') ?: [];

        $episodes = array_filter(
            array_map([$this, 'parseFile'], $files)
        );

        if (!$includeDrafts) {
            $episodes = array_filter($episodes, fn($ep) => ($ep['status'] ?? 'published') !== 'draft');
        }

        // Custom order?
        $orderFile = dirname($this->contentDir) . '/config/episodes_order.json';
        if (file_exists($orderFile)) {
            $order = json_decode(file_get_contents($orderFile), true);
            if (is_array($order) && !empty($order)) {
                return $this->sortByOrder($episodes, $order);
            }
        }

        // Reverse chronological sort (newest first, oldest at bottom)
        // Use pubdate (full timestamp) when available for accurate ordering
        usort($episodes, function($a, $b) {
            $ta = strtotime($a['pubdate'] ?? $a['date'] ?? '');
            $tb = strtotime($b['pubdate'] ?? $b['date'] ?? '');
            return $tb - $ta;
        });

        return array_values($episodes);
    }

    /**
     * Saves the custom episode order (list of slugs).
     *
     * @param  array $slugs  Ordered list of slugs
     * @return bool
     */
    public function saveOrder(array $slugs): bool {
        $orderFile = dirname($this->contentDir) . '/config/episodes_order.json';
        return file_put_contents(
            $orderFile,
            json_encode(array_values($slugs), JSON_PRETTY_PRINT),
            LOCK_EX
        ) !== false;
    }

    /**
     * Deletes the custom order (returns to date-based sorting).
     */
    public function resetOrder(): bool {
        $orderFile = dirname($this->contentDir) . '/config/episodes_order.json';
        return !file_exists($orderFile) || unlink($orderFile);
    }

    /**
     * Returns an episode by its slug, or null if not found.
     *
     * @param  string      $slug  URL identifier (e.g. "mon-episode-12")
     * @return array|null         Array of metadata + body + content_html
     */
    public function getBySlug(string $slug): ?array {
        $file = $this->contentDir . '/episodes/' . $slug . '.md';
        return file_exists($file) ? $this->parseFile($file) : null;
    }

    /**
     * Parses a .md file and returns its structured data.
     * Automatically adds:
     *   - 'slug'         : file name without extension
     *   - 'content_html' : Markdown body converted to HTML
     *
     * @return array|null  null if the file is unreadable
     */
    public function parseFile(string $filepath): ?array {
        if (!file_exists($filepath)) {
            return null;
        }

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
    //  Public API — writing
    // =========================================================================

    /**
     * Saves (creates or overwrites) an episode to disk.
     *
     * The content is serialized with YAML frontmatter then Markdown body.
     * The slug is sanitized before writing (allowed characters: a-z 0-9 -).
     *
     * @param  string $slug  Unique episode identifier
     * @param  array  $meta  Key/value pairs for the YAML frontmatter
     * @param  string $body  Markdown body of the episode (show notes)
     * @return bool          true if the write succeeded
     */
    public function save(string $slug, array $meta, string $body): bool {
        $slug     = $this->sanitizeSlug($slug);
        $filepath = $this->contentDir . '/episodes/' . $slug . '.md';

        // Serialization: YAML frontmatter + line break + body
        $content = "---\n";
        foreach ($meta as $key => $value) {
            $content .= $key . ': ' . $this->yamlValue((string) $value) . "\n";
        }
        $content .= "---\n\n" . $body;

        return file_put_contents($filepath, $content, LOCK_EX) !== false;
    }

    /**
     * Deletes the Markdown file of an episode.
     * Does not delete the audio file or the associated transcript.
     *
     * @return bool  true if the deletion succeeded (or if the file didn't exist)
     */
    public function delete(string $slug): bool {
        $filepath = $this->contentDir . '/episodes/' . $slug . '.md';
        return !file_exists($filepath) || unlink($filepath);
    }

    // =========================================================================
    //  Custom sorting
    // =========================================================================

    /**
     * Sorts episodes according to an ordered list of slugs.
     * Episodes absent from the list are appended at the end, sorted by date.
     */
    private function sortByOrder(array $episodes, array $order): array {
        $indexed = [];
        foreach ($episodes as $ep) {
            $indexed[$ep['slug'] ?? ''] = $ep;
        }

        $sorted  = [];
        foreach ($order as $slug) {
            if (isset($indexed[$slug])) {
                $sorted[] = $indexed[$slug];
                unset($indexed[$slug]);
            }
        }

        // Remaining episodes — newest first
        $remaining = array_values($indexed);
        usort($remaining, function($a, $b) {
            $ta = strtotime($a['pubdate'] ?? $a['date'] ?? '');
            $tb = strtotime($b['pubdate'] ?? $b['date'] ?? '');
            return $tb - $ta;
        });

        return array_merge($sorted, $remaining);
    }

    // =========================================================================
    //  YAML frontmatter parsing
    // =========================================================================

    /**
     * Separates the YAML frontmatter from the Markdown body.
     *
     * Minimalist implementation (no dependency) that handles:
     *   - Simple values   : key: value
     *   - Quoted values   : key: "value with: colons"
     *   - Missing frontmatter (returns raw content in 'body')
     *
     * @return array<string, string>  Metadata + 'body' key for the Markdown
     */
    private function parseFrontmatter(string $content): array {
        $result = ['body' => $content];

        // The frontmatter must start at the very first character of the file
        if (!(strpos($content, '---') === 0)) {
            return $result;
        }

        // Split on "---" separators at the beginning of a line
        $parts = preg_split('/^---\s*$/m', $content, 3);
        if (count($parts) < 3) {
            return $result;
        }

        $result['body'] = trim($parts[2]);

        // Parse line by line: "key: value"
        foreach (explode("\n", $parts[1]) as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', trim($line), $m)) {
                $result[$m[1]] = trim($m[2], '"\'');
            }
        }

        return $result;
    }

    // =========================================================================
    //  Markdown → HTML converter (lightweight, no dependency)
    // =========================================================================

    /**
     * Converts a subset of Markdown to HTML.
     *
     * Supported elements:
     *   - Headings    : # ## ###
     *   - Bold        : **text**
     *   - Italic      : *text*
     *   - Links       : [text](url)
     *   - Paragraphs  : blocks separated by a blank line
     *   - Line breaks : \n within a paragraph → <br>
     *
     * The Markdown is first HTML-escaped to neutralize any XSS,
     * then allowed tags are injected via regex.
     */
    private function markdownToHtml(string $markdown): string {
        // Step 1: escape to neutralize any raw HTML in the source
        $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        // Step 2: headings (from most specific to least specific, to avoid false positives)
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $html);

        // Step 3: inline emphasis
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/',     '<em>$1</em>',         $html);

        // Step 4: links (with scheme validation to block javascript: etc.)
        $html = preg_replace_callback('/\[(.+?)\]\((.+?)\)/', function ($m) {
            $href = $m[2];
            // Allow only http(s), mailto, relative paths and anchors
            if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $href) && !preg_match('/^(https?|mailto):/i', $href)) {
                return $m[1]; // Dangerous scheme → keep the text, remove the link
            }
            return '<a href="' . $href . '">' . $m[1] . '</a>';
        }, $html);

        // Step 5: paragraphs (blocks separated by >=2 line breaks)
        $blocks = preg_split('/\n{2,}/', $html);
        $html   = implode('', array_map(function (string $block): string {
            $block = trim($block);
            if ($block === '') {
                return '';
            }
            // Do not wrap blocks that are already block-level tags
            if (preg_match('/^<(h[1-6]|ul|ol|li|blockquote)/', $block)) {
                return $block;
            }
            return '<p>' . nl2br($block) . '</p>';
        }, $blocks));

        return $html;
    }

    // =========================================================================
    //  Internal utilities
    // =========================================================================

    /**
     * Normalizes a slug: lowercase, hyphens, ASCII characters only.
     * E.g.: "Mon Épisode #12!" → "mon-episode-12"
     */
    private function sanitizeSlug(string $slug): string {
        // Transliteration of accented characters (É→E, ñ→n, ü→u, etc.)
        if (function_exists('transliterator_transliterate')) {
            $slug = transliterator_transliterate('Any-Latin; Latin-ASCII', $slug);
        } else {
            // Fallback without intl: Unicode decomposition + diacritics removal
            $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        }

        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);       // multiple hyphens → single
        return trim($slug, '-');
    }

    /**
     * Serializes a value for the YAML frontmatter.
     * Adds double quotes if the value contains characters
     * that could be misinterpreted by a standard YAML parser.
     */
    private function yamlValue(string $value): string {
        // Characters requiring quotes in YAML
        if (preg_match('/[:#\[\]{}|>&*!,]/', $value) || (strpos($value, "\n") !== false)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }
}
