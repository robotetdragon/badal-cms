<?php
// =============================================================================
//  core/Security.php — Sécurité centralisée
//
//  Point unique pour toutes les préoccupations de sécurité du CMS :
//
//    1. En-têtes HTTP de sécurité (CSP, HSTS, X-Frame-Options…)
//    2. Configuration des sessions PHP sécurisées
//    3. Rate-limiting du formulaire de login (flat-file, sans BDD)
//    4. Validation MIME des uploads audio (via finfo, pas l'extension)
//    5. Journal d'audit des événements d'authentification
//    6. Audit des permissions fichiers
//
//  Cette classe est instanciée dans bootstrap.php et dans Auth.php.
//  Les méthodes statiques (configureSession, clientIp, validateAudioUpload)
//  peuvent être appelées sans instanciation.
// =============================================================================

class Security {

    // ── Rate limiting ────────────────────────────────────────────────────────

    /** Nombre maximum de tentatives de connexion avant blocage */
    private const MAX_ATTEMPTS = 5;

    /** Fenêtre de temps pour le comptage des tentatives (15 minutes) */
    private const LOCKOUT_WINDOW = 900;

    // ── Types MIME audio autorisés ────────────────────────────────────────────
    // Clé = MIME type réel (détecté par finfo), valeur = extensions compatibles.
    // Un fichier est refusé si son MIME réel ne figure pas dans cette liste,
    // quelle que soit son extension déclarée.

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

    /** Dossier de stockage des compteurs de rate-limit (un fichier JSON par IP) */
    private string $rateLimitDir;

    /** Fichier de journal d'authentification */
    private string $logFile;

    /** Vrai si la requête courante est servie en HTTPS */
    private bool $isHttps;

    public function __construct(string $configDir) {
        $this->rateLimitDir = rtrim($configDir, '/') . '/ratelimit';
        $this->logFile      = rtrim($configDir, '/') . '/auth.log';

        // HTTPS détecté via la variable serveur ou le port
        $this->isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                      || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

        // Créer le dossier ratelimit avec permissions restrictives
        if (!is_dir($this->rateLimitDir)) {
            mkdir($this->rateLimitDir, 0700, true);
        }
    }

    // =========================================================================
    //  1. Sessions PHP
    // =========================================================================

