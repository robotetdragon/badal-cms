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
    $ctx = stream_context_create(['http' => [
        'timeout'          => $timeoutSec,
        'follow_location'  => true,
        'max_redirects'    => 5,
        'user_agent'       => 'Badal-Importer/1.0',
        'ignore_errors'    => false,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) === 0) return false;
    return file_put_contents($dest, $data, LOCK_EX) !== false;
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
        $rssContent = @file_get_contents($_POST['rss_url'], false, stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'Badal-Importer/1.0', 'follow_location' => true]
        ]));
        if ($rssContent === false) $errors[] = "Impossible de récupérer l'URL RSS.";
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
  .progress-bar {
    height: 4px; background: var(--border); border-radius: 2px; overflow: hidden;
    margin: 1rem 0;
  }
  .progress-fill {
    height: 100%; background: var(--accent); border-radius: 2px;
    transition: width .3s ease;
  }
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

    <!-- Step progress bar -->
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
        echo "<script>document.getElementById('progressBar').style.width='{$pct}%';document.getElementById('progressLabel').textContent=".json_encode($label).";document.getElementById('progressCount').textContent=$count;$stepJs document.getElementById('logWrap').scrollTop=document.getElementById('logWrap').scrollHeight;</script>\n";
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
        importLog("Flux détecté : <strong>" . htmlspecialchars($podTitle, ENT_QUOTES, 'UTF-8') . "</strong>", 'ok', true);

        $items = $channel->item ?? [];
        $total = count((array)$items);
        importLog("$total épisodes trouvés dans le flux.", 'info');
        importProgress(0, $total, "Analyse du flux…");

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

            // Date
            $date = '';
            if ($pubDate) {
                $ts   = strtotime($pubDate);
                $date = $ts ? date('Y-m-d', $ts) : '';
            }
            if (!$date) $date = date('Y-m-d');

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
        importProgress($done, $total, "Import terminé ✓", '', 0);
        importLog("", 'info');
        importLog("Import terminé — $done/" . $total . " épisodes importés.", 'ok');
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
  btn.innerHTML = '<i data-feather="loader"></i> Import en cours…';
  if (window.feather) feather.replace({'stroke-width':2});
}
</script>
</body>
</html>
