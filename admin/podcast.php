<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$configFile = ROOT_DIR . '/config/config.php';
$audioDir   = $config['audio_dir'];
$errors  = [];
$success = [];

function podWriteKey(string $file, string $key, string $value): void {
    $content = file_get_contents($file);
    $escaped = str_replace("'", "\\'", $value);
    $content = preg_replace(
        "/('$key'\s*=>\s*)'(?:[^'\\\\]|\\\\.)*'/",
        "'$key' => '$escaped'",
        $content
    );
    file_put_contents($file, $content);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['podcast_title','podcast_description','author','email','language','category'];
    foreach ($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        if ($key === 'podcast_title' && !$val) { $errors[] = "Le titre est requis."; continue; }
        if ($key === 'base_url') $val = rtrim($val, '/');
        podWriteKey($configFile, $key, $val);
        $config[$key] = $val;
    }
    if (!empty($_FILES['cover_image']['tmp_name'])) {
        $file = $_FILES['cover_image'];
        $allowedPodMime = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        ];
        $finfoP   = finfo_open(FILEINFO_MIME_TYPE);
        $mimeP    = (string) finfo_file($finfoP, $file['tmp_name']);
        finfo_close($finfoP);
        $ext = $allowedPodMime[$mimeP] ?? '';
        if (!$ext) {
            $errors[] = "Format non supporté (MIME : $mimeP).";
        } elseif ($file['size'] > 5*1024*1024) {
            $errors[] = "Image trop lourde (max 5 Mo).";
        } else {
            $dest = $audioDir . '/cover.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                // Vérifier les dimensions (recommandé 1400×1400 min pour Apple Podcasts)
                $imgSize = getimagesize($dest);
                if ($imgSize && ($imgSize[0] < 3000 || $imgSize[1] < 3000)) {
                    $errors[] = "Image trop petite ({$imgSize[0]}×{$imgSize[1]}px). Apple Podcasts requiert 3000×3000px minimum.";
                    @unlink($dest);
                } else {
                    podWriteKey($configFile, 'cover_image', 'cover.' . $ext);
                    $config['cover_image'] = 'cover.' . $ext;
                }
            } else {
                $errors[] = "Erreur upload cover.";
            }
        }
    }
    if (!$errors) $success[] = "Podcast mis à jour.";
}

$itunesCategories = ['Arts','Business','Comedy','Education','Fiction','Government',
    'Health & Fitness','History','Kids & Family','Leisure','Music','News',
    'Religion & Spirituality','Science','Society & Culture','Sports',
    'Technology','True Crime','TV & Film'];

$pageTitle = 'Mon podcast';
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>
<style>
  .pod-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
  @media(max-width:700px) { .pod-grid { grid-template-columns:1fr; } }
  .cover-preview { width:100%;aspect-ratio:1;border-radius:10px;object-fit:cover;border:1px solid var(--border);display:block;margin-bottom:.75rem }
  .cover-placeholder { width:100%;aspect-ratio:1;border-radius:10px;border:2px dashed var(--border);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;color:var(--muted);font-size:.82rem;margin-bottom:.75rem }
