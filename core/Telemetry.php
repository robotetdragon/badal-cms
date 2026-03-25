<?php
// =============================================================================
//  core/Telemetry.php — Anonymous opt-in telemetry
//
//  Periodically sends anonymous statistics if the user has opted in.
//  No personal data is collected — only:
//    - Badal version
//    - number of published episodes
//    - interface language
//    - a random installation ID (generated once, never linked to a person)
// =============================================================================

class Telemetry {

    // Collection URL — simple POST endpoint
    public const ENDPOINT = 'https://robotetdragon.com/telemetrie-badal-cms/ping.php';

    // Interval between two sends (24 hours)
    public const SEND_INTERVAL = 86400;

    /**
     * Sends a ping if opt-in is enabled and the interval has elapsed.
     *
     * @param  array  $config      CMS configuration
     * @param  string $configDir   config/ directory for cache
     * @param  int    $epCount     Number of published episodes
     */
    public static function maybeSend(array $config, string $configDir, int $epCount): void {
        // Check opt-in
        $telemetryFile = $configDir . '/telemetry.json';
        $data = [];
        if (file_exists($telemetryFile)) {
            $data = json_decode(file_get_contents($telemetryFile), true) ?: [];
        }

        if (empty($data['opt_in'])) return;

        $now = time();
        if (!empty($data['last_sent']) && ($now - $data['last_sent']) < self::SEND_INTERVAL) return;

        // Generate a persistent anonymous installation ID (never sent with personal data)
        if (empty($data['install_id'])) {
            $data['install_id'] = bin2hex(random_bytes(12));
        }

        $payload = [
            'id'       => $data['install_id'],
            'version'  => Version::current(),
            'episodes' => $epCount,
            'lang'     => $config['language'] ?? 'fr-FR',
        ];

        // Asynchronous send — we don't wait for the response
        $sent = self::asyncPost(self::ENDPOINT, $payload);

        if ($sent) {
            $data['last_sent'] = $now;
            file_put_contents($telemetryFile, json_encode($data), LOCK_EX);
        }
    }

    /**
     * Enables or disables telemetry.
     */
    public static function setOptIn(string $configDir, bool $enabled): void {
        $telemetryFile = $configDir . '/telemetry.json';
        $data = [];
        if (file_exists($telemetryFile)) {
            $data = json_decode(file_get_contents($telemetryFile), true) ?: [];
        }
        if (empty($data['install_id'])) {
            $data['install_id'] = bin2hex(random_bytes(12));
        }
        $data['opt_in'] = $enabled;
        file_put_contents($telemetryFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Returns the current opt-in status.
     */
    public static function isOptIn(string $configDir): bool {
        $f = $configDir . '/telemetry.json';
        if (!file_exists($f)) return false;
        $d = json_decode(file_get_contents($f), true);
        return !empty($d['opt_in']);
    }

    /**
     * POST send via cURL (fallback file_get_contents).
     * Short timeout to avoid slowing down the page.
     */
    private static function asyncPost(string $url, array $payload): bool {
        $body = json_encode($payload);

        // cURL — available on virtually all hosting providers
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $result = curl_exec($ch);
            $ok = curl_errno($ch) === 0;
            curl_close($ch);
            return $ok;
        }

        // Fallback — file_get_contents with stream context
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nConnection: close\r\n",
                'content' => $body,
                'timeout' => 3,
            ],
        ]);
        return @file_get_contents($url, false, $ctx) !== false;
    }
}
