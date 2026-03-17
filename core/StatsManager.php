<?php
// =============================================================================
//  core/StatsManager.php — Suivi des écoutes par épisode
//
//  Stockage : un seul fichier JSON dans config/stats.json.
//  Structure du JSON :
//  {
//    "mon-episode": {
//      "total": 142,
//      "daily": {
//        "2026-03-01": 12,
//        "2026-03-02": 8,
//        …
//      }
//    },
//    …
//  }
//
//  L'historique daily est conservé sur 90 jours glissants.
//  Les entrées plus anciennes sont automatiquement purgées à chaque recordPlay().
//
//  Le comptage est déclenché par public/audio.php qui intercepte les requêtes
//  audio et n'enregistre que les écoutes initiales (Range: bytes=0-).
//
//  Usage :
//      $stats = new StatsManager($configDir);
//      $stats->recordPlay('mon-episode');
//      echo $stats->getGrandTotal();
// =============================================================================

class StatsManager {

    /** Nombre de jours conservés dans l'historique journalier */
    private const HISTORY_DAYS = 90;

    private string $statsFile;

    /**
     * Données chargées en mémoire.
     * Structure : [ slug => [ 'total' => int, 'daily' => [ 'Y-m-d' => int ] ] ]
     */
    private array $data;

    public function __construct(string $configDir) {
        $this->statsFile = rtrim($configDir, '/') . '/stats.json';
        $this->load();
    }

    // =========================================================================
    //  Enregistrement
    // =========================================================================

    /**
     * Enregistre une écoute pour un épisode donné.
     *
     * Actions :
     *   1. Initialise la structure si c'est le premier play de cet épisode
     *   2. Incrémente le compteur total
     *   3. Incrémente le compteur du jour courant
     *   4. Purge les entrées daily antérieures à HISTORY_DAYS jours
     *   5. Persiste sur le disque
     *
     * @param  string $slug  Identifiant de l'épisode (ex: "mon-episode-12")
     * @return bool          true si la sauvegarde a réussi
     */
    public function recordPlay(string $slug): bool {
        $today = date('Y-m-d');

        // Initialisation de la structure pour un nouvel épisode
        if (!isset($this->data[$slug])) {
            $this->data[$slug] = ['total' => 0, 'daily' => []];
        }

        $this->data[$slug]['total']++;
        $this->data[$slug]['daily'][$today] = ($this->data[$slug]['daily'][$today] ?? 0) + 1;

        // Purge de l'historique : on ne garde que les HISTORY_DAYS derniers jours
        // krsort() trie par date descendante, array_slice() tronque
        $daily = &$this->data[$slug]['daily'];
        krsort($daily);
        $daily = array_slice($daily, 0, self::HISTORY_DAYS, true);

        return $this->save();
    }

    // =========================================================================
    //  Lecture — épisode individuel
    // =========================================================================

    /**
     * Retourne le nombre total d'écoutes pour un épisode.
     * Retourne 0 si l'épisode n'a jamais été écouté.
     */
    public function getTotal(string $slug): int {
        return $this->data[$slug]['total'] ?? 0;
    }

    /**
     * Retourne l'historique journalier d'un épisode sur les $days derniers jours.
     * Les jours sans écoute sont inclus avec la valeur 0 (tableau complet).
     *
     * @param  int                   $days  Nombre de jours à retourner (défaut : 30)
     * @return array<string, int>           [ 'Y-m-d' => count, … ]
     */
    public function getDailyHistory(string $slug, int $days = 30): array {
        $raw    = $this->data[$slug]['daily'] ?? [];
        $result = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date          = date('Y-m-d', strtotime("-{$i} days"));
            $result[$date] = $raw[$date] ?? 0;
        }

        return $result;
    }

    // =========================================================================
    //  Lecture — agrégations globales
    // =========================================================================

    /**
     * Retourne le total d'écoutes de tous les épisodes, tous temps confondus.
     */
    public function getGrandTotal(): int {
        return array_sum(array_column($this->data, 'total'));
    }

    /**
     * Retourne le total d'écoutes sur les $days derniers jours, tous épisodes.
     *
     * @param  int  $days  Fenêtre temporelle en jours
     * @return int         Nombre total d'écoutes sur la période
     */
    public function getRecentTotal(int $days = 30): int {
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));
        $total  = 0;

        foreach ($this->data as $info) {
            foreach ($info['daily'] ?? [] as $date => $count) {
                if ($date >= $cutoff) {
                    $total += $count;
                }
            }
        }

        return $total;
    }

    /**
     * Retourne les totaux par épisode, triés du plus écouté au moins écouté.
     *
     * @return array<string, int>  [ 'slug' => total, … ]
     */
    public function getAllTotals(): array {
        $totals = array_map(fn($info) => $info['total'] ?? 0, $this->data);
        arsort($totals);
        return $totals;
    }

    /**
     * Retourne le classement des $limit épisodes les plus écoutés.
     *
     * @param  int                  $limit  Nombre d'épisodes à retourner
     * @return array<string, int>           [ 'slug' => total, … ]
     */
    public function getRanking(int $limit = 10): array {
        return array_slice($this->getAllTotals(), 0, $limit, true);
    }

    /**
     * Retourne l'historique journalier agrégé sur tous les épisodes.
     * Utile pour le graphique de la page Statistiques.
     *
     * Les jours sans aucune écoute sont inclus avec la valeur 0.
     *
     * @param  int                  $days  Nombre de jours à retourner
     * @return array<string, int>          [ 'Y-m-d' => total, … ]
     */

    /**
     * Retourne l'historique journalier complet (toutes dates confondues).
     * Utilisé pour la vue "Tout" dans les statistiques.
     *
     * @return array<string, int>  [ 'Y-m-d' => total, … ] trié par date
     */
    public function getGlobalDailyHistoryAll(): array {
        $result = [];
        foreach ($this->data as $info) {
            foreach ($info['daily'] ?? [] as $date => $count) {
                $result[$date] = ($result[$date] ?? 0) + $count;
            }
        }
        ksort($result);
        return $result;
    }

    /**
     * Retourne l'historique journalier d'un épisode sur toute la durée.
     *
     * @param  string               $slug  Identifiant de l'épisode
     * @return array<string, int>          [ 'Y-m-d' => count, … ]
     */
    public function getDailyHistoryAll(string $slug): array {
        $daily = $this->data[$slug]['daily'] ?? [];
        ksort($daily);
        return $daily;
    }

    public function getGlobalDailyHistory(int $days = 30): array {
        // Initialiser le tableau avec des 0 pour chaque jour
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $result[date('Y-m-d', strtotime("-{$i} days"))] = 0;
        }

        // Additionner les écoutes de chaque épisode
        foreach ($this->data as $info) {
            foreach ($info['daily'] ?? [] as $date => $count) {
                if (isset($result[$date])) {
                    $result[$date] += $count;
                }
            }
        }

        return $result;
    }

    // =========================================================================
    //  Persistance (privé)
    // =========================================================================

    /** Charge les données depuis le fichier JSON. Initialise à [] si absent. */
    private function load(): void {
        if (!file_exists($this->statsFile)) {
            $this->data = [];
            return;
        }

        $raw         = file_get_contents($this->statsFile);
        $decoded     = json_decode($raw ?: '{}', true);
        $this->data  = is_array($decoded) ? $decoded : [];
    }

    /** Persiste les données en JSON indenté avec verrou d'écriture. */
    private function save(): bool {
        return file_put_contents(
            $this->statsFile,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) !== false;
    }
}