</style>
<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu"><i data-feather="menu"></i></button>
    <h1>Mon podcast</h1>
  </div>
  <div class="content">
    <?php if ($errors): ?>
      <div style="background:rgba(255,80,80,.1);border:1px solid rgba(255,80,80,.3);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;color:#ff7070;font-size:.85rem">
        <?php foreach ($errors as $e): ?><div>⚠ <?= e($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div style="background:rgba(90,255,154,.08);border:1px solid rgba(90,255,154,.25);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;color:#5aff9a;font-size:.85rem">
        <?php foreach ($success as $s): ?><div>✓ <?= e($s) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
      <div class="pod-grid" style="margin-bottom:1.25rem">
        <div style="display:flex;flex-direction:column;gap:1.25rem">
          <div class="card" style="padding:1.5rem">
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Identité</div>
            <div class="field">
              <label>Titre <span style="color:#ff7070">*</span></label>
              <input type="text" name="podcast_title" value="<?= e($config['podcast_title'] ?? '') ?>" placeholder="Mon Super Podcast">
            </div>
            <div class="field">
              <label>Auteur</label>
              <input type="text" name="author" value="<?= e($config['author'] ?? '') ?>" placeholder="Prénom Nom">
            </div>
            <div class="field">
              <label>Email de contact</label>
              <input type="email" name="email" value="<?= e($config['email'] ?? '') ?>" placeholder="contact@monpodcast.com">
            </div>
            <div class="field" style="margin-bottom:0">
              <label>Description</label>
              <textarea name="podcast_description" rows="5" placeholder="Décrivez votre podcast…" style="resize:vertical"><?= e($config['podcast_description'] ?? '') ?></textarea>
              <span class="hint">Affichée sur la home et dans le flux RSS.</span>
            </div>
          </div>
          <div class="card" style="padding:1.5rem">
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Diffusion</div>
            <div class="field">
              <label>Catégorie Apple Podcasts</label>
              <select name="category">
                <?php foreach ($itunesCategories as $cat): ?>
                  <option value="<?= e($cat) ?>" <?= ($config['category'] ?? '') === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Langue du flux</label>
              <select name="language">
                <?php foreach ([
                    'fr-FR'=>'Français','en-US'=>'English (US)','en-GB'=>'English (UK)',
                    'es-ES'=>'Español','de-DE'=>'Deutsch','it-IT'=>'Italiano',
                    'pt-BR'=>'Português (BR)','nl-NL'=>'Nederlands','ja-JP'=>'日本語'
                ] as $code => $label): ?>
                  <option value="<?= e($code) ?>" <?= ($config['language'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field" style="margin-bottom:0">
              <label>URL de base</label>
              <div style="display:flex;align-items:center;gap:.75rem;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:.6rem .9rem">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <code style="font-size:.8rem;color:var(--muted)"><?= e($config['base_url'] ?? '') ?></code>
              </div>
              <span class="hint">⚠ Modifier cette valeur casserait tous les liens. Éditez <code>config/config.php</code> directement si nécessaire.</span>
            </div>
          </div>
        </div>
        <div class="card" style="padding:1.5rem;align-self:start">
          <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Pochette principale</div>
          <?php
          $coverImg  = $config['cover_image'] ?? '';
          $coverPath = $audioDir . '/' . $coverImg;
          $coverDims = ($coverImg && file_exists($coverPath)) ? getimagesize($coverPath) : null;
          if ($coverImg && file_exists($coverPath)): ?>
            <img src="<?= url('/audio/' . e($coverImg)) ?>" alt="Cover" class="cover-preview" id="coverPreview">
            <?php if ($coverDims): ?>
            <div style="font-size:.7rem;color:var(--muted);text-align:center;margin-bottom:.5rem">
              <?= $coverDims[0] ?>×<?= $coverDims[1] ?>px
              <?php if ($coverDims[0] >= 3000 && $coverDims[1] >= 3000): ?>
                <span style="color:#5aff9a">✓ Apple Podcasts</span>
              <?php else: ?>
                <span style="color:#ff7070">⚠ trop petit</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="cover-placeholder" id="coverPlaceholder">
              <i data-feather="image" style="width:32px;height:32px;opacity:.4"></i>
              <span>Aucune pochette</span>
            </div>
            <img src="" alt="" class="cover-preview" id="coverPreview" style="display:none">
          <?php endif; ?>
          <label class="btn btn-ghost" style="width:100%;justify-content:center;cursor:pointer;margin-bottom:.5rem">
            <i data-feather="upload"></i> Choisir une image
            <input type="file" name="cover_image" accept="image/*" style="display:none" onchange="previewCover(event)">
          </label>
          <div style="font-size:.72rem;color:var(--muted);text-align:center">JPG · PNG · WebP · recommandé <strong style="color:var(--text)">3000×3000px</strong></div>
          <div style="font-size:.68rem;color:var(--muted);text-align:center;margin-top:.2rem">Requis pour Apple Podcasts, Spotify, Google Podcasts</div>
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end">
        <button type="submit" class="btn"><i data-feather="save"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<script>
function previewCover(e) {
  const file = e.target.files[0];
  if (!file) return;
  const r = new FileReader();
  r.onload = ev => {
    const img = document.getElementById('coverPreview');
    const ph  = document.getElementById('coverPlaceholder');
    img.src = ev.target.result;
    img.style.display = '';
    if (ph) ph.style.display = 'none';
  };
  r.readAsDataURL(file);
}
</script>
</body>
</html>
