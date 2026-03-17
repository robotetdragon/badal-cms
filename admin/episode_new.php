<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$errors = [];
$values = [
    'title'       => '',
    'slug'        => '',
    'date'        => date('Y-m-d'),
    'episode'     => '',
    'duration'    => '',
    'description' => '',
    'body'        => '',
    'transcript'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $values['title']       = trim($_POST['title']       ?? '');
    $values['slug']        = trim($_POST['slug']        ?? '');
    $values['date']        = trim($_POST['date']        ?? date('Y-m-d'));
    $values['episode']     = trim($_POST['episode']     ?? '');
    $values['duration']    = trim($_POST['duration']    ?? '');
    $values['description'] = trim($_POST['description'] ?? '');
    $values['body']        = trim($_POST['body']        ?? '');
    $values['transcript']  = trim($_POST['transcript']  ?? '');

    if (empty($values['title'])) $errors[] = __('ep_title_required');
    if (empty($values['slug']))  $errors[] = __('ep_slug_required');

    if (empty($values['slug']) && !empty($values['title'])) {
        $values['slug'] = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $values['title']));
    }

    $audioFilename = '';
    if (!empty($_FILES['audio']['name'])) {
        $validation = Security::validateAudioUpload($_FILES['audio']);
        if (!$validation['ok']) {
            $errors[] = $validation['error'];
        } else {
            $epSlug = $values['slug'];
            $epDir  = $config['audio_dir'] . '/' . $epSlug;
            if (!is_dir($epDir)) { mkdir($epDir, 0755, true); }
            $audioFilename = $epSlug . '/audio.' . $validation['ext'];
            $dest = $config['audio_dir'] . '/' . $audioFilename;
            if (!move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) {
                $errors[] = __('ep_upload_error');
                $audioFilename = '';
            } else {
                // Durée automatique via ffprobe
                if (empty($values['duration'])) {
                    $detectedDuration = AudioDuration::fromFile($dest);
                    if ($detectedDuration) {
                        $values['duration'] = $detectedDuration;
                    }
                }
            }
        }
    }

    $coverFilename = '';
    if (!empty($_FILES['cover']['name'])) {
        $allowedImgMime = [
            'image/jpeg' => 'jpg', 'image/png' => 'png',
            'image/webp' => 'webp', 'image/gif' => 'gif',
        ];
        $finfoCover = finfo_open(FILEINFO_MIME_TYPE);
        $coverMime  = (string) finfo_file($finfoCover, $_FILES['cover']['tmp_name']);
        finfo_close($finfoCover);
        $coverExt   = $allowedImgMime[$coverMime] ?? '';
        if ($coverExt) {
            $epSlug2 = $values['slug'];
            $epDir2  = $config['audio_dir'] . '/' . $epSlug2;
            if (!is_dir($epDir2)) { mkdir($epDir2, 0755, true); }
            $coverFilename = $epSlug2 . '/cover.' . $coverExt;
            $dest = $config['audio_dir'] . '/' . $coverFilename;
            if (!move_uploaded_file($_FILES['cover']['tmp_name'], $dest)) {
                $coverFilename = '';
            }
        }
    }

    if (empty($errors)) {
        $parser = new EpisodeParser($config['content_dir']);
        $meta = [
            'title'       => $values['title'],
            'date'        => $values['date'],
            'episode'     => $values['episode'],
            'duration'    => $values['duration'],
            'description' => $values['description'],
            'audio'       => $audioFilename,
            'cover'       => $coverFilename,
        ];

        if ($parser->save($values['slug'], $meta, $values['body'])) {
            if (!empty($values['transcript'])) {
                $tm = new TranscriptManager($config['content_dir']);
                $tm->save($values['slug'], $values['transcript']);
            }
            $sitemap = new SitemapGenerator($config['base_url'], ROOT_DIR);
            $sitemap->generate($parser->getAll());
            $_SESSION['flash'] = ['type' => 'success', 'message' => __('ep_created')];
            redirect(url('/admin/episodes.php'));
        } else {
            $errors[] = __('ep_save_file_error');
        }
    }
}

