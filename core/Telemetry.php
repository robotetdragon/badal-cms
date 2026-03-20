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
        $sent = self::asyncPost(self::ENDPOINT, $payload);

        if ($sent) {
            $data['last_sent'] = $now;
            file_put_contents($telemetryFile, json_encode($data), LOCK_EX);
        }
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
     * Envoi POST via cURL (fallback file_get_contents).
     * Timeout court pour ne pas ralentir la page.
     */
    private static function asyncPost(string $url, array $payload): bool {
        $body = json_encode($payload);

        // cURL — disponible sur la quasi-totalité des hébergements
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

        // Fallback — file_get_contents avec stream context
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
