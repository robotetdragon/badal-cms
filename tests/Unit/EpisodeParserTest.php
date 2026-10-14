<?php

use PHPUnit\Framework\TestCase;

class EpisodeParserTest extends TestCase
{
    private string $contentDir;

    protected function setUp(): void
    {
        $this->contentDir = TEST_FIXTURES_DIR . '/ep_' . uniqid();
        mkdir($this->contentDir . '/episodes', 0755, true);

        // Also create the config directory (for episodes_order.json)
        mkdir(dirname($this->contentDir) . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->contentDir);
        $configDir = dirname($this->contentDir) . '/config';
        if (is_dir($configDir)) {
            $this->recursiveDelete($configDir);
        }
    }

    // =========================================================================
    //  Frontmatter parsing
    // =========================================================================

    public function testParseFileReturnsFrontmatterAndBody(): void
    {
        $md = <<<'MD'
---
title: Mon épisode
date: 2026-01-15
duration: 12:30
episode: 1
audio: ep1.mp3
---

## Show notes

Contenu **gras** et *italique*.
MD;
        file_put_contents($this->contentDir . '/episodes/ep-1.md', $md);

        $parser = new EpisodeParser($this->contentDir);
        $ep = $parser->parseFile($this->contentDir . '/episodes/ep-1.md');

        $this->assertNotNull($ep);
        $this->assertSame('Mon épisode', $ep['title']);
        $this->assertSame('2026-01-15', $ep['date']);
        $this->assertSame('12:30', $ep['duration']);
        $this->assertSame('1', $ep['episode']);
        $this->assertSame('ep1.mp3', $ep['audio']);
        $this->assertSame('ep-1', $ep['slug']);
        $this->assertStringContainsString('<strong>gras</strong>', $ep['content_html']);
        $this->assertStringContainsString('<em>italique</em>', $ep['content_html']);
    }

    public function testParseFileWithoutFrontmatterReturnsBodAsIs(): void
    {
        file_put_contents(
            $this->contentDir . '/episodes/no-fm.md',
            "Just plain markdown content\n"
        );

        $parser = new EpisodeParser($this->contentDir);
        $ep = $parser->parseFile($this->contentDir . '/episodes/no-fm.md');

        $this->assertNotNull($ep);
        $this->assertSame('no-fm', $ep['slug']);
        $this->assertStringContainsString('Just plain markdown content', $ep['body']);
    }

    public function testParseFileReturnsNullForMissingFile(): void
    {
        $parser = new EpisodeParser($this->contentDir);
        $result = $parser->parseFile($this->contentDir . '/episodes/inexistant.md');

        $this->assertNull($result);
    }

    // =========================================================================
    //  Markdown → HTML
    // =========================================================================

    public function testMarkdownHeadingsConvertedToHtml(): void
    {
        $md = "---\ntitle: Test\n---\n\n# Titre 1\n\n## Titre 2\n\n### Titre 3";
        file_put_contents($this->contentDir . '/episodes/headings.md', $md);

        $parser = new EpisodeParser($this->contentDir);
        $ep = $parser->parseFile($this->contentDir . '/episodes/headings.md');

        $this->assertStringContainsString('<h1>Titre 1</h1>', $ep['content_html']);
        $this->assertStringContainsString('<h2>Titre 2</h2>', $ep['content_html']);
        $this->assertStringContainsString('<h3>Titre 3</h3>', $ep['content_html']);
    }

    public function testMarkdownLinksConvertedToHtml(): void
    {
        $md = "---\ntitle: Test\n---\n\n[Mon lien](https://example.com)";
        file_put_contents($this->contentDir . '/episodes/links.md', $md);

        $parser = new EpisodeParser($this->contentDir);
        $ep = $parser->parseFile($this->contentDir . '/episodes/links.md');

        $this->assertStringContainsString(
            '<a href="https://example.com">Mon lien</a>',
            $ep['content_html']
        );
    }

