<?php
// =============================================================================
//  core/RssGenerator.php — Apple Podcasts-compatible RSS 2.0 feed generation
//
//  Produces XML conforming to the RSS 2.0 specification enriched with namespaces:
//    - itunes  : Apple Podcasts metadata (category, image, duration...)
//    - content : CDATA-encoded body (content:encoded)
//    - atom    : canonical link to the feed itself (atom:link)
//
//  Usage:
//      $rss = new RssGenerator($config);
//      echo $rss->generate($episodes);
// =============================================================================

class RssGenerator {

    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    // =========================================================================
    //  Feed generation
    // =========================================================================

    /**
     * Generates the complete RSS feed XML from the list of episodes.
     *
     * Episodes without an audio file are skipped (an <enclosure> is required
     * by podcast aggregators for an item to be recognized).
     *
     * @param  array  $episodes  Episode arrays as returned by EpisodeParser
     * @return string            Feed XML, ready to be sent with Content-Type: application/rss+xml
     */
    public function generate(array $episodes): string {
        $xml  = $this->buildChannelOpen();
        $xml .= $this->buildChannelMeta();
        $xml .= $this->buildChannelImage();

        foreach ($episodes as $ep) {
            // An episode without audio is not distributable — skip it
            if (empty($ep['audio'])) {
                continue;
            }
            $xml .= $this->buildItem($ep);
        }

        $xml .= "</channel>\n</rss>";
        return $xml;
    }

    // =========================================================================
    //  Channel construction
    // =========================================================================

