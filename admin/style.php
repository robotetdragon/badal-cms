<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$theme = new ThemeManager(dirname($config['content_dir']) . '/config');
$fonts = ThemeManager::fontList();
$socials = ThemeManager::socialNetworks();

$allowedFonts   = $fonts;
$allowedWeights = ['100','200','300','400','500','600','700','800','900'];
$allowed        = ['png','jpg','jpeg','gif','svg','webp'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle logo/cover upload
function handleUpload(string $field, string $audioDir): string {
    if (empty($_FILES[$field]['name'])) return '';
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return '';
    $filename = $field . '_' . time() . '.' . $ext;
    $dest = $audioDir . '/' . $filename;
    return move_uploaded_file($_FILES[$field]['tmp_name'], $dest) ? $filename : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $data = [];

    // Couleurs
    foreach (['color_bg','color_surface','color_border','color_accent','color_text','color_muted'] as $k) {
        if (!empty($_POST[$k]) && preg_match('/^[#][0-9a-fA-F]{3,8}$/', $_POST[$k])) {
            $data[$k] = $_POST[$k];
        }
    }

    // Typo
    foreach (['font_heading','font_body'] as $k) {
        if (!empty($_POST[$k]) && in_array($_POST[$k], $allowedFonts)) {
            $data[$k] = $_POST[$k];
        }
    }
    foreach (['font_weight_heading','font_weight_body'] as $k) {
        if (!empty($_POST[$k]) && in_array($_POST[$k], $allowedWeights)) {
            $data[$k] = $_POST[$k];
        }
    }

    // Textes
    foreach (['home_tagline','home_cta_label','home_cta_url','home_footer_text'] as $k) {
        $data[$k] = trim($_POST[$k] ?? '');
    }

    // Reseaux sociaux
    foreach (array_keys($socials) as $net) {
        $data['social_' . $net] = trim($_POST['social_' . $net] ?? '');
    }

    // Mise en page
    $data['layout_width']          = max(400, min(1400, (int)($_POST['layout_width'] ?? 740)));
    $data['header_align']          = in_array($_POST['header_align'] ?? '', ['center','left']) ? $_POST['header_align'] : 'center';
    $data['episodes_style']        = in_array($_POST['episodes_style'] ?? '', ['list','grid']) ? $_POST['episodes_style'] : 'list';
    $data['show_episode_number']   = isset($_POST['show_episode_number']) ? '1' : '0';
    $data['show_episode_date']     = isset($_POST['show_episode_date']) ? '1' : '0';
    $data['show_episode_duration'] = isset($_POST['show_episode_duration']) ? '1' : '0';

    // Logo type
    $data['logo_type'] = in_array($_POST['logo_type'] ?? '', ['icon','image']) ? $_POST['logo_type'] : 'icon';

    // Image uploads
    $audioDir = $config['audio_dir'];
    foreach (['logo_image','cover_image'] as $imgField) {
        $uploaded = handleUpload($imgField, $audioDir);
        if ($uploaded) {
            $data[$imgField] = $uploaded;
        } else {
            $data[$imgField] = $theme->get($imgField); // keep existing
        }
    }

    $ok = $theme->save($data);

    // Requête AJAX (autosave) — réponse JSON
    if (!empty($_POST['_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }

    // Requête normale — redirect
    $_SESSION['flash'] = ['type' => $ok ? 'success' : 'error', 'message' => $ok ? __('app_saved') : 'Erreur lors de la sauvegarde.'];
    header('Location: ' . url('/admin/style.php'));
    exit;
}

$t = $theme->getAll();

$pageTitle = __('app_title');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>
<link rel="stylesheet" href="/betapodcast/admin/assets/style.css">

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <?php echo '<h1>' . __('app_title') . '</h1>'; ?>
    <div class="topbar-actions">
      <?php echo '<a href="' . url('/') . '" target="_blank" class="btn btn-ghost"><span class="btn-label">' . __('nav_view_site') . '</span> ↗</a>'; ?>
      <span id="autosave-status" style="font-size:.75rem;color:var(--muted);display:flex;align-items:center;gap:.4rem"></span>
    </div>
  </div>

  <div class="content">
    <?php if ($flash): ?>
      <?php echo '<div class="alert alert-' . e($flash['type']) . '">' . e($flash['message']) . '</div>'; ?>
    <?php endif; ?>

    <div>
      <?php
        $tabs = [
          'colors' => ['label'=>__('app_tab_colors'), 'icon'=>'droplet'],
          'typo'   => ['label'=>__('app_tab_typo'),   'icon'=>'type'],
          'texts'  => ['label'=>__('app_tab_texts'),   'icon'=>'file-text'],
          'media'  => ['label'=>__('app_tab_media'),   'icon'=>'image'],
          'layout' => ['label'=>__('app_tab_layout'),  'icon'=>'layout'],
        ];
        echo '<div class="tabs">';
        $first = true;
        foreach ($tabs as $tid => $tab) {
          $act = $first ? ' active' : ''; $first = false;
          echo '<button type="button" class="tab-btn' . $act . '" data-tab="' . $tid . '"><i data-feather="' . $tab['icon'] . '"></i> ' . $tab['label'] . '</button>';
        }
        echo '</div>';
        ?>

        <form id="appearance-form" method="POST" data-enc="multipart/form-data">
          <?php echo '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">'; ?>

          <!-- ── COULEURS ── -->
          <div class="tab-pane active" id="tab-colors">
            <div class="card" style="padding:1.75rem">
              <?php
              $colorFields = [
                'color_bg'      => ['Fond principal', 'Couleur de fond de la page'],
                'color_surface' => ['Surface / cartes', 'Fond des éléments en relief'],
                'color_border'  => ['Bordures', 'Lignes de séparation'],
                'color_accent'  => ['Accentuation', 'Titres hover, badges, CTA'],
                'color_text'    => ['Texte principal', 'Corps de texte'],
                'color_muted'   => ['Texte secondaire', 'Metadonnees, labels'],
              ];
              echo '<div class="three-col">';
              foreach ($colorFields as $key => [$label, $hint]) {
                $val = e($t[$key]);
                echo '<div class="field" style="margin-bottom:0">';
                echo '<label>' . $label . '</label>';
                echo '<div class="color-field">';
                echo '<input type="color" id="cp_' . $key . '" value="' . $val . '" data-color="' . $key . '">';
                echo '<input type="text" id="ct_' . $key . '" name="' . $key . '" value="' . $val . '" data-sync="' . $key . '" maxlength="9">';
                echo '</div>';
                echo '<span class="hint">' . $hint . '</span>';
                echo '</div>';
              }
              echo '</div>';
              ?>

              <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border)">
                <div class="section-label">Presets</div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                  <?php
                  $presets = [
                    ['Sombre',      'S1', 'e8ff5a'],
                    ['Nuit bleue',  'S2', '6eb5ff'],
                    ['Minuit vert', 'S4', '00e5a0'],
                    ['Sable',       'S6', 'c45c2a'],
                    ['Papier',      'S7', '1a1a1a'],
                  ];
                  foreach ($presets as [$name, $pid, $acc]) {
                    echo '<button type="button" class="btn btn-ghost preset-btn" data-pid="' . $pid . '">';
                    echo '<span class="preset-dot" data-color="' . $acc . '"></span>';
                    echo $name;
                    echo '</button>';
                  }
                  ?>
                </div>
              </div>
            </div>
          </div>

          <!-- ── TYPOGRAPHIE ── -->
          <div class="tab-pane" id="tab-typo">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
              <?php
              $fontPairs = [
                'font_heading' => ['label' => __('app_font_heading'), 'wkey' => 'font_weight_heading'],
                'font_body'    => ['label' => __('app_font_body'),    'wkey' => 'font_weight_body'],
              ];
              foreach ($fontPairs as $key => $cfg) {
                $wkey = $cfg['wkey'];
                $wVal = $t[$wkey] ?? '400';
                echo '<div class="card" style="padding:1.5rem">';
                echo '<div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">' . $cfg['label'] . '</div>';
                echo '<div class="field" style="margin-bottom:.85rem">';
                echo '<label>Police</label>';
                echo '<select name="' . $key . '" id="sel_' . $key . '" data-font-key="' . $key . '">';
                foreach ($fonts as $font) {
                  $sel = $t[$key] === $font ? ' selected' : '';
                  echo '<option value="' . e($font) . '"' . $sel . '>' . e($font) . '</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '<div class="field" style="margin-bottom:1rem">';
                echo '<label>Weight</label>';
                echo '<select name="' . $wkey . '" id="sel_' . $wkey . '" data-weight-for="' . $key . '">';
                $weights = ['100'=>'Thin 100','200'=>'ExtraLight 200','300'=>'Light 300','400'=>'Regular 400','500'=>'Medium 500','600'=>'SemiBold 600','700'=>'Bold 700','800'=>'ExtraBold 800','900'=>'Black 900'];
                foreach ($weights as $wv => $wl) {
                  echo '<option value="' . $wv . '"' . ($wVal === $wv ? ' selected' : '') . '>' . $wl . '</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '<div class="font-preview" id="prev_' . $key . '" style="margin-top:.5rem;padding:.75rem;background:var(--bg);border-radius:8px;border:1px solid var(--border);font-size:.88rem;color:var(--muted)">';
                echo '<span id="prev_' . $key . '_text" data-font="' . e($t[$key]) . '" data-weight="' . e($wVal) . '">' . e($t[$key]) . ' — Le podcast qui vous inspire</span>';
                echo '</div>';
                echo '</div>';
              }
              ?>
            </div>
            <div class="card" style="padding:1.5rem">
              <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Aperçu combiné</div>
              <div id="font-combo-preview" style="padding:.75rem;background:var(--bg);border-radius:8px;border:1px solid var(--border)">
                <div id="combo-heading" style="font-size:1.8rem;letter-spacing:-.02em;margin-bottom:.4rem">Mon Podcast</div>
                <div id="combo-body" style="font-size:1rem;color:#888;line-height:1.6">Le podcast qui vous inspire chaque semaine. Des conversations profondes sur la technologie et la créativité.</div>
              </div>
            </div>
          </div>

          <!-- ── TEXTES ── -->
          <div class="tab-pane" id="tab-texts">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
              <div>
                <div class="card" style="padding:1.5rem;margin-bottom:1.25rem">
                  <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">En-tête</div>
                  <div class="field" style="margin-bottom:1rem">
                    <label>Tagline (sous le titre)</label>
                    <?php echo '<input type="text" name="home_tagline" value="' . e($t['home_tagline']) . '" placeholder="Laissez vide pour la description">'; ?>
                    <span class="hint">Remplace la description sur la home</span>
                  </div>
                  <div class="field" style="margin-bottom:1rem">
                    <label>Texte du bouton RSS</label>
                    <?php echo '<input type="text" name="home_cta_label" value="' . e($t['home_cta_label']) . '">'; ?>
                  </div>
                  <div class="field" style="margin-bottom:0">
                    <label>URL du bouton RSS</label>
                    <?php echo '<input type="text" name="home_cta_url" value="' . e($t['home_cta_url']) . '">'; ?>
                  </div>
                </div>
                <div class="card" style="padding:1.5rem">
                  <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Pied de page</div>
                  <div class="field" style="margin-bottom:0">
                    <label>Texte personnalisé</label>
                    <?php echo '<input type="text" name="home_footer_text" value="' . e($t['home_footer_text']) . '" placeholder="Footer automatique">'; ?>
                    <span class="hint">Laissez vide pour le footer automatique</span>
                  </div>
                </div>
              </div>
              <div class="card" style="padding:1.5rem;align-self:start">
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Réseaux sociaux</div>
                <?php
                foreach ($socials as $net => $info) {
                  echo '<div class="social-row">';
                  echo '<div class="social-label">' . e($info['label']) . '</div>';
                  echo '<input type="text" name="social_' . $net . '" value="' . e($t['social_' . $net] ?? '') . '" placeholder="' . e($info['placeholder']) . '">';
                  echo '</div>';
                }
                ?>
              </div>
            </div>
          </div>

          <!-- ── MÉDIAS ── -->
          <div class="tab-pane" id="tab-media">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">
              <div class="card" style="padding:1.5rem">
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Logo</div>
                <?php
                $ci = $t['logo_type'] === 'icon' ? ' checked' : '';
                $cm = $t['logo_type'] === 'image' ? ' checked' : '';
                echo '<div class="field" style="margin-bottom:1rem">';
                echo '<label>' . __('app_logo_type') . '</label>';
                echo '<div style="display:flex;gap:1rem;margin-top:.25rem">';
                echo '<label style="text-transform:none;font-size:.88rem;display:flex;align-items:center;gap:.5rem;cursor:pointer"><input type="radio" name="logo_type" value="icon"' . $ci . '> Icône par défaut</label>';
                echo '<label style="text-transform:none;font-size:.88rem;display:flex;align-items:center;gap:.5rem;cursor:pointer"><input type="radio" name="logo_type" value="image"' . $cm . '> Image personnalisée</label>';
                echo '</div></div>';
                ?>
                <div class="field" style="margin-bottom:0">
                  <label>Fichier logo (PNG, SVG recommandé)</label>
                  <?php if (!empty($t['logo_image'])): ?>
                    <?php echo '<div style="font-size:.8rem;color:var(--muted);margin-bottom:.4rem">Actuel : <strong>' . e($t['logo_image']) . '</strong></div>'; ?>
                  <?php endif; ?>
                  <input type="file" name="logo_image" accept="image/*">
                  <span class="hint">200×200px minimum</span>
                </div>
              </div>

              <div class="card" style="padding:1.5rem">
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Image de couverture</div>
                <div class="field" style="margin-bottom:0">
                  <label>Image de fond de l'en-tête</label>
                  <?php if (!empty($t['cover_image'])): ?>
                    <?php echo '<div style="font-size:.8rem;color:var(--muted);margin-bottom:.4rem">Actuelle : <strong>' . e($t['cover_image']) . '</strong></div>'; ?>
                  <?php endif; ?>
                  <input type="file" name="cover_image" accept="image/*">
                  <span class="hint">JPG/PNG — fond de l'en-tête (optionnel)</span>
                </div>
              </div>
            </div>
          </div>

          <!-- ── MISE EN PAGE ── -->
          <div class="tab-pane" id="tab-layout">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">
              <div class="card" style="padding:1.5rem">
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Général</div>
                <div class="field" style="margin-bottom:1rem">
                  <label>Largeur max du contenu (px)</label>
                  <?php echo '<input type="text" name="layout_width" value="' . e($t['layout_width']) . '" placeholder="740">'; ?>
                </div>
                <div class="field" style="margin-bottom:1rem">
                  <label>Alignement de l'en-tête</label>
                  <?php
                  $cc = $t['header_align'] === 'center' ? ' selected' : '';
                  $cl = $t['header_align'] === 'left' ? ' selected' : '';
                  echo '<select name="header_align">';
                  echo '<option value="center"' . $cc . '>Centre</option>';
                  echo '<option value="left"' . $cl . '>' . __('app_header_left') . '</option>';
                  echo '</select>';
                  ?>
                </div>
                <div class="field" style="margin-bottom:0">
                  <label>Style de la liste d'épisodes</label>
                  <select name="episodes_style">
                    <?php
                    $el = $t['episodes_style'] === 'list' ? ' selected' : '';
                    $eg = $t['episodes_style'] === 'grid' ? ' selected' : '';
                    echo '<option value="list"' . $el . '>Liste (compact)</option>';
                    echo '<option value="grid"' . $eg . '>Grille (cartes)</option>';
                    ?>
                  </select>
                </div>
              </div>

              <div class="card" style="padding:1.5rem">
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Infos affichées sur chaque épisode</div>
                <?php
                $toggles = [
                  'show_episode_number'   => "Numéro d'épisode",
                  'show_episode_date'     => 'Date de publication',
                  'show_episode_duration' => 'Durée',
                ];
                foreach ($toggles as $key => $label) {
                  $chk = $t[$key] !== '0' ? ' checked' : '';
                  echo '<div class="toggle-wrap" style="margin-bottom:.85rem">';
                  echo '<label class="toggle">';
                  echo '<input type="checkbox" name="' . $key . '"' . $chk . '>';
                  echo '<span class="slider"></span></label>';
                  echo '<span style="font-size:.88rem">' . $label . '</span>';
                  echo '</div>';
                }
                ?>
              </div>
            </div>
          </div>

        </form>
    </div>
  </div>
</div>

<script src="/betapodcast/admin/assets/style.js"></script>
</body>
</html>
