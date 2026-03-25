<?php
// =============================================================================
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
//  Counting is triggered by public/audio.php which intercepts audio
//  requests and only records initial listens (Range: bytes=0-).
//
//  Usage:
//      $stats = new StatsManager($configDir);
//      $stats->recordPlay('mon-episode');
//      echo $stats->getGrandTotal();
// =============================================================================

class StatsManager {

    /** Number of days kept in the daily history */
    private const HISTORY_DAYS = 90;

    private string $statsFile;

    /**
     * Data loaded in memory.
     * Structure: [ slug => [ 'total' => int, 'daily' => [ 'Y-m-d' => int ] ] ]
     */
    private array $data;

    public function __construct(string $configDir) {
        $this->statsFile = rtrim($configDir, '/') . '/stats.json';
        $this->load();
    }

    // =========================================================================
    //  Recording
    // =========================================================================

    /**
     * Records a listen for a given episode.
     *
     * Actions:
     *   1. Initializes the structure if this is the first play of this episode
     *   2. Increments the total counter
     *   3. Increments the current day counter
     *   4. Purges daily entries older than HISTORY_DAYS days
     *   5. Persists to disk
     *
     * @param  string $slug  Episode identifier (e.g. "mon-episode-12")
     * @return bool          true if the save succeeded
     */
    public function recordPlay(string $slug): bool {
        $today = date('Y-m-d');

        // Structure initialization for a new episode
        if (!isset($this->data[$slug])) {
            $this->data[$slug] = ['total' => 0, 'daily' => []];
        }

        $this->data[$slug]['total']++;
        $this->data[$slug]['daily'][$today] = ($this->data[$slug]['daily'][$today] ?? 0) + 1;

        // History purge: keep only the last HISTORY_DAYS days
        // krsort() sorts by descending date, array_slice() truncates
        $daily = &$this->data[$slug]['daily'];
        krsort($daily);
        $daily = array_slice($daily, 0, self::HISTORY_DAYS, true);

        return $this->save();
    }

    // =========================================================================
    //  Reading — individual episode
    // =========================================================================

    /**
     * Returns the total number of listens for an episode.
     * Returns 0 if the episode has never been listened to.
     */
    public function getTotal(string $slug): int {
        return $this->data[$slug]['total'] ?? 0;
    }

    /**
     * Returns the daily history of an episode over the last $days days.
     * Days without listens are included with value 0 (complete array).
     *
     * @param  int                   $days  Number of days to return (default: 30)
     * @return array<string, int>           [ 'Y-m-d' => count, ... ]
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
    //  Reading — global aggregations
    // =========================================================================

    /**
     * Returns the total listens across all episodes, all time.
     */
    public function getGrandTotal(): int {
        return array_sum(array_column($this->data, 'total'));
    }

    /**
     * Returns the total listens over the last $days days, all episodes.
     *
     * @param  int  $days  Time window in days
     * @return int         Total number of listens over the period
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
     * @param  int                  $limit  Number of episodes to return
     * @return array<string, int>           [ 'slug' => total, ... ]
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
     * @param  int                  $days  Number of days to return
     * @return array<string, int>          [ 'Y-m-d' => total, ... ]
     */

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

    /**
     * Returns the daily history of an episode over its entire duration.
     *
     * @param  string               $slug  Episode identifier
     * @return array<string, int>          [ 'Y-m-d' => count, ... ]
     */
    public function getDailyHistoryAll(string $slug): array {
        $daily = $this->data[$slug]['daily'] ?? [];
        ksort($daily);
        return $daily;
    }

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

    // =========================================================================
    //  Persistence (private)
    // =========================================================================

    /** Loads data from the JSON file. Restores from backup if absent. */
    private function load(): void {
        // If the main file was deleted, restore from backup
        if (!file_exists($this->statsFile)) {
            $backup = $this->statsFile . '.backup';
            if (file_exists($backup)) {
                copy($backup, $this->statsFile);
            } else {
                $this->data = [];
                return;
            }
        }

        $raw         = file_get_contents($this->statsFile);
        $decoded     = json_decode($raw ?: '{}', true);
        $this->data  = is_array($decoded) ? $decoded : [];
    }

    /** Persists data as indented JSON with write lock. */
    private function save(): bool {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Rolling backup: stats.backup.json (overwritten on each save)
        if (file_exists($this->statsFile)) {
            copy($this->statsFile, $this->statsFile . '.backup');
        }

        return file_put_contents($this->statsFile, $json, LOCK_EX) !== false;
    }
}
