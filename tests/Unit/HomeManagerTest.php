<?php

use PHPUnit\Framework\TestCase;

class HomeManagerTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = TEST_FIXTURES_DIR . '/home_' . uniqid();
        mkdir($this->configDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->configDir);
    }

    // =========================================================================
    //  Default loading
    // =========================================================================

    public function testDefaultsLoadedWhenNoFile(): void
    {
        $hm = new HomeManager($this->configDir);
        $defaults = $hm->getDefaults();

        $this->assertSame($defaults['active_theme'], $hm->get('active_theme'));
        $this->assertSame('sombre', $hm->get('active_theme'));
    }

    public function testGetReturnsDefaultForUnknownKey(): void
    {
        $hm = new HomeManager($this->configDir);
        $this->assertSame('fallback', $hm->get('inexistant', 'fallback'));
    }

    public function testGetAllReturnsCompleteConfig(): void
    {
        $hm = new HomeManager($this->configDir);
        $all = $hm->getAll();

        $this->assertArrayHasKey('active_theme', $all);
        $this->assertArrayHasKey('home_tagline', $all);
        $this->assertArrayHasKey('social_instagram', $all);
    }

    // =========================================================================
    //  Loading from existing file
    // =========================================================================

    public function testLoadsExistingFile(): void
    {
        file_put_contents($this->configDir . '/home.json', json_encode([
            'home_tagline' => 'Mon podcast',
            'active_theme' => 'papier',
        ]));

        $hm = new HomeManager($this->configDir);

        $this->assertSame('Mon podcast', $hm->get('home_tagline'));
        $this->assertSame('papier', $hm->get('active_theme'));
    }

    public function testMergesWithDefaultsOnLoad(): void
    {
        file_put_contents($this->configDir . '/home.json', json_encode([
            'home_tagline' => 'Custom',
        ]));

        $hm = new HomeManager($this->configDir);

        // The custom value is loaded
        $this->assertSame('Custom', $hm->get('home_tagline'));
        // Default values remain for missing keys
        $this->assertSame('sombre', $hm->get('active_theme'));
    }

    // =========================================================================
    //  Saving
    // =========================================================================

    public function testSaveWritesFile(): void
    {
        $hm = new HomeManager($this->configDir);
        $result = $hm->save(['home_tagline' => 'Nouveau tagline']);

        $this->assertTrue($result);
        $this->assertFileExists($this->configDir . '/home.json');

        $saved = json_decode(file_get_contents($this->configDir . '/home.json'), true);
        $this->assertSame('Nouveau tagline', $saved['home_tagline']);
    }

    public function testSavePreservesExistingValues(): void
    {
        $hm = new HomeManager($this->configDir);
        $hm->save(['home_tagline' => 'Premier']);
        $hm->save(['active_theme' => 'papier']);

        $this->assertSame('Premier', $hm->get('home_tagline'));
        $this->assertSame('papier', $hm->get('active_theme'));
    }

    public function testSaveUpdatesInMemoryState(): void
    {
        $hm = new HomeManager($this->configDir);
        $hm->save(['home_tagline' => 'Updated']);

        $this->assertSame('Updated', $hm->get('home_tagline'));
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
