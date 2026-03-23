<?php
ob_start();
// =============================================================================
//  admin/episodes.php — Liste de tous les épisodes (réordonnables)
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->requireLogin();

$parser   = new EpisodeParser($config['content_dir']);

// ── AJAX : sauvegarder l'ordre ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    csrf_check();
    $slugs = json_decode($_POST['order'] ?? '[]', true);
    if (is_array($slugs)) {
        // Sanitize slugs
        $slugs = array_map(fn($s) => preg_replace('/[^a-z0-9-]/i', '', $s), $slugs);
        $ok = $parser->saveOrder($slugs);
    } else {
        $ok = false;
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok]);
    exit;
}

// ── AJAX : réinitialiser l'ordre ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_order') {
    csrf_check();
    $ok = $parser->resetOrder();
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok]);
    exit;
}

$episodes = $parser->getAll();

// Ordre personnalisé actif ?
$orderFile = dirname($config['content_dir']) . '/config/episodes_order.json';
$hasCustomOrder = file_exists($orderFile);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = __('ep_list_title');
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <h1>
      <?= __('ep_list_title') ?>
      <span class="badge" style="margin-left:.5rem"><?= count($episodes) ?></span>
    </h1>
    <div class="topbar-actions">
      <span id="reorder-status" style="font-size:.75rem;color:var(--muted);display:flex;align-items:center;gap:.4rem"></span>
      <?php if ($hasCustomOrder): ?>
        <button type="button" class="btn btn-ghost" id="btn-reset-order" style="font-size:.78rem;padding:.35rem .75rem" title="Revenir au tri par date">
          <i data-feather="rotate-ccw" style="width:13px;height:13px"></i> Tri par date
        </button>
      <?php endif; ?>
      <a href="<?= url('/admin/episode_new.php') ?>" class="btn">
        <i data-feather="plus" style="width:14px;height:14px;stroke-width:2.5"></i>
        <?= __('nav_new_episode') ?>
      </a>
    </div>
  </div>

  <div class="content">

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
      <?php if (empty($episodes)): ?>
        <div style="padding:3rem 2rem;text-align:center;color:var(--muted)">
          <div style="width:56px;height:56px;border-radius:14px;background:rgba(232,255,90,.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem">
            <i data-feather="mic" style="width:24px;height:24px;color:var(--accent)"></i>
          </div>
          <div style="font-size:1.05rem;font-weight:700;color:var(--text);margin-bottom:.5rem">Aucun épisode pour l'instant</div>
          <p style="font-size:.85rem;max-width:400px;margin:0 auto 1.5rem;line-height:1.6">Créez votre premier épisode, ou importez un podcast existant depuis son flux RSS.</p>
          <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
            <a href="<?= url('/admin/episode_new.php') ?>" class="btn">
              <i data-feather="plus-circle"></i> Nouvel épisode
            </a>
            <a href="<?= url('/admin/import.php') ?>" class="btn btn-ghost">
              <i data-feather="download-cloud"></i> Importer un podcast
            </a>
          </div>
        </div>

      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:32px"></th>
                <th><?= __('ep_col_num') ?></th>
                <th><?= __('ep_col_title') ?></th>
                <th><?= __('ep_col_date') ?></th>
                <th><?= __('ep_col_duration') ?></th>
                <th><?= __('ep_col_audio') ?></th>
                <th><?= __('ep_col_actions') ?></th>
              </tr>
            </thead>
            <tbody id="episodes-body">
              <?php foreach ($episodes as $ep): ?>
              <tr data-slug="<?= e($ep['slug']) ?>">

                <!-- Poignée de drag -->
                <td class="drag-handle" style="cursor:grab;color:var(--muted);text-align:center;user-select:none">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="18" x2="16" y2="18"/></svg>
                </td>

                <td>
                  <span class="badge"><?= e($ep['episode'] ?? '—') ?></span>
                </td>

                <td>
                  <div style="font-weight:600"><?= e($ep['title'] ?? 'Sans titre') ?></div>
                  <?php if (!empty($ep['description'])): ?>
                    <div style="font-size:.78rem;color:var(--muted);margin-top:.15rem">
                      <?= e(mb_strimwidth($ep['description'], 0, 80, '…')) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td style="color:var(--muted);white-space:nowrap"><?= e($ep['date'] ?? '') ?></td>
                <td style="color:var(--muted)"><?= e($ep['duration'] ?? '—') ?></td>

                <td>
                  <?php if (!empty($ep['audio'])): ?>
                    <span style="color:var(--success);font-size:.8rem">✓</span>
                    <span style="font-size:.78rem;color:var(--muted)"><?= e($ep['audio']) ?></span>
                  <?php else: ?>
                    <span style="color:var(--muted);font-size:.8rem">—</span>
                  <?php endif; ?>
                </td>

                <td>
                  <div style="display:flex;gap:.5rem">
                    <a href="<?= url('/admin/episode_edit.php?slug=' . urlencode($ep['slug'])) ?>"
                       class="btn btn-ghost" style="font-size:.78rem;padding:.35rem .75rem">
                      <?= __('edit') ?>
                    </a>
                    <form method="POST" action="<?= url('/admin/episode_delete.php') ?>" class="form-delete">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="slug"       value="<?= e($ep['slug']) ?>">
                      <input type="hidden" name="confirm_title" value="<?= e($ep['title'] ?? '') ?>">
                      <button type="submit" class="btn btn-danger" style="font-size:.78rem;padding:.35rem .75rem">
                        <?= __('delete') ?>
                      </button>
                    </form>
                  </div>
                </td>

              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<style>
  .drag-handle:hover { color: var(--accent) !important; }
  tr.dragging { opacity: .4; }
  tr.drag-over td { border-top: 2px solid var(--accent) !important; }