    public function testMarkdownXssIsSanitized(): void
    {
        $md = "---\ntitle: Test\n---\n\n<script>alert('xss')</script>";
        file_put_contents($this->contentDir . '/episodes/xss.md', $md);

        $parser = new EpisodeParser($this->contentDir);
        $ep = $parser->parseFile($this->contentDir . '/episodes/xss.md');

        $this->assertStringNotContainsString('<script>', $ep['content_html']);
    }

    /**
     * @dataProvider dangerousLinks
     */
    public function testDangerousLinkSchemesAreStripped(string $link): void
    {
        $md = "---\ntitle: Test\n---\n\n[clic]($link)";
        file_put_contents($this->contentDir . '/episodes/danger.md', $md);

        $parser = new EpisodeParser($this->contentDir);
        $ep = $parser->parseFile($this->contentDir . '/episodes/danger.md');

        $this->assertStringNotContainsString('<a ', $ep['content_html']);
        $this->assertStringContainsString('clic', $ep['content_html']);
    }

    public function dangerousLinks(): array
    {
        return [
            'plain'            => ['javascript:alert(1)'],
            'uppercase'        => ['JavaScript:alert(1)'],
            'leading space'    => [' javascript:alert(1)'],
            'tab inside'       => ["java\tscript:alert(1)"],
            'data uri'         => ['data:text/html,x'],
            'vbscript'         => ['vbscript:x'],
        ];
    }

    public function testSafeLinksAreKept(): void
    {
        $md = "---\ntitle: Test\n---\n\n[a](mailto:me@example.com) [b](/episodes/x) [c](#top) [d]( https://example.com )";
        file_put_contents($this->contentDir . '/episodes/safe.md', $md);

        $parser = new EpisodeParser($this->contentDir);
        $html   = $parser->parseFile($this->contentDir . '/episodes/safe.md')['content_html'];

        $this->assertStringContainsString('<a href="mailto:me@example.com">a</a>', $html);
        $this->assertStringContainsString('<a href="/episodes/x">b</a>', $html);
        $this->assertStringContainsString('<a href="#top">c</a>', $html);
        $this->assertStringContainsString('<a href="https://example.com">d</a>', $html);
    }

    // =========================================================================
    //  Episode CRUD
    // =========================================================================

    public function testGetBySlugReturnsEpisode(): void
    {
        $md = "---\ntitle: Episode trouvé\ndate: 2026-03-01\n---\n\nBody";
        file_put_contents($this->contentDir . '/episodes/mon-slug.md', $md);

        $parser = new EpisodeParser($this->contentDir);
        $ep = $parser->getBySlug('mon-slug');

        $this->assertNotNull($ep);
        $this->assertSame('Episode trouvé', $ep['title']);
    }

    public function testGetBySlugReturnsNullForMissing(): void
    {
        $parser = new EpisodeParser($this->contentDir);
        $this->assertNull($parser->getBySlug('inexistant'));
    }

    public function testSaveCreatesEpisodeFile(): void
    {
        $parser = new EpisodeParser($this->contentDir);
        $result = $parser->save('nouveau', ['title' => 'Nouveau', 'date' => '2026-06-01'], 'Mon contenu');

        $this->assertTrue($result);
        $this->assertFileExists($this->contentDir . '/episodes/nouveau.md');

        $ep = $parser->getBySlug('nouveau');
        $this->assertSame('Nouveau', $ep['title']);
        $this->assertStringContainsString('Mon contenu', $ep['body']);
    }

    public function testSaveSanitizesSlug(): void
    {
        $parser = new EpisodeParser($this->contentDir);
        $parser->save('Mon Épisode #12!', ['title' => 'Test'], 'Body');

        $this->assertFileExists($this->contentDir . '/episodes/mon-episode-12.md');
    }

    public function testSaveQuotesYamlSpecialChars(): void
    {
        $parser = new EpisodeParser($this->contentDir);
        $parser->save('special', ['title' => 'Episode: The Sequel'], 'Body');

        $raw = file_get_contents($this->contentDir . '/episodes/special.md');
        $this->assertStringContainsString('"Episode: The Sequel"', $raw);
    }

