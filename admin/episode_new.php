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
    'chapters'    => '',
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
    $values['chapters']    = trim($_POST['chapters']    ?? '');

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
                $detectedDuration = AudioDuration::fromFile($dest);
                if ($detectedDuration) {
                    $values['duration'] = $detectedDuration;
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
            if (!empty($values['chapters'])) {
                $cm = new ChaptersManager($config['content_dir']);
                $cm->saveFromText($values['slug'], $values['chapters']);
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
        <button type="button" class="tab-btn"        onclick="switchTab('chapters',this)"><?= __('ep_form_chapters') ?></button>
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

      <!-- CHAPTERS -->
      <div id="tab-chapters" class="tab-pane">
        <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
          <div class="section-label" style="margin-bottom:.6rem"><?= __('ep_form_chapters') ?></div>
          <p style="font-size:.8rem;color:var(--muted);margin-bottom:1rem;line-height:1.6">
            <?= __('ep_chapters_hint') ?>
          </p>
          <textarea name="chapters" class="transcript-area" style="min-height:160px"
            placeholder="00:00 <?= e(__('ep_chapters_ph_intro')) ?>
05:30 <?= e(__('ep_chapters_ph_topic')) ?>
12:00 <?= e(__('ep_chapters_ph_interview')) ?>"><?= e($values['chapters']) ?></textarea>
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
          <div class="field" style="margin-top:1rem">
            <label for="duration"><?= __('ep_duration') ?></label>
            <input type="text" id="duration" name="duration" value="<?= e($values['duration']) ?>" placeholder="45:30">
            <span class="hint" id="duration-hint" style="transition:color .2s"><?= __('ep_duration_auto') ?></span>
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

<!-- Popup publication réussie -->
<div class="publish-overlay" id="publishOverlay">
  <div class="publish-popup">
    <!-- Micro SVG animé (dessin progressif + effacement simultané) -->
    <div class="publish-icon">
      <svg width="200" height="200" viewBox="0 0 400 400" fill="none">
        <path class="mic-draw" pathLength="1"
          stroke="var(--accent)" stroke-width="14" stroke-linecap="round" stroke-linejoin="round"
          d="M41.19,184.02h15.58c7.73,0,14-6.27,14-14v-9.15c0-7.73,6.26-13.99,13.99-13.99h0c7.73,0,13.99,6.26,13.99,13.99v23.48s0,34.06,0,34.06c0,7.73,6.26,13.99,13.99,13.99h0c7.73,0,13.99-6.26,13.99-13.99v-34.06s0-84.13,0-84.13c0-7.73,6.26-13.99,13.99-13.99h0c7.73,0,13.99,6.26,13.99,13.99v84.13s0,81.47,0,81.47c0,7.73,6.26,13.99,13.99,13.99h0c7.73,0,13.99-6.26,13.99-13.99v-81.47s0-56.06,0-56.06c0-7.73,6.26-13.99,13.99-13.99h0c7.73,0,13.99,6.26,13.99,13.99v56.06s0,33.47,0,33.47c0,7.73,6.26,13.99,13.99,13.99h0c7.73,0,13.99-6.26,13.99-13.99v-27.45c0-7.73,6.27-14,14-14h17.06c7.73,0,14,6.27,14,14v8.58c0,46.14-36.9,84.38-83.04,84.72-46.5.34-84.31-37.25-84.31-83.67v-9.91c0-7.73,6.27-14,14-14h19.81c7.73,0,14,6.27,14,14v8.99c0,20.09,16.44,36.9,36.54,36.53,19.49-.36,35.18-16.04,35.18-35.61v-94.92c0-20.13-16.47-36.97-36.6-36.56-19.46.39-35.08,16.29-35.08,35.85v95.62h0c0,19.71,15.91,35.72,35.61,35.85h.9s-.56,95.64-.56,95.64h47.67-95.62"/>
      </svg>
    </div>
    <div class="publish-text">
      <div class="publish-msg publish-msg-pending">Publication en cours…</div>
      <div class="publish-msg publish-msg-done">L'épisode est en ligne</div>
    </div>
  </div>
</div>

<style>
  .publish-overlay {
    position: fixed; inset: 0; z-index: 10002;
    background: rgba(0,0,0,.6); backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
    animation: pub-fade-in .3s ease;
  }
  .publish-overlay.visible { display: flex; }
  @keyframes pub-fade-in { from { opacity: 0; } to { opacity: 1; } }

  .publish-popup {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 18px; padding: 2.5rem 3rem; max-width: 400px; width: 90%;
    text-align: center;
    box-shadow: 0 24px 64px rgba(0,0,0,.5);
    animation: pub-slide-in .35s cubic-bezier(.25,.46,.45,.94);
  }
  @keyframes pub-slide-in {
    from { opacity: 0; transform: translateY(24px) scale(.95); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }

  .publish-icon { margin-bottom: 1.5rem; }

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

  .publish-msg {
    font-family: 'Syne', sans-serif;
    font-size: 1.1rem; font-weight: 700;
    letter-spacing: -.02em;
  }

  .publish-msg-pending {
    color: var(--muted);
    opacity: 0;
    animation: fade-in .5s ease .5s forwards, fade-out .5s ease 5.5s forwards;
  }

  .publish-msg-done {
    font-size: 1.3rem; font-weight: 800;
    color: var(--text);
    opacity: 0;
    animation: pub-text-in .6s ease 6s forwards;
  }

  @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
  @keyframes fade-out { from { opacity: 1; } to { opacity: 0; } }
  @keyframes pub-text-in {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }
</style>

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

// Popup de publication — intercepte le submit
(function() {
  var form = document.querySelector('form[enctype]');
  if (!form) return;

  form.addEventListener('submit', function(e) {
    // Ne pas afficher la popup s'il y a des erreurs évidentes côté client
    var title = document.getElementById('title');
    var slug  = document.getElementById('slug');
    if (title && !title.value.trim()) return;
    if (slug && !slug.value.trim()) return;

    e.preventDefault();
    var overlay = document.getElementById('publishOverlay');
    overlay.classList.add('visible');

    // Soumettre le formulaire après l'animation (micro 6s + texte + pause)
    setTimeout(function() { form.submit(); }, 7200);
  });
})();

// Auto-détection durée audio via Web Audio API (côté navigateur)
(function() {
  var audioInput    = document.getElementById("audio");
  var durationField = document.getElementById("duration");
  if (!audioInput || !durationField) return;

  audioInput.addEventListener("change", function() {
    var file = audioInput.files[0];
    if (!file) return;

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
