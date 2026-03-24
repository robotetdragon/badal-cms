<?php
// =============================================================================
//  core/ChaptersManager.php — Gestion des chapitres d'épisodes
//
//  Chaque jeu de chapitres est un fichier JSON dans content/chapters/
//  nommé d'après le slug de l'épisode : <slug>.json
//
//  Format JSON (Podcasting 2.0 compatible) :
//    [
//      {"startTime": 0,   "title": "Introduction"},
//      {"startTime": 330, "title": "Le concept", "url": "https://..."},
//      {"startTime": 720, "title": "Interview"}
//    ]
//
//  Format texte (saisie admin) :
//    00:00 Introduction
//    05:30 Le concept
//    12:00 Interview
//
//  Usage :
//      $cm = new ChaptersManager($config['content_dir']);
//      $cm->saveFromText('mon-episode', $texte);
//      $json = $cm->toJson('mon-episode');
// =============================================================================

class ChaptersManager {

    /** Chemin absolu vers content/chapters/ */
    private string $dir;

    public function __construct(string $contentDir) {
        $this->dir = rtrim($contentDir, '/') . '/chapters';

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    // =========================================================================
    //  API publique
    // =========================================================================

    /** Retourne true si des chapitres existent pour cet épisode. */
    public function exists(string $slug): bool {
        return file_exists($this->filePath($slug));
    }

    /**
     * Retourne les chapitres sous forme de tableau PHP.
     * Chaque élément : ['startTime' => int, 'title' => string, 'url' => string|null]
     *
     * @return array<int, array{startTime: int, title: string, url?: string}>
     */
    public function get(string $slug): array {
        $file = $this->filePath($slug);
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file) ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    /**
     * Retourne le texte brut éditable (pour pré-remplir le formulaire admin).
     * Format : une ligne par chapitre "HH:MM:SS Titre" ou "MM:SS Titre".
     */
    public function getText(string $slug): string {
        $chapters = $this->get($slug);
        $lines = [];
        foreach ($chapters as $ch) {
            $ts = $this->formatTimestamp((int) ($ch['startTime'] ?? 0));
            $line = $ts . ' ' . ($ch['title'] ?? '');
            if (!empty($ch['url'])) {
                $line .= ' ' . $ch['url'];
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Parse le texte brut du formulaire admin et sauvegarde en JSON.
     * Accepte les formats :
     *   HH:MM:SS Titre
     *   MM:SS Titre
     *   HH:MM:SS Titre https://url
     *
     * @return bool  true si l'écriture a réussi
     */
    public function saveFromText(string $slug, string $text): bool {
        $text = trim($text);
        if ($text === '') {
            return $this->delete($slug);
        }

        $chapters = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Match: timestamp then title, optional URL at end
            if (preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?)\s+(.+)$/', $line, $m)) {
                $secs  = $this->parseTimestamp($m[1]);
                $rest  = trim($m[2]);

                $url   = null;
                // Detect trailing URL
                if (preg_match('/^(.+?)\s+(https?:\/\/\S+)$/', $rest, $u)) {
                    $rest = trim($u[1]);
                    $url  = $u[2];
                }

                $chapter = [
                    'startTime' => $secs,
                    'title'     => $rest,
                ];
                if ($url) {
                    $chapter['url'] = $url;
                }
                $chapters[] = $chapter;
            }
        }

        if (empty($chapters)) {
            return $this->delete($slug);
        }

        // Trier par startTime
        usort($chapters, fn($a, $b) => $a['startTime'] - $b['startTime']);

        return file_put_contents(
            $this->filePath($slug),
            json_encode($chapters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) !== false;
    }

    /**
     * Supprime les chapitres d'un épisode.
     * Retourne true même si le fichier n'existait pas (idempotent).
     */
    public function delete(string $slug): bool {
        $file = $this->filePath($slug);
        return !file_exists($file) || unlink($file);
    }

    /**
     * Retourne le JSON Podcasting 2.0 compatible pour le flux RSS.
     * Spec : https://github.com/Podcastindex-org/podcast-namespace/blob/main/chapters/jsonChapters.md
     */
    public function toJson(string $slug): string {
        $chapters = $this->get($slug);
        if (empty($chapters)) {
            return '{"version":"1.2.0","chapters":[]}';
        }

        $output = [
            'version'  => '1.2.0',
            'chapters' => $chapters,
        ];

        return json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Génère le HTML des chapitres pour la page publique.
     * Chaque chapitre est un lien cliquable avec data-secs pour le player.
     */
    public function toHtml(string $slug): string {
        $chapters = $this->get($slug);
        if (empty($chapters)) {
            return '';
        }

        $html = '<ol class="chapters-list">' . "\n";
        foreach ($chapters as $ch) {
            $secs  = (int) ($ch['startTime'] ?? 0);
            $ts    = htmlspecialchars($this->formatTimestamp($secs), ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($ch['title'] ?? '', ENT_QUOTES, 'UTF-8');

            $html .= '  <li class="chapter-item">';
            $html .= '<a href="#" class="chapter-link ts-link" data-secs="' . $secs . '">';
            $html .= '<span class="chapter-ts">' . $ts . '</span>';
            $html .= '<span class="chapter-title">' . $title . '</span>';
            $html .= '</a>';

            if (!empty($ch['url'])) {
                $url = htmlspecialchars($ch['url'], ENT_QUOTES, 'UTF-8');
                $html .= ' <a href="' . $url . '" class="chapter-ext-link" target="_blank" rel="noopener">↗</a>';
            }

            $html .= "</li>\n";
        }
        $html .= "</ol>\n";

        return $html;
    }

    // =========================================================================
    //  Privé
    // =========================================================================

    /** Chemin sécurisé vers le fichier JSON d'un slug. */
    private function filePath(string $slug): string {
        $safeSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        return $this->dir . '/' . $safeSlug . '.json';
    }

    /** Convertit "HH:MM:SS" ou "MM:SS" en secondes. */
    private function parseTimestamp(string $ts): int {
        $parts = array_reverse(array_map('intval', explode(':', $ts)));
        return ($parts[0] ?? 0)
             + ($parts[1] ?? 0) * 60
             + ($parts[2] ?? 0) * 3600;
    }

    /** Convertit des secondes en "HH:MM:SS" ou "MM:SS". */
    private function formatTimestamp(int $secs): string {
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%02d:%02d', $m, $s);
    }
}