$pageTitle = __('nav_new_episode');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/easymde/2.18.0/easymde.min.css">
<style>
.tab-bar { display:flex; border-bottom:1px solid var(--border); margin-bottom:1.5rem; gap:0; overflow-x:auto; }
.tab-btn { background:none; border:none; color:var(--muted); font-family:'Syne',sans-serif; font-size:.8rem; font-weight:600; padding:.65rem 1.1rem; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; transition:color .15s,border-color .15s; }
.tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }
.EasyMDEContainer .CodeMirror { background:var(--bg) !important; color:var(--text) !important; border-color:var(--border) !important; border-radius:0 0 8px 8px !important; font-size:.88rem; }
.EasyMDEContainer .editor-toolbar { background:var(--surface2) !important; border-color:var(--border) !important; border-radius:8px 8px 0 0 !important; }
.EasyMDEContainer .editor-toolbar button { color:var(--muted) !important; }
.EasyMDEContainer .editor-toolbar button:hover,.EasyMDEContainer .editor-toolbar button.active { background:rgba(255,255,255,.06) !important; color:var(--text) !important; }
.EasyMDEContainer .editor-toolbar i.separator { border-color:var(--border) !important; }
.EasyMDEContainer .editor-preview { background:var(--surface) !important; color:var(--text) !important; border-color:var(--border) !important; }
.editor-statusbar { color:var(--muted) !important; }
.transcript-area { font-family:monospace; font-size:.83rem; line-height:1.65; background:var(--bg); color:var(--text); border:1px solid var(--border); border-radius:8px; padding:.85rem 1rem; width:100%; resize:vertical; min-height:220px; outline:none; transition:border-color .2s; }
.transcript-area:focus { border-color:var(--accent); }
</style>

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <h1><?= __('nav_new_episode') ?></h1>
    <a href="<?= url('/admin/episodes.php') ?>" class="btn btn-ghost"><?= __('back') ?></a>
  </div>

  <div class="content">
    <?php if ($errors): ?>
      <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

      <div class="tab-bar">
        <button type="button" class="tab-btn active" onclick="switchTab('infos',this)"><?= __('ep_form_info') ?></button>
        <button type="button" class="tab-btn"        onclick="switchTab('audio',this)"><?= __('ep_form_audio') ?></button>
        <button type="button" class="tab-btn"        onclick="switchTab('content',this)"><?= __('ep_form_content') ?></button>
        <button type="button" class="tab-btn"        onclick="switchTab('transcript',this)"><?= __('ep_form_transcript') ?></button>
      </div>

      <!-- INFOS -->
      <div id="tab-infos" class="tab-pane active">
        <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
          <div class="section-label" style="margin-bottom:1.1rem"><?= __('ep_form_info') ?></div>
          <div class="form-grid">
            <div class="field form-full">
              <label for="title"><?= __('ep_title') ?></label>
              <input type="text" id="title" name="title" value="<?= e($values['title']) ?>"
                oninput="autoSlug(this.value)" placeholder="Ex: La révolution de l'IA">
            </div>
            <div class="field">
              <label for="slug"><?= __('ep_slug') ?></label>
              <input type="text" id="slug" name="slug" value="<?= e($values['slug']) ?>" placeholder="la-revolution-de-l-ia">
              <span class="hint"><?= __('ep_slug_hint') ?></span>
            </div>
            <div class="field">
              <label for="date"><?= __('ep_date') ?></label>
              <input type="date" id="date" name="date" value="<?= e($values['date']) ?>">
            </div>
            <div class="field">
              <label for="duration"><?= __('ep_duration') ?></label>
              <input type="text" id="duration" name="duration" value="<?= e($values['duration']) ?>" placeholder="45:30">
              <span class="hint" id="duration-hint" style="transition:color .2s"><?= empty($values['duration']) ? "Rempli automatiquement à l'upload" : "" ?></span>
            </div>
            <div class="field form-full">
              <label for="description"><?= __('ep_description') ?></label>
              <textarea id="description" name="description" rows="3"
                placeholder="Résumé de l'épisode, utilisé dans le flux RSS"><?= e($values['description']) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- CONTENT -->
      <div id="tab-content" class="tab-pane">
        <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
          <div class="section-label" style="margin-bottom:1rem"><?= __('ep_body') ?></div>
          <textarea id="body" name="body"><?= e($values['body']) ?></textarea>
        </div>
      </div>

      <!-- TRANSCRIPT -->
      <div id="tab-transcript" class="tab-pane">
        <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
          <div class="section-label" style="margin-bottom:.6rem"><?= __('ep_form_transcript') ?></div>
          <p style="font-size:.8rem;color:var(--muted);margin-bottom:1rem;line-height:1.6">
            <?= __('ep_transcript_hint') ?>
          </p>
          <textarea name="transcript" class="transcript-area"
            placeholder="HOST: Bonjour et bienvenue...

