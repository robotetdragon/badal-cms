<?php
ob_start(); // Buffer tout output — évite "headers already sent"
// =============================================================================
//  core/bootstrap.php — Point d'entrée unique de l'application
//
//  Inclus en première ligne de chaque page (admin et public).
//  Responsabilités :
//    1. Définir ROOT_DIR (chemin absolu de la racine du projet)
//    2. Enregistrer l'autoloader PSR-0 simplifié pour les classes de core/
//    3. Charger la configuration depuis config/config.php
//    4. Envoyer les en-têtes de sécurité HTTP appropriés (admin vs public)
//    5. Configurer les sessions PHP de façon sécurisée
//    6. Exposer les fonctions utilitaires globales
// =============================================================================

// Racine absolue du projet (un niveau au-dessus de core/)
define('ROOT_DIR', dirname(__DIR__));

// -----------------------------------------------------------------------------
//  Chemin de base de l'application
//  Si le site est installé dans un sous-dossier (ex: /betapodcast/),
//  BASE_PATH vaut '/betapodcast'. À la racine, vaut ''.
//  Dérivé automatiquement depuis base_url dans config.php.
// -----------------------------------------------------------------------------
// On ne peut pas utiliser $config ici (pas encore chargé), donc on le définit
// après le chargement de la config, via une seconde passe ci-dessous.

// -----------------------------------------------------------------------------
//  Autoloader — charge automatiquement les classes de core/ à la demande.
//  Convention : le nom de classe correspond exactement au nom de fichier.
//  Ex : new EpisodeParser(...) → core/EpisodeParser.php
// -----------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $file = ROOT_DIR . '/core/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// -----------------------------------------------------------------------------
//  Configuration — tableau associatif retourné par config/config.php.
//  Distribué en lecture seule à toutes les classes via leur constructeur.
// -----------------------------------------------------------------------------
$config = require ROOT_DIR . '/config/config.php';

// -----------------------------------------------------------------------------
//  BASE — préfixe de sous-dossier extrait de base_url.
//  Ex: 'https://robotetdragon.com/betapodcast' → BASE = '/betapodcast'
//      'https://monpodcast.com'               → BASE = ''
// -----------------------------------------------------------------------------
$_parsed = parse_url($config['base_url'] ?? '');
define('BASE', rtrim($_parsed['path'] ?? '', '/'));
unset($_parsed);

// -----------------------------------------------------------------------------
//  Session sécurisée  ← AVANT tout envoi de header
//  session_set_cookie_params() doit être appelé avant session_start()
//  et avant tout header() — sinon PHP génère des warnings.
// -----------------------------------------------------------------------------
Security::configureSession();

// -----------------------------------------------------------------------------
//  Langue de l'interface  ← démarre la session via session_start()
// -----------------------------------------------------------------------------
Lang::init();

// -----------------------------------------------------------------------------
//  En-têtes de sécurité HTTP  ← APRÈS session (headers envoyés ici)
// -----------------------------------------------------------------------------
$_configDir = dirname($config['content_dir']) . '/config';
$_security  = new Security($_configDir);

if ((bool) preg_match('#/admin(/|$)#', $_SERVER['REQUEST_URI'] ?? '')) {
    $_security->sendAdminHeaders();
} else {
    $_security->sendPublicHeaders();
}

header_remove('X-Powered-By');

// =============================================================================
//  Fonctions utilitaires globales
//  Fonctions pures, sans effet de bord, disponibles dans toutes les vues.
// =============================================================================

/**
 * Redirige vers une URL et arrête l'exécution.
 * Wrapper autour de header('Location:') pour ne jamais oublier exit.
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Génère une URL absolue en préfixant avec BASE si nécessaire.
 * Utiliser pour tous les liens internes à la place des chemins codés en dur.
 *
 * url('/admin/')          → '/betapodcast/admin/'  (sous-dossier)
 * url('/episodes/slug')   → '/betapodcast/episodes/slug'
 * url('/rss.xml')         → '/betapodcast/rss.xml'
 */
function url(string $path): string {
    return BASE . '/' . ltrim($path, '/');
}

/**
 * Échappe une valeur pour l'affichage HTML.
 * À utiliser systématiquement pour toute donnée affichée dans une vue.
 * Accepte mixed pour éviter les cast manuels dans les templates.
 */
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Génère ou retourne le token CSRF de la session en cours.
 * Le token est un nonce de 32 octets aléatoires encodé en hexadécimal,
 * renouvelé à chaque nouvelle session.
 */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie que le token CSRF du POST correspond à celui de la session.
 * Appeler en début de tout handler POST. Arrête l'exécution si invalide.
 * Utilise hash_equals() pour se protéger contre les attaques temporelles.
 */
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submitted = $_POST['csrf_token'] ?? '';
        if (!hash_equals(csrf_token(), $submitted)) {
            http_response_code(403);
            die('Token CSRF invalide.');
        }
    }
}

/**
 * Traduit une clé de l'interface dans la langue active.
 * Raccourci global pour Lang::get().
 *
 * Exemples :
 *   <?= __('save') ?>                        → "Enregistrer" / "Save"
 *   <?= __('stats_period', ['%days%' => 30]) ?>  → "Écoutes (30 jours)"
 *
 * @param  string $key     Clé définie dans Lang::strings()
 * @param  array  $replace Substitutions optionnelles dans la chaîne
 * @return string          Chaîne traduite, HTML-safe non appliqué (utiliser e() si nécessaire)
 */
function __(string $key, array $replace = []): string {
    return Lang::get($key, $replace);
}
