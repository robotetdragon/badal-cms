<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$parser   = new EpisodeParser($config['content_dir']);
$episodes = $parser->getAll(true);

// Redirect if episodes already exist
if (!empty($episodes)) {
    redirect(BASE . '/admin/episodes.php');
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function importLog(string $msg, string $type = 'info', bool $raw = false): void {
    $icons = ['info' => '·', 'ok' => '✓', 'warn' => '⚠', 'error' => '✗'];
    $icon  = $icons[$type] ?? '·';
    $safe  = $raw ? $msg : htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<div class=\"log-line log-$type\">$icon $safe</div>\n";
    ob_flush(); flush();
}

function slugify(string $str): string {
    $str = transliterator_transliterate('Any-Latin; Latin-ASCII', $str) ?: $str;
    $str = strtolower($str);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    $str = trim($str, '-');
    return substr($str, 0, 80);
}

function downloadFile(string $url, string $dest, int $timeoutSec = 120): bool {
    // Use cURL when available (handles HTTPS/redirects better than file_get_contents)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($dest, 'wb');
        if (!$fp) return false;
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fp,
            CURLOPT_TIMEOUT         => $timeoutSec,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_USERAGENT       => 'Badal-Importer/1.0',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_FAILONERROR     => true,
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400) { @unlink($dest); return false; }
        // Reject HTML pages disguised as media (CDN error pages, captive portals)
        if (file_exists($dest) && filesize($dest) > 0) {
            $head = file_get_contents($dest, false, null, 0, 64);
            if ($head !== false && (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<html') !== false)) {
                @unlink($dest);
                return false;
            }
        }
        return file_exists($dest) && filesize($dest) > 0;
    }

    // Fallback: file_get_contents with SSL context
    $ctx = stream_context_create([
        'http' => [
            'timeout'          => $timeoutSec,
            'follow_location'  => true,
            'max_redirects'    => 5,
            'user_agent'       => 'Badal-Importer/1.0',
            'ignore_errors'    => false,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) === 0) return false;
    // Reject HTML error pages
    if (stripos($data, '<!DOCTYPE') !== false || stripos(substr($data, 0, 256), '<html') !== false) {
        return false;
    }
    return file_put_contents($dest, $data, LOCK_EX) !== false;
}

/**
 * Updates a single key in config/config.php (same logic as podcast.php).
 */
function importWriteKey(string $file, string $key, string $value): void {
    $content = file_get_contents($file);
    $escaped = str_replace("'", "\\'", $value);
    $count   = 0;
    // Use preg_replace_callback to avoid $n backreference issues in replacement
    $replacement = "'$key' => '$escaped'";
    $content = preg_replace_callback(
        "/('$key'\s*=>\s*)'(?:[^'\\\\]|\\\\.)*'/",
        function() use ($replacement) { return $replacement; },
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

/**
 * Parses an RSS pubDate string, fixing common typos, non-standard formats,
 * JavaScript Date.toString() output, and French month names.
 * Returns a Unix timestamp or false on failure.
 */
function parseRssDate(string $pubDate) {
    $pubDate = trim($pubDate);
    if (!$pubDate) return false;

    // 1) Strip parenthetical timezone names — JS Date.toString() output
    //    e.g. "GMT+0200 (heure d'été d'Europe centrale)" → "GMT+0200"
    $pubDate = preg_replace('/\s*\([^)]*\)\s*$/', '', $pubDate);

    // 2) French month abbreviations → English
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

    // 3) Fix common day-name typos (e.g. "Wen" → "Wed")
    $pubDate = preg_replace('/^Wen\b/i',  'Wed', $pubDate);
    $pubDate = preg_replace('/^Thi\b/i',  'Thu', $pubDate);
    $pubDate = preg_replace('/^Thr\b/i',  'Thu', $pubDate);
    $pubDate = preg_replace('/^Tues\b/i', 'Tue', $pubDate);
    $pubDate = preg_replace('/^Thur\b/i', 'Thu', $pubDate);

    // 4) "GMT+0200" → "+0200", "GMT+2" → "+0200", "GMT" alone → "+0000"
    $pubDate = preg_replace_callback('/GMT\s*([+-])(\d{1,2})(\d{2})?$/', function($m) {
        $hours = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $mins  = $m[3] ?? '00';
        return $m[1] . $hours . $mins;
    }, $pubDate);
    $pubDate = preg_replace('/\bGMT\s*$/', '+0000', $pubDate);

    $ts = strtotime($pubDate);
    if ($ts !== false) return $ts;

    // 5) Last resort: strip the day name entirely and retry
    $stripped = preg_replace('/^[A-Za-z]+,?\s*/', '', $pubDate);
    $ts = strtotime($stripped);
    if ($ts !== false) return $ts;

    return false;
}

// ── Import processing ────────────────────────────────────────────────────────
$importing = isset($_POST['action']) && $_POST['action'] === 'import';
$rssContent = null;
$errors = [];

if ($importing) {
    // Retrieve the RSS feed (upload or URL)
    if (!empty($_FILES['rss_file']['tmp_name'])) {
        $rssContent = file_get_contents($_FILES['rss_file']['tmp_name']);
    } elseif (!empty($_POST['rss_url'])) {
        $rssUrl = trim($_POST['rss_url']);
        // Try cURL first (better HTTPS/redirect handling)
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
        if ($rssContent === false) {
            $detail = !empty($curlErr) ? " ($curlErr)" : '';
            $errors[] = "Impossible de récupérer l'URL RSS." . $detail;
        }
    } else {
        $errors[] = "Fournissez un fichier RSS ou une URL.";
    }
}

$pageTitle = 'Importer un podcast';
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<style>
  .import-hero {
    max-width: 620px;
    margin: 0 auto;
    padding: 2.5rem 0;
    text-align: center;
  }
  .import-icon {
    width: 64px; height: 64px; border-radius: 16px;
    background: rgba(232,255,90,.1);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.5rem;
  }
  .import-form .field { margin-bottom: 1rem; text-align: left; }
  .import-divider {
    display: flex; align-items: center; gap: .75rem;
    margin: 1.25rem 0; color: var(--muted); font-size: .78rem;
  }
  .import-divider::before, .import-divider::after {
    content: ''; flex: 1; height: 1px; background: var(--border);
  }
  .log-wrap {
    background: var(--bg); border: 1px solid var(--border); border-radius: 10px;
    padding: 1.25rem 1.5rem; max-height: 420px; overflow-y: auto;
    font-family: monospace; font-size: .78rem; line-height: 1.7;
    text-align: left;
  }
  .log-line { padding: .1rem 0; }
  .log-ok    { color: #5aff9a; }
  .log-warn  { color: #ffc85a; }
  .log-error { color: #ff7070; }
  .log-info  { color: var(--muted); }
  .log-title { color: var(--text); font-weight: 700; }

  /* ── Spinner ─────────────────────────────────────────────────────────── */
  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner {
    width: 18px; height: 18px;
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
    position: absolute; top: 2px; left: 5px;
    width: 5px; height: 9px;
    border: solid var(--bg); border-width: 0 2px 2px 0;
    transform: rotate(45deg);
  }

  /* ── Steps pipeline ──────────────────────────────────────────────────── */
  .import-steps {
    display: flex; flex-direction: column; gap: .6rem;
    margin-bottom: 1.25rem; padding: 0;
  }
  .import-step {
    display: flex; align-items: center; gap: .65rem;
    font-size: .82rem; color: var(--muted);
    transition: color .3s;
  }
  .import-step.active  { color: var(--text); font-weight: 600; }
  .import-step.done    { color: #5aff9a; }
  .import-step .step-dot {
    width: 18px; height: 18px;
    border: 2px solid var(--border);
    border-radius: 50%;
    flex-shrink: 0;
    transition: border-color .3s;
  }
  .import-step.active .step-dot { display: none; }
  .import-step.done   .step-dot { display: none; }
  .import-step .spinner   { display: none; }
  .import-step.active .spinner   { display: block; }
  .import-step .check-icon { display: none; }
  .import-step.done .check-icon  { display: block; }
  .import-step.done .step-dot    { display: none; }
</style>

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <h1>Importer un podcast</h1>
  </div>

  <div class="content">

<?php if ($importing && !$errors && $rssContent): ?>

  <!-- ── Import in progress mode ───────────────────────────────────────────── -->
  <div class="card" style="padding:1.5rem">

    <!-- Steps pipeline -->
    <div class="import-steps" id="importSteps">
      <div class="import-step active" id="step-parse">
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
        <span style="font-size:.72rem;color:var(--muted)" id="progressCount"></span>
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

    <div class="log-wrap" id="logWrap">
<?php
    function importProgress(int $done, int $total, string $label, string $step = '', int $stepPct = 0): void {
        $pct   = $total > 0 ? round($done / $total * 100) : 0;
        $count = $done > 0 ? json_encode("$done / $total") : '""';
        $stepJs = $step
            ? 'document.getElementById("stepWrap").style.display="";document.getElementById("stepLabel").textContent='.json_encode($step).';document.getElementById("stepBar").style.width="'.$stepPct.'%";'
            : 'document.getElementById("stepWrap").style.display="none";';
        // Update the episodes step label with live counter
        $epLabelJs = ($done > 0 && $total > 0)
            ? 'var el=document.getElementById("step-episodes-label");if(el)el.textContent="Téléchargement des épisodes ("+' . json_encode("$done") . '+"/' . $total . ')";'
            : '';
        echo "<script>document.getElementById('progressBar').style.width='{$pct}%';document.getElementById('progressLabel').textContent=".json_encode($label).";document.getElementById('progressCount').textContent=$count;$stepJs $epLabelJs document.getElementById('logWrap').scrollTop=document.getElementById('logWrap').scrollHeight;</script>\n";
        ob_flush(); flush();
    }

    /** Advance the step pipeline: mark previous steps done, activate the given step */
    function importStep(string $stepId): void {
        echo "<script>(function(){";
        echo "var steps=document.querySelectorAll('.import-step');";
        echo "var found=false;";
        echo "for(var i=0;i<steps.length;i++){";
        echo   "if(steps[i].id===".json_encode($stepId)."){found=true;steps[i].className='import-step active';}";
        echo   "else if(!found){steps[i].className='import-step done';}";
        echo   "else{steps[i].className='import-step';}";
        echo "}";
        echo "document.getElementById('logWrap').scrollTop=document.getElementById('logWrap').scrollHeight;";
        echo "})()</script>\n";
        ob_flush(); flush();
    }

    // Parse the RSS
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rssContent);
    if (!$xml) {
        importLog("Impossible de parser le XML RSS.", 'error');
        $lastErr = libxml_get_last_error();
        importLog($lastErr ? $lastErr->message : 'Erreur inconnue', 'error');
    } else {
        $channel = $xml->channel;
        $ns      = $xml->getNamespaces(true);
        $itunes  = isset($ns['itunes']) ? $channel->children($ns['itunes']) : null;

        $podTitle  = (string)($channel->title ?? 'Podcast importé');
        $podDesc   = (string)($channel->description ?? '');
        $podAuthor = $itunes ? (string)($itunes->author ?? '') : '';
        $podLang   = (string)($channel->language ?? '');
        $podCat    = '';
        if ($itunes && isset($itunes->category)) {
            $catAttrs = $itunes->category->attributes();
            $podCat   = (string)($catAttrs['text'] ?? '');
        }

        importStep('step-meta');
        importLog("Flux détecté : <strong>" . htmlspecialchars($podTitle, ENT_QUOTES, 'UTF-8') . "</strong>", 'ok', true);
        if ($podDesc) {
            $shortPodDesc = mb_strimwidth(strip_tags($podDesc), 0, 100, '…');
            importLog("Description : $shortPodDesc", 'info');
        }

        // ── Save podcast metadata to config ──────────────────────────────
        $configFile = ROOT_DIR . '/config/config.php';
        if ($podTitle)  importWriteKey($configFile, 'podcast_title', $podTitle);
        if ($podDesc) {
            $cleanDesc = trim(preg_replace('/\s+/', ' ', strip_tags($podDesc)));
            importWriteKey($configFile, 'podcast_description', $cleanDesc);
        }
        if ($podAuthor) importWriteKey($configFile, 'author', $podAuthor);
        if ($podLang)   importWriteKey($configFile, 'language', $podLang);
        if ($podCat)    importWriteKey($configFile, 'category', $podCat);
        importLog("Métadonnées du podcast enregistrées dans la configuration.", 'ok');

        // ── Download podcast cover image ─────────────────────────────────
        $podCoverUrl = '';
        if ($itunes && isset($itunes->image)) {
            $imgAttrs    = $itunes->image->attributes();
            $podCoverUrl = (string)($imgAttrs['href'] ?? '');
        }
        if ($podCoverUrl) {
            $covExt = pathinfo(parse_url($podCoverUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $covExt = preg_replace('/[^a-z0-9]/', '', strtolower($covExt));
            if (!in_array($covExt, ['jpg','jpeg','png','webp'])) $covExt = 'jpg';
            $podCoverDest = $config['audio_dir'] . '/cover.' . $covExt;
            if (downloadFile($podCoverUrl, $podCoverDest)) {
                importWriteKey($configFile, 'cover_image', 'cover.' . $covExt);
                importLog("Cover du podcast téléchargée.", 'ok');
            } else {
                importLog("Cover du podcast : échec du téléchargement.", 'warn');
            }
        }

        // ── Backup existing rss.xml if present ──────────────────────────
        $rssFile   = ROOT_DIR . '/rss.xml';
        $rssBackup = ROOT_DIR . '/rss_back.xml';
        if (file_exists($rssFile)) {
            if (rename($rssFile, $rssBackup)) {
                importLog("Ancien flux RSS sauvegardé → rss_back.xml", 'ok');
            } else {
                importLog("Impossible de renommer rss.xml", 'warn');
            }
        }

        $items = $channel->item ?? [];
        $total = count((array)$items);
        importStep('step-episodes');
        importLog("$total épisodes trouvés dans le flux.", 'info');
        importProgress(0, $total, "Téléchargement des épisodes…");

        $audioDir   = $config['audio_dir'];
        $contentDir = $config['content_dir'];

        // Remove any custom order so episodes sort by publication date
        $parser->resetOrder();

        $epNum    = $total; // decreasing numbering → start with the oldest
        $done     = 0;
        $usedSlugs = [];

        foreach ($items as $item) {
            $itItem   = isset($ns['itunes']) ? $item->children($ns['itunes']) : null;

            // ── Metadata ─────────────────────────────────────────────────────
            $title    = (string)($item->title ?? 'Sans titre');
            $pubDate  = (string)($item->pubDate ?? '');
            $link     = (string)($item->link ?? '');
            $desc     = (string)($item->description ?? '');
            $duration = $itItem ? (string)($itItem->duration ?? '') : '';
            $epNumTag = $itItem ? (string)($itItem->episode ?? $epNum) : $epNum;
            $season   = $itItem ? (string)($itItem->season ?? '') : '';
            $explicit = $itItem ? (string)($itItem->explicit ?? 'no') : 'no';
            $author   = $itItem ? (string)($itItem->author ?? '') : (string)($item->author ?? '');
            $subtitle = $itItem ? (string)($itItem->subtitle ?? '') : '';

            // Clean HTML description
            if (strpos($desc, '<') !== false) {
                $desc = strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $desc));
                $desc = trim(preg_replace("/\n{3,}/", "\n\n", $desc));
            }

            // Date — parse with typo correction, keep full timestamp with timezone
            $date    = '';
            $pubFull = '';
            if ($pubDate) {
                $ts = parseRssDate($pubDate);
                if ($ts) {
                    $date    = date('Y-m-d', $ts);
                    $pubFull = date('c', $ts); // ISO 8601 with timezone offset
                }
            }
            if (!$date) $date = date('Y-m-d');
            if (!$pubFull) $pubFull = date('c');

            // Slug (with collision avoidance)
            $slug = slugify($title);
            if (!$slug) $slug = 'episode-' . $epNum;
            $baseSlug = $slug;
            $suffix = 2;
            while (isset($usedSlugs[$slug])) {
                $slug = $baseSlug . '-' . $suffix++;
            }
            $usedSlugs[$slug] = true;

            // ── Global progress ──────────────────────────────────────────
            $stepNum  = $total - $epNum + 1; // position in the list (1-based)
            $epLabel  = "Épisode $stepNum/$total — " . mb_strimwidth($title, 0, 50, '…');
            importProgress($done, $total, $epLabel);
            importLog("", 'info');
            importLog("[$stepNum/$total] $title", 'title');

            // ── Audio ────────────────────────────────────────────────────────
            $audioUrl   = '';
            $audioFile  = '';
            $enclosure  = $item->enclosure;
            if ($enclosure) {
                $attrs    = $enclosure->attributes();
                $audioUrl = (string)($attrs['url'] ?? '');
            }

            if ($audioUrl) {
                $ext       = pathinfo(parse_url($audioUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp3';
                $ext       = preg_replace('/[^a-z0-9]/', '', strtolower($ext));
                $epDir     = $audioDir . '/' . $slug;
                if (!is_dir($epDir)) { mkdir($epDir, 0755, true); }
                $audioFile = $slug . '/audio.' . $ext;
                $audioDest = $audioDir . '/' . $audioFile;

                importProgress($done, $total, $epLabel, "Téléchargement audio…", 33);
                importLog("Audio : téléchargement…", 'info');
                if (downloadFile($audioUrl, $audioDest)) {
                    $size = round(filesize($audioDest) / (1024*1024), 1);
                    importLog("Audio : $audioFile ($size Mo)", 'ok');
                } else {
                    importLog("Audio : échec du téléchargement ($audioUrl)", 'warn');
                    $audioFile = '';
                }
            } else {
                importLog("Aucun fichier audio dans l'enclosure.", 'warn');
            }

            // ── Cover ────────────────────────────────────────────────────────
            $coverFile = '';
            $coverUrl  = '';

            // 1) itunes:image on the item
            if ($itItem && isset($itItem->image)) {
                $imgAttrs = $itItem->image->attributes();
                $coverUrl = (string)($imgAttrs['href'] ?? '');
            }
            // 2) media:content or media:thumbnail (Spotify, Audioboom, etc.)
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
            // 3) Fallback: channel-level itunes:image
            if (!$coverUrl && $itunes && isset($itunes->image)) {
                $imgAttrs = $itunes->image->attributes();
                $coverUrl = (string)($imgAttrs['href'] ?? '');
            }

            if ($coverUrl) {
                $ext2      = pathinfo(parse_url($coverUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $ext2      = preg_replace('/[^a-z0-9]/', '', strtolower($ext2));
                if (!in_array($ext2, ['jpg','jpeg','png','webp'])) $ext2 = 'jpg';
                $epDir2    = $audioDir . '/' . $slug;
                if (!is_dir($epDir2)) { mkdir($epDir2, 0755, true); }
                $coverFile = $slug . '/cover.' . $ext2;
                $coverDest = $audioDir . '/' . $coverFile;

                importProgress($done, $total, $epLabel, "Téléchargement cover…", 66);
                importLog("Cover : téléchargement…", 'info');
                if (downloadFile($coverUrl, $coverDest)) {
                    $sizeK = round(filesize($coverDest) / 1024);
                    importLog("Cover : $coverFile ({$sizeK} Ko)", 'ok');
                } else {
                    importLog("Cover : échec ($coverUrl)", 'warn');
                    $coverFile = '';
                }
            } else {
                importLog("Aucune cover trouvée pour cet épisode.", 'info');
            }

            // ── Episode creation ─────────────────────────────────────────────
            importProgress($done, $total, $epLabel, "Enregistrement…", 90);
            // Build a short description for RSS (single line, max 300 chars)
            $shortDesc = $subtitle ?: $desc;
            $shortDesc = preg_replace('/\s+/', ' ', trim($shortDesc));
            if (mb_strlen($shortDesc) > 300) {
                $shortDesc = mb_substr($shortDesc, 0, 297) . '…';
            }

            $meta = [
                'title'    => $title,
                'date'     => $date,
                'pubdate'  => $pubFull,
            ];
            if ($shortDesc)  $meta['description'] = $shortDesc;
            if ($epNumTag)   $meta['episode']     = (int)$epNumTag;
            if ($season)     $meta['season']      = (int)$season;
            if ($duration)   $meta['duration']     = $duration;
            if ($author)     $meta['guest']        = $author;
            if ($audioFile)  $meta['audio']        = $audioFile;
            if ($coverFile)  $meta['cover']        = $coverFile;
            if ($explicit === 'yes') $meta['explicit'] = 'yes';

            $body = '';
            if ($subtitle && $subtitle !== $title) $body .= $subtitle . "\n\n";
            $body .= $desc;

            $saved = $parser->save($slug, $meta, $body);
            if ($saved) {
                importLog("Épisode créé : $slug.md", 'ok');
            } else {
                importLog("Erreur d'écriture pour $slug.md", 'error');
            }

            $done++;
            $epNum--;
        }

        // Bar at 100% and step bar hidden
        importStep('step-done');
        importProgress($done, $total, "Import terminé", '', 0);
        importLog("", 'info');
        importLog("Import terminé — $done/" . $total . " épisodes importés.", 'ok');
        // Mark all steps done (including step-done)
        echo "<script>document.querySelectorAll('.import-step').forEach(function(s){s.className='import-step done'});</script>\n";
        ob_flush(); flush();
    }
?>
    </div><!-- /.log-wrap -->
  </div>

  <div style="display:flex;gap:1rem;margin-top:1rem">
    <a href="<?= url('/admin/episodes.php') ?>" class="btn" style="flex:1;text-align:center">
      <i data-feather="list"></i> Voir les épisodes
    </a>
    <a href="<?= url('/') ?>" target="_blank" class="btn btn-ghost" style="flex:1;text-align:center">
      <i data-feather="home"></i> Voir le site ↗
    </a>
  </div>

<?php else: ?>

  <!-- ── Import form ──────────────────────────────────────────────────────── -->

  <?php if ($errors): ?>
    <div style="background:rgba(255,80,80,.1);border:1px solid rgba(255,80,80,.3);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;color:#ff7070;font-size:.85rem;max-width:620px;margin-left:auto;margin-right:auto">
      <?php foreach ($errors as $e): ?><div>⚠ <?= e($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="import-hero">
    <div class="import-icon">
      <i data-feather="download-cloud" style="width:28px;height:28px;color:var(--accent)"></i>
    </div>
    <h2 style="font-size:1.35rem;font-weight:800;margin-bottom:.5rem">Importer un podcast existant</h2>
    <p style="color:var(--muted);font-size:.88rem;margin-bottom:2rem;line-height:1.6">
      Badal va lire votre flux RSS, télécharger tous les épisodes et leurs couvertures,
      et créer les fiches avec les métadonnées existantes.
    </p>

    <form method="POST" enctype="multipart/form-data" class="import-form" onsubmit="startImport(event)">
      <input type="hidden" name="action" value="import">

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

<?php endif; ?>

  </div><!-- /.content -->
</div><!-- /.main -->

<script>
function startImport(e) {
  const btn  = document.getElementById('importBtn');
  const file = document.getElementById('rss_file').value;
  const url  = document.getElementById('rss_url').value;
  if (!file && !url) {
    e.preventDefault();
    alert('Fournissez un fichier RSS ou une URL.');
    return;
  }
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span> Importation en cours…';
  btn.style.opacity = '.7';
  btn.style.pointerEvents = 'none';
}
</script>
</body>
</html>
