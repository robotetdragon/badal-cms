<?php

use PHPUnit\Framework\TestCase;

class ChaptersManagerTest extends TestCase
{
    private string $contentDir;

    protected function setUp(): void
    {
        $this->contentDir = TEST_FIXTURES_DIR . '/ch_' . uniqid();
        mkdir($this->contentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->contentDir);
    }

    // =========================================================================
    //  Existence and reading
    // =========================================================================

    public function testExistsReturnsFalseForMissing(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $this->assertFalse($cm->exists('inexistant'));
    }

    public function testGetReturnsEmptyArrayForMissing(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $this->assertSame([], $cm->get('inexistant'));
    }

    // =========================================================================
    //  saveFromText + get
    // =========================================================================

    public function testSaveFromTextParsesTimestamps(): void
    {
        $cm = new ChaptersManager($this->contentDir);

        $text = "00:00 Introduction\n05:30 Le concept\n1:12:00 Conclusion";
        $cm->saveFromText('ep1', $text);

        $chapters = $cm->get('ep1');

        $this->assertCount(3, $chapters);
        $this->assertSame(0, $chapters[0]['startTime']);
        $this->assertSame('Introduction', $chapters[0]['title']);
        $this->assertSame(330, $chapters[1]['startTime']);
        $this->assertSame('Le concept', $chapters[1]['title']);
        $this->assertSame(4320, $chapters[2]['startTime']);
    }

    public function testSaveFromTextParsesUrls(): void
    {
        $cm = new ChaptersManager($this->contentDir);

        $text = "00:00 Intro https://example.com\n05:30 Suite";
        $cm->saveFromText('ep2', $text);

        $chapters = $cm->get('ep2');

        $this->assertSame('https://example.com', $chapters[0]['url']);
        $this->assertArrayNotHasKey('url', $chapters[1]);
    }

    public function testSaveFromTextSortsByTime(): void
    {
        $cm = new ChaptersManager($this->contentDir);

        // Reversed order in the text
        $text = "10:00 Fin\n00:00 Début\n05:00 Milieu";
        $cm->saveFromText('ep3', $text);

        $chapters = $cm->get('ep3');
        $this->assertSame(0, $chapters[0]['startTime']);
        $this->assertSame(300, $chapters[1]['startTime']);
        $this->assertSame(600, $chapters[2]['startTime']);
    }

    public function testSaveFromTextEmptyDeletesFile(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $cm->saveFromText('ep4', "00:00 Intro");
        $this->assertTrue($cm->exists('ep4'));

        $cm->saveFromText('ep4', '');
        $this->assertFalse($cm->exists('ep4'));
    }

    // =========================================================================
    //  getText (editable format)
    // =========================================================================

    public function testGetTextReturnsEditableFormat(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $cm->saveFromText('ep5', "00:00 Intro\n05:30 Suite https://example.com");

        $text = $cm->getText('ep5');

        $this->assertStringContainsString('00:00 Intro', $text);
        $this->assertStringContainsString('05:30 Suite https://example.com', $text);
    }

    // =========================================================================
    //  Deletion
    // =========================================================================

    public function testDeleteRemovesChapters(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $cm->saveFromText('ep6', "00:00 Intro");
        $this->assertTrue($cm->exists('ep6'));

        $cm->delete('ep6');
        $this->assertFalse($cm->exists('ep6'));
    }

    public function testDeleteIsIdempotent(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $this->assertTrue($cm->delete('inexistant'));
    }

    // =========================================================================
    //  JSON and HTML output
    // =========================================================================

    public function testToJsonReturnsPodcasting20Format(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $cm->saveFromText('ep7', "00:00 Intro\n05:30 Suite");

        $json = $cm->toJson('ep7');
        $decoded = json_decode($json, true);

        $this->assertSame('1.2.0', $decoded['version']);
        $this->assertCount(2, $decoded['chapters']);
    }

    public function testToJsonReturnsEmptyForMissing(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $json = $cm->toJson('inexistant');
        $decoded = json_decode($json, true);

        $this->assertSame('1.2.0', $decoded['version']);
        $this->assertEmpty($decoded['chapters']);
    }

    public function testToHtmlGeneratesClickableTimestamps(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $cm->saveFromText('ep8', "00:00 Intro\n05:30 Suite");

        $html = $cm->toHtml('ep8');

        $this->assertStringContainsString('class="chapters-list"', $html);
        $this->assertStringContainsString('data-secs="0"', $html);
        $this->assertStringContainsString('data-secs="330"', $html);
        $this->assertStringContainsString('Intro', $html);
        $this->assertStringContainsString('Suite', $html);
    }

    public function testToHtmlReturnsEmptyStringForMissing(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $this->assertSame('', $cm->toHtml('inexistant'));
    }

    public function testToHtmlEscapesXss(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        // Inject a title with HTML
        $chaptersDir = $this->contentDir . '/chapters';
        file_put_contents($chaptersDir . '/xss.json', json_encode([
            ['startTime' => 0, 'title' => '<script>alert("xss")</script>']
        ]));

        $html = $cm->toHtml('xss');
        $this->assertStringNotContainsString('<script>', $html);
    }

    // =========================================================================
    //  Security — slug sanitization
    // =========================================================================

    public function testSlugSanitizationPreventsPathTraversal(): void
    {
        $cm = new ChaptersManager($this->contentDir);
        $cm->saveFromText('../../etc/passwd', "00:00 Hack");

        // The file must be created in chapters/ with a sanitized slug
        $this->assertFalse(file_exists($this->contentDir . '/../../etc/passwd.json'));
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
