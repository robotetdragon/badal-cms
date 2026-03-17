<?php
// =============================================================================
//  core/RssGenerator.php — Génération du flux RSS 2.0 compatible Apple Podcasts
//
//  Produit un XML conforme à la spécification RSS 2.0 enrichi des namespaces :
//    - itunes  : métadonnées Apple Podcasts (catégorie, image, durée…)
//    - content : corps encodé en CDATA (content:encoded)
//    - atom    : lien canonique vers le flux lui-même (atom:link)
//
//  Usage :
//      $rss = new RssGenerator($config);
//      echo $rss->generate($episodes);
// =============================================================================

class RssGenerator {

    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    // =========================================================================
    //  Génération du flux
    // =========================================================================

    /**
     * Génère le XML complet du flux RSS à partir de la liste des épisodes.
     *
     * Les épisodes sans fichier audio sont ignorés (un <enclosure> est requis
     * par les agrégateurs de podcasts pour qu'un item soit reconnu).
     *
     * @param  array  $episodes  Tableaux d'épisodes tels que retournés par EpisodeParser
     * @return string            XML du flux, prêt à être envoyé avec Content-Type: application/rss+xml
     */
    public function generate(array $episodes): string {
        $xml  = $this->buildChannelOpen();
        $xml .= $this->buildChannelMeta();
        $xml .= $this->buildChannelImage();

        foreach ($episodes as $ep) {
            // Un épisode sans audio n'est pas diffusable — on le saute
            if (empty($ep['audio'])) {
                continue;
            }
            $xml .= $this->buildItem($ep);
        }

        $xml .= "</channel>\n</rss>";
        return $xml;
    }

    // =========================================================================
    //  Construction du canal (channel)
    // =========================================================================

    /** Déclaration XML + ouverture de <rss> avec les namespaces requis */
    private function buildChannelOpen(): string {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0"',
            '  xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"',
            '  xmlns:content="http://purl.org/rss/1.0/modules/content/"',
            '  xmlns:atom="http://www.w3.org/2005/Atom">',
            '<channel>',
            '',
        ]);
    }

    /** Métadonnées du canal (title, link, description, itunes:*…) */
    private function buildChannelMeta(): string {
        $baseUrl  = $this->config['base_url'];
        $title    = $this->x($this->config['podcast_title']);
        $desc     = $this->x($this->config['podcast_description']);
        $author   = $this->x($this->config['author']);
        $email    = $this->x($this->config['email']);
        $language = $this->config['language'] ?? 'fr-FR';
        $category = $this->x($this->config['category'] ?? 'Technology');

        return <<<XML
          <title>{$title}</title>
          <link>{$baseUrl}</link>
          <description>{$desc}</description>
          <language>{$language}</language>
          <lastBuildDate>{$this->rssDate()}</lastBuildDate>
          <atom:link href="{$baseUrl}/rss.xml" rel="self" type="application/rss+xml"/>

          <itunes:author>{$author}</itunes:author>
          <itunes:summary>{$desc}</itunes:summary>
          <itunes:owner>
            <itunes:name>{$author}</itunes:name>
            <itunes:email>{$email}</itunes:email>
          </itunes:owner>
          <itunes:category text="{$category}"/>
          <itunes:explicit>false</itunes:explicit>
          <itunes:type>episodic</itunes:type>

        XML;
    }

    /**
     * Balises d'image du canal (RSS <image> + itunes:image).
     * Omis si aucune image n'est configurée.
     */
    private function buildChannelImage(): string {
        $coverImage = $this->config['cover_image'] ?? '';
        if (!$coverImage) {
            return '';
        }

        $baseUrl = $this->config['base_url'];
        $title   = $this->x($this->config['podcast_title']);
        $imgUrl  = $baseUrl . '/audio/' . $coverImage;

        return <<<XML
          <itunes:image href="{$imgUrl}"/>
          <image>
            <url>{$imgUrl}</url>
            <title>{$title}</title>
            <link>{$baseUrl}</link>
          </image>

        XML;
    }

    // =========================================================================
    //  Construction d'un item (épisode)
    // =========================================================================

    /**
     * Construit le bloc <item> RSS pour un épisode.
     *
     * Champs générés :
     *   - title, link, guid, pubDate, description
     *   - content:encoded (description en CDATA pour les clients riches)
     *   - enclosure (URL audio + taille + MIME type)
     *   - itunes:duration, itunes:episode, itunes:author, itunes:explicit
     */
    private function buildItem(array $ep): string {
        $baseUrl  = $this->config['base_url'];
        $author   = $this->x($this->config['author']);

        $slug     = $ep['slug'] ?? '';
        $title    = $this->x($ep['title'] ?? 'Sans titre');
        $desc     = $this->x(strip_tags($ep['description'] ?? ''));
        $pubDate  = isset($ep['date']) ? date('r', strtotime($ep['date'])) : date('r');
        $epUrl    = $baseUrl . '/episodes/' . $slug;

        // Fichier audio
        $audioFile = ROOT_DIR . '/audio/' . $ep['audio'];
        $audioUrl  = $baseUrl . '/audio/' . rawurlencode($ep['audio']);
        $fileSize  = file_exists($audioFile) ? filesize($audioFile) : 0;
        $mimeType  = $this->mimeType($ep['audio']);

        $xml = "  <item>\n";
        $xml .= "    <title>{$title}</title>\n";
        $xml .= "    <link>{$epUrl}</link>\n";
        // isPermaLink="true" signale aux agrégateurs que le guid est une URL canonique
        $xml .= "    <guid isPermaLink=\"true\">{$epUrl}</guid>\n";
        $xml .= "    <pubDate>{$pubDate}</pubDate>\n";
        $xml .= "    <description>{$desc}</description>\n";
        $xml .= "    <content:encoded><![CDATA[{$desc}]]></content:encoded>\n";
        $xml .= "    <enclosure url=\"{$audioUrl}\" length=\"{$fileSize}\" type=\"{$mimeType}\"/>\n";
        $xml .= "    <itunes:summary>{$desc}</itunes:summary>\n";
        $xml .= "    <itunes:author>{$author}</itunes:author>\n";
        $xml .= "    <itunes:explicit>false</itunes:explicit>\n";

        // Champs optionnels — omis si vides pour ne pas polluer le flux
        if (!empty($ep['duration'])) {
            $xml .= "    <itunes:duration>{$ep['duration']}</itunes:duration>\n";
        }
        if (!empty($ep['episode'])) {
            $xml .= "    <itunes:episode>{$ep['episode']}</itunes:episode>\n";
        }

        $xml .= "  </item>\n";
        return $xml;
    }

    // =========================================================================
    //  Utilitaires privés
    // =========================================================================

    /**
     * Retourne le MIME type correct pour un fichier audio à partir de son extension.
     * Utilisé dans l'attribut type de la balise <enclosure>.
     */
    private function mimeType(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
            'm4a' => 'audio/x-m4a', 'aac' => 'audio/aac',
            'wav' => 'audio/wav', 'flac' => 'audio/flac', 'opus' => 'audio/opus',
        ];
        return $map[$ext] ?? 'audio/mpeg';
    }

    /**
     * Échappe une chaîne pour l'insertion dans le XML (entités HTML standard).
     * Alias court de htmlspecialchars() pour la lisibilité dans buildItem().
     */
    private function x(string $value): string {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Retourne la date courante au format RFC 2822 (requis par RSS 2.0) */
    private function rssDate(): string {
        return date('r');
    }
}
