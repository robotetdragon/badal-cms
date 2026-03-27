<?php
// =============================================================================
//  core/Telemetry.php — Anonymous opt-in telemetry
//
//  Sends anonymous usage data once per day if the user has opted in.
//  Collected fields: Badal version, episode count, interface language.
//  A random installation ID is generated locally — never linked to a person.
//  No personal data, no IP, no hostname.
// =============================================================================

class Telemetry
{
    /** Collection endpoint (simple POST). */
    public const ENDPOINT = 'https://robotetdragon.com/telemetrie-badal-cms/ping.php';

    /** Minimum interval between two pings (24 hours). */
    public const SEND_INTERVAL = 86400;

    // =========================================================================
    //  Public API
    // =========================================================================

    /**
     * Sends a ping if opt-in is enabled and enough time has elapsed.
     *
     * Called from admin/index.php on every dashboard load.
     * The HTTP call uses a short timeout (2–3 s) so it never blocks the page.
     */
    public static function maybeSend(array $config, string $configDir, int $epCount): void
    {
        $file = self::filePath($configDir);
        $data = self::readFile($file);

        // Opt-in required
        if (empty($data['opt_in'])) {
            return;
        }

        // Throttle: one ping per interval
        $now = time();
        if (!empty($data['last_sent']) && ($now - $data['last_sent']) < self::SEND_INTERVAL) {
            return;
        }

        // Ensure a persistent anonymous ID exists
        if (empty($data['install_id'])) {
            $data['install_id'] = bin2hex(random_bytes(12));
        }

        $payload = [
            'id'       => $data['install_id'],
            'version'  => Version::current(),
            'episodes' => $epCount,
            'lang'     => $config['language'] ?? 'fr-FR',
        ];

        if (self::post(self::ENDPOINT, $payload)) {
            $data['last_sent'] = $now;
            self::writeFile($file, $data);
        }
    }

    /**
     * Enables or disables telemetry.
     */
    public static function setOptIn(string $configDir, bool $enabled): void
    {
        $file = self::filePath($configDir);
        $data = self::readFile($file);

        if (empty($data['install_id'])) {
            $data['install_id'] = bin2hex(random_bytes(12));
        }

        $data['opt_in'] = $enabled;
        self::writeFile($file, $data);
    }

    /**
     * Returns the current opt-in status.
     */
    public static function isOptIn(string $configDir): bool
    {
        $data = self::readFile(self::filePath($configDir));
        return !empty($data['opt_in']);
    }

    // =========================================================================
    //  Internals
    // =========================================================================

    /** Canonical path to the telemetry state file. */
    private static function filePath(string $configDir): string
    {
        return rtrim($configDir, '/') . '/telemetry.json';
    }

    /** Read and decode the telemetry JSON file. */
    private static function readFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?: [];
    }

    /** Write the telemetry state back to disk. */
    private static function writeFile(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * HTTP POST with short timeout (cURL preferred, file_get_contents fallback).
     */
    private static function post(string $url, array $payload): bool
    {
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
            curl_exec($ch);
            $ok = curl_errno($ch) === 0;
            curl_close($ch);
            return $ok;
        }

        // Fallback — stream context
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
