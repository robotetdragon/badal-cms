<?php
// =============================================================================
//  core/AudioDuration.php — Audio file duration extraction
//
//  Uses ffprobe (available on the server) to read metadata.
//  Returns a string in MM:SS or HH:MM:SS format, or '' on failure.
// =============================================================================

class AudioDuration {

    /**
     * Returns the duration of an audio file in MM:SS or HH:MM:SS format.
     * Returns '' if ffprobe is absent or if the file is invalid.
     */
    public static function fromFile(string $filepath): string {
        if (!file_exists($filepath) || !is_file($filepath)) return '';

        $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
        if (!$ffprobe) return '';

        // Escape the path to prevent any shell injection
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
     * Converts seconds to MM:SS or HH:MM:SS.
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
