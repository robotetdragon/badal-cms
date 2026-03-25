<?php

use PHPUnit\Framework\TestCase;

class RssGeneratorTest extends TestCase
{
    private array $baseConfig;
    private string $audioDir;

    protected function setUp(): void
    {
        $this->audioDir = TEST_FIXTURES_DIR . '/rss_audio_' . uniqid();
        mkdir($this->audioDir, 0755, true);

        $this->baseConfig = [
            'podcast_title'       => 'Mon Podcast',
            'podcast_description' => 'Description du podcast',
            'author'              => 'Jean Dupont',
            'email'               => 'jean@example.com',
            'language'            => 'fr-FR',
            'category'            => 'Technology',
            'base_url'            => 'https://example.com',
            'cover_image'         => 'cover.jpg',
            'redirect_feed_url'   => '',
        ];
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->audioDir);
    }

    // =========================================================================
    //  Structure XML de base
    // =========================================================================

    public function testGenerateReturnsValidXmlStructure(): void
    {
        $rss = new RssGenerator($this->baseConfig);
        $xml = $rss->generate([]);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<rss version="2.0"', $xml);
        $this->assertStringContainsString('<channel>', $xml);
        $this->assertStringContainsString('</channel>', $xml);
        $this->assertStringContainsString('</rss>', $xml);
    }

    public function testGenerateContainsNamespaces(): void
    {
        $rss = new RssGenerator($this->baseConfig);
        $xml = $rss->generate([]);

        $this->assertStringContainsString('xmlns:itunes=', $xml);
        $this->assertStringContainsString('xmlns:content=', $xml);
        $this->assertStringContainsString('xmlns:atom=', $xml);
        $this->assertStringContainsString('xmlns:podcast=', $xml);
        $this->assertStringContainsString('xmlns:googleplay=', $xml);
    }

    // =========================================================================
    //  Channel metadata
    // =========================================================================

    public function testGenerateContainsChannelMetadata(): void
    {
        $rss = new RssGenerator($this->baseConfig);
        $xml = $rss->generate([]);

        $this->assertStringContainsString('<title>Mon Podcast</title>', $xml);
        $this->assertStringContainsString('<description>Description du podcast</description>', $xml);
        $this->assertStringContainsString('<language>fr-FR</language>', $xml);
        $this->assertStringContainsString('<itunes:author>Jean Dupont</itunes:author>', $xml);
        $this->assertStringContainsString('<itunes:email>jean@example.com</itunes:email>', $xml);
    }

    public function testGenerateContainsCoverImage(): void
    {
        $rss = new RssGenerator($this->baseConfig);
        $xml = $rss->generate([]);

        $this->assertStringContainsString('<itunes:image href="https://example.com/audio/cover.jpg"/>', $xml);
    }

    public function testGenerateOmitsCoverImageIfEmpty(): void
    {
        $config = $this->baseConfig;
        $config['cover_image'] = '';

        $rss = new RssGenerator($config);
        $xml = $rss->generate([]);

        $this->assertStringNotContainsString('<itunes:image', $xml);
    }

    // =========================================================================
    //  Feed redirection
    // =========================================================================

    public function testGenerateContainsRedirectTagsWhenConfigured(): void
    {
        $config = $this->baseConfig;
        $config['redirect_feed_url'] = 'https://new-host.com/rss.xml';

        $rss = new RssGenerator($config);
        $xml = $rss->generate([]);

        $this->assertStringContainsString('<itunes:new-feed-url>', $xml);
        $this->assertStringContainsString('https://new-host.com/rss.xml', $xml);
        $this->assertStringContainsString('<podcast:previousUrl>', $xml);
    }

    public function testGenerateOmitsRedirectTagsWhenEmpty(): void
    {
        $rss = new RssGenerator($this->baseConfig);
        $xml = $rss->generate([]);

        $this->assertStringNotContainsString('<itunes:new-feed-url>', $xml);
    }

    // =========================================================================
    //  Items (episodes)
    // =========================================================================

    public function testGenerateIncludesEpisodesWithAudio(): void
    {
        // Create a dummy audio file so filesize() works
        mkdir($this->audioDir . '/ep1', 0755, true);
        file_put_contents($this->audioDir . '/ep1/audio.mp3', str_repeat('x', 1024));

        $config = $this->baseConfig;
        $config['audio_dir'] = $this->audioDir;

        $rss = new RssGenerator($config);
        $episodes = [[
            'slug'        => 'episode-1',
            'title'       => 'Premier épisode',
            'description' => 'Description de l\'épisode',
            'date'        => '2026-01-15',
            'audio'       => 'ep1/audio.mp3',
            'duration'    => '12:30',
            'episode'     => '1',
        ]];

        $xml = $rss->generate($episodes);

        $this->assertStringContainsString('<item>', $xml);
        $this->assertStringContainsString('<title>Premier épisode</title>', $xml);
        $this->assertStringContainsString('<itunes:duration>12:30</itunes:duration>', $xml);
        $this->assertStringContainsString('<itunes:episode>1</itunes:episode>', $xml);
    }

    public function testGenerateSkipsEpisodesWithoutAudio(): void
    {
        $rss = new RssGenerator($this->baseConfig);
        $episodes = [[
            'slug'  => 'no-audio',
            'title' => 'Sans audio',
            'date'  => '2026-01-15',
            'audio' => '',
        ]];

        $xml = $rss->generate($episodes);
        $this->assertStringNotContainsString('<item>', $xml);
    }

    // =========================================================================
    //  XML security
    // =========================================================================

    public function testGenerateEscapesXmlSpecialChars(): void
    {
        $config = $this->baseConfig;
        $config['podcast_title'] = 'Podcast <&> "Spécial"';

        $rss = new RssGenerator($config);
        $xml = $rss->generate([]);

        $this->assertStringNotContainsString('<&>', $xml);
        $this->assertStringContainsString('&amp;', $xml);
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
