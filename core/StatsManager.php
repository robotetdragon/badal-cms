<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  core/StatsManager.php — Per-episode listen tracking
//
//  Storage: a single JSON file in config/stats.json.
//  JSON structure:
//  {
//    "mon-episode": {
//      "total": 142,
//      "daily": {
//        "2026-03-01": 12,
//        "2026-03-02": 8,
//        ...
//      }
//    },
//    ...
//  }
//
//  The daily history is kept on a 90-day rolling window.
//  Older entries are automatically purged on each recordPlay().
//
//  Counting is triggered by public/stats-record.php, called by the player
//  JS on actual play events (deduplicated per IP / episode / 24 h).
//
//  Usage:
//      $stats = new StatsManager($configDir);
//      $stats->recordPlay('mon-episode');
//      echo $stats->getGrandTotal();
// ═══════════════════════════════════════════════════════════════════════════════

class StatsManager {

    /** Number of days kept in the daily history */
    private const HISTORY_DAYS = 90;

    /** Path to the stats JSON file */
    private string $statsFile;

    /**
     * Data loaded in memory.
     * Structure: [ slug => [ 'total' => int, 'daily' => [ 'Y-m-d' => int ] ] ]
     */
    private array $data;

    /**
     * Initializes the stats manager and loads persisted data.
     *
     * @param string $configDir  Path to the configuration directory
     */
    public function __construct(string $configDir) {
        $this->statsFile = rtrim($configDir, '/') . '/stats.json';
        $this->load();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  RECORDING
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Records a listen for a given episode.
     *
     * The whole read-modify-write runs under an exclusive lock on stats.json,
     * so concurrent plays are never lost. If the file cannot be decoded, it is
     * left untouched (never overwritten with a near-empty dataset).
     *
     * Actions:
     *   1. Initializes the structure if this is the first play of this episode
     *   2. Increments the total counter
     *   3. Increments the current day counter
     *   4. Purges daily entries older than HISTORY_DAYS days
     *   5. Persists to disk (previous content kept as stats.json.backup)
     *
     * @param  string $slug  Episode identifier (e.g. "mon-episode-12")
     * @return bool          true if the save succeeded
     */
    public function recordPlay(string $slug): bool {
        // Recover from a deleted main file before creating an empty one
        if (!file_exists($this->statsFile) && file_exists($this->statsFile . '.backup')) {
            @copy($this->statsFile . '.backup', $this->statsFile);
        }

        $fp = @fopen($this->statsFile, 'c+');
        if (!$fp) {
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }

            $raw  = (string) stream_get_contents($fp);
            $data = trim($raw) === '' ? [] : json_decode($raw, true);
            if (!is_array($data)) {
                return false;
            }

            $today = date('Y-m-d');

            // Structure initialization for a new episode
            if (!isset($data[$slug])) {
                $data[$slug] = ['total' => 0, 'daily' => []];
            }

            $data[$slug]['total']++;
            $data[$slug]['daily'][$today] = ($data[$slug]['daily'][$today] ?? 0) + 1;

            // History purge: keep only the last HISTORY_DAYS days
            // krsort() sorts by descending date, array_slice() truncates
            krsort($data[$slug]['daily']);
            $data[$slug]['daily'] = array_slice($data[$slug]['daily'], 0, self::HISTORY_DAYS, true);

            // Rolling backup of the last known-good content
            if (trim($raw) !== '') {
                @file_put_contents($this->statsFile . '.backup', $raw, LOCK_EX);
            }

            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }

            ftruncate($fp, 0);
            rewind($fp);
            $ok = fwrite($fp, $json) === strlen($json);
            fflush($fp);

            $this->data = $data;
            return $ok;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  READING — INDIVIDUAL EPISODE
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Returns the total number of listens for an episode.
     * Returns 0 if the episode has never been listened to.
     *
     * @param  string $slug  Episode identifier
     * @return int           Total listen count
     */
    public function getTotal(string $slug): int {
        return $this->data[$slug]['total'] ?? 0;
    }

    /**
     * Returns the daily history of an episode over the last $days days.
     * Days without listens are included with value 0 (complete array).
     *
     * @param  string              $slug  Episode identifier
     * @param  int                 $days  Number of days to return (default: 30)
     * @return array<string, int>         [ 'Y-m-d' => count, ... ]
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

    /**
     * Returns the daily history of an episode over its entire duration.
     *
     * @param  string              $slug  Episode identifier
     * @return array<string, int>         [ 'Y-m-d' => count, ... ]
     */
    public function getDailyHistoryAll(string $slug): array {
        $daily = $this->data[$slug]['daily'] ?? [];
        ksort($daily);
        return $daily;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  READING — GLOBAL AGGREGATIONS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Returns the total listens across all episodes, all time.
     *
     * @return int  Grand total of all listens
     */
    public function getGrandTotal(): int {
        return array_sum(array_column($this->data, 'total'));
    }

    /**
     * Returns the total listens over the last $days days, all episodes.
     *
     * @param  int $days  Time window in days
     * @return int        Total number of listens over the period
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
     * Returns totals per episode, sorted from most listened to least.
     *
     * @return array<string, int>  [ 'slug' => total, ... ]
     */
    public function getAllTotals(): array {
        $totals = array_map(fn($info) => $info['total'] ?? 0, $this->data);
        arsort($totals);
        return $totals;
    }

    /**
     * Returns the ranking of the $limit most listened episodes.
     *
     * @param  int                 $limit  Number of episodes to return
     * @return array<string, int>          [ 'slug' => total, ... ]
     */
    public function getRanking(int $limit = 10): array {
        return array_slice($this->getAllTotals(), 0, $limit, true);
    }

    /**
     * Returns the aggregated daily history across all episodes.
     * Useful for the Statistics page chart.
     *
     * Days without any listens are included with value 0.
     *
     * @param  int                 $days  Number of days to return
     * @return array<string, int>         [ 'Y-m-d' => total, ... ]
     */
    public function getGlobalDailyHistory(int $days = 30): array {
        // Initialize the array with 0s for each day
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $result[date('Y-m-d', strtotime("-{$i} days"))] = 0;
        }

        // Sum the listens from each episode
        foreach ($this->data as $info) {
            foreach ($info['daily'] ?? [] as $date => $count) {
                if (isset($result[$date])) {
                    $result[$date] += $count;
                }
            }
        }

        return $result;
    }

    /**
     * Returns the complete daily history (all dates).
     * Used for the "All" view in statistics.
     *
     * @return array<string, int>  [ 'Y-m-d' => total, ... ] sorted by date
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

    // ═══════════════════════════════════════════════════════════════════════════
    //  PERSISTENCE (PRIVATE)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Loads data from the JSON file (shared lock). Restores from backup if absent.
     *
     * @return void
     */
    private function load(): void {
        $this->data = [];

        // If the main file was deleted, restore from backup
        if (!file_exists($this->statsFile)) {
            $backup = $this->statsFile . '.backup';
            if (!file_exists($backup)) {
                return;
            }
            copy($backup, $this->statsFile);
        }

        $fp = @fopen($this->statsFile, 'r');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $decoded    = json_decode($raw ?: '{}', true);
        $this->data = is_array($decoded) ? $decoded : [];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  MAINTENANCE
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Purges expired deduplication files (older than 24h).
     * Called periodically to avoid accumulating stale files.
     *
     * @param  string $configDir  Path to the configuration directory
     * @return void
     */
    public static function purgeDedupeFiles(string $configDir): void {
        $dir = rtrim($configDir, '/') . '/stats-dedupe';
        if (!is_dir($dir)) {
            return;
        }

        $now = time();
        foreach (glob($dir . '/*.json') as $file) {
            $ts = (int) @file_get_contents($file);
            if ($now - $ts > 86400) {
                @unlink($file);
            }
        }
    }
}
