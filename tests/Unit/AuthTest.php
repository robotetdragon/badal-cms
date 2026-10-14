<?php

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    private string $rootDir;
    private array  $config;

    protected function setUp(): void
    {
        $this->rootDir = TEST_FIXTURES_DIR . '/auth_' . uniqid();
        mkdir($this->rootDir . '/content', 0755, true);
        mkdir($this->rootDir . '/config', 0755, true);

        $this->config = [
            'content_dir'         => $this->rootDir . '/content',
            'admin_username'      => 'admin',
            'admin_password_hash' => password_hash('oldpass123', PASSWORD_BCRYPT, ['cost' => 4]),
        ];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['REMOTE_ADDR']     = '203.0.113.5';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->recursiveDelete($this->rootDir);
    }

    // =========================================================================
    //  Session lifecycle
    // =========================================================================

    public function testLoginOpensValidSession(): void
    {
        $auth = new Auth($this->config);

        $this->assertTrue($auth->login('admin', 'oldpass123'));
        $this->assertTrue($auth->isLoggedIn());
    }

    public function testWrongPasswordIsRejected(): void
    {
        $auth = new Auth($this->config);

        $this->assertFalse($auth->login('admin', 'nope'));
        $this->assertFalse($auth->isLoggedIn());
    }

    public function testPasswordChangeInvalidatesExistingSessions(): void
    {
        (new Auth($this->config))->login('admin', 'oldpass123');

        // Password changed elsewhere: the next request loads the new config
        $newConfig = $this->config;
        $newConfig['admin_password_hash'] = password_hash('newpass123', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertFalse((new Auth($newConfig))->isLoggedIn());
    }

    public function testStartSessionWithNewHashStaysLoggedIn(): void
    {
        $newConfig = $this->config;
        $newConfig['admin_password_hash'] = password_hash('newpass123', PASSWORD_BCRYPT, ['cost' => 4]);

        $auth = new Auth($newConfig);
        $auth->startSession('admin');

        $this->assertTrue($auth->isLoggedIn());
    }

    public function testSessionWithoutFingerprintIsRejected(): void
    {
        $_SESSION['badal_auth'] = [
            'user'    => 'admin',
            'time'    => time(),
            'ip'      => '203.0.113.5',
            'ua_hash' => hash('sha256', 'PHPUnit'),
        ];

        $this->assertFalse((new Auth($this->config))->isLoggedIn());
    }

    public function testUserAgentChangeInvalidatesSession(): void
    {
        $auth = new Auth($this->config);
        $auth->startSession('admin');

        $_SERVER['HTTP_USER_AGENT'] = 'Other browser';

        $this->assertFalse($auth->isLoggedIn());
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
