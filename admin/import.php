<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->requireLogin();

$parser   = new EpisodeParser($config['content_dir']);
$episodes = $parser->getAll(true);

// ═══ REDIRECT GUARD ═════════════════════════════════════════════════════════
// Redirect if episodes already exist (unless resuming an import)

$manifestFile = ROOT_DIR . '/config/import_manifest.json';
if (!empty($episodes) && !file_exists($manifestFile)) {
    redirect(BASE . '/admin/episodes.php');
}

// ═══ HELPERS ════════════════════════════════════════════════════════════════

function slugify(string $str): string {
    // Transliterate accented characters (intl extension), fallback to iconv
    if (function_exists('transliterator_transliterate')) {
        $str = transliterator_transliterate('Any-Latin; Latin-ASCII', $str) ?: $str;
    } else {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str) ?: $str;
    }
    $str = strtolower($str);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    $str = trim($str, '-');
    return substr($str, 0, 80);
}

function downloadFile(string $url, string $dest, int $timeoutSec = 120): bool {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($dest, 'wb');
        if (!$fp) return false;

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'Badal-Importer/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR    => true,
        ]);

        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $code >= 400) { @unlink($dest); return false; }

        if (file_exists($dest) && filesize($dest) > 0) {
            $head = file_get_contents($dest, false, null, 0, 64);
            if ($head !== false && (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<html') !== false)) {
                @unlink($dest);
                return false;
            }
        }

        return file_exists($dest) && filesize($dest) > 0;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout'         => $timeoutSec,
            'follow_location' => true,
            'max_redirects'   => 5,
            'user_agent'      => 'Badal-Importer/1.0',
            'ignore_errors'   => false,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) === 0) return false;
    if (stripos($data, '<!DOCTYPE') !== false || stripos(substr($data, 0, 256), '<html') !== false) {
        return false;
    }

    return file_put_contents($dest, $data, LOCK_EX) !== false;
}

function importWriteKey(string $file, string $key, string $value): void {
    $content     = file_get_contents($file);
    $escaped     = str_replace("'", "\\'", $value);
    $count       = 0;
    $replacement = "'$key' => '$escaped'";

    $content = preg_replace_callback(
        "/('$key'\s*=>\s*)'(?:[^'\\\\]|\\\\.)*'/",
        function () use ($replacement) { return $replacement; },
        $content,
        -1,
        $count
    );

    if ($count === 0) {
        $content = preg_replace(
            '/\n\];\s*$/',
            "\n    '$key' => '$escaped',\n\n];\n",
            $content
        );
    }

    file_put_contents($file, $content);
}

