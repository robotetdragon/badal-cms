<?php

use PHPUnit\Framework\TestCase;

class TranscriptManagerTest extends TestCase
{
    private string $contentDir;

    protected function setUp(): void
    {
        $this->contentDir = TEST_FIXTURES_DIR . '/tr_' . uniqid();
        mkdir($this->contentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->contentDir);
    }

    // =========================================================================
    //  Basic CRUD
    // =========================================================================

    public function testExistsReturnsFalseForMissing(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $this->assertFalse($tm->exists('inexistant'));
    }

    public function testSaveAndGet(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $text = "HOST: Bonjour\nINVITÉ: Salut !";

        $this->assertTrue($tm->save('ep1', $text));
        $this->assertTrue($tm->exists('ep1'));
        $this->assertSame($text, $tm->get('ep1'));
    }

    public function testGetReturnsEmptyForMissing(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $this->assertSame('', $tm->get('inexistant'));
    }

    public function testDeleteRemovesTranscript(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $tm->save('ep2', 'Contenu');
        $this->assertTrue($tm->exists('ep2'));

        $this->assertTrue($tm->delete('ep2'));
        $this->assertFalse($tm->exists('ep2'));
    }

    public function testDeleteIsIdempotent(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $this->assertTrue($tm->delete('inexistant'));
    }

    // =========================================================================
    //  toHtml — speakers
    // =========================================================================

    public function testToHtmlDetectsSpeakers(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $tm->save('speakers', "HOST: Bonjour\nINVITÉ: Merci !");

        $html = $tm->toHtml('speakers');

        $this->assertStringContainsString('<strong class="speaker">HOST</strong>', $html);
        $this->assertStringContainsString('<strong class="speaker">INVITÉ</strong>', $html);
        $this->assertStringContainsString('Bonjour', $html);
        $this->assertStringContainsString('Merci !', $html);
    }

    // =========================================================================
    //  toHtml — timestamps
    // =========================================================================

    public function testToHtmlConvertsTimestamps(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $tm->save('ts', "[00:05] Début\n[1:12:34] Suite");

        $html = $tm->toHtml('ts');

        $this->assertStringContainsString('data-secs="5"', $html);
        $this->assertStringContainsString('data-secs="4354"', $html);
        $this->assertStringContainsString('class="ts-link"', $html);
    }

    // =========================================================================
    //  toHtml — paragraphs
    // =========================================================================

    public function testToHtmlGroupsLinesIntoParagraphs(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $tm->save('para', "Ligne un\nLigne deux\n\nNouveau paragraphe");

        $html = $tm->toHtml('para');

        // Two distinct paragraphs
        $this->assertSame(2, substr_count($html, '<p>'));
        $this->assertStringContainsString('Ligne un Ligne deux', $html);
        $this->assertStringContainsString('Nouveau paragraphe', $html);
    }

    public function testToHtmlReturnsEmptyForEmptyTranscript(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $tm->save('empty', '   ');

        $this->assertSame('', $tm->toHtml('empty'));
    }

    // =========================================================================
    //  Security
    // =========================================================================

    public function testToHtmlEscapesXss(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $tm->save('xss', '<script>alert("xss")</script>');

        $html = $tm->toHtml('xss');
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testSlugSanitizationPreventsPathTraversal(): void
    {
        $tm = new TranscriptManager($this->contentDir);
        $tm->save('../../etc/passwd', 'hack');

        $this->assertFalse(file_exists($this->contentDir . '/../../etc/passwd.txt'));
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
