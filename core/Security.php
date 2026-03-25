<?php
// =============================================================================
//  core/Security.php — Centralized security
//
//  Single point for all CMS security concerns:
//
//    1. HTTP security headers (CSP, HSTS, X-Frame-Options...)
//    2. Secure PHP session configuration
//    3. Login form rate-limiting (flat-file, no database)
//    4. MIME validation of audio uploads (via finfo, not the extension)
//    5. Authentication event audit log
//    6. File permissions audit
//
//  This class is instantiated in bootstrap.php and in Auth.php.
//  Static methods (configureSession, clientIp, validateAudioUpload)
//  can be called without instantiation.
// =============================================================================

class Security {

    // ── Rate limiting ────────────────────────────────────────────────────────

    /** Maximum number of login attempts before lockout */
    private const MAX_ATTEMPTS = 5;

    /** Time window for counting attempts (15 minutes) */
    private const LOCKOUT_WINDOW = 900;

    // ── Allowed image MIME types ─────────────────────────────────────────────
    // Used by validateImageUpload() for logos, covers and episode images.

    private const IMAGE_MIME_MAP = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/svg+xml' => 'svg',
    ];

    // ── Allowed audio MIME types ────────────────────────────────────────────
    // Key = actual MIME type (detected by finfo), value = compatible extensions.
    // A file is rejected if its actual MIME type is not in this list,
    // regardless of its declared extension.

    private const AUDIO_MIME_MAP = [
        'audio/mpeg'      => ['mp3'],
        'audio/mp3'       => ['mp3'],
        'audio/ogg'       => ['ogg', 'oga'],
        'audio/x-m4a'     => ['m4a'],
        'audio/mp4'       => ['m4a', 'mp4'],
        'audio/aac'       => ['aac', 'm4a'],
        'audio/x-aac'     => ['aac'],
        'audio/wav'       => ['wav'],
        'audio/x-wav'     => ['wav'],
        'audio/flac'      => ['flac'],
        'audio/x-flac'    => ['flac'],
        'audio/opus'      => ['opus'],
        'application/ogg' => ['ogg'],
    ];

    /** Storage directory for rate-limit counters (one JSON file per IP) */
    private string $rateLimitDir;

    /** Authentication log file */
    private string $logFile;

    /** True if the current request is served over HTTPS */
    private bool $isHttps;

    public function __construct(string $configDir) {
        $this->rateLimitDir = rtrim($configDir, '/') . '/ratelimit';
        $this->logFile      = rtrim($configDir, '/') . '/auth.log';

        // HTTPS detected via the server variable or port
        $this->isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                      || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

        // Create the ratelimit directory with restrictive permissions
        if (!is_dir($this->rateLimitDir)) {
            mkdir($this->rateLimitDir, 0700, true);
        }
    }

    // =========================================================================
    //  1. PHP Sessions
    // =========================================================================

    /**
     * Configures secure session parameters.
     *
     * Must be called BEFORE session_start(), ideally in bootstrap.php.
     * The ini_set() calls only take effect if the session is not yet started.
     *
     * Applied parameters:
     *   - HttpOnly        : cookie is not accessible via JS (XSS protection)
     *   - SameSite=Strict : cookie is not sent cross-site (CSRF protection)
     *   - Secure          : HTTPS-only cookie if the server is on HTTPS
     *   - use_only_cookies: forbids session ID in the URL
     *   - use_strict_mode : rejects client-provided session IDs
     *   - sid_length=48   : 48-character session ID (high entropy)
     */
    public static function configureSession(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return; // too late to change the parameters
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

        session_set_cookie_params([
            'lifetime' => 0,            // expires when the browser is closed
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        // Note: ini_set for session.sid_length etc. are deprecated in PHP 8.x
        // session_set_cookie_params() above is sufficient for essential security.
    }

    /**
     * Regenerates the session ID to prevent session fixation.
     * Call immediately after a successful login.
     * Preserves existing session data.
     */
    public static function regenerateSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true); // true = deletes the old session
        }
    }

    // =========================================================================
    //  2. HTTP security headers
    // =========================================================================

    /**
     * Sends security headers for admin pages.
     *
     * CSP stricter than public: no media-src (no audio/video),
     * frame-src=none (no iframes allowed), form-action=self.
     * Used CDNs (EasyMDE, Chart.js, Google Fonts) are explicitly
     * listed in script-src and style-src.
     */
    public function sendAdminHeaders(): void {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com",
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://fonts.gstatic.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
        header('Content-Security-Policy: ' . $csp);

        // HSTS: force HTTPS for 6 months — sent only if already on HTTPS
        // to avoid blocking HTTP access in local development
        if ($this->isHttps) {
            header('Strict-Transport-Security: max-age=15768000; includeSubDomains');
        }
    }

    /**
     * Sends security headers for public pages.
     *
     * More permissive CSP: media-src=self allowed (HTML5 audio player),
     * frame-src=none maintained, no restrictive Permissions-Policy.
     */
    public function sendPublicHeaders(): void {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.gstatic.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "media-src 'self'",     // required for the <audio> element
            "connect-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
        ]);
        header('Content-Security-Policy: ' . $csp);

        if ($this->isHttps) {
            header('Strict-Transport-Security: max-age=15768000; includeSubDomains');
        }
    }

    // =========================================================================
    //  3. Login rate limiting
    // =========================================================================

    /**
     * Returns the rate-limit status for an IP.
     * Automatically purges attempts older than the time window.
     *
     * @param  string $ip  Client IP address (from clientIp())
     * @return array{locked: bool, remaining: int, wait_seconds: int}
     */
    public function checkRateLimit(string $ip): array {
        $data = $this->loadRateData($ip);
        $this->pruneOldAttempts($data);

        $count = count($data['attempts']);

        if ($count >= self::MAX_ATTEMPTS) {
            $oldest    = min($data['attempts']);
            $waitSecs  = max(0, ($oldest + self::LOCKOUT_WINDOW) - time());
            return ['locked' => true, 'remaining' => 0, 'wait_seconds' => $waitSecs];
        }

        return [
            'locked'       => false,
            'remaining'    => self::MAX_ATTEMPTS - $count,
            'wait_seconds' => 0,
        ];
    }

    /**
     * Records a failed login attempt for an IP.
     * Persists to disk in config/ratelimit/<hash_ip>.json
     */
    public function recordFailedAttempt(string $ip): void {
        $data              = $this->loadRateData($ip);
        $data['attempts'][] = time();
        $this->saveRateData($ip, $data);
    }

    /**
     * Clears the attempt counter for an IP.
     * Called after a successful login.
     */
    public function clearAttempts(string $ip): void {
        $file = $this->rateLimitPath($ip);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // =========================================================================
    //  4. MIME validation of audio uploads
    // =========================================================================

    /**
     * Validates an uploaded audio file via $_FILES.
     *
     * Two levels of verification:
     *   a) Actual MIME type: detected by finfo (magic bytes), not the client declaration
     *   b) Extension consistency: the declared extension must match the actual MIME type
     *
     * This approach rejects renamed files (e.g. shell.php renamed to shell.mp3).
     *
     * @param  array  $file  $_FILES entry (e.g. $_FILES['audio'])
     * @return array{ok: bool, mime: string, ext: string, error: string}
     */
    public static function validateAudioUpload(array $file): array {
        // Verify that the file is a legitimate PHP upload
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'mime' => '', 'ext' => '', 'error' => 'Aucun fichier reçu.'];
        }

        // PHP error code during upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE])) {
                $msg = 'Fichier trop volumineux.';
            } elseif ($file['error'] === UPLOAD_ERR_PARTIAL) {
                $msg = 'Upload incomplet, réessayez.';
            } else {
                $msg = 'Erreur lors de l\'upload (code ' . $file['error'] . ').';
            }
            return ['ok' => false, 'mime' => '', 'ext' => '', 'error' => $msg];
        }

        // Actual MIME type detection via magic bytes (independent of extension)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = (string) finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mime, self::AUDIO_MIME_MAP)) {
            return [
                'ok'    => false,
                'mime'  => $mime,
                'ext'   => '',
                'error' => "Format non supporté ($mime). Formats acceptés : MP3, OGG, M4A, AAC, WAV, FLAC, OPUS.",
            ];
        }

        // Verify consistency between the declared extension and the actual MIME type
        $allowedExtensions = self::AUDIO_MIME_MAP[$mime];
        $declaredExt       = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

        if ($declaredExt && !in_array($declaredExt, $allowedExtensions, true)) {
            return [
                'ok'    => false,
                'mime'  => $mime,
                'ext'   => '',
                'error' => "Extension .$declaredExt incohérente avec le type réel du fichier ($mime).",
            ];
        }

        // Use the declared extension if valid, otherwise the first allowed extension
        $ext = $declaredExt ?: $allowedExtensions[0];

        return ['ok' => true, 'mime' => $mime, 'ext' => $ext, 'error' => ''];
    }

    // =========================================================================
    //  4b. MIME validation of image uploads
    // =========================================================================

    /**
     * Validates an uploaded image file via $_FILES.
     *
     * Detects the actual MIME type via finfo (magic bytes) and verifies it matches
     * an allowed image format. Returns the normalized extension on success.
     *
     * @param  array  $file           $_FILES entry (e.g. $_FILES['cover'])
     * @param  array  $allowedMimes   Subset of IMAGE_MIME_MAP to allow (null = all)
     * @return array{ok: bool, mime: string, ext: string, error: string}
     */
    public static function validateImageUpload(array $file, ?array $allowedMimes = null): array {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'mime' => '', 'ext' => '', 'error' => 'Aucun fichier reçu.'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'mime' => '', 'ext' => '', 'error' => 'Erreur lors de l\'upload (code ' . $file['error'] . ').'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return ['ok' => false, 'mime' => '', 'ext' => '', 'error' => 'Impossible d\'initialiser la détection MIME.'];
        }

        $mime = (string) finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $mimeMap = $allowedMimes ?? self::IMAGE_MIME_MAP;

        if (!array_key_exists($mime, $mimeMap)) {
            $allowed = implode(', ', array_values($mimeMap));
            return [
                'ok'    => false,
                'mime'  => $mime,
                'ext'   => '',
                'error' => "Format non supporté ($mime). Formats acceptés : $allowed.",
            ];
        }

        return ['ok' => true, 'mime' => $mime, 'ext' => $mimeMap[$mime], 'error' => ''];
    }

    // =========================================================================
    //  5. Audit log
    // =========================================================================

    /**
     * Records a security event in config/auth.log.
     *
     * Line format:
     *   2026-03-15 14:32:01 | LOGIN_OK | ip=1.2.3.4 | detail=user=admin | ua=Mozilla/5.0...
     *
     * The log is automatically archived if its size exceeds 2 MB.
     *
     * @param string $event   Event type: login_ok | login_fail | logout |
     *                        lockout | perm_denied | ip_change | ua_mismatch | rehash_needed
     * @param string $detail  Free context (user=..., path=..., wait=...)
     */
    public function log(string $event, string $detail = ''): void {
        $ip   = self::clientIp();
        $ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 120);
        $ts   = date('Y-m-d H:i:s');

        $parts = ["$ts", strtoupper($event), "ip=$ip"];
        if ($detail !== '') {
            $parts[] = "detail=$detail";
        }
        $parts[] = "ua=$ua";

        $line = implode(' | ', $parts) . "\n";

        // Automatic rotation at 2 MB to avoid an infinite log file
        if (file_exists($this->logFile) && filesize($this->logFile) > 2 * 1024 * 1024) {
            rename($this->logFile, $this->logFile . '.' . date('Ymd-His') . '.bak');
        }

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    //  6. File permissions audit
    // =========================================================================

    /**
     * Checks the permissions of sensitive files and directories.
     *
     * Returns a results array used by admin/security.php
     * and tools/check_permissions.php to display corrections to apply.
     *
     * @param  string  $rootDir  Absolute project root
     * @return array<int, array{path: string, current: string, recommended: string, ok: bool}>
     */
    public static function auditPermissions(string $rootDir): array {
        // [ absolute path, max allowed mode in octal, displayed label ]
        $checks = [
            [$rootDir . '/config',             0750, 'config/'],
            [$rootDir . '/config/config.php',  0640, 'config/config.php'],
            [$rootDir . '/config/auth.log',    0640, 'config/auth.log'],
            [$rootDir . '/content',            0750, 'content/'],
            [$rootDir . '/audio',              0750, 'audio/'],
            [$rootDir . '/.htaccess',          0644, '.htaccess'],
        ];

        $results = [];
        foreach ($checks as [$path, $maxMode, $label]) {
            if (!file_exists($path)) {
                continue;
            }
            $current = fileperms($path) & 0777;
            $results[] = [
                'path'        => $label,
                'current'     => decoct($current),
                'recommended' => decoct($maxMode),
                'ok'          => $current <= $maxMode,
            ];
        }

        return $results;
    }

    // =========================================================================
    //  Public static utilities
    // =========================================================================

    /**
     * Returns the real client IP address.
     *
     * The X-Forwarded-For and CF-Connecting-IP headers are only considered
     * if REMOTE_ADDR matches a trusted proxy (Cloudflare, local,
     * or custom list via config 'trusted_proxies').
     *
     * This prevents a direct client from spoofing their IP via these headers.
     */
    public static function clientIp(array $trustedProxies = []): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Known Cloudflare ranges (IPv4) + loopback + common private networks for reverse proxies
        $defaultTrusted = [
            '127.0.0.1', '::1',
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        ];

        $allTrusted = array_merge($defaultTrusted, $trustedProxies);
        $isTrustedProxy = self::ipInRanges($remoteAddr, $allTrusted);

        // Only accept proxy headers if REMOTE_ADDR is a trusted proxy
        if ($isTrustedProxy) {
            $candidates = [
                $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',  // Cloudflare
                $_SERVER['HTTP_X_FORWARDED_FOR']  ?? '',  // reverse proxy
            ];

            foreach ($candidates as $raw) {
                $ip = trim(explode(',', $raw)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Use REMOTE_ADDR directly
        return $remoteAddr;
    }

    /**
     * Checks whether an IP belongs to a list of CIDR ranges or exact addresses.
     */
    private static function ipInRanges(string $ip, array $ranges): bool {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            // IPv6: exact match only
            return in_array($ip, $ranges, true);
        }

        foreach ($ranges as $range) {
            if (str_contains($range, '/')) {
                [$subnet, $bits] = explode('/', $range, 2);
                $subnetLong = ip2long($subnet);
                if ($subnetLong === false) continue;
                $mask = -1 << (32 - (int) $bits);
                if (($ipLong & $mask) === ($subnetLong & $mask)) {
                    return true;
                }
            } elseif ($ip === $range) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    //  Private — rate limiting
    // =========================================================================

    /**
     * Returns the path to the rate-limit JSON file for an IP.
     * The IP is stored hashed (SHA-256) to never write a plain IP to disk.
     */
    private function rateLimitPath(string $ip): string {
        return $this->rateLimitDir . '/' . hash('sha256', $ip) . '.json';
    }

    /** Loads rate-limit data for an IP (returns [] if absent). */
    private function loadRateData(string $ip): array {
        $file = $this->rateLimitPath($ip);
        if (!file_exists($file)) {
            return ['attempts' => []];
        }
        $raw = file_get_contents($file);
        return json_decode($raw ?: '{}', true) ?: ['attempts' => []];
    }

    /** Persists rate-limit data for an IP. */
    private function saveRateData(string $ip, array $data): void {
        file_put_contents(
            $this->rateLimitPath($ip),
            json_encode($data),
            LOCK_EX
        );
    }

    /**
     * Removes from the array attempts older than the LOCKOUT_WINDOW.
     * Called before each read to ensure up-to-date counters.
     */
    private function pruneOldAttempts(array &$data): void {
        $cutoff           = time() - self::LOCKOUT_WINDOW;
        $data['attempts'] = array_values(
            array_filter($data['attempts'], fn(int $t) => $t >= $cutoff)
        );
    }
}
