<?php
// =============================================================================
//  core/Auth.php — Authentication and admin session management
//
//  Responsibilities:
//    - Verify credentials against the bcrypt hash from config.php
//    - Create / invalidate the PHP session after login / logout
//    - Validate the session on each request (expiry, UA fingerprint, IP)
//    - Delegate rate-limiting and logging to Security
//
//  Typical usage:
//      $auth = new Auth($config);
//      $auth->requireLogin();   // redirects if not logged in
// =============================================================================

class Auth {

    // Key used in $_SESSION to store admin session data
    private const SESSION_KEY = 'badal_auth';

    // Maximum inactivity duration before automatic expiration (8 hours)
    private const SESSION_MAX_AGE = 8 * 3600;

    private array    $config;
    private Security $security;

    public function __construct(array $config) {
        $this->config   = $config;
        $this->security = new Security($this->configDir());
        // Session already configured and started by bootstrap.php
    }

    // =========================================================================
    //  Login / logout
    // =========================================================================

    /**
     * Attempts a login with the provided credentials.
     *
     * Verification order:
     *   1. IP rate-limit (blocks if too many attempts)
     *   2. Username comparison + password_verify (bcrypt hash)
     *   3. On success: session ID regeneration + log
     *   4. On failure: attempt recording + log
     *
     * @return bool  true if login succeeded, false otherwise
     */
    public function login(string $username, string $password): bool {
        $ip       = Security::clientIp();
        $security = $this->security;

        // 1. Rate-limit check before testing the password
        $rate = $security->checkRateLimit($ip);
        if ($rate['locked']) {
            $wait = (int) ceil($rate['wait_seconds'] / 60);
            $security->log('lockout', "user=$username wait={$wait}min");
            return false;
        }

        $validUser = $this->config['admin_username']      ?? 'admin';
        $validHash = $this->config['admin_password_hash'] ?? '';

        // 2. Credentials verification
        if ($username !== $validUser || !password_verify($password, $validHash)) {
            // Failure: record the attempt and log
            $security->recordFailedAttempt($ip);
            $remaining = $security->checkRateLimit($ip)['remaining'];
            $security->log('login_fail', "user=$username remaining=$remaining");
            return false;
        }

        // 3. Success — prevent session fixation before writing to it
        Security::regenerateSession();

        $_SESSION[self::SESSION_KEY] = [
            'user'    => $username,
            'time'    => time(),
            'ip'      => $ip,
            // User-Agent fingerprint: invalidates the session if the browser changes
            'ua_hash' => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
        ];

        $security->clearAttempts($ip);
        $security->log('login_ok', "user=$username");

        // Flag if the hash should be rehashed with a higher cost
        if (password_needs_rehash($validHash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $security->log('rehash_needed', "user=$username");
        }

        return true;
    }

    /**
     * Logs out the user: removes session data and destroys it.
     */
    public function logout(): void {
        $user = $_SESSION[self::SESSION_KEY]['user'] ?? 'unknown';
        $this->security->log('logout', "user=$user");

        unset($_SESSION[self::SESSION_KEY]);
        session_destroy();
    }

    // =========================================================================
    //  Session validation
    // =========================================================================

    /**
     * Checks whether the current session is valid.
     *
     * Checks performed in order:
     *   1. Presence of the session key
     *   2. Expiration (inactivity > SESSION_MAX_AGE)
     *   3. User-Agent fingerprint (detects cookie theft in most cases)
     *   4. IP change (logged but non-blocking — mobile IPs change)
     *
     * If the session is valid, the activity timestamp is refreshed (sliding window).
     *
     * @return bool
     */
    public function isLoggedIn(): bool {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        $session = $_SESSION[self::SESSION_KEY];

        // 1. Inactivity expiration
        if (time() - ($session['time'] ?? 0) > self::SESSION_MAX_AGE) {
            $this->logout();
            return false;
        }

        // 2. User-Agent fingerprint change → invalid session
        $currentUaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if (isset($session['ua_hash']) && $session['ua_hash'] !== $currentUaHash) {
            $this->security->log('ua_mismatch', "user={$session['user']}");
            $this->logout();
            return false;
        }

        // 3. IP change → log only (mobile/IPv6 IPs legitimately roam)
        $currentIp = Security::clientIp();
        if (isset($session['ip']) && $session['ip'] !== $currentIp) {
            $this->security->log(
                'ip_change',
                "user={$session['user']} was={$session['ip']} now=$currentIp"
            );
        }

        // Sliding window: pushes back the expiration on each valid request
        $_SESSION[self::SESSION_KEY]['time'] = time();

        return true;
    }

    /**
     * Redirects to the login page if the user is not logged in.
     * Call as the first line of every protected admin page.
     */
    public function requireLogin(): void {
        if (!$this->isLoggedIn()) {
            $this->security->log('perm_denied', 'path=' . ($_SERVER['REQUEST_URI'] ?? ''));
            redirect(BASE . '/admin/login.php');
        }
    }

    // =========================================================================
    //  Information exposed to views
    // =========================================================================

    /**
     * Returns the current rate-limit status for the client IP.
     * Used by login.php to display the number of remaining attempts.
     *
     * @return array{locked: bool, remaining: int, wait_seconds: int}
     */
    public function getRateLimitStatus(): array {
        return $this->security->checkRateLimit(Security::clientIp());
    }

    // =========================================================================
    //  Password reset
    // =========================================================================

    /** Validity duration of a reset token (30 minutes). */
    private const RESET_TOKEN_TTL = 1800;

    /** Maximum number of reset requests per IP per hour. */
    private const RESET_MAX_REQUESTS = 3;

    /**
     * Creates a reset token and stores it in a JSON file.
     *
     * @return string  The raw token (to include in the email link)
     */
    public function createResetToken(): string {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $file      = $this->configDir() . '/reset_token.json';

        $data = [
            'hash'    => $tokenHash,
            'expires' => time() + self::RESET_TOKEN_TTL,
        ];

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        $this->security->log('reset_requested', 'ip=' . Security::clientIp());

        return $token;
    }

    /**
     * Checks whether a reset token is valid.
     *
     * @return bool
     */
    public function verifyResetToken(string $token): bool {
        $file = $this->configDir() . '/reset_token.json';

        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data) {
            return false;
        }

        // Token expired
        if (time() > ($data['expires'] ?? 0)) {
            @unlink($file);
            return false;
        }

        // Constant-time comparison
        $tokenHash = hash('sha256', $token);
        return hash_equals($data['hash'], $tokenHash);
    }

