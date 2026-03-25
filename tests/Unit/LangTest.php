<?php

use PHPUnit\Framework\TestCase;

class LangTest extends TestCase
{
    // =========================================================================
    //  Constants
    // =========================================================================

    public function testSupportedLangsContainsFourLanguages(): void
    {
        $this->assertCount(4, Lang::SUPPORTED_LANGS);
        $this->assertContains('fr', Lang::SUPPORTED_LANGS);
        $this->assertContains('en', Lang::SUPPORTED_LANGS);
        $this->assertContains('es', Lang::SUPPORTED_LANGS);
        $this->assertContains('pt', Lang::SUPPORTED_LANGS);
    }

    public function testDefaultLangIsFrench(): void
    {
        $this->assertSame('fr', Lang::DEFAULT_LANG);
    }

    public function testLabelsExistForAllSupportedLangs(): void
    {
        foreach (Lang::SUPPORTED_LANGS as $lang) {
            $this->assertArrayHasKey($lang, Lang::LABELS);
        }
    }

    // =========================================================================
    //  current()
    // =========================================================================

    public function testCurrentReturnsFrenchByDefault(): void
    {
        // Reset the session
        $_SESSION = [];
        $this->assertSame('fr', Lang::current());
    }

    public function testCurrentReturnsSessionLang(): void
    {
        $_SESSION['lang'] = 'en';
        $this->assertSame('en', Lang::current());
    }

    public function testCurrentFallsBackToDefaultForInvalidLang(): void
    {
        $_SESSION['lang'] = 'xx';
        $this->assertSame('fr', Lang::current());
    }

    // =========================================================================
    //  get() — translations
    // =========================================================================

    public function testGetReturnsKeyIfTranslationMissing(): void
    {
        $_SESSION['lang'] = 'fr';
        $this->assertSame('inexistant_key', Lang::get('inexistant_key'));
    }

    public function testGetReplacesPlaceholders(): void
    {
        $_SESSION['lang'] = 'fr';

        // Test the replacement mechanism with a key that returns the key itself
        $result = Lang::get('hello :name', [':name' => 'Jean']);
        // If the translation doesn't exist, the key is returned with replacements applied
        $this->assertSame('hello Jean', $result);
    }
}
