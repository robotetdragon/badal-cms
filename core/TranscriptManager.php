<?php
// =============================================================================
//  core/TranscriptManager.php — Gestion des transcriptions d'épisodes
//
//  Chaque transcription est un fichier texte brut (UTF-8) dans content/transcripts/
//  nommé d'après le slug de l'épisode : <slug>.txt
//
//  Format supporté (libre, mais deux conventions enrichissent le rendu HTML) :
//
//    Étiquettes d'intervenant :
//      HOST: Bonjour et bienvenue dans ce nouvel épisode.
//      INVITÉ: Merci de m'accueillir !
//      → L'étiquette devient un <strong class="speaker"> dans le HTML rendu.
//
//    Horodatages :
//      [00:12:34] ou [12:34]
//      → Converti en lien cliquable qui positionne le lecteur audio persistant.
//
//  Usage :
//      $tm = new TranscriptManager($config['content_dir']);
//      $tm->save('mon-episode', $texte);
//      echo $tm->toHtml('mon-episode');
// =============================================================================

class TranscriptManager {

    /** Chemin absolu vers content/transcripts/ */
    private string $dir;

    public function __construct(string $contentDir) {
        $this->dir = rtrim($contentDir, '/') . '/transcripts';

        // Créer le dossier à la première utilisation s'il n'existe pas encore
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    // =========================================================================
    //  API publique
    // =========================================================================

    /** Retourne true si une transcription existe pour cet épisode. */
    public function exists(string $slug): bool {
        return file_exists($this->filePath($slug));
    }

    /**
     * Retourne le texte brut de la transcription, ou une chaîne vide.
     * Jamais d'exception — préférable pour les templates.
     */
    public function get(string $slug): string {
        $file = $this->filePath($slug);
        return file_exists($file) ? (file_get_contents($file) ?: '') : '';
    }

    /**
     * Sauvegarde le texte brut d'une transcription.
     * Utilise LOCK_EX pour éviter les corruptions en écriture concurrente.
     *
     * @return bool  true si l'écriture a réussi
     */
    public function save(string $slug, string $text): bool {
        return file_put_contents($this->filePath($slug), $text, LOCK_EX) !== false;
    }

    /**
     * Supprime la transcription d'un épisode.
     * Retourne true même si le fichier n'existait pas (idempotent).
     */
    public function delete(string $slug): bool {
        $file = $this->filePath($slug);
        return !file_exists($file) || unlink($file);
    }

    /**
     * Convertit la transcription brute en HTML enrichi.
     *
     * Pipeline de transformation :
     *   1. Découpage en lignes
     *   2. Détection des étiquettes d'intervenant (MAJUSCULES:)
     *   3. Accumulation des lignes ordinaires dans un buffer → <p>
     *   4. Vidage du buffer sur une ligne vide ou un changement d'intervenant
     *   5. Appel de formatLine() sur chaque texte pour l'échappement HTML
     *      et la conversion des horodatages en liens
     *
     * @return string  HTML prêt à insérer dans une vue (contenu de confiance)
     */
    public function toHtml(string $slug): string {
        $text = $this->get($slug);
        if (trim($text) === '') {
            return '';
        }

        $html   = '';
        $buffer = '';   // accumulateur pour les lignes ordinaires

        foreach (explode("\n", $text) as $rawLine) {
            $line = rtrim($rawLine);

            // Ligne vide → vider le buffer en cours en un paragraphe
            if ($line === '') {
                if ($buffer !== '') {
                    $html  .= '<p>' . $this->formatLine($buffer) . "</p>\n";
                    $buffer = '';
                }
                continue;
            }

            // Étiquette d'intervenant : "HOST:", "INVITÉ:", "MARIE:", etc.
            // Regex : lettre capitale + lettres/espaces (max 30 chars) + ":"
            if (preg_match('/^([A-ZÀÂÄÉÈÊËÎÏÔÙÛÜ][A-Za-zÀ-ÿ\s]{0,30}):\s+(.*)/', $line, $m)) {
                // Vider le buffer précédent avant d'ouvrir un bloc intervenant
                if ($buffer !== '') {
                    $html  .= '<p>' . $this->formatLine($buffer) . "</p>\n";
                    $buffer = '';
                }
                $speaker = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $content = $this->formatLine($m[2]);
                $html   .= '<p><strong class="speaker">' . $speaker . '</strong> ' . $content . "</p>\n";
            } else {
                // Ligne ordinaire → ajout au buffer (un espace comme séparateur de mots)
                $buffer .= ($buffer !== '' ? ' ' : '') . $line;
            }
        }

        // Vider le dernier buffer s'il reste du texte non émis
        if ($buffer !== '') {
            $html .= '<p>' . $this->formatLine($buffer) . "</p>\n";
        }

        return $html;
    }

    // =========================================================================
    //  Privé
    // =========================================================================

    /**
     * Retourne le chemin absolu du fichier de transcription pour un slug.
     * Le slug est assaini pour éviter toute tentative de path traversal.
     */
    private function filePath(string $slug): string {
        // Autoriser uniquement : a-z, 0-9, tiret — toute autre valeur est tronquée
        $safeSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        return $this->dir . '/' . $safeSlug . '.txt';
    }

    /**
     * Prépare une ligne de texte pour l'affichage HTML :
     *   1. Échappe les caractères HTML spéciaux (XSS)
     *   2. Transforme les horodatages [HH:MM:SS] en liens cliquables
     *      Les liens portent data-secs (secondes totales) pour que le
     *      lecteur JavaScript puisse se positionner directement.
     */
    private function formatLine(string $text): string {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Horodatages : [12:34] ou [01:12:34]
        $escaped = preg_replace_callback(
            '/\[(\d{1,2}:\d{2}(?::\d{2})?)\]/',
            function (array $m): string {
                $ts    = $m[1];
                // Conversion HH:MM:SS → secondes pour l'attribut data-secs
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
