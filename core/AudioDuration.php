<?php
// =============================================================================
//  core/AudioDuration.php — Audio file duration extraction
//
//  Uses ffprobe (available on the server) to read metadata.
//  Returns a string in MM:SS or HH:MM:SS format, or '' on failure.
// =============================================================================

class AudioDuration
{
    // =========================================================================
    //  Public API
    // =========================================================================

    /**
     * Returns the duration of an audio file in MM:SS or HH:MM:SS format.
     * Returns '' if ffprobe is absent or if the file is invalid.
     *
     * @param  string $filepath  Absolute path to the audio file.
     * @return string
     */
    public static function fromFile(string $filepath): string
    {
        if (!file_exists($filepath) || !is_file($filepath)) {
            return '';
        }

        $ffprobe = self::ffprobePath();

        if ($ffprobe === '') {
            return '';
        }

        // Escape both paths to prevent any shell injection (and handle spaces)
        $cmd = escapeshellarg($ffprobe) . ' -v error -show_entries format=duration'
             . ' -of default=noprint_wrappers=1:nokey=1 '
             . escapeshellarg($filepath) . ' 2>' . self::nullDevice();

        $output = trim(shell_exec($cmd) ?? '');
        $secs   = (float) $output;

        if ($secs <= 0) {
            return '';
        }

        return self::secondsToTimecode($secs);
    }

    /**
     * Converts seconds to MM:SS or HH:MM:SS.
     *
     * @param  float $secs  Duration in seconds.
     * @return string
     */
    public static function secondsToTimecode(float $secs): string
    {
        $total = (int) round($secs);
        $h     = intdiv($total, 3600);
        $m     = intdiv($total % 3600, 60);
        $s     = $total % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%d:%02d', $m, $s);
    }

    // =========================================================================
    //  Internals
    // =========================================================================

    /**
     * Locates the ffprobe executable on Linux, macOS and Windows.
     *
     * "which" does not exist on Windows (and "2>/dev/null" breaks the whole
     * command there), so each OS uses its own lookup command.
     *
     * @return string  Absolute path to ffprobe, or '' if not found.
     */
    private static function ffprobePath(): string
    {
        static $path = null;

        if ($path === null) {
            $lookup = self::isWindows()
                ? 'where ffprobe 2>NUL'
                : 'command -v ffprobe 2>/dev/null';

            // "where" may list several matches: keep the first line
            $out  = (string) (function_exists('shell_exec') ? shell_exec($lookup) : '');
            $path = trim((string) strtok($out, "\r\n"));
        }

        return $path;
    }

    /**
     * Returns the null device for shell redirections.
     *
     * @return string
     */
    private static function nullDevice(): string
    {
        return self::isWindows() ? 'NUL' : '/dev/null';
    }

    /**
     * @return bool  True when running on Windows.
     */
    private static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }
}
