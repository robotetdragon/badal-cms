<?php

use PHPUnit\Framework\TestCase;

class ThemeManagerTest extends TestCase
{
    private string $themesDir;

    protected function setUp(): void
    {
        $this->themesDir = TEST_FIXTURES_DIR . '/themes_' . uniqid();
        mkdir($this->themesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->themesDir);
    }

    // =========================================================================
    //  Loading
    // =========================================================================

    public function testLoadActiveUsesDefaultsIfFileMissing(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $tm->loadActive('inexistant');

        $defaults = $tm->getDefaults();
        $this->assertSame($defaults['color_bg'], $tm->get('color_bg'));
        $this->assertSame($defaults['font_heading'], $tm->get('font_heading'));
    }

    public function testLoadActiveMergesFileWithDefaults(): void
    {
        file_put_contents($this->themesDir . '/custom.json', json_encode([
            'name' => 'Custom',
            'color_accent' => '#ff0000',
        ]));

        $tm = new ThemeManager($this->themesDir);
        $tm->loadActive('custom');

        $this->assertSame('#ff0000', $tm->get('color_accent'));
        // Other keys remain at their defaults
        $this->assertSame($tm->getDefaults()['color_bg'], $tm->get('color_bg'));
    }

    public function testLoadFromArrayOverridesValues(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $tm->loadFromArray(['color_accent' => '#00ff00']);

        $this->assertSame('#00ff00', $tm->get('color_accent'));
    }

    public function testGetActiveSlug(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $tm->loadActive('nuit-bleue');

        $this->assertSame('nuit-bleue', $tm->getActiveSlug());
    }

    // =========================================================================
    //  Reading
    // =========================================================================

    public function testGetReturnsDefaultForUnknownKey(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $this->assertSame('fallback', $tm->get('inexistant', 'fallback'));
    }

    public function testGetAllReturnsFullTheme(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $all = $tm->getAll();

        $this->assertArrayHasKey('color_bg', $all);
        $this->assertArrayHasKey('font_heading', $all);
    }

    // =========================================================================
    //  Theme list
    // =========================================================================

    public function testListThemesReturnsAllJsonFiles(): void
    {
        file_put_contents($this->themesDir . '/sombre.json', json_encode(['name' => 'Sombre']));
        file_put_contents($this->themesDir . '/papier.json', json_encode(['name' => 'Papier']));

        $tm = new ThemeManager($this->themesDir);
        $list = $tm->listThemes();

        $this->assertCount(2, $list);
        $this->assertArrayHasKey('sombre', $list);
        $this->assertArrayHasKey('papier', $list);
        $this->assertSame('Sombre', $list['sombre']['name']);
    }

    // =========================================================================
    //  Saving
    // =========================================================================

    public function testSaveCreatesThemeFile(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $result = $tm->save('nouveau', ['name' => 'Nouveau', 'color_accent' => '#123456']);

        $this->assertTrue($result);
        $this->assertFileExists($this->themesDir . '/nouveau.json');

        $saved = json_decode(file_get_contents($this->themesDir . '/nouveau.json'), true);
        $this->assertSame('#123456', $saved['color_accent']);
    }

    public function testSaveIgnoresUnknownKeys(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $tm->save('test', ['name' => 'Test', 'unknown_key' => 'ignored']);

        $saved = json_decode(file_get_contents($this->themesDir . '/test.json'), true);
        $this->assertArrayNotHasKey('unknown_key', $saved);
    }

    public function testSaveUpdatesActiveTheme(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $tm->save('test', ['color_accent' => '#abcdef']);

        $this->assertSame('test', $tm->getActiveSlug());
        $this->assertSame('#abcdef', $tm->get('color_accent'));
    }

    // =========================================================================
    //  Deletion
    // =========================================================================

    public function testDeleteRemovesThemeFile(): void
    {
        file_put_contents($this->themesDir . '/custom.json', '{}');

        $tm = new ThemeManager($this->themesDir);
        $this->assertTrue($tm->delete('custom'));
        $this->assertFileDoesNotExist($this->themesDir . '/custom.json');
    }

    public function testDeleteRefusesToDeleteSombre(): void
    {
        file_put_contents($this->themesDir . '/sombre.json', '{}');

        $tm = new ThemeManager($this->themesDir);
        $this->assertFalse($tm->delete('sombre'));
        $this->assertFileExists($this->themesDir . '/sombre.json');
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $this->assertFalse($tm->delete('inexistant'));
    }

    // =========================================================================
    //  CSS et Google Fonts
    // =========================================================================

    public function testToCssVarsContainsAllVariables(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $css = $tm->toCssVars();

        $this->assertStringContainsString('--bg:', $css);
        $this->assertStringContainsString('--surface:', $css);
        $this->assertStringContainsString('--accent:', $css);
        $this->assertStringContainsString('--text:', $css);
        $this->assertStringContainsString('--font-heading:', $css);
        $this->assertStringContainsString('--font-body:', $css);
    }

    public function testToGoogleFontsUrlReturnsValidUrl(): void
    {
        $tm = new ThemeManager($this->themesDir);
        $url = $tm->toGoogleFontsUrl();

        $this->assertStringStartsWith('https://fonts.googleapis.com/css2?family=', $url);
        $this->assertStringContainsString('display=swap', $url);
    }

    // =========================================================================
    //  Static methods
    // =========================================================================

    public function testFontMapReturnsNonEmptyArray(): void
    {
        $map = ThemeManager::fontMap();

        $this->assertIsArray($map);
        $this->assertNotEmpty($map);
        $this->assertArrayHasKey('Syne', $map);
    }

    public function testFontListMatchesFontMapKeys(): void
    {
        $this->assertSame(array_keys(ThemeManager::fontMap()), ThemeManager::fontList());
    }

    public function testSocialNetworksReturnsExpectedKeys(): void
    {
        $networks = ThemeManager::socialNetworks();

        $this->assertArrayHasKey('instagram', $networks);
        $this->assertArrayHasKey('youtube', $networks);
        $this->assertArrayHasKey('spotify', $networks);
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
