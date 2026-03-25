<?php
// =============================================================================
//  core/SitemapGenerator.php — sitemap.xml generation
//
//  Produces an XML sitemap conforming to the sitemaps.org protocol (0.9).
//  Called automatically after each episode creation, modification or deletion
//  to keep the sitemap up to date without a scheduled task.
//
//  The sitemap.xml file is written at the project root and served
//  via the .htaccess rewrite rule: ^sitemap\.xml$ → public/sitemap.php
//
//  Usage:
//      $sitemap = new SitemapGenerator($config['base_url'], ROOT_DIR);
//      $sitemap->generate($parser->getAll());
// =============================================================================

class SitemapGenerator {

    private string $baseUrl;

    /** Absolute path of the sitemap.xml file to write */
    private string $outputFile;

    public function __construct(string $baseUrl, string $rootDir) {
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->outputFile = rtrim($rootDir, '/') . '/sitemap.xml';
    }

    // =========================================================================
    //  Generation
    // =========================================================================

    /**
     * Regenerates the complete sitemap.xml file.
     *
     * Included URLs:
     *   - The home page (priority 1.0, changefreq weekly)
     *   - Each episode page (priority 0.8, changefreq monthly)
     *
     * @param  array  $episodes  Episode arrays (from EpisodeParser::getAll())
     * @return bool              true if the file was written successfully
     */
    public function generate(array $episodes): bool {
        $urls = [];

        // Home page — frequently updated (new season, design...)
        $urls[] = $this->buildUrl($this->baseUrl . '/', date('c'), 'weekly', '1.0');

        // Episode pages — stable after publication
        foreach ($episodes as $ep) {
            $slug = $ep['slug'] ?? '';
            if (!$slug) {
                continue;
            }

            // lastmod based on the episode date, otherwise today
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
    //  Private
    // =========================================================================

    /**
     * Builds a <url> tag for the sitemap.
     *
     * @param string $loc        Canonical URL of the page
     * @param string $lastmod    Last modification date (ISO 8601)
     * @param string $changefreq Change frequency (always|hourly|daily|weekly|monthly|yearly|never)
     * @param string $priority   Relative priority from 0.0 to 1.0
     */
    private function buildUrl(
        string $loc,
        string $lastmod,
        string $changefreq,
        string $priority
    ): string {
        // ENT_XML1 to ensure that & in URLs are properly encoded as &amp;
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
