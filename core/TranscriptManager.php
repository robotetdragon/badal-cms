<?php
// =============================================================================
//  core/TranscriptManager.php — Episode transcript management
//
//  Each transcript is a plain text file (UTF-8) in content/transcripts/
//  named after the episode slug: <slug>.txt
//
//  Supported format (free-form, but two conventions enrich the HTML rendering):
//
//    Speaker labels:
//      HOST: Bonjour et bienvenue dans ce nouvel épisode.
//      INVITÉ: Merci de m'accueillir !
//      → The label becomes a <strong class="speaker"> in the rendered HTML.
//
//    Timestamps:
//      [00:12:34] or [12:34]
//      → Converted to a clickable link that positions the persistent audio player.
//
//  Usage:
//      $tm = new TranscriptManager($config['content_dir']);
//      $tm->save('mon-episode', $texte);
//      echo $tm->toHtml('mon-episode');
// =============================================================================

class TranscriptManager
{
    // =========================================================================
    //  Properties
    // =========================================================================

    /** Absolute path to content/transcripts/ */
    private string $dir;

    // =========================================================================
    //  Constructor
    // =========================================================================

    /**
     * Create a new TranscriptManager instance.
     *
     * @param string $contentDir  Absolute path to the content/ directory.
     */
    public function __construct(string $contentDir)
    {
        $this->dir = rtrim($contentDir, '/') . '/transcripts';

        // Create the directory on first use if it doesn't exist yet
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    // =========================================================================
    //  Public API
    // =========================================================================

    /**
     * Returns true if a transcript exists for this episode.
     *
     * @param  string $slug  Episode slug.
     * @return bool
     */
    public function exists(string $slug): bool
    {
        return file_exists($this->filePath($slug));
    }

    /**
     * Returns the raw text of the transcript, or an empty string.
     * Never throws — preferable for templates.
     *
     * @param  string $slug  Episode slug.
     * @return string
     */
    public function get(string $slug): string
    {
        $file = $this->filePath($slug);

        return file_exists($file) ? (file_get_contents($file) ?: '') : '';
    }

    /**
     * Saves the raw text of a transcript.
     * Uses LOCK_EX to avoid corruption from concurrent writes.
     *
     * @param  string $slug  Episode slug.
     * @param  string $text  Raw transcript text.
     * @return bool  True if the write succeeded.
     */
    public function save(string $slug, string $text): bool
    {
        return file_put_contents($this->filePath($slug), $text, LOCK_EX) !== false;
    }

    /**
     * Deletes the transcript of an episode.
     * Returns true even if the file didn't exist (idempotent).
     *
     * @param  string $slug  Episode slug.
     * @return bool
     */
    public function delete(string $slug): bool
    {
        $file = $this->filePath($slug);

        return !file_exists($file) || unlink($file);
    }

    /**
     * Converts the raw transcript to enriched HTML.
     *
     * Transformation pipeline:
     *   1. Split into lines
     *   2. Detect speaker labels (UPPERCASE:)
     *   3. Accumulate ordinary lines in a buffer -> <p>
     *   4. Flush the buffer on empty line or speaker change
     *   5. Call formatLine() on each text for HTML escaping
     *      and timestamp-to-link conversion
     *
     * @param  string $slug  Episode slug.
     * @return string  HTML ready to insert in a view (trusted content).
     */
    public function toHtml(string $slug): string
    {
        $text = $this->get($slug);

        if (trim($text) === '') {
            return '';
        }

        $html   = '';
        $buffer = '';   // accumulator for ordinary lines

        foreach (explode("\n", $text) as $rawLine) {
            $line = rtrim($rawLine);

            // Empty line -> flush the current buffer into a paragraph
            if ($line === '') {
                if ($buffer !== '') {
                    $html  .= '<p>' . $this->formatLine($buffer) . "</p>\n";
                    $buffer = '';
                }
                continue;
            }

            // Speaker label: "HOST:", "INVITÉ:", "MARIE:", etc.
            // Regex: capital letter + letters/spaces (max 30 chars) + ":"
            if (preg_match('/^([A-ZÀÂÄÉÈÊËÎÏÔÙÛÜ][A-Za-zÀ-ÿ\s]{0,30}):\s+(.*)/', $line, $m)) {
                // Flush the previous buffer before opening a speaker block
                if ($buffer !== '') {
                    $html  .= '<p>' . $this->formatLine($buffer) . "</p>\n";
                    $buffer = '';
                }

                $speaker = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $content = $this->formatLine($m[2]);
                $html   .= '<p><strong class="speaker">' . $speaker . '</strong> ' . $content . "</p>\n";
            } else {
                // Ordinary line -> append to buffer (space as word separator)
                $buffer .= ($buffer !== '' ? ' ' : '') . $line;
            }
        }

        // Flush the last buffer if there is remaining unemitted text
        if ($buffer !== '') {
            $html .= '<p>' . $this->formatLine($buffer) . "</p>\n";
        }

        return $html;
    }

    // =========================================================================
    //  Private
    // =========================================================================

    /**
     * Returns the absolute path to the transcript file for a slug.
     * The slug is sanitized to prevent any path traversal attempt.
     *
     * @param  string $slug  Episode slug.
     * @return string
     */
    private function filePath(string $slug): string
    {
        // Allow only: a-z, 0-9, hyphen — any other value is stripped
        $safeSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

        return $this->dir . '/' . $safeSlug . '.txt';
    }

    /**
     * Prepares a line of text for HTML display:
     *   1. Escapes special HTML characters (XSS)
     *   2. Transforms [HH:MM:SS] timestamps into clickable links
     *      Links carry data-secs (total seconds) so that the
     *      JavaScript player can seek directly.
     *
     * @param  string $text  Raw line of text.
     * @return string
     */
    private function formatLine(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Timestamps: [12:34] or [01:12:34]
        $escaped = preg_replace_callback(
            '/\[(\d{1,2}:\d{2}(?::\d{2})?)\]/',
            function (array $m): string {
                $ts    = $m[1];
                $parts = array_reverse(explode(':', $ts));
                $secs  = (int) ($parts[0] ?? 0)
                       + (int) ($parts[1] ?? 0) * 60
                       + (int) ($parts[2] ?? 0) * 3600;

                return '<a href="#" class="ts-link" data-secs="' . $secs . '">'
                     . '[' . $ts . ']</a>';
            },
            $escaped
        );

        return $escaped;
    }
}
