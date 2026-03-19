<?php
// =============================================================================
//  core/Telemetry.php — Télémétrie anonyme opt-in
//
//  Envoie périodiquement des statistiques anonymes si l'utilisateur a opté.
//  Aucune donnée personnelle n'est collectée — uniquement :
//    - version de Badal
//    - nombre d'épisodes publiés
//    - langue de l'interface
//    - un ID installation aléatoire (généré une fois, jamais lié à une personne)
// =============================================================================

class Telemetry {

    // URL de collecte — endpoint POST simple
    public const ENDPOINT = 'https://robotetdragon.com/telemetrie-badal-cms/ping.php';

    // Intervalle entre deux envois (24 heures)
    public const SEND_INTERVAL = 86400;

    /**
     * Envoie un ping si l'opt-in est activé et que l'intervalle est écoulé.
     *
     * @param  array  $config      Configuration du CMS
     * @param  string $configDir   Dossier config/ pour le cache
     * @param  int    $epCount     Nombre d'épisodes publiés
     */
    public static function maybeSend(array $config, string $configDir, int $epCount): void {
        // Vérifier l'opt-in
        $telemetryFile = $configDir . '/telemetry.json';
        $data = [];
        if (file_exists($telemetryFile)) {
            $data = json_decode(file_get_contents($telemetryFile), true) ?: [];
        }

        if (empty($data['opt_in'])) return;

        $now = time();
        if (!empty($data['last_sent']) && ($now - $data['last_sent']) < self::SEND_INTERVAL) return;

        // Générer un ID installation persistant et anonyme (jamais envoyé avec des données persos)
        if (empty($data['install_id'])) {
            $data['install_id'] = bin2hex(random_bytes(12));
        }

        $payload = [
            'id'       => $data['install_id'],
            'version'  => Version::CURRENT,
            'episodes' => $epCount,
            'lang'     => $config['language'] ?? 'fr-FR',
        ];

        // Envoi asynchrone — on n'attend pas la réponse
        self::asyncPost(self::ENDPOINT, $payload);

        $data['last_sent'] = $now;
        file_put_contents($telemetryFile, json_encode($data), LOCK_EX);
    }

    /**
     * Active ou désactive la télémétrie.
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
     * Retourne l'état actuel de l'opt-in.
     */
    public static function isOptIn(string $configDir): bool {
        $f = $configDir . '/telemetry.json';
        if (!file_exists($f)) return false;
        $d = json_decode(file_get_contents($f), true);
        return !empty($d['opt_in']);
    }

    /**
     * Envoi POST non-bloquant via fsockopen (ne ralentit pas la page).
     */
    private static function asyncPost(string $url, array $payload): void {
        $parsed  = parse_url($url);
        $host    = $parsed['host'] ?? '';
        $path    = ($parsed['path'] ?? '/') . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
        $port    = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
        $scheme  = $parsed['scheme'] ?? 'http';

        $body    = json_encode($payload);
        $headers = "POST $path HTTP/1.1\r\n"
                 . "Host: $host\r\n"
                 . "Content-Type: application/json\r\n"
                 . "Content-Length: " . strlen($body) . "\r\n"
                 . "Connection: close\r\n\r\n";

        $prefix  = $scheme === 'https' ? 'ssl://' : '';
        $fp = @fsockopen($prefix . $host, $port, $errno, $errstr, 2);
        if ($fp) {
            @fwrite($fp, $headers . $body);
            @fclose($fp);
        }
    }
}