    public function testDeleteRemovesFile(): void
    {
        file_put_contents($this->contentDir . '/episodes/to-delete.md', "---\ntitle: X\n---\n\n");

        $parser = new EpisodeParser($this->contentDir);
        $this->assertTrue($parser->delete('to-delete'));
        $this->assertFileDoesNotExist($this->contentDir . '/episodes/to-delete.md');
    }

    public function testDeleteReturnsTrueForMissingFile(): void
    {
        $parser = new EpisodeParser($this->contentDir);
        $this->assertTrue($parser->delete('inexistant'));
    }

    // =========================================================================
    //  Sorting and ordering
    // =========================================================================

    public function testGetAllSortsByDateByDefault(): void
    {
        file_put_contents($this->contentDir . '/episodes/old.md', "---\ntitle: Old\ndate: 2025-01-01\n---\n\n");
        file_put_contents($this->contentDir . '/episodes/new.md', "---\ntitle: New\ndate: 2026-06-01\n---\n\n");

        $parser = new EpisodeParser($this->contentDir);
        $all = $parser->getAll();

        $this->assertCount(2, $all);
        $this->assertSame('new', $all[0]['slug']);
        $this->assertSame('old', $all[1]['slug']);
    }

    public function testGetAllRespectsCustomOrder(): void
    {
        file_put_contents($this->contentDir . '/episodes/a.md', "---\ntitle: A\ndate: 2026-01-01\n---\n\n");
        file_put_contents($this->contentDir . '/episodes/b.md', "---\ntitle: B\ndate: 2026-06-01\n---\n\n");

        // Write custom order (b is more recent, but we want a first)
        $configDir = dirname($this->contentDir) . '/config';
        file_put_contents($configDir . '/episodes_order.json', json_encode(['a', 'b']));

        $parser = new EpisodeParser($this->contentDir);
        $all = $parser->getAll();

        $this->assertSame('a', $all[0]['slug']);
        $this->assertSame('b', $all[1]['slug']);
    }

    public function testSaveOrderAndResetOrder(): void
    {
        $parser = new EpisodeParser($this->contentDir);

        $this->assertTrue($parser->saveOrder(['b', 'a']));
        $orderFile = dirname($this->contentDir) . '/config/episodes_order.json';
        $this->assertFileExists($orderFile);

        $this->assertTrue($parser->resetOrder());
        $this->assertFileDoesNotExist($orderFile);
    }

    // =========================================================================
    //  Slug safety
    // =========================================================================

    public function testIsValidSlug(): void
    {
        $this->assertTrue(EpisodeParser::isValidSlug('mon-episode-12'));
        $this->assertFalse(EpisodeParser::isValidSlug(''));
        $this->assertFalse(EpisodeParser::isValidSlug('../config/x'));
        $this->assertFalse(EpisodeParser::isValidSlug('Mon Episode'));
        $this->assertFalse(EpisodeParser::isValidSlug('a/b'));
    }

    public function testSanitizeSlug(): void
    {
        $this->assertSame('mon-episode-12', EpisodeParser::sanitizeSlug('Mon Épisode #12!'));
        $this->assertSame('config', EpisodeParser::sanitizeSlug('../config'));
        $this->assertSame('', EpisodeParser::sanitizeSlug('!!!'));
    }

    public function testGetBySlugRejectsTraversal(): void
    {
        file_put_contents(dirname($this->contentDir) . '/secret.md', "---
title: x
---
secret");
        $parser = new EpisodeParser($this->contentDir);

        $this->assertNull($parser->getBySlug('../../secret'));
        $this->assertFalse($parser->delete('../../secret'));
        $this->assertFileExists(dirname($this->contentDir) . '/secret.md');

        unlink(dirname($this->contentDir) . '/secret.md');
    }

    public function testExists(): void
    {
        $parser = new EpisodeParser($this->contentDir);
        $parser->save('ep-one', ['title' => 'One'], '');

        $this->assertTrue($parser->exists('ep-one'));
        $this->assertFalse($parser->exists('ep-two'));
        $this->assertFalse($parser->exists('../ep-one'));
    }

    public function testSaveWithEmptySlugFails(): void
    {
        $parser = new EpisodeParser($this->contentDir);
        $this->assertFalse($parser->save('!!!', ['title' => 'x'], ''));
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
