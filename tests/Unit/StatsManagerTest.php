<?php

use PHPUnit\Framework\TestCase;

class StatsManagerTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = TEST_FIXTURES_DIR . '/stats_' . uniqid();
        mkdir($this->configDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->configDir);
    }

    // =========================================================================
    //  recordPlay et getTotal
    // =========================================================================

    public function testRecordPlayIncrementsTotal(): void
    {
        $stats = new StatsManager($this->configDir);

        $stats->recordPlay('ep1');
        $stats->recordPlay('ep1');
        $stats->recordPlay('ep1');

        $this->assertSame(3, $stats->getTotal('ep1'));
    }

    public function testGetTotalReturnsZeroForUnknownEpisode(): void
    {
        $stats = new StatsManager($this->configDir);
        $this->assertSame(0, $stats->getTotal('inexistant'));
    }

    public function testRecordPlayPersistsToFile(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');

        // Reload from file
        $stats2 = new StatsManager($this->configDir);
        $this->assertSame(1, $stats2->getTotal('ep1'));
    }

    // =========================================================================
    //  Daily history
    // =========================================================================

    public function testGetDailyHistoryFillsZeros(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');

        $history = $stats->getDailyHistory('ep1', 7);

        $this->assertCount(7, $history);
        // Today must have 1
        $today = date('Y-m-d');
        $this->assertSame(1, $history[$today]);
        // Other days must be 0
        foreach ($history as $date => $count) {
            if ($date !== $today) {
                $this->assertSame(0, $count);
            }
        }
    }

    public function testGetDailyHistoryAllReturnsStoredDays(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');

        $history = $stats->getDailyHistoryAll('ep1');

        $this->assertArrayHasKey(date('Y-m-d'), $history);
        $this->assertSame(1, $history[date('Y-m-d')]);
    }

    // =========================================================================
    //  Global aggregations
    // =========================================================================

    public function testGetGrandTotal(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');
        $stats->recordPlay('ep1');
        $stats->recordPlay('ep2');

        $this->assertSame(3, $stats->getGrandTotal());
    }

    public function testGetRecentTotal(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');
        $stats->recordPlay('ep2');

        $this->assertSame(2, $stats->getRecentTotal(30));
    }

    public function testGetAllTotalsReturnsSortedDesc(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');
        $stats->recordPlay('ep2');
        $stats->recordPlay('ep2');
        $stats->recordPlay('ep2');

        $totals = $stats->getAllTotals();

        $slugs = array_keys($totals);
        $this->assertSame('ep2', $slugs[0]);
        $this->assertSame('ep1', $slugs[1]);
        $this->assertSame(3, $totals['ep2']);
        $this->assertSame(1, $totals['ep1']);
    }

    public function testGetRankingLimitsResults(): void
    {
        $stats = new StatsManager($this->configDir);
        for ($i = 1; $i <= 5; $i++) {
            $stats->recordPlay("ep{$i}");
        }

        $ranking = $stats->getRanking(3);
        $this->assertCount(3, $ranking);
    }

    // =========================================================================
    //  Global history
    // =========================================================================

    public function testGetGlobalDailyHistoryAggregatesAllEpisodes(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');
        $stats->recordPlay('ep2');
        $stats->recordPlay('ep2');

        $history = $stats->getGlobalDailyHistory(7);

        $this->assertCount(7, $history);
        $this->assertSame(3, $history[date('Y-m-d')]);
    }

    public function testGetGlobalDailyHistoryAllReturnsAllDates(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');

        $history = $stats->getGlobalDailyHistoryAll();

        $this->assertArrayHasKey(date('Y-m-d'), $history);
        $this->assertSame(1, $history[date('Y-m-d')]);
    }

    // =========================================================================
    //  Backup and restoration
    // =========================================================================

    public function testBackupIsCreatedOnSave(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');
        $stats->recordPlay('ep1');  // 2e appel crée le backup

        $this->assertFileExists($this->configDir . '/stats.json.backup');
    }

    public function testRestoresFromBackupIfMainFileMissing(): void
    {
        $stats = new StatsManager($this->configDir);
        $stats->recordPlay('ep1');
        $stats->recordPlay('ep1');

        // Delete the main file, keep the backup
        unlink($this->configDir . '/stats.json');
        $this->assertFileExists($this->configDir . '/stats.json.backup');

        $stats2 = new StatsManager($this->configDir);
        // The backup contains the state after the 1st recordPlay (before the 2nd)
        $this->assertSame(1, $stats2->getTotal('ep1'));
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
