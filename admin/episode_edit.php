<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$slug    = $_GET['slug'] ?? '';
$parser  = new EpisodeParser($config['content_dir']);
$episode = $parser->getBySlug($slug);

if (!$episode) {
    $_SESSION['flash'] = ['type'=>'error','message'=>'Épisode introuvable.'];
    redirect(url('/admin/episodes.php'));
}

$tm         = new TranscriptManager($config['content_dir']);
$cm         = new ChaptersManager($config['content_dir']);
$errors     = [];
$values     = $episode;
$values['transcript'] = $tm->get($slug);
$values['chapters']   = $cm->getText($slug);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $values['title']       = trim($_POST['title']       ?? '');
    $values['date']        = trim($_POST['date']        ?? '');
    $values['episode']     = trim($_POST['episode']     ?? '');
    $values['duration']    = trim($_POST['duration']    ?? '');
    $values['description'] = trim($_POST['description'] ?? '');
    $values['body']        = trim($_POST['body']        ?? '');
    $values['transcript']  = trim($_POST['transcript']  ?? '');
    $values['chapters']    = trim($_POST['chapters']    ?? '');
    $newSlug               = trim($_POST['slug']        ?? $slug);

    if (empty($values['title'])) $errors[] = __('ep_title_required');

    $audioFilename = $values['audio'] ?? '';

    // Audio deletion requested
    if (!empty($_POST['delete_audio']) && $audioFilename) {
        $audioPath = $config['audio_dir'] . '/' . $audioFilename;
        if (file_exists($audioPath)) { unlink($audioPath); }
        $audioFilename = '';
        $values['duration'] = '';
    }

    // Upload a new audio file (only if no existing audio)
    if (!empty($_FILES['audio']['name'])) {
        $validation = Security::validateAudioUpload($_FILES['audio']);
        if (!$validation['ok']) {
            $errors[] = $validation['error'];
        } else {
            $epDir = $config['audio_dir'] . '/' . $newSlug;
            if (!is_dir($epDir)) { mkdir($epDir, 0755, true); }
            $audioFilename = $newSlug . '/audio.' . $validation['ext'];
            $dest = $config['audio_dir'] . '/' . $audioFilename;
            if (!move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) {
                $errors[] = __('ep_upload_error');
                $audioFilename = $values['audio'] ?? '';
            } else {
                // Automatic duration via ffprobe (new file → always detect)
                $detectedDuration = AudioDuration::fromFile($dest);
                if ($detectedDuration) {
                    $values['duration'] = $detectedDuration;
                }
            }
        }
    }

    $coverFilename = $values['cover'] ?? '';

    // Cover deletion requested
    if (!empty($_POST['delete_cover']) && $coverFilename) {
        $coverPath = $config['audio_dir'] . '/' . $coverFilename;
        if (file_exists($coverPath)) { unlink($coverPath); }
        $coverFilename = '';
    }

    // Upload a new cover (only if no existing cover)
    if (!empty($_FILES['cover']['name'])) {
        $imgValidation = Security::validateImageUpload($_FILES['cover']);
        if ($imgValidation['ok']) {
            $epDir2 = $config['audio_dir'] . '/' . $newSlug;
            if (!is_dir($epDir2)) { mkdir($epDir2, 0755, true); }
            $coverFilename = $newSlug . '/cover.' . $imgValidation['ext'];
            $dest = $config['audio_dir'] . '/' . $coverFilename;
            if (!move_uploaded_file($_FILES['cover']['tmp_name'], $dest)) {
                $coverFilename = $values['cover'] ?? '';
            }
        }
    }

    if (empty($errors)) {
        $status = isset($_POST['save_draft']) ? 'draft' : 'published';
        $meta = [
            'title'       => $values['title'],
            'date'        => $values['date'],
            'episode'     => $values['episode'],
            'duration'    => $values['duration'],
            'description' => $values['description'],
            'audio'       => $audioFilename,
            'cover'       => $coverFilename,
            'status'      => $status,
        ];

        if ($newSlug !== $slug) {
            $parser->delete($slug);
            $tm->delete($slug);
            $cm->delete($slug);
        }

        if ($parser->save($newSlug, $meta, $values['body'])) {
            $tm->save($newSlug, $values['transcript']);
            $cm->saveFromText($newSlug, $values['chapters']);
            $sitemap = new SitemapGenerator($config['base_url'], ROOT_DIR);
            $sitemap->generate($parser->getAll());
            $_SESSION['flash'] = ['type'=>'success','message'=>'Épisode mis à jour.'];
            redirect(url('/admin/episode_edit.php?slug=' . urlencode($newSlug)));
        } else {
            $errors[] = __('ep_save_error');
        }
    }
}