function parseRssDate(string $pubDate) {
    $pubDate = trim($pubDate);
    if (!$pubDate) return false;

    $pubDate = preg_replace('/\s*\([^)]*\)\s*$/', '', $pubDate);

    // French month abbreviations to English
    $frMonths = [
        'Jan' => 'Jan', 'Fev' => 'Feb', 'Fév' => 'Feb', 'Mar' => 'Mar',
        'Avr' => 'Apr', 'Mai' => 'May', 'Jui' => 'Jun', 'Jul' => 'Jul',
        'Juil'=> 'Jul', 'Aoû' => 'Aug', 'Aou' => 'Aug', 'Sep' => 'Sep',
        'Oct' => 'Oct', 'Nov' => 'Nov', 'Déc' => 'Dec', 'Dec' => 'Dec',
    ];
    foreach ($frMonths as $fr => $en) {
        if ($fr !== $en && stripos($pubDate, $fr) !== false) {
            $pubDate = str_ireplace($fr, $en, $pubDate);
            break;
        }
    }

    // Fix common day-name typos
    $pubDate = preg_replace('/^Wen\b/i',  'Wed', $pubDate);
    $pubDate = preg_replace('/^Thi\b/i',  'Thu', $pubDate);
    $pubDate = preg_replace('/^Thr\b/i',  'Thu', $pubDate);
    $pubDate = preg_replace('/^Tues\b/i', 'Tue', $pubDate);
    $pubDate = preg_replace('/^Thur\b/i', 'Thu', $pubDate);

    // Normalize GMT offsets
    $pubDate = preg_replace_callback('/GMT\s*([+-])(\d{1,2})(\d{2})?$/', function ($m) {
        $hours = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $mins  = $m[3] ?? '00';
        return $m[1] . $hours . $mins;
    }, $pubDate);
    $pubDate = preg_replace('/\bGMT\s*$/', '+0000', $pubDate);

    $ts = strtotime($pubDate);
    if ($ts !== false) return $ts;

    $stripped = preg_replace('/^[A-Za-z]+,?\s*/', '', $pubDate);
    $ts = strtotime($stripped);
    if ($ts !== false) return $ts;

    return false;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  AJAX ACTIONS -- each returns JSON and exits
// ═══════════════════════════════════════════════════════════════════════════════

$ajaxAction = $_POST['ajax_action'] ?? '';

// ═══ PARSE: read RSS, save manifest + podcast metadata ══════════════════════

if ($ajaxAction === 'parse') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();

    $rssContent = null;

    // -- Fetch RSS from file upload or URL
    if (!empty($_FILES['rss_file']['tmp_name'])) {
        $rssContent = file_get_contents($_FILES['rss_file']['tmp_name']);
    } elseif (!empty($_POST['rss_url'])) {
        $rssUrl = trim($_POST['rss_url']);
        if (function_exists('curl_init')) {
            $ch = curl_init($rssUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_USERAGENT      => 'Badal-Importer/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $rssContent = curl_exec($ch);
            $httpCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr    = curl_error($ch);
            curl_close($ch);
            if ($rssContent === false || $httpCode >= 400) {
                $rssContent = false;
            }
        } else {
            $rssContent = @file_get_contents($rssUrl, false, stream_context_create([
                'http' => ['timeout' => 30, 'user_agent' => 'Badal-Importer/1.0', 'follow_location' => true],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]));
        }
    }

    if (!$rssContent) {
        echo json_encode(['ok' => false, 'error' => "Impossible de récupérer le flux RSS."]);
        exit;
    }

    // -- Parse XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rssContent);
    if (!$xml) {
        $lastErr = libxml_get_last_error();
        echo json_encode(['ok' => false, 'error' => "Impossible de parser le XML RSS. " . ($lastErr ? $lastErr->message : '')]);
        exit;
    }

    $channel = $xml->channel;
    $ns      = $xml->getNamespaces(true);
    $itunes  = isset($ns['itunes']) ? $channel->children($ns['itunes']) : null;

    // -- Extract podcast-level metadata
    $podTitle  = (string)($channel->title ?? 'Podcast importé');
    $podDesc   = (string)($channel->description ?? '');
    $podAuthor = $itunes ? (string)($itunes->author ?? '') : '';
    $podLang   = (string)($channel->language ?? '');
    $podCat    = '';
    if ($itunes && isset($itunes->category)) {
        $catAttrs = $itunes->category->attributes();
        $podCat   = (string)($catAttrs['text'] ?? '');
    }

    // -- Podcast cover URL
    $podCoverUrl = '';
    if ($itunes && isset($itunes->image)) {
        $imgAttrs    = $itunes->image->attributes();
        $podCoverUrl = (string)($imgAttrs['href'] ?? '');
    }

    // -- Save podcast metadata to config
    $configFile = ROOT_DIR . '/config/config.php';
    if ($podTitle)  importWriteKey($configFile, 'podcast_title', $podTitle);
    if ($podDesc) {
        $cleanDesc = trim(preg_replace('/\s+/', ' ', strip_tags($podDesc)));
        importWriteKey($configFile, 'podcast_description', $cleanDesc);
    }
    if ($podAuthor) importWriteKey($configFile, 'author', $podAuthor);
    if ($podLang)   importWriteKey($configFile, 'language', $podLang);
    if ($podCat)    importWriteKey($configFile, 'category', $podCat);

    // -- Download podcast cover
    $podCoverSaved = false;
    if ($podCoverUrl) {
        $covExt = pathinfo(parse_url($podCoverUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $covExt = preg_replace('/[^a-z0-9]/', '', strtolower($covExt));
        if (!in_array($covExt, ['jpg','jpeg','png','webp'])) $covExt = 'jpg';
        $podCoverDest = $config['audio_dir'] . '/cover.' . $covExt;
        if (downloadFile($podCoverUrl, $podCoverDest)) {
            importWriteKey($configFile, 'cover_image', 'cover.' . $covExt);
            $podCoverSaved = true;
        }
    }

    // -- Backup existing rss.xml
    $rssFile   = ROOT_DIR . '/rss.xml';
    $rssBackup = ROOT_DIR . '/rss_back.xml';
    if (file_exists($rssFile)) {
        rename($rssFile, $rssBackup);
    }

    // -- Extract all episodes into manifest
    $items     = $channel->item ?? [];
    $itemCount = $channel->item ? $channel->item->count() : 0;
    $usedSlugs = [];
    $epList    = [];
    $epNum     = $itemCount;

    foreach ($items as $item) {
        $itItem   = isset($ns['itunes']) ? $item->children($ns['itunes']) : null;

        $title    = (string)($item->title ?? 'Sans titre');
        $pubDate  = (string)($item->pubDate ?? '');
        $desc     = (string)($item->description ?? '');
        $duration = $itItem ? (string)($itItem->duration ?? '') : '';
        $epNumTag = $itItem ? (string)($itItem->episode ?? $epNum) : (string)$epNum;
        $season   = $itItem ? (string)($itItem->season ?? '') : '';
        $explicit = $itItem ? (string)($itItem->explicit ?? 'no') : 'no';
        $author   = $itItem ? (string)($itItem->author ?? '') : (string)($item->author ?? '');
        $subtitle = $itItem ? (string)($itItem->subtitle ?? '') : '';

        // Clean HTML from description
        if (strpos($desc, '<') !== false) {
            $desc = strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $desc));
            $desc = trim(preg_replace("/\n{3,}/", "\n\n", $desc));
        }

        // Parse date
        $date    = '';
        $pubFull = '';
        if ($pubDate) {
            $ts = parseRssDate($pubDate);
            if ($ts) {
                $date    = date('Y-m-d', $ts);
                $pubFull = date('c', $ts);
            }
        }
        if (!$date) $date = date('Y-m-d');
        if (!$pubFull) $pubFull = date('c');

        // Slug (collision avoidance)
        $slug     = slugify($title);
        if (!$slug) $slug = 'episode-' . $epNum;
        $baseSlug = $slug;
        $suffix   = 2;
        while (isset($usedSlugs[$slug])) {
            $slug = $baseSlug . '-' . $suffix++;
        }
        $usedSlugs[$slug] = true;

        // Audio URL from enclosure
        $audioUrl  = '';
        $enclosure = $item->enclosure;
        if ($enclosure) {
            $attrs    = $enclosure->attributes();
            $audioUrl = (string)($attrs['url'] ?? '');
        }

        // Cover URL (with fallbacks)
        $coverUrl = '';
        if ($itItem && isset($itItem->image)) {
            $imgAttrs = $itItem->image->attributes();
            $coverUrl = (string)($imgAttrs['href'] ?? '');
        }
        if (!$coverUrl && isset($ns['media'])) {
            $media = $item->children($ns['media']);
            if (isset($media->thumbnail)) {
                $coverUrl = (string)($media->thumbnail->attributes()['url'] ?? '');
            }
            if (!$coverUrl && isset($media->content)) {
                $mAttrs = $media->content->attributes();
                $mType  = (string)($mAttrs['medium'] ?? $mAttrs['type'] ?? '');
                if (strpos($mType, 'image') !== false || preg_match('/\.(jpe?g|png|webp)$/i', (string)($mAttrs['url'] ?? ''))) {
                    $coverUrl = (string)($mAttrs['url'] ?? '');
                }
            }
        }
        if (!$coverUrl && $podCoverUrl) {
            $coverUrl = $podCoverUrl;
        }

        $epList[] = [
            'title'    => $title,
            'slug'     => $slug,
            'date'     => $date,
            'pubFull'  => $pubFull,
            'desc'     => $desc,
            'subtitle' => $subtitle,
            'duration' => $duration,
            'epNum'    => $epNumTag,
            'season'   => $season,
            'explicit' => $explicit,
            'author'   => $author,
            'audioUrl' => $audioUrl,
            'coverUrl' => $coverUrl,
        ];

        $epNum--;
    }

    // Reset custom episode order
    $parser->resetOrder();

    // Save manifest to disk
    file_put_contents($manifestFile, json_encode([
        'podcast' => [
            'title'      => $podTitle,
            'desc'       => mb_strimwidth(strip_tags($podDesc), 0, 100, '…'),
            'coverSaved' => $podCoverSaved,
        ],
        'episodes' => $epList,
        'total'    => count($epList),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $total = count($epList);

    echo json_encode([
        'ok'         => true,
        'podTitle'   => $podTitle,
        'podDesc'    => mb_strimwidth(strip_tags($podDesc), 0, 100, '…'),
        'coverSaved' => $podCoverSaved,
        'total'      => $total,
        'episodes'   => array_map(function ($ep) {
            return ['title' => $ep['title'], 'slug' => $ep['slug'], 'hasAudio' => !empty($ep['audioUrl']), 'hasCover' => !empty($ep['coverUrl'])];
        }, $epList),
    ]);
    exit;
}

// ═══ IMPORT_EPISODE: download audio + cover + create .md for one episode ════

if ($ajaxAction === 'import_episode') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();

    $index = (int)($_POST['index'] ?? -1);

    if (!file_exists($manifestFile)) {
        echo json_encode(['ok' => false, 'error' => 'Manifest introuvable. Relancez l\'import.']);
        exit;
    }

    $manifest = json_decode(file_get_contents($manifestFile), true);
    if (!$manifest || !isset($manifest['episodes'][$index])) {
        echo json_encode(['ok' => false, 'error' => "Épisode #$index introuvable dans le manifest."]);
        exit;
    }

    $ep       = $manifest['episodes'][$index];
    $audioDir = $config['audio_dir'];
    $logs     = [];

    // -- Audio download
    $audioFile = '';
    if (!empty($ep['audioUrl'])) {
        $ext       = pathinfo(parse_url($ep['audioUrl'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp3';
        $ext       = preg_replace('/[^a-z0-9]/', '', strtolower($ext));
        $epDir     = $audioDir . '/' . $ep['slug'];
        if (!is_dir($epDir)) { mkdir($epDir, 0755, true); }
        $audioFile = $ep['slug'] . '/audio.' . $ext;
        $audioDest = $audioDir . '/' . $audioFile;

        if (file_exists($audioDest) && filesize($audioDest) > 0) {
            $size   = round(filesize($audioDest) / (1024*1024), 1);
            $logs[] = ['type' => 'ok', 'msg' => "Audio déjà présent ($size Mo) — ignoré"];
        } elseif (downloadFile($ep['audioUrl'], $audioDest)) {
            $size   = round(filesize($audioDest) / (1024*1024), 1);
            $logs[] = ['type' => 'ok', 'msg' => "Audio : $audioFile ($size Mo)"];
        } else {
            $logs[]    = ['type' => 'warn', 'msg' => "Audio : échec du téléchargement"];
            $audioFile = '';
        }
    } else {
        $logs[] = ['type' => 'warn', 'msg' => "Aucun fichier audio dans l'enclosure"];
    }

    // -- Cover download
    $coverFile = '';
    if (!empty($ep['coverUrl'])) {
        $ext2      = pathinfo(parse_url($ep['coverUrl'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $ext2      = preg_replace('/[^a-z0-9]/', '', strtolower($ext2));
        if (!in_array($ext2, ['jpg','jpeg','png','webp'])) $ext2 = 'jpg';
        $epDir2    = $audioDir . '/' . $ep['slug'];
        if (!is_dir($epDir2)) { mkdir($epDir2, 0755, true); }
        $coverFile = $ep['slug'] . '/cover.' . $ext2;
        $coverDest = $audioDir . '/' . $coverFile;

        if (file_exists($coverDest) && filesize($coverDest) > 0) {
            $logs[] = ['type' => 'ok', 'msg' => "Cover déjà présente — ignorée"];
        } elseif (downloadFile($ep['coverUrl'], $coverDest)) {
            $sizeK  = round(filesize($coverDest) / 1024);
            $logs[] = ['type' => 'ok', 'msg' => "Cover : $coverFile ({$sizeK} Ko)"];
        } else {
            $logs[]    = ['type' => 'warn', 'msg' => "Cover : échec du téléchargement"];
            $coverFile = '';
        }
    }

    // -- Create episode .md file
    $shortDesc = $ep['subtitle'] ?: $ep['desc'];
    $shortDesc = preg_replace('/\s+/', ' ', trim($shortDesc));
    if (mb_strlen($shortDesc) > 300) {
        $shortDesc = mb_substr($shortDesc, 0, 297) . '…';
    }

    $meta = [
        'title'   => $ep['title'],
        'date'    => $ep['date'],
        'pubdate' => $ep['pubFull'],
    ];
    if ($shortDesc)                $meta['description'] = $shortDesc;
    if ($ep['epNum'])              $meta['episode']     = (int)$ep['epNum'];
    if ($ep['season'])             $meta['season']      = (int)$ep['season'];
    if ($ep['duration'])           $meta['duration']    = $ep['duration'];
    if ($ep['author'])             $meta['guest']       = $ep['author'];
    if ($audioFile)                $meta['audio']       = $audioFile;
    if ($coverFile)                $meta['cover']       = $coverFile;
    if ($ep['explicit'] === 'yes') $meta['explicit']    = 'yes';

    $body = '';
    if ($ep['subtitle'] && $ep['subtitle'] !== $ep['title']) $body .= $ep['subtitle'] . "\n\n";
    $body .= $ep['desc'];

    $saved = $parser->save($ep['slug'], $meta, $body);
    if ($saved) {
        $logs[] = ['type' => 'ok', 'msg' => "Épisode créé : {$ep['slug']}.md"];
    } else {
        $logs[] = ['type' => 'error', 'msg' => "Erreur d'écriture pour {$ep['slug']}.md"];
    }

    echo json_encode(['ok' => $saved, 'logs' => $logs]);
    exit;
}

// ═══ CLEANUP: remove manifest after import ══════════════════════════════════

if ($ajaxAction === 'cleanup') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    if (file_exists($manifestFile)) {
        @unlink($manifestFile);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  HTML PAGE -- form + progress UI (driven by JS)
// ═══════════════════════════════════════════════════════════════════════════════

$pageTitle = 'Importer un podcast';
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!--  STYLES                                                                -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<style>
    /* ── Import hero ──────────────────────────────────────────────────── */
    .import-hero {
        max-width: 620px;
        margin: 0 auto;
        padding: 2.5rem 0;
        text-align: center;
    }
    .import-icon {
        width: 64px;
        height: 64px;
        border-radius: 16px;
        background: rgba(232,255,90,.1);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
    }

    /* ── Form elements ────────────────────────────────────────────────── */
    .import-form .field { margin-bottom: 1rem; text-align: left; }

    .import-divider {
        display: flex;
        align-items: center;
        gap: .75rem;
        margin: 1.25rem 0;
        color: var(--muted);
        font-size: .78rem;
    }
    .import-divider::before,
    .import-divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--border);
    }

    /* ── Log display ──────────────────────────────────────────────────── */
    .log-wrap {
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 1.25rem 1.5rem;
        max-height: 420px;
        overflow-y: auto;
        font-family: monospace;
        font-size: .78rem;
        line-height: 1.7;
        text-align: left;
    }
    .log-line  { padding: .1rem 0; }
    .log-ok    { color: #5aff9a; }
    .log-warn  { color: #ffc85a; }
    .log-error { color: #ff7070; }
    .log-info  { color: var(--muted); }
    .log-title { color: var(--text); font-weight: 700; }

    /* ── Spinner ──────────────────────────────────────────────────────── */
    @keyframes spin { to { transform: rotate(360deg); } }

    .spinner {
        width: 18px;
        height: 18px;
        border: 2.5px solid var(--border);
        border-top-color: var(--accent);
        border-radius: 50%;
        animation: spin .7s linear infinite;
        flex-shrink: 0;
    }
    .spinner-done {
        animation: none;
        border-color: #5aff9a;
        border-top-color: #5aff9a;
        background: #5aff9a;
        position: relative;
    }
    .spinner-done::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 5px;
        width: 5px;
        height: 9px;
        border: solid var(--bg);
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
    }

    /* ── Steps pipeline ───────────────────────────────────────────────── */
    .import-steps {
        display: flex;
        flex-direction: column;
        gap: .6rem;
        margin-bottom: 1.25rem;
        padding: 0;
    }
    .import-step {
        display: flex;
        align-items: center;
        gap: .65rem;
        font-size: .82rem;
        color: var(--muted);
        transition: color .3s;
    }
    .import-step.active { color: var(--text); font-weight: 600; }
    .import-step.done   { color: #5aff9a; }

    .import-step .step-dot {
        width: 18px;
        height: 18px;
        border: 2px solid var(--border);
        border-radius: 50%;
        flex-shrink: 0;
        transition: border-color .3s;
    }
    .import-step.active .step-dot { display: none; }
    .import-step.done   .step-dot { display: none; }

    .import-step .spinner        { display: none; }
    .import-step.active .spinner { display: block; }

    .import-step .check-icon       { display: none; }
    .import-step.done .check-icon  { display: block; }
    .import-step.done .step-dot    { display: none; }

    /* ── Elapsed time ─────────────────────────────────────────────────── */
    .elapsed {
        font-size: .72rem;
        color: var(--muted);
        font-family: monospace;
    }

    /* ── Redirect popup ───────────────────────────────────────────────── */
    .publish-overlay {
        position: fixed;
        inset: 0;
        z-index: 10002;
        background: rgba(0,0,0,.6);
        backdrop-filter: blur(6px);
        display: none;
        align-items: center;
        justify-content: center;
        animation: pub-fade-in .3s ease;
    }
    .publish-overlay.visible { display: flex; }

    @keyframes pub-fade-in {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    .redirect-popup {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 2rem 2.25rem;
        max-width: 520px;
        width: 92%;
        text-align: center;
        box-shadow: 0 24px 64px rgba(0,0,0,.5);
        animation: pub-slide-in .35s cubic-bezier(.25,.46,.45,.94);
        max-height: 90vh;
        overflow-y: auto;
    }
    @keyframes pub-slide-in {
        from { opacity: 0; transform: translateY(24px) scale(.95); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .redirect-icon { margin-bottom: 1rem; }

    .mic-draw {
        stroke-dasharray: 0.15 1.85;
        stroke-dashoffset: 1;
        animation: mic-trace 6s cubic-bezier(.25,.1,.25,1) forwards;
        animation-delay: .3s;
    }
    @keyframes mic-trace {
        0%   { stroke-dashoffset: 1; }
        100% { stroke-dashoffset: -1; }
    }

    .redirect-title {
        font-family: 'Syne', sans-serif;
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--text);
        margin-bottom: .25rem;
        opacity: 0;
        animation: fade-in .5s ease .3s forwards;
    }
    .redirect-subtitle {
        font-size: .88rem;
        color: var(--accent);
        font-weight: 600;
        margin-bottom: 1.25rem;
        opacity: 0;
        animation: fade-in .5s ease .6s forwards;
    }

    .redirect-steps {
        text-align: left;
        display: flex;
        flex-direction: column;
        gap: .6rem;
        opacity: 0;
        animation: fade-in .5s ease .9s forwards;
    }
    .redirect-step {
        display: flex;
        gap: .65rem;
        align-items: flex-start;
        font-size: .8rem;
        line-height: 1.55;
        color: var(--muted);
    }
    .redirect-step code {
        font-size: .72rem;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 4px;
        padding: .1rem .35rem;
    }
    .redirect-step-num {
        width: 22px;
        height: 22px;
        min-width: 22px;
        border-radius: 50%;
        background: rgba(232,255,90,.12);
        color: var(--accent);
        font-size: .7rem;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: .1rem;
    }

    @keyframes fade-in {
        from { opacity: 0; }
        to   { opacity: 1; }
    }
</style>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!--  MAIN CONTENT                                                          -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="main">
    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
            <i data-feather="menu"></i>
        </button>
        <h1>Importer un podcast</h1>
    </div>

    <div class="content">

        <!-- ── Progress UI (hidden until import starts) ───────────────── -->
        <div id="importProgress" style="display:none">
            <div class="card" style="padding:1.5rem">

                <!-- Steps pipeline -->
                <div class="import-steps" id="importSteps">
                    <div class="import-step" id="step-parse">
                        <div class="step-dot"></div>
                        <div class="spinner"></div>
                        <div class="spinner-done check-icon"></div>
                        <span>Lecture du flux RSS…</span>
                    </div>
                    <div class="import-step" id="step-meta">
                        <div class="step-dot"></div>
                        <div class="spinner"></div>
                        <div class="spinner-done check-icon"></div>
                        <span>Enregistrement des métadonnées du podcast</span>
                    </div>
                    <div class="import-step" id="step-episodes">
                        <div class="step-dot"></div>
                        <div class="spinner"></div>
                        <div class="spinner-done check-icon"></div>
                        <span id="step-episodes-label">Téléchargement des épisodes</span>
                    </div>
                    <div class="import-step" id="step-done">
                        <div class="step-dot"></div>
                        <div class="spinner"></div>
                        <div class="spinner-done check-icon"></div>
                        <span>Finalisation</span>
                    </div>
                </div>

                <!-- Global progress bar -->
                <div style="margin-bottom:1.25rem">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem">
                        <span style="font-size:.78rem;font-weight:700" id="progressLabel">Démarrage…</span>
                        <div style="display:flex;gap:1rem;align-items:center">
                            <span class="elapsed" id="elapsedTime"></span>
                            <span style="font-size:.72rem;color:var(--muted)" id="progressCount"></span>
                        </div>
                    </div>
                    <div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                        <div id="progressBar" style="height:100%;background:var(--accent);border-radius:3px;width:0%;transition:width .4s ease"></div>
                    </div>
                </div>

                <!-- Episode sub-step -->
                <div style="margin-bottom:1.25rem;display:none" id="stepWrap">
                    <div style="font-size:.70rem;color:var(--muted);margin-bottom:.3rem" id="stepLabel"></div>
                    <div style="height:3px;background:var(--border);border-radius:2px;overflow:hidden">
                        <div id="stepBar" style="height:100%;background:rgba(232,255,90,.5);border-radius:2px;width:0%;transition:width .3s ease"></div>
                    </div>
                </div>

                <div class="log-wrap" id="logWrap"></div>
            </div>

            <!-- Action buttons (shown when done) -->
            <div id="importActions" style="display:none;margin-top:1rem">
                <div style="display:flex;gap:1rem">
                    <a href="<?= url('/admin/episodes.php') ?>" class="btn" style="flex:1;text-align:center">
                        <i data-feather="list"></i> Voir les épisodes
                    </a>
                    <a href="<?= url('/') ?>" target="_blank" class="btn btn-ghost" style="flex:1;text-align:center">
                        <i data-feather="home"></i> Voir le site ↗
                    </a>
                </div>
            </div>
        </div>

        <!-- ── Feed redirect popup ────────────────────────────────────── -->
        <div class="publish-overlay" id="redirectOverlay">
            <div class="redirect-popup">
                <div class="redirect-icon">
                    <svg width="200" height="200" viewBox="0 0 400 400" fill="none">
                        <path class="mic-draw" pathLength="1"
                            stroke="var(--accent)" stroke-width="14" stroke-linecap="round" stroke-linejoin="round"
                            d="M41.19,184.02h15.58c7.73,0,14-6.27,14-14v-9.15c0-7.73,6.26-13.99,13.99-13.99h0c7.73,0,13.99,6.26,13.99,13.99v23.48s0,34.06,0,34.06c0,7.73,6.26,13.99,13.99,13.99h0c7.73,0,13.99-6.26,13.99-13.99v-34.06s0-84.13,0-84.13c0-7.73,6.26-13.99,13.99-13.99h0c7.73,0,13.99,6.26,13.99,13.99v84.13s0,81.47,0,81.47c0,7.73,6.26,13.99,13.99,13.99h0c7.73,0,13.99-6.26,13.99-13.99v-81.47s0-56.06,0-56.06c0-7.73,6.26-13.99,13.99-13.99h0c7.73,0,13.99,6.26,13.99,13.99v56.06s0,33.47,0,33.47c0,7.73,6.26,13.99,13.99,13.99h0c7.73,0,13.99-6.26,13.99-13.99v-27.45c0-7.73,6.27-14,14-14h17.06c7.73,0,14,6.27,14,14v8.58c0,46.14-36.9,84.38-83.04,84.72-46.5.34-84.31-37.25-84.31-83.67v-9.91c0-7.73,6.27-14,14-14h19.81c7.73,0,14,6.27,14,14v8.99c0,20.09,16.44,36.9,36.54,36.53,19.49-.36,35.18-16.04,35.18-35.61v-94.92c0-20.13-16.47-36.97-36.6-36.56-19.46.39-35.08,16.29-35.08,35.85v95.62h0c0,19.71,15.91,35.72,35.61,35.85h.9s-.56,95.64-.56,95.64h47.67-95.62"/>
                    </svg>
                </div>
                <div class="redirect-title">Import terminé !</div>
                <div class="redirect-subtitle">Pensez à rediriger votre ancien flux RSS</div>

                <div class="redirect-steps">
                    <div class="redirect-step">
                        <div class="redirect-step-num">1</div>
                        <div>Vérifiez vos épisodes importés et le flux <code>/rss.xml</code> de cette installation.</div>
                    </div>
                    <div class="redirect-step">
                        <div class="redirect-step-num">2</div>
                        <div>Sur <strong>l'ancien hébergeur</strong>, ajoutez une redirection vers le nouveau flux. Si l'ancien est aussi un Badal, allez dans <em>Outils → Redirection du flux RSS</em>.</div>
                    </div>
                    <div class="redirect-step">
                        <div class="redirect-step-num">3</div>
                        <div>Les balises <code>&lt;itunes:new-feed-url&gt;</code> et <code>&lt;podcast:previousUrl&gt;</code> indiquent aux agrégateurs (Apple, Spotify…) la nouvelle adresse.</div>
                    </div>
                    <div class="redirect-step">
                        <div class="redirect-step-num">4</div>
                        <div>Gardez l'ancien flux actif <strong>au moins 4 semaines</strong>. Comptez 24 à 72 h pour la propagation.</div>
                    </div>
                    <div class="redirect-step">
                        <div class="redirect-step-num">5</div>
                        <div>Désactivez la redirection sur l'ancien hébergeur une fois tous les abonnés migrés.</div>
                    </div>
                </div>

                <div style="display:flex;gap:.75rem;margin-top:1.5rem">
                    <a href="<?= url('/admin/episodes.php') ?>" class="btn" style="flex:1;justify-content:center">
                        <i data-feather="list"></i> Voir les épisodes
                    </a>
                    <button type="button" class="btn btn-ghost" style="flex:1;justify-content:center" onclick="document.getElementById('redirectOverlay').classList.remove('visible')">
                        Fermer
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Import form ────────────────────────────────────────────── -->
        <div id="importForm">
            <div class="import-hero">
                <div class="import-icon">
                    <i data-feather="download-cloud" style="width:28px;height:28px;color:var(--accent)"></i>
                </div>
                <h2 style="font-size:1.35rem;font-weight:800;margin-bottom:.5rem">Importer un podcast existant</h2>
                <p style="color:var(--muted);font-size:.88rem;margin-bottom:2rem;line-height:1.6">
                    Badal va lire votre flux RSS, télécharger tous les épisodes et leurs couvertures,
                    et créer les fiches avec les métadonnées existantes.
                </p>

                <form id="importFormEl" enctype="multipart/form-data" class="import-form">
                    <div class="field">
                        <label for="rss_file">Fichier RSS <span style="color:var(--muted)">.xml</span></label>
                        <input type="file" id="rss_file" name="rss_file" accept=".xml,application/rss+xml,text/xml"
                               onchange="document.getElementById('rss_url').value=''">
                    </div>

                    <div class="import-divider">ou</div>

                    <div class="field">
                        <label for="rss_url">URL du flux RSS</label>
                        <input type="url" id="rss_url" name="rss_url" placeholder="https://monpodcast.com/rss.xml"
                               oninput="document.getElementById('rss_file').value=''">
                    </div>

                    <div style="background:rgba(232,255,90,.06);border:1px solid rgba(232,255,90,.15);border-radius:10px;padding:1rem 1.25rem;margin:.75rem 0 1.5rem;text-align:left;font-size:.8rem;color:var(--muted);line-height:1.6">
                        <strong style="color:var(--text);display:block;margin-bottom:.4rem">Ce qui sera importé</strong>
                        Titre · date · durée · numéro d'épisode · saison · auteur/invité · show notes<br>
                        + téléchargement des fichiers audio et des couvertures
                    </div>

                    <button type="submit" class="btn" style="width:100%;justify-content:center" id="importBtn">
                        <i data-feather="download-cloud"></i>
                        Lancer l'importation
                    </button>
                </form>
            </div>
        </div>

    </div><!-- /.content -->
</div><!-- /.main -->

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!--  JAVASCRIPT                                                            -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<script>
var CSRF  = <?= json_encode(csrf_token()) ?>;
var ICONS = { info: '·', ok: '✓', warn: '⚠', error: '✗', title: '▸' };

// ═══ UTILITIES ══════════════════════════════════════════════════════════════

function setStep(stepId) {
    var steps = document.querySelectorAll('.import-step');
    var found = false;
    for (var i = 0; i < steps.length; i++) {
        if (steps[i].id === stepId) {
            found = true;
            steps[i].className = 'import-step active';
        } else if (!found) {
            steps[i].className = 'import-step done';
        } else {
            steps[i].className = 'import-step';
        }
    }
}

function allDone() {
    document.querySelectorAll('.import-step').forEach(function (s) {
        s.className = 'import-step done';
    });
}

function setProgress(pct, label, count) {
    document.getElementById('progressBar').style.width   = pct + '%';
    document.getElementById('progressLabel').textContent  = label;
    document.getElementById('progressCount').textContent  = count || '';
}

function setSubStep(label, pct) {
    var w = document.getElementById('stepWrap');
    if (!label) { w.style.display = 'none'; return; }
    w.style.display = '';
    document.getElementById('stepLabel').textContent = label;
    document.getElementById('stepBar').style.width   = pct + '%';
}

function log(msg, type) {
    var wrap = document.getElementById('logWrap');
    var cls  = 'log-line' + (type ? ' log-' + type : ' log-info');
    var icon = ICONS[type] || ICONS.info;
    var div  = document.createElement('div');
    div.className = cls;
    if (type === 'title') {
        div.innerHTML = icon + ' <strong>' + escHtml(msg) + '</strong>';
    } else {
        div.textContent = icon + ' ' + msg;
    }
    wrap.appendChild(div);
    wrap.scrollTop = wrap.scrollHeight;
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// ═══ ELAPSED TIME ═══════════════════════════════════════════════════════════

var startTime     = null;
var timerInterval = null;

function startTimer() {
    startTime     = Date.now();
    timerInterval = setInterval(updateTimer, 1000);
}

function updateTimer() {
    if (!startTime) return;
    var elapsed = Math.floor((Date.now() - startTime) / 1000);
    var h     = Math.floor(elapsed / 3600);
    var m     = Math.floor((elapsed % 3600) / 60);
    var s     = elapsed % 60;
    var parts = [];
    if (h > 0) parts.push(h + 'h');
    parts.push(String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'));
    document.getElementById('elapsedTime').textContent = parts.join('');
}

function stopTimer() {
    clearInterval(timerInterval);
    updateTimer();
}

// ═══ MAIN IMPORT ORCHESTRATOR ═══════════════════════════════════════════════

document.getElementById('importFormEl').addEventListener('submit', function (e) {
    e.preventDefault();

    var fileInput = document.getElementById('rss_file');
    var urlInput  = document.getElementById('rss_url');
    if (!fileInput.value && !urlInput.value.trim()) {
        alert('Fournissez un fichier RSS ou une URL.');
        return;
    }

    // Switch to progress view
    document.getElementById('importForm').style.display     = 'none';
    document.getElementById('importProgress').style.display = '';
    startTimer();

    // Phase 1: parse RSS
    setStep('step-parse');
    setProgress(0, 'Lecture du flux RSS…', '');
    log('Envoi du flux RSS…', 'info');

    var fd = new FormData();
    fd.append('ajax_action', 'parse');
    fd.append('csrf_token', CSRF);
    if (fileInput.files[0]) {
        fd.append('rss_file', fileInput.files[0]);
    } else {
        fd.append('rss_url', urlInput.value.trim());
    }

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) {
                return r.text().then(function (txt) {
                    throw new Error('HTTP ' + r.status + ': ' + txt.substring(0, 200));
                });
            }
            return r.text().then(function (txt) {
                try { return JSON.parse(txt); }
                catch (e) { throw new Error('JSON invalide: ' + txt.substring(0, 200)); }
            });
        })
        .then(function (data) {
            if (!data.ok) {
                log(data.error || 'Erreur lors du parsing RSS.', 'error');
                setProgress(0, 'Erreur', '');
                stopTimer();
                return;
            }

            // Phase 1 done
            setStep('step-meta');
            log('Flux détecté : ' + data.podTitle, 'ok');
            if (data.podDesc) log('Description : ' + data.podDesc, 'info');
            log('Métadonnées du podcast enregistrées.', 'ok');
            if (data.coverSaved) log('Cover du podcast téléchargée.', 'ok');

            setStep('step-episodes');
            log(data.total + ' épisodes trouvés dans le flux.', 'info');

            // Phase 2: import episodes one by one
            importEpisodes(data.episodes, data.total);
        })
        .catch(function (err) {
            log('Erreur réseau : ' + err.message, 'error');
            setProgress(0, 'Erreur réseau', '');
            stopTimer();
        });
});

// ═══ EPISODE IMPORT LOOP ════════════════════════════════════════════════════

function importEpisodes(episodes, total) {
    var done   = 0;
    var errors = 0;
    var warns  = 0;

    function next() {
        if (done >= total) {
            // Phase 3: finalize
            setStep('step-done');
            setSubStep('', 0);
            setProgress(100, 'Import terminé', done + ' / ' + total);

            var el = document.getElementById('step-episodes-label');
            if (el) el.textContent = 'Téléchargement des épisodes (' + done + '/' + total + ')';

            log('', 'info');
            var summary = 'Import terminé — ' + done + '/' + total + ' épisodes importés.';
            if (warns > 0)  summary += ' (' + warns + ' avertissement' + (warns > 1 ? 's' : '') + ')';
            if (errors > 0) summary += ' (' + errors + ' erreur' + (errors > 1 ? 's' : '') + ')';
            log(summary, 'ok');

            allDone();
            stopTimer();

            // Show action buttons
            document.getElementById('importActions').style.display = '';

            // Cleanup manifest
            var cfd = new FormData();
            cfd.append('ajax_action', 'cleanup');
            cfd.append('csrf_token', CSRF);
            fetch(window.location.href, { method: 'POST', body: cfd });

            // Show redirect popup
            setTimeout(function () {
                var o = document.getElementById('redirectOverlay');
                if (o) o.classList.add('visible');
            }, 800);

            return;
        }

        var ep    = episodes[done];
        var num   = done + 1;
        var label = 'Épisode ' + num + '/' + total + ' — ' + truncate(ep.title, 50);

        setProgress(Math.round(done / total * 100), label, done + ' / ' + total);
        setSubStep('Téléchargement…', 0);

        var el = document.getElementById('step-episodes-label');
        if (el) el.textContent = 'Téléchargement des épisodes (' + num + '/' + total + ')';

        log('', 'info');
        log('[' + num + '/' + total + '] ' + ep.title, 'title');

        var fd = new FormData();
        fd.append('ajax_action', 'import_episode');
        fd.append('csrf_token', CSRF);
        fd.append('index', done);

        setSubStep('Téléchargement audio + cover…', 33);

        fetch(window.location.href, { method: 'POST', body: fd })
            .then(function (r) {
                if (!r.ok) {
                    return r.text().then(function (txt) {
                        throw new Error('HTTP ' + r.status + ': ' + txt.substring(0, 200));
                    });
                }
                return r.text().then(function (txt) {
                    try { return JSON.parse(txt); }
                    catch (e) { throw new Error('JSON invalide: ' + txt.substring(0, 200)); }
                });
            })
            .then(function (data) {
                setSubStep('Enregistrement…', 90);

                if (data.logs) {
                    data.logs.forEach(function (l) {
                        log(l.msg, l.type);
                        if (l.type === 'warn')  warns++;
                        if (l.type === 'error') errors++;
                    });
                }

                if (!data.ok) errors++;

                done++;
                setProgress(Math.round(done / total * 100), label, done + ' / ' + total);
                setSubStep('', 0);

                // Continue to next episode
                next();
            })
            .catch(function (err) {
                log('Erreur pour ' + ep.title + ' : ' + err.message, 'error');
                errors++;
                done++;

                // Continue despite error
                next();
            });
    }

    next();
}

function truncate(s, n) {
    return s.length > n ? s.substring(0, n - 1) + '…' : s;
}

// ═══ KEYBOARD SHORTCUTS ════════════════════════════════════════════════════

// Close redirect popup with Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var o = document.getElementById('redirectOverlay');
        if (o) o.classList.remove('visible');
    }
});
</script>
</body>
</html>