    /** XML declaration + opening <rss> with required namespaces */
    private function buildChannelOpen(): string {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0"',
            '  xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"',
            '  xmlns:content="http://purl.org/rss/1.0/modules/content/"',
            '  xmlns:atom="http://www.w3.org/2005/Atom"',
            '  xmlns:podcast="https://podcastindex.org/namespace/1.0"',
            '  xmlns:googleplay="http://www.google.com/schemas/play-podcasts/1.0">',
            '<channel>',
            '',
        ]);
    }

    /** Channel metadata (title, link, description, itunes:*...) */
    private function buildChannelMeta(): string {
        $baseUrl  = $this->baseUrl();
        $title    = $this->x($this->config['podcast_title']);
        $desc     = $this->x($this->config['podcast_description']);
        $author   = $this->x($this->config['author']);
        $email    = $this->x($this->config['email']);
        $language = $this->config['language'] ?? 'fr-FR';
        $category = $this->x($this->config['category'] ?? 'Technology');

        $xBaseUrl = $this->x($baseUrl);

        return <<<XML
    <title>{$title}</title>
    <link>{$xBaseUrl}</link>
    <description>{$desc}</description>
    <language>{$language}</language>
    <lastBuildDate>{$this->rssDate()}</lastBuildDate>
    <atom:link href="{$xBaseUrl}/rss.xml" rel="self" type="application/rss+xml"/>

    <itunes:author>{$author}</itunes:author>
    <itunes:summary>{$desc}</itunes:summary>
    <itunes:owner>
      <itunes:name>{$author}</itunes:name>
      <itunes:email>{$email}</itunes:email>
    </itunes:owner>
    <itunes:category text="{$category}"/>
    <itunes:explicit>false</itunes:explicit>
    <itunes:type>episodic</itunes:type>

    <googleplay:author>{$author}</googleplay:author>
    <googleplay:description>{$desc}</googleplay:description>
    <googleplay:category text="{$category}"/>
    <googleplay:explicit>no</googleplay:explicit>

    <podcast:locked>no</podcast:locked>
{$this->buildRedirectTags()}    <generator>Badal CMS {$this->x(Version::current())} — https://github.com/robotetdragon/badal-cms</generator>

XML;
    }

    /**
     * Channel image tags (RSS <image> + itunes:image).
     * Omitted if no image is configured.
     */
    private function buildChannelImage(): string {
        $coverImage = $this->config['cover_image'] ?? '';
        if (!$coverImage) {
            return '';
        }

        $baseUrl  = $this->baseUrl();
        $title    = $this->x($this->config['podcast_title']);
        $xBaseUrl = $this->x($baseUrl);
        $imgUrl   = $this->x($baseUrl . '/audio/' . $coverImage);

        return <<<XML
    <itunes:image href="{$imgUrl}"/>
    <image>
      <url>{$imgUrl}</url>
      <title>{$title}</title>
      <link>{$xBaseUrl}</link>
    </image>

XML;
    }

    // =========================================================================
    //  Feed redirection
    // =========================================================================

    /**
     * Generates feed redirect tags if a URL is configured.
     * Allows moving a podcast to another host without losing
     * subscribers.
     *
     * Generated tags:
     *   - <itunes:new-feed-url>  : recognized by Apple Podcasts, Spotify, Overcast...
     *   - <podcast:previousUrl>  : Podcasting 2.0 (PocketCasts, modern apps)
     */
    private function buildRedirectTags(): string {
        $newUrl = trim($this->config['redirect_feed_url'] ?? '');
        if (!$newUrl) {
            return '';
        }

        $xNewUrl  = $this->x($newUrl);
        $xCurrent = $this->x($this->baseUrl() . '/rss.xml');

        return "    <itunes:new-feed-url>{$xNewUrl}</itunes:new-feed-url>\n"
             . "    <podcast:previousUrl>{$xCurrent}</podcast:previousUrl>\n";
    }

    // =========================================================================
    //  Item (episode) construction
    // =========================================================================

    /**
     * Builds the RSS <item> block for an episode.
     *
     * Generated fields:
     *   - title, link, guid, pubDate, description
     *   - content:encoded (description in CDATA for rich clients)
     *   - enclosure (audio URL + size + MIME type)
     *   - itunes:duration, itunes:episode, itunes:author, itunes:explicit
     */
    private function buildItem(array $ep): string {
        $baseUrl  = $this->baseUrl();
        $author   = $this->x($this->config['author']);

        $slug     = $ep['slug'] ?? '';
        $title    = $this->x($ep['title'] ?? 'Sans titre');
        $desc     = $this->x(strip_tags($ep['description'] ?? ''));
        $pubDate  = isset($ep['date']) ? date('r', strtotime($ep['date'])) : date('r');
        $epUrl    = $this->x($baseUrl . '/episodes/' . $slug);

        // Audio file — encode each path segment individually
        $audioDir  = $this->config['audio_dir'] ?? (ROOT_DIR . '/audio');
        $audioFile = $audioDir . '/' . $ep['audio'];
        $audioUrl  = $this->x($baseUrl . '/audio/' . $this->encodeAudioPath($ep['audio']));
        $fileSize  = file_exists($audioFile) ? filesize($audioFile) : 0;
        $mimeType  = $this->mimeType($ep['audio']);

        // Episode without audio file present on disk → skip it
        if ($fileSize === 0) {
            return '';
        }

        $xml = "  <item>\n";
        $xml .= "    <title>{$title}</title>\n";
        $xml .= "    <link>{$epUrl}</link>\n";
        // isPermaLink="true" signals to aggregators that the guid is a canonical URL
        $xml .= "    <guid isPermaLink=\"true\">{$epUrl}</guid>\n";
        $xml .= "    <pubDate>{$pubDate}</pubDate>\n";
        $xml .= "    <description>{$desc}</description>\n";
        $body  = $ep['content_html'] ?? '';
        $xml .= "    <content:encoded><![CDATA[" . ($body ?: $desc) . "]]></content:encoded>\n";
        $xml .= "    <enclosure url=\"{$audioUrl}\" length=\"{$fileSize}\" type=\"{$mimeType}\"/>\n";
        $xml .= "    <itunes:summary>{$desc}</itunes:summary>\n";
        $xml .= "    <itunes:author>{$author}</itunes:author>\n";
        $isExplicit = ($ep['explicit'] ?? '') === 'yes';
        $xml .= "    <itunes:explicit>" . ($isExplicit ? 'true' : 'false') . "</itunes:explicit>\n";
        $xml .= "    <googleplay:description>{$desc}</googleplay:description>\n";
        $xml .= "    <googleplay:explicit>" . ($isExplicit ? 'yes' : 'no') . "</googleplay:explicit>\n";

        // Optional fields — omitted if empty to avoid cluttering the feed
        if (!empty($ep['duration'])) {
            $xml .= "    <itunes:duration>{$this->x($ep['duration'])}</itunes:duration>\n";
        }
        if (!empty($ep['episode'])) {
            $xml .= "    <itunes:episode>{$this->x($ep['episode'])}</itunes:episode>\n";
        }

        // Per-episode cover (Podcasting 2.0 + itunes)
        if (!empty($ep['cover'])) {
            $coverUrl = $this->x($baseUrl . '/audio/' . $this->encodeAudioPath($ep['cover']));
            $xml .= "    <itunes:image href=\"{$coverUrl}\"/>\n";
        }

        // Chapters (Podcasting 2.0)
        if (!empty($ep['has_chapters'])) {
            $chapUrl = $this->x($baseUrl . '/chapters/' . $slug . '.json');
            $xml .= "    <podcast:chapters url=\"{$chapUrl}\" type=\"application/json+chapters\"/>\n";
        }

        $xml .= "  </item>\n";
        return $xml;
    }

    // =========================================================================
    //  Private utilities
    // =========================================================================

    /** Returns the cleaned base_url (without trailing slash) */
    private function baseUrl(): string {
        return rtrim($this->config['base_url'], '/');
    }

    /**
     * Encodes an audio path while preserving directory separators.
     * E.g.: "mon-episode/audio.mp3" → "mon-episode/audio.mp3"  (/ preserved)
     *        "fichier spécial.mp3"  → "fichier%20sp%C3%A9cial.mp3"
     */
    private function encodeAudioPath(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Returns the correct MIME type for an audio file based on its extension.
     * Used in the type attribute of the <enclosure> tag.
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
     * Escapes a string for XML insertion (standard HTML entities).
     * Short alias for htmlspecialchars() for readability in buildItem().
     */
    private function x(string $value): string {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Returns the current date in RFC 2822 format (required by RSS 2.0) */
    private function rssDate(): string {
        return date('r');
    }
}
