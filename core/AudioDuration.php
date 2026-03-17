<?php
// =============================================================================
//  core/AudioDuration.php — Extraction de la durée d'un fichier audio
//
//  Utilise ffprobe (disponible sur le serveur) pour lire les métadonnées.
//  Retourne une chaîne au format MM:SS ou HH:MM:SS, ou '' si échec.
// =============================================================================

class AudioDuration {

    /**
     * Retourne la durée d'un fichier audio au format MM:SS ou HH:MM:SS.
     * Retourne '' si ffprobe est absent ou si le fichier est invalide.
     */
    public static function fromFile(string $filepath): string {
        if (!file_exists($filepath) || !is_file($filepath)) return '';

        $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
        if (!$ffprobe) return '';

        // Échapper le chemin pour éviter toute injection shell
        $escaped = escapeshellarg($filepath);
        $cmd     = $ffprobe . ' -v error -show_entries format=duration'
                 . ' -of default=noprint_wrappers=1:nokey=1 '
                 . $escaped . ' 2>/dev/null';

        $output = trim(shell_exec($cmd) ?? '');
        $secs   = (float) $output;

        if ($secs <= 0) return '';

        return self::secondsToTimecode($secs);
    }

    /**
     * Convertit des secondes en MM:SS ou HH:MM:SS.
     */
    public static function secondsToTimecode(float $secs): string {
        $total = (int) round($secs);
        $h     = intdiv($total, 3600);
        $m     = intdiv($total % 3600, 60);
        $s     = $total % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }
}