    /**
     * Consumes the token after a successful reset (deletes the file).
     */
    public function consumeResetToken(): void {
        $file = $this->configDir() . '/reset_token.json';
        if (file_exists($file)) {
            @unlink($file);
        }
        $this->security->log('reset_success', 'ip=' . Security::clientIp());
    }

    /**
     * Checks the rate-limit specific to reset requests.
     *
     * @return array{allowed: bool, wait_minutes: int}
     */
    public function checkResetRateLimit(): array {
        $file = $this->configDir() . '/reset_attempts.json';
        $ip   = Security::clientIp();
        $now  = time();

        $attempts = [];
        if (file_exists($file)) {
            $attempts = json_decode(file_get_contents($file), true) ?: [];
        }

        // Clean entries older than one hour
        $ipHash = hash('sha256', $ip);
        $recent = array_filter(
            $attempts[$ipHash] ?? [],
            fn($t) => $now - $t < 3600
        );

        if (count($recent) >= self::RESET_MAX_REQUESTS) {
            $oldest = min($recent);
            $wait   = (int) ceil((3600 - ($now - $oldest)) / 60);
            return ['allowed' => false, 'wait_minutes' => $wait];
        }

        // Record this attempt
        $recent[]            = $now;
        $attempts[$ipHash]   = array_values($recent);
        file_put_contents($file, json_encode($attempts, JSON_PRETTY_PRINT));

        return ['allowed' => true, 'wait_minutes' => 0];
    }

    // =========================================================================
    //  Static utilities
    // =========================================================================

    /**
     * Generates a secure bcrypt hash for a password.
     * To be used in setup.php or CLI to initialize config.php.
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // =========================================================================
    //  Private
    // =========================================================================

    /** Returns the path to the configuration directory. */
    private function configDir(): string {
        return dirname($this->config['content_dir']) . '/config';
    }
}