INVITÉ: Merci de m'accueillir !

[00:01:30] HOST: Commençons par votre parcours..."><?= e($values['transcript']) ?></textarea>
        </div>
      </div>

      <!-- AUDIO -->
      <div id="tab-audio" class="tab-pane">
        <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
          <div class="section-label" style="margin-bottom:1.25rem"><?= __('ep_form_audio') ?></div>
          <div class="field">
            <label for="audio">Upload audio</label>
            <input type="file" id="audio" name="audio" accept="audio/*">
            <span class="hint"><?= __('ep_audio_hint') ?></span>
          </div>
        </div>
        <div class="card" style="padding:1.75rem">
          <div class="section-label" style="margin-bottom:1.25rem"><?= __('ep_cover_label') ?></div>
          <div class="field">
            <label for="cover">Image de couverture</label>
            <input type="file" id="cover" name="cover" accept="image/*">
            <span class="hint">JPG, PNG ou WebP — 1400×1400px recommandé (optionnel)</span>
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:.75rem;padding-bottom:2rem">
        <a href="<?= url('/admin/episodes.php') ?>" class="btn btn-ghost"><?= __('cancel') ?></a>
        <button type="submit" class="btn"><?= __('publish') ?></button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/easymde/2.18.0/easymde.min.js"></script>
<script>
function autoSlug(t) {
  const f = document.getElementById('slug');
  if (f._edited) return;
  f.value = t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
}
document.getElementById('slug').addEventListener('input', () => document.getElementById('slug')._edited = true);

function switchTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
  if (name === 'content' && !window._mde) initMDE();
}

function initMDE() {
  window._mde = new EasyMDE({
    element: document.getElementById('body'),
    spellChecker: false,
    autosave: { enabled: true, uniqueId: 'new-ep-body', delay: 3000 },
    toolbar: ['bold','italic','heading','|','quote','unordered-list','ordered-list','|','link','|','preview','side-by-side','fullscreen','|','guide'],
    minHeight: '340px',
    placeholder: '## À propos de cet épisode\n\nDescription longue, show notes, timestamps…',
    status: ['autosave','lines','words'],
  });
}

// Auto-détection durée audio via Web Audio API (côté navigateur)
(function() {
  var audioInput    = document.getElementById("audio");
  var durationField = document.getElementById("duration");
  if (!audioInput || !durationField) return;

  audioInput.addEventListener("change", function() {
    var file = audioInput.files[0];
    if (!file) return;
    if (durationField.value && durationField.value.trim()) return; // déjà rempli

    var hint = document.getElementById("duration-hint");
    if (hint) hint.textContent = "Lecture en cours…";

    var url = URL.createObjectURL(file);
    var audio = new Audio();
    audio.preload = "metadata";
    audio.onloadedmetadata = function() {
      URL.revokeObjectURL(url);
      var secs  = Math.round(audio.duration);
      var h = Math.floor(secs / 3600);
      var m = Math.floor((secs % 3600) / 60);
      var s = secs % 60;
      var fmt = h > 0
        ? h + ":" + String(m).padStart(2,"0") + ":" + String(s).padStart(2,"0")
        : m + ":" + String(s).padStart(2,"0");
      durationField.value = fmt;
      if (hint) hint.textContent = "Durée détectée automatiquement";
    };
    audio.onerror = function() {
      URL.revokeObjectURL(url);
      if (hint) hint.textContent = "Durée à saisir manuellement";
    };
    audio.src = url;
  });
})();
</script>
</body>
</html>
