<?php
// =============================================================================
//  core/Version.php — Version de Badal
// =============================================================================

class Version {

    public const CURRENT = '0.13';

    // URL du fichier JSON de version hébergé sur GitHub
    // Format attendu : {"version":"1.2.0","url":"https://github.com/...","notes":"..."}
    public const CHECK_URL = 'https://raw.githubusercontent.com/robotetdragon/badal-cms/main/version.json';

    // Intervalle entre deux vérifications (secondes) — 24h
    public const CHECK_INTERVAL = 86400;

    /**
     * Vérifie s'il y a une nouvelle version disponible.
     * Retourne null si pas de réseau ou pas encore l'heure.
     *
     * @param  string $cacheFile  Chemin vers le fichier cache JSON
     * @return array|null  ['current','latest','has_update','url','notes'] ou null
     */
    public static function check(string $cacheFile): ?array {
        // Lire le cache
        $cache = [];
        if (file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
        }

        $now = time();

        // Pas encore l'heure de revérifier
        if (!empty($cache['checked_at']) && ($now - $cache['checked_at']) < self::CHECK_INTERVAL) {
            return $cache['result'] ?? null;
        }

        // Appel réseau
        $ctx = stream_context_create(['http' => [
            'timeout'    => 5,
            'user_agent' => 'Badal/' . self::CURRENT . ' (update-check)',
        ]]);

        $raw = @file_get_contents(self::CHECK_URL, false, $ctx);
        if (!$raw) {
            // Échec réseau — mettre à jour le timestamp pour ne pas retry immédiatement
            file_put_contents($cacheFile, json_encode([
                'checked_at' => $now,
                'result'     => $cache['result'] ?? null,
            ]), LOCK_EX);
            return $cache['result'] ?? null;
        }

        $data = json_decode($raw, true);
        if (empty($data['version'])) return null;

        $result = [
            'current'    => self::CURRENT,
            'latest'     => $data['version'],
            'has_update' => version_compare($data['version'], self::CURRENT, '>'),
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
