<?php
// =============================================================================
//  core/Auth.php — Authentification et gestion de session admin
//
//  Responsabilités :
//    - Vérifier les identifiants contre le hash bcrypt de config.php
//    - Créer / invalider la session PHP après connexion / déconnexion
//    - Valider la session à chaque requête (expiry, UA fingerprint, IP)
//    - Déléguer le rate-limiting et les logs à Security
//
//  Usage type :
//      $auth = new Auth($config);
//      $auth->requireLogin();   // redirige si non connecté
// =============================================================================

class Auth {

    // Clé utilisée dans $_SESSION pour stocker les données de session admin
    private const SESSION_KEY = 'badal_auth';

    // Durée maximale d'inactivité avant expiration automatique (8 heures)
    private const SESSION_MAX_AGE = 8 * 3600;

    private array    $config;
    private Security $security;

    public function __construct(array $config) {
        $this->config   = $config;
        $this->security = new Security($this->configDir());
        // Session déjà configurée et démarrée par bootstrap.php
    }

    // =========================================================================
    //  Connexion / déconnexion
    // =========================================================================

    /**
     * Tente une connexion avec les identifiants fournis.
     *
     * Ordre des vérifications :
     *   1. Rate-limit de l'IP (bloque si trop de tentatives)
     *   2. Comparaison username + password_verify (hash bcrypt)
     *   3. En cas de succès : régénération de l'ID de session + log
     *   4. En cas d'échec  : enregistrement de la tentative + log
     *
     * @return bool  true si connexion réussie, false sinon
     */
    public function login(string $username, string $password): bool {
        $ip       = Security::clientIp();
        $security = $this->security;

        // 1. Vérification du rate-limit avant de tester le mot de passe
        $rate = $security->checkRateLimit($ip);
        if ($rate['locked']) {
            $wait = (int) ceil($rate['wait_seconds'] / 60);
            $security->log('lockout', "user=$username wait={$wait}min");
            return false;
        }

        $validUser = $this->config['admin_username']      ?? 'admin';
        $validHash = $this->config['admin_password_hash'] ?? '';

        // 2. Vérification des identifiants
        if ($username !== $validUser || !password_verify($password, $validHash)) {
            // Échec : on enregistre la tentative et on logue
            $security->recordFailedAttempt($ip);
            $remaining = $security->checkRateLimit($ip)['remaining'];
            $security->log('login_fail', "user=$username remaining=$remaining");
            return false;
        }

        // 3. Succès — prévenir la fixation de session avant d'écrire dedans
        Security::regenerateSession();

        $_SESSION[self::SESSION_KEY] = [
            'user'    => $username,
            'time'    => time(),
            'ip'      => $ip,
            // Empreinte de l'User-Agent : invalide la session si le navigateur change
            'ua_hash' => md5($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ];

        $security->clearAttempts($ip);
        $security->log('login_ok', "user=$username");

        // Signaler si le hash devrait être recalculé avec un coût plus élevé
        if (password_needs_rehash($validHash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $security->log('rehash_needed', "user=$username");
        }

        return true;
    }

    /**
     * Déconnecte l'utilisateur : supprime les données de session et la détruit.
     */
    public function logout(): void {
        $user = $_SESSION[self::SESSION_KEY]['user'] ?? 'unknown';
        $this->security->log('logout', "user=$user");

        unset($_SESSION[self::SESSION_KEY]);
        session_destroy();
    }

    // =========================================================================
    //  Validation de session
    // =========================================================================

    /**
     * Vérifie si la session courante est valide.
     *
     * Contrôles effectués dans l'ordre :
     *   1. Présence de la clé de session
     *   2. Expiration (inactivité > SESSION_MAX_AGE)
     *   3. Empreinte User-Agent (détecte le vol de cookie dans la majorité des cas)
     *   4. Changement d'IP (loggé mais non bloquant — les IPs mobiles changent)
     *
     * Si la session est valide, le timestamp d'activité est rafraîchi (sliding window).
     *
     * @return bool
     */
    public function isLoggedIn(): bool {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        $session = $_SESSION[self::SESSION_KEY];

        // 1. Expiration par inactivité
        if (time() - ($session['time'] ?? 0) > self::SESSION_MAX_AGE) {
            $this->logout();
            return false;
        }

        // 2. Changement d'empreinte User-Agent → session invalide
        $currentUaHash = md5($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (isset($session['ua_hash']) && $session['ua_hash'] !== $currentUaHash) {
            $this->security->log('ua_mismatch', "user={$session['user']}");
            $this->logout();
            return false;
        }

        // 3. Changement d'IP → log uniquement (IPs mobiles/IPv6 roament légitimement)
        $currentIp = Security::clientIp();
        if (isset($session['ip']) && $session['ip'] !== $currentIp) {
            $this->security->log(
                'ip_change',
                "user={$session['user']} was={$session['ip']} now=$currentIp"
            );
        }

        // Sliding window : repousse l'expiration à chaque requête valide
        $_SESSION[self::SESSION_KEY]['time'] = time();

        return true;
    }

    /**
     * Redirige vers la page de login si l'utilisateur n'est pas connecté.
     * À appeler en première ligne de chaque page admin protégée.
     */
    public function requireLogin(): void {
        if (!$this->isLoggedIn()) {
            $this->security->log('perm_denied', 'path=' . ($_SERVER['REQUEST_URI'] ?? ''));
            redirect(BASE . '/admin/login.php');
        }
    }

    // =========================================================================
    //  Informations exposées aux vues
    // =========================================================================

    /**
     * Retourne l'état courant du rate-limit pour l'IP cliente.
     * Utilisé par login.php pour afficher le nombre de tentatives restantes.
     *
     * @return array{locked: bool, remaining: int, wait_seconds: int}
     */
    public function getRateLimitStatus(): array {
        return $this->security->checkRateLimit(Security::clientIp());
    }

    // =========================================================================
    //  Réinitialisation de mot de passe
    // =========================================================================

    /** Durée de validité d'un token de réinitialisation (30 minutes). */
    private const RESET_TOKEN_TTL = 1800;

    /** Nombre max de demandes de reset par IP par heure. */
    private const RESET_MAX_REQUESTS = 3;

    /**
     * Crée un token de réinitialisation et le stocke dans un fichier JSON.
     *
     * @return string  Le token brut (à inclure dans le lien email)
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
     * Vérifie si un token de réinitialisation est valide.
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

        // Token expiré
        if (time() > ($data['expires'] ?? 0)) {
            @unlink($file);
            return false;
        }

        // Comparaison en temps constant
        $tokenHash = hash('sha256', $token);
        return hash_equals($data['hash'], $tokenHash);
    }

    /**
     * Consomme le token après un reset réussi (supprime le fichier).
     */
    public function consumeResetToken(): void {
        $file = $this->configDir() . '/reset_token.json';
        if (file_exists($file)) {
            @unlink($file);
        }
        $this->security->log('reset_success', 'ip=' . Security::clientIp());
    }

    /**
     * Vérifie le rate-limit spécifique aux demandes de reset.
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

        // Nettoyer les entrées de plus d'une heure
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

        // Enregistrer cette tentative
        $recent[]            = $now;
        $attempts[$ipHash]   = array_values($recent);
        file_put_contents($file, json_encode($attempts, JSON_PRETTY_PRINT));

        return ['allowed' => true, 'wait_minutes' => 0];
    }

    // =========================================================================
    //  Utilitaires statiques
    // =========================================================================

    /**
     * Génère un hash bcrypt sécurisé pour un mot de passe.
     * À utiliser dans setup.php ou en CLI pour initialiser config.php.
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // =========================================================================
    //  Privé
    // =========================================================================

    /** Retourne le chemin du dossier de configuration. */
    private function configDir(): string {
        return dirname($this->config['content_dir']) . '/config';
    }
}