$pageTitle = 'Modifier : ' . ($values['title'] ?? $slug);
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
.btn-delete-media { display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .7rem;font-size:.72rem;font-weight:600;font-family:'Syne',sans-serif;color:#ff5a5a;background:rgba(255,90,90,.08);border:1px solid rgba(255,90,90,.2);border-radius:6px;cursor:pointer;transition:background .15s,border-color .15s; }
.btn-delete-media:hover { background:rgba(255,90,90,.15);border-color:rgba(255,90,90,.4); }
.btn-delete-media.checked { background:rgba(255,90,90,.2);border-color:#ff5a5a;text-decoration:line-through; }
</style>

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <h1 style="font-size:.95rem"><?= e($values['title'] ?? $slug) ?></h1>
    <div style="display:flex;gap:.5rem">
      <?php if (($values['status'] ?? 'published') !== 'draft'): ?>
        <a href="<?= url('/episodes/' . e($slug)) ?>" target="_blank" class="btn btn-ghost" style="font-size:.78rem">Voir ↗</a>
      <?php else: ?>
        <span style="font-size:.72rem;font-weight:700;padding:.3rem .65rem;border-radius:6px;background:rgba(234,179,8,.12);color:#eab308">BROUILLON</span>
      <?php endif; ?>
      <a href="<?= url('/admin/episodes.php') ?>" class="btn btn-ghost"><?= __('back') ?></a>
    </div>
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
        <button type="button" class="tab-btn"        onclick="switchTab('chapters',this)">
          <?= __('ep_form_chapters') ?><?php if ($cm->exists($slug)): ?><span style="color:var(--accent);margin-left:.3rem">●</span><?php endif; ?>
        </button>
        <button type="button" class="tab-btn"        onclick="switchTab('transcript',this)">
          <?= __('ep_form_transcript') ?><?php if ($tm->exists($slug)): ?><span style="color:var(--accent);margin-left:.3rem">●</span><?php endif; ?>
        </button>
      </div>

      <!-- INFOS -->
      <div id="tab-infos" class="tab-pane active">
        <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
          <div class="section-label" style="margin-bottom:1.1rem"><?= __('ep_form_info') ?></div>
          <div class="form-grid">
            <div class="field form-full">
              <label><?= __('ep_title') ?></label>
              <input type="text" name="title" value="<?= e($values['title'] ?? '') ?>">
            </div>
            <div class="field">
              <label><?= __('ep_slug') ?></label>
              <input type="text" name="slug" value="<?= e($slug) ?>">
              <span class="hint"><?= __('ep_slug_rename') ?></span>
            </div>
            <div class="field">
              <label><?= __('ep_date') ?></label>
              <input type="date" name="date" value="<?= e($values['date'] ?? '') ?>">
            </div>
            <div class="field form-full">
              <label><?= __('ep_description') ?></label>
              <textarea name="description" rows="3"><?= e($values['description'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- CONTENT -->
      <div id="tab-content" class="tab-pane">
        <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
          <div class="section-label" style="margin-bottom:1rem"><?= __('ep_body') ?></div>
          <textarea id="body" name="body"><?= e($values['body'] ?? '') ?></textarea>
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
            Format <code style="color:var(--accent)">INTERVENANT: texte</code> et horodatages <code style="color:var(--accent)">[00:12:34]</code>.
          </p>
          <textarea name="transcript" class="transcript-area"
            placeholder="HOST: Bonjour..."><?= e($values['transcript']) ?></textarea>
        </div>
      </div>

      <!-- AUDIO -->
      <div id="tab-audio" class="tab-pane">
        <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
            <div class="section-label" style="margin:0"><?= __('ep_form_audio') ?></div>
            <?php if (!empty($values['audio'])): ?>
              <label class="btn-delete-media">
                <input type="checkbox" name="delete_audio" value="1" hidden onchange="confirmDelete(this, '<?= __('ep_audio_delete_confirm') ?>')">
                <?= __('delete') ?>
              </label>
            <?php endif; ?>
          </div>
          <?php if (!empty($values['audio'])): ?>
            <div style="padding:.85rem 1rem;background:var(--bg);border-radius:8px;border:1px solid var(--border);margin-bottom:1rem">
              <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                <span style="color:var(--success)">✓</span>
                <span style="font-size:.88rem"><?= e($values['audio']) ?></span>
                <audio controls style="height:32px;flex:1;min-width:200px">
                  <source src="<?= url('/audio/' . e($values['audio'])) ?>">
                </audio>
              </div>
            </div>
          <?php else: ?>
            <div class="field">
              <label for="audio"><?= __('ep_audio_upload') ?></label>
              <input type="file" id="audio" name="audio" accept="audio/*">
              <span class="hint"><?= __('ep_audio_hint') ?></span>
            </div>
          <?php endif; ?>
          <div class="field" style="margin-top:1rem">
            <label><?= __('ep_duration') ?></label>
            <input type="text" id="duration" name="duration" value="<?= e($values['duration'] ?? '') ?>" placeholder="45:30">
            <span class="hint" id="duration-hint" style="transition:color .2s"><?= __('ep_duration_auto') ?></span>
          </div>
        </div>
        <div class="card" style="padding:1.75rem">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
            <div class="section-label" style="margin:0"><?= __('ep_cover_label') ?></div>
            <?php if (!empty($values['cover'])): ?>
              <label class="btn-delete-media">
                <input type="checkbox" name="delete_cover" value="1" hidden onchange="confirmDelete(this, '<?= __('ep_cover_delete_confirm') ?>')">
                <?= __('delete') ?>
              </label>
            <?php endif; ?>
          </div>
          <?php if (!empty($values['cover'])): ?>
            <img src="<?= url('/audio/' . e($values['cover'])) ?>" alt="<?= __('ep_cover_label') ?>"
                 style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
          <?php else: ?>
            <div class="field">
              <label for="cover"><?= __('ep_cover_upload') ?></label>
              <input type="file" id="cover" name="cover" accept="image/*">
              <span class="hint"><?= __('ep_cover_hint') ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:2rem">
        <form method="POST" action="/admin/episode_delete.php"
          onsubmit="return confirm('Supprimer cet épisode définitivement ?')">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="slug" value="<?= e($slug) ?>">
          <button type="submit" class="btn" style="background:rgba(255,90,90,.1);color:#ff5a5a;border:1px solid rgba(255,90,90,.25)"><?= __('delete') ?></button>
        </form>
        <div style="display:flex;gap:.75rem">
          <a href="<?= url('/admin/episodes.php') ?>" class="btn btn-ghost"><?= __('cancel') ?></a>
          <?php if (($values['status'] ?? 'published') === 'draft'): ?>
            <button type="submit" name="save_draft" value="1" class="btn btn-ghost">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="flex-shrink:0"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Sauvegarder
            </button>
            <button type="submit" class="btn"><?= __('publish') ?></button>
          <?php else: ?>
            <button type="submit" name="save_draft" value="1" class="btn btn-ghost">Repasser en brouillon</button>
            <button type="submit" class="btn"><?= __('save') ?></button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/easymde/2.18.0/easymde.min.js"></script>
<script>
function confirmDelete(cb, msg) {
  if (cb.checked && !confirm(msg)) { cb.checked = false; return; }
  cb.closest('.btn-delete-media').classList.toggle('checked', cb.checked);
}

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
    autosave: { enabled: true, uniqueId: 'edit-ep-<?= e($slug) ?>', delay: 3000 },
    toolbar: ['bold','italic','heading','|','quote','unordered-list','ordered-list','|','link','|','preview','side-by-side','fullscreen','|','guide'],
    minHeight: '340px',
    status: ['autosave','lines','words'],
  });
}

// Auto-detect audio duration via Web Audio API (browser-side)
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