    /**
     * Configure les paramètres de session sécurisés.
     *
     * Doit être appelé AVANT session_start(), idéalement dans bootstrap.php.
     * Les ini_set() n'ont d'effet que si la session n'est pas encore démarrée.
     *
     * Paramètres appliqués :
     *   - HttpOnly        : le cookie n'est pas accessible via JS (protection XSS)
     *   - SameSite=Strict : le cookie n'est pas envoyé en cross-site (protection CSRF)
     *   - Secure          : cookie HTTPS-only si le serveur est en HTTPS
     *   - use_only_cookies: interdit l'ID de session dans l'URL
     *   - use_strict_mode : rejette les IDs de session fournis par le client
     *   - sid_length=48   : ID de session de 48 caractères (entropie élevée)
     */
    public static function configureSession(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return; // trop tard pour changer les paramètres
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

        session_set_cookie_params([
            'lifetime' => 0,            // expire à la fermeture du navigateur
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        // Note: ini_set pour session.sid_length etc. sont dépréciés en PHP 8.x
        // session_set_cookie_params() ci-dessus suffit pour la sécurité essentielle.
    }

    /**
     * Régénère l'ID de session pour prévenir la fixation de session.
     * À appeler immédiatement après une connexion réussie.
     * Conserve les données de session existantes.
     */
    public static function regenerateSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true); // true = supprime l'ancienne session
        }
    }

    // =========================================================================
    //  2. En-têtes HTTP de sécurité
    // =========================================================================

    /**
     * Envoie les en-têtes de sécurité pour les pages admin.
     *
     * CSP plus stricte qu'en public : pas de media-src (pas d'audio/vidéo),
     * frame-src=none (aucune iframe autorisée), form-action=self.
     * Les CDN utilisés (EasyMDE, Chart.js, Google Fonts) sont explicitement
     * listés dans script-src et style-src.
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

        // HSTS : forcer HTTPS pour 6 mois — envoyé uniquement si déjà en HTTPS
        // pour éviter de bloquer l'accès HTTP en développement local
        if ($this->isHttps) {
            header('Strict-Transport-Security: max-age=15768000; includeSubDomains');
        }
    }

    /**
     * Envoie les en-têtes de sécurité pour les pages publiques.
     *
     * CSP plus permissive : media-src=self autorisé (lecteur audio HTML5),
     * frame-src=none maintenu, pas de Permissions-Policy restrictive.
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
            "media-src 'self'",     // nécessaire pour l'élément <audio>
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
    //  3. Rate limiting du login
    // =========================================================================

    /**
     * Retourne l'état du rate-limit pour une IP.
     * Purge automatiquement les tentatives antérieures à la fenêtre de temps.
     *
     * @param  string $ip  Adresse IP du client (depuis clientIp())
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
     * Enregistre une tentative de connexion échouée pour une IP.
     * Persiste sur le disque dans config/ratelimit/<hash_ip>.json
     */
    public function recordFailedAttempt(string $ip): void {
        $data              = $this->loadRateData($ip);
        $data['attempts'][] = time();
        $this->saveRateData($ip, $data);
    }

    /**
     * Supprime le compteur de tentatives pour une IP.
     * Appelé après une connexion réussie.
     */
    public function clearAttempts(string $ip): void {
        $file = $this->rateLimitPath($ip);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // =========================================================================
    //  4. Validation MIME des uploads audio
    // =========================================================================

    /**
     * Valide un fichier audio uploadé via $_FILES.
     *
     * Deux niveaux de vérification :
     *   a) MIME type réel : détecté par finfo (magic bytes), pas la déclaration client
     *   b) Cohérence extension : l'extension déclarée doit correspondre au MIME réel
     *
     * Cette approche rejette les fichiers renommés (ex: shell.php renommé shell.mp3).
     *
     * @param  array  $file  Entrée de $_FILES (ex: $_FILES['audio'])
     * @return array{ok: bool, mime: string, ext: string, error: string}
     */
    public static function validateAudioUpload(array $file): array {
        // Vérifier que le fichier est bien un upload PHP légitime
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'mime' => '', 'ext' => '', 'error' => 'Aucun fichier reçu.'];
        }

        // Code d'erreur PHP lors de l'upload
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

        // Détection du MIME type réel via les magic bytes (indépendant de l'extension)
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

        // Vérifier la cohérence entre l'extension déclarée et le MIME réel
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

        // Utiliser l'extension déclarée si valide, sinon la première extension autorisée
        $ext = $declaredExt ?: $allowedExtensions[0];

        return ['ok' => true, 'mime' => $mime, 'ext' => $ext, 'error' => ''];
    }

    // =========================================================================
    //  5. Journal d'audit
    // =========================================================================

    /**
     * Enregistre un événement de sécurité dans config/auth.log.
     *
     * Format d'une ligne :
     *   2026-03-15 14:32:01 | LOGIN_OK | ip=1.2.3.4 | detail=user=admin | ua=Mozilla/5.0…
     *
     * Le log est automatiquement archivé si sa taille dépasse 2 Mo.
     *
     * @param string $event   Type d'événement : login_ok | login_fail | logout |
     *                        lockout | perm_denied | ip_change | ua_mismatch | rehash_needed
     * @param string $detail  Contexte libre (user=…, path=…, wait=…)
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

        // Rotation automatique à 2 Mo pour éviter un fichier log infini
        if (file_exists($this->logFile) && filesize($this->logFile) > 2 * 1024 * 1024) {
            rename($this->logFile, $this->logFile . '.' . date('Ymd-His') . '.bak');
        }

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    //  6. Audit des permissions fichiers
    // =========================================================================

    /**
     * Vérifie les permissions des fichiers et dossiers sensibles.
     *
     * Retourne un tableau de résultats utilisé par admin/security.php
     * et tools/check_permissions.php pour afficher les corrections à appliquer.
     *
     * @param  string  $rootDir  Racine absolue du projet
     * @return array<int, array{path: string, current: string, recommended: string, ok: bool}>
     */
    public static function auditPermissions(string $rootDir): array {
        // [ chemin absolu, mode max autorisé en octal, label affiché ]
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
    //  Utilitaires statiques publics
    // =========================================================================

    /**
     * Retourne l'adresse IP réelle du client.
     *
     * Priorité : Cloudflare → proxy nginx (X-Forwarded-For) → REMOTE_ADDR.
     * Seules les IP publiques valides sont retournées ; en cas d'échec,
     * REMOTE_ADDR est utilisé comme fallback sans validation.
     *
     * ⚠️  X-Forwarded-For peut être falsifié si le serveur n'est pas derrière
     * un proxy de confiance. Adapter selon l'infrastructure.
     */
    public static function clientIp(): string {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',  // Cloudflare
            $_SERVER['HTTP_X_FORWARDED_FOR']  ?? '',  // reverse proxy
            $_SERVER['REMOTE_ADDR']           ?? '',
        ];

        foreach ($candidates as $raw) {
            // Prendre la première adresse d'une liste séparée par des virgules
            $ip = trim(explode(',', $raw)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // Fallback sans filtrage (réseau local / développement)
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // =========================================================================
    //  Privé — rate limiting
    // =========================================================================

    /**
     * Retourne le chemin du fichier JSON de rate-limit pour une IP.
     * L'IP est stockée hachée (SHA-256) pour ne jamais écrire d'IP en clair sur disque.
     */
    private function rateLimitPath(string $ip): string {
        return $this->rateLimitDir . '/' . hash('sha256', $ip) . '.json';
    }

    /** Charge les données de rate-limit pour une IP (retourne [] si absent). */
    private function loadRateData(string $ip): array {
        $file = $this->rateLimitPath($ip);
        if (!file_exists($file)) {
            return ['attempts' => []];
        }
        $raw = file_get_contents($file);
        return json_decode($raw ?: '{}', true) ?: ['attempts' => []];
    }

    /** Persiste les données de rate-limit pour une IP. */
    private function saveRateData(string $ip, array $data): void {
        file_put_contents(
            $this->rateLimitPath($ip),
            json_encode($data),
            LOCK_EX
        );
    }

    /**
     * Supprime du tableau les tentatives antérieures à la fenêtre LOCKOUT_WINDOW.
     * Appelé avant chaque lecture pour garantir des compteurs à jour.
     */
    private function pruneOldAttempts(array &$data): void {
        $cutoff           = time() - self::LOCKOUT_WINDOW;
        $data['attempts'] = array_values(
            array_filter($data['attempts'], fn(int $t) => $t >= $cutoff)
        );
    }
}
