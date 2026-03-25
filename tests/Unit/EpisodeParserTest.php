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
