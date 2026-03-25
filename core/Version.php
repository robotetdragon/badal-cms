<?php
// =============================================================================
//  core/Version.php — Version de Badal
// =============================================================================

class Version {

    // URL of the version JSON file hosted on GitHub
    // Expected format: {"version":"1.2.0","url":"https://github.com/...","notes":"..."}
    public const CHECK_URL = 'https://raw.githubusercontent.com/robotetdragon/badal-cms/main/version.json';

    /**
     * Returns the current version read from version.json (single source of truth).
     * Result is statically cached to avoid re-reading the file on each call.
     */
    public static function current(): string {
        static $version = null;
        if ($version === null) {
            $file = dirname(__DIR__) . '/version.json';
            $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
            $version = $data['version'] ?? '0.0';
        }
        return $version;
    }

    // Interval between two checks (seconds) — 24h
    public const CHECK_INTERVAL = 86400;

    /**
     * Checks if a new version is available.
     * Returns null if no network or not yet time to check.
     *
     * @param  string $cacheFile  Path to the cache JSON file
     * @return array|null  ['current','latest','has_update','url','notes'] or null
     */
    public static function check(string $cacheFile): ?array {
        // Read the cache
        $cache = [];
        if (file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
        }

        $now = time();

        // Not yet time to recheck
        if (!empty($cache['checked_at']) && ($now - $cache['checked_at']) < self::CHECK_INTERVAL) {
            return $cache['result'] ?? null;
        }

        // Network call
        $ctx = stream_context_create(['http' => [
            'timeout'    => 5,
            'user_agent' => 'Badal/' . self::current() . ' (update-check)',
        ]]);

        $raw = @file_get_contents(self::CHECK_URL, false, $ctx);
        if (!$raw) {
            // Network failure — update the timestamp to avoid immediate retry
            file_put_contents($cacheFile, json_encode([
                'checked_at' => $now,
                'result'     => $cache['result'] ?? null,
            ]), LOCK_EX);
            return $cache['result'] ?? null;
        }

        $data = json_decode($raw, true);
        if (empty($data['version'])) return null;

        $result = [
            'current'    => self::current(),
            'latest'     => $data['version'],
            'has_update' => version_compare($data['version'], self::current(), '>'),
            'url'        => $data['url']   ?? '',
            'notes'      => $data['notes'] ?? '',
            'checked_at' => $now,
        ];

        file_put_contents($cacheFile, json_encode([
            'checked_at' => $now,
            'result'     => $result,
        ]), LOCK_EX);

        return $result;
    }
}