</style>

<script>
// ── Confirmation suppression ──
document.querySelectorAll('.form-delete').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    var title = form.querySelector('[name="confirm_title"]').value;
    if (!window.confirm('Supprimer « ' + title + ' » ?')) {
      e.preventDefault();
    }
  });
});

// ── Drag & drop réordonnement ──
(function() {
  var tbody  = document.getElementById('episodes-body');
  if (!tbody) return;

  var csrf   = '<?= csrf_token() ?>';
  var status = document.getElementById('reorder-status');

  var CHECK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#5aff9a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
  var SPIN_SVG  = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation:spin .8s linear infinite"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';

  var dragRow = null;

  tbody.addEventListener('dragstart', function(e) {
    var tr = e.target.closest('tr');
    if (!tr) return;
    dragRow = tr;
    tr.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });

  tbody.addEventListener('dragend', function(e) {
    if (dragRow) dragRow.classList.remove('dragging');
    dragRow = null;
    tbody.querySelectorAll('.drag-over').forEach(function(r) { r.classList.remove('drag-over'); });
  });

  tbody.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var tr = e.target.closest('tr');
    if (!tr || tr === dragRow) return;
    tbody.querySelectorAll('.drag-over').forEach(function(r) { r.classList.remove('drag-over'); });
    tr.classList.add('drag-over');
  });

  tbody.addEventListener('drop', function(e) {
    e.preventDefault();
    var tr = e.target.closest('tr');
    if (!tr || !dragRow || tr === dragRow) return;
    tr.classList.remove('drag-over');

    // Insérer avant ou après selon la position
    var rect  = tr.getBoundingClientRect();
    var after = e.clientY > rect.top + rect.height / 2;
    if (after) {
      tbody.insertBefore(dragRow, tr.nextSibling);
    } else {
      tbody.insertBefore(dragRow, tr);
    }

    saveOrder();
  });

  // Rendre les lignes draggable
  tbody.querySelectorAll('tr').forEach(function(tr) {
    tr.setAttribute('draggable', 'true');
  });

  function saveOrder() {
    var slugs = [];
    tbody.querySelectorAll('tr[data-slug]').forEach(function(tr) {
      slugs.push(tr.dataset.slug);
    });

    status.innerHTML = SPIN_SVG + '<span style="color:var(--muted)">Enregistrement…</span>';

    var data = new FormData();
    data.append('csrf_token', csrf);
    data.append('action', 'reorder');
    data.append('order', JSON.stringify(slugs));

    fetch(window.location.href, { method: 'POST', body: data })
      .then(function(r) { return r.json(); })
      .then(function(json) {
        if (json.ok) {
          status.innerHTML = CHECK_SVG + '<span style="color:#5aff9a">Ordre sauvegardé</span>';
          setTimeout(function() { status.innerHTML = ''; }, 2500);
        }
      })
      .catch(function() {
        status.innerHTML = '<span style="color:var(--danger)">Erreur</span>';
      });
  }

  // ── Reset order ──
  var resetBtn = document.getElementById('btn-reset-order');
  if (resetBtn) {
    resetBtn.addEventListener('click', function() {
      if (!confirm('Revenir au tri par date de publication ?')) return;
      var data = new FormData();
      data.append('csrf_token', csrf);
      data.append('action', 'reset_order');
      fetch(window.location.href, { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(json) {
          if (json.ok) window.location.reload();
        });
    });
  }
})();
</script>
</body>
</html>
