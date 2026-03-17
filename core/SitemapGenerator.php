<?php
// =============================================================================
//  core/SitemapGenerator.php — Génération du sitemap.xml
//
//  Produit un sitemap XML conforme au protocole sitemaps.org (0.9).
//  Appelé automatiquement après chaque création, modification ou suppression
//  d'épisode afin de maintenir le sitemap à jour sans tâche planifiée.
//
//  Le fichier sitemap.xml est écrit à la racine du projet et servi
//  via la règle de réécriture .htaccess : ^sitemap\.xml$ → public/sitemap.php
//
//  Usage :
//      $sitemap = new SitemapGenerator($config['base_url'], ROOT_DIR);
//      $sitemap->generate($parser->getAll());
// =============================================================================

class SitemapGenerator {

    private string $baseUrl;

    /** Chemin absolu du fichier sitemap.xml à écrire */
    private string $outputFile;

    public function __construct(string $baseUrl, string $rootDir) {
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->outputFile = rtrim($rootDir, '/') . '/sitemap.xml';
    }

    // =========================================================================
    //  Génération
    // =========================================================================

    /**
     * Régénère le fichier sitemap.xml complet.
     *
     * URLs incluses :
     *   - La page d'accueil (priority 1.0, changefreq weekly)
     *   - Chaque page d'épisode (priority 0.8, changefreq monthly)
     *
     * @param  array  $episodes  Tableaux d'épisodes (depuis EpisodeParser::getAll())
     * @return bool              true si le fichier a été écrit avec succès
     */
    public function generate(array $episodes): bool {
        $urls = [];

        // Page d'accueil — mise à jour fréquente (nouvelle saison, design…)
        $urls[] = $this->buildUrl($this->baseUrl . '/', date('c'), 'weekly', '1.0');

        // Pages d'épisodes — stables après publication
        foreach ($episodes as $ep) {
            $slug = $ep['slug'] ?? '';
            if (!$slug) {
                continue;
            }

            // lastmod basé sur la date de l'épisode, sinon aujourd'hui
            $lastmod = ($ep['date'] ?? '') . 'T00:00:00+00:00';

            $urls[] = $this->buildUrl(
                $this->baseUrl . '/episodes/' . $slug,
                $lastmod,
                'monthly',
                '0.8'
            );
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= implode('', $urls);
        $xml .= '</urlset>' . "\n";

        return file_put_contents($this->outputFile, $xml, LOCK_EX) !== false;
    }

    // =========================================================================
    //  Privé
    // =========================================================================

    /**
     * Construit une balise <url> pour le sitemap.
     *
     * @param string $loc        URL canonique de la page
     * @param string $lastmod    Date de dernière modification (ISO 8601)
     * @param string $changefreq Fréquence de changement (always|hourly|daily|weekly|monthly|yearly|never)
     * @param string $priority   Priorité relative de 0.0 à 1.0
     */
    private function buildUrl(
        string $loc,
        string $lastmod,
        string $changefreq,
        string $priority
    ): string {
        // ENT_XML1 pour s'assurer que les & dans les URLs sont bien encodés &amp;
        $safeLoc     = htmlspecialchars($loc, ENT_XML1, 'UTF-8');
        $safeLastmod = htmlspecialchars($lastmod, ENT_XML1, 'UTF-8');

        return "  <url>\n"
             . "    <loc>{$safeLoc}</loc>\n"
             . "    <lastmod>{$safeLastmod}</lastmod>\n"
             . "    <changefreq>{$changefreq}</changefreq>\n"
             . "    <priority>{$priority}</priority>\n"
             . "  </url>\n";
    }
}
