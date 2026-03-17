<?php
ob_start();
// =============================================================================
//  admin/episodes.php — Liste de tous les épisodes
//
//  Affiche un tableau de tous les épisodes avec : numéro, titre, date,
//  durée, présence d'un fichier audio, et actions (modifier / supprimer).
//  Un message flash (succès ou erreur) peut être affiché après une action.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->requireLogin();

// Charger tous les épisodes, triés du plus récent au plus ancien
$parser   = new EpisodeParser($config['content_dir']);
$episodes = $parser->getAll();

// Récupérer et effacer le message flash (affiché une seule fois)
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
    <a href="<?= url('/admin/episode_new.php') ?>" class="btn">
      <i data-feather="plus" style="width:14px;height:14px;stroke-width:2.5"></i>
      <?= __('nav_new_episode') ?>
    </a>
  </div>

  <div class="content">

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
      <?php if (empty($episodes)): ?>
        <!-- État vide : aucun épisode créé -->
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
                <th><?= __('ep_col_num') ?></th>
                <th><?= __('ep_col_title') ?></th>
                <th><?= __('ep_col_date') ?></th>
                <th><?= __('ep_col_duration') ?></th>
                <th><?= __('ep_col_audio') ?></th>
                <th><?= __('ep_col_actions') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($episodes as $ep): ?>
              <tr>

                <!-- Numéro d'épisode -->
                <td>
                  <span class="badge"><?= e($ep['episode'] ?? '—') ?></span>
                </td>

                <!-- Titre + description courte -->
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

                <!-- Indicateur de présence du fichier audio -->
                <td>
                  <?php if (!empty($ep['audio'])): ?>
                    <span style="color:var(--success);font-size:.8rem">✓</span>
                    <span style="font-size:.78rem;color:var(--muted)"><?= e($ep['audio']) ?></span>
                  <?php else: ?>
                    <span style="color:var(--muted);font-size:.8rem">—</span>
                  <?php endif; ?>
                </td>

                <!-- Actions : modifier | supprimer -->
                <td>
                  <div style="display:flex;gap:.5rem">
                    <a href="<?= url('/admin/episode_edit.php?slug=' . urlencode($ep['slug'])) ?>"
                       class="btn btn-ghost" style="font-size:.78rem;padding:.35rem .75rem">
                      <?= __('edit') ?>
                    </a>

                    <!-- Formulaire de suppression avec confirmation JS -->
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


<script>
document.querySelectorAll('.form-delete').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    var title = form.querySelector('[name="confirm_title"]').value;
    if (!window.confirm('Supprimer « ' + title + ' » ?')) {
      e.preventDefault();
    }
  });
});
</script>
</body>
</html>
