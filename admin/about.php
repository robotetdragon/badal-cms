<?php
ob_start();
require_once __DIR__ . '/../core/bootstrap.php';
$auth = new Auth($config);
$auth->requireLogin();

$pageTitle = 'À propos';
include __DIR__ . '/layout_head.php';
include __DIR__ . '/sidebar.php';
?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" onclick="openSidebar()" aria-label="Menu">
      <i data-feather="menu"></i>
    </button>
    <h1>À propos</h1>
    <div class="topbar-actions"></div>
  </div>

  <div class="content">

    <div style="max-width:640px">

      <!-- Logo -->
      <div style="margin-bottom:2.5rem;text-align:center">
        <div style="height:48px;aspect-ratio:541/183;margin:0 auto 1rem;background:var(--accent);-webkit-mask:url('<?= url('/audio/badal_logo.svg') ?>') center/contain no-repeat;mask:url('<?= url('/audio/badal_logo.svg') ?>') center/contain no-repeat"></div>
        <div style="font-size:.78rem;color:var(--muted)">Version <?= defined('BADAL_VERSION') ? BADAL_VERSION : '0.11' ?></div>
      </div>

      <!-- Étymologie -->
      <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
        <h2 style="font-size:1.1rem;font-weight:800;letter-spacing:-.02em;margin-bottom:.75rem">Badal <span style="font-weight:400;color:var(--muted);font-size:.88rem">[baˈðal]</span></h2>
        <p style="font-size:.9rem;color:var(--muted);line-height:1.75;margin-bottom:1rem">
          Mot occitan qui signifie <em style="color:var(--accent);font-style:normal;font-weight:700">l'ouverture</em>.
          Issu du latin <em>batare</em> (ouvrir la bouche), cousin du catalan <em>badallar</em>.
          C'est le geste de celui qui ouvre grand la bouche — pour parler, chanter,
          ou rester ébahi devant une bonne histoire.
        </p>
        <p style="font-size:.9rem;color:var(--muted);line-height:1.75;margin-bottom:1rem">
          Autrement dit : exactement ce que vous faites devant un micro. Et exactement ce que
          font vos auditeurs quand ils découvrent votre podcast (en tout cas, on l'espère).
        </p>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.25rem;margin-top:.5rem">
          <p style="font-size:.85rem;line-height:1.75;color:var(--text)">
            Dans un monde où le podcast est de plus en plus enfermé dans des écosystèmes
            propriétaires et des algorithmes qui décident à votre place ce que les gens
            devraient écouter, Badal naît d'une idée toute bête :
            <strong style="color:var(--accent)">et si on vous rendait les clés ?</strong>
          </p>
          <p style="font-size:.85rem;line-height:1.75;color:var(--text);margin-top:.6rem">
            Pas de base de données capricieuse. Pas de dépendance obscure. Pas d'abonnement
            mensuel qui augmente en douce. Juste vos fichiers, votre serveur, votre voix.
            Le podcast à l'ancienne — avec un CMS qui ne l'est pas.
          </p>
        </div>
      </div>

      <!-- Fonctionnalités -->
      <div class="card" style="padding:1.75rem;margin-bottom:1.25rem">
        <h2 style="font-size:1.1rem;font-weight:800;letter-spacing:-.02em;margin-bottom:1rem">Fonctionnalités</h2>
        <?php
        $features = [
          ['mic',        'Gestion d\'épisodes',    'Création, édition, programmation et publication en Markdown + YAML'],
          ['rss',        'Flux RSS',               'Génération automatique compatible Apple Podcasts, Spotify et tous les agrégateurs'],
          ['bar-chart-2','Statistiques',            'Comptage des écoutes par épisode, graphiques et tendances'],
          ['sliders',    'Personnalisation',        'Couleurs, typographie, mise en page et réseaux sociaux — tout est configurable'],
          ['file-text',  'Transcriptions',          'Support des transcriptions avec timestamps cliquables et détection des locuteurs'],
          ['shield',     'Sécurité',                'Sessions sécurisées, CSRF, rate-limiting, headers HTTP stricts'],
          ['hard-drive', 'Sans base de données',    'Fichiers plats : aucune dépendance MySQL/PostgreSQL, backup = copie de dossier'],
          ['globe',      'Multilingue',             'Interface disponible en français, anglais, espagnol et portugais'],
        ];
        foreach ($features as [$icon, $title, $desc]):
        ?>
        <div style="display:flex;gap:.85rem;align-items:flex-start;<?= $icon !== 'mic' ? 'margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);' : '' ?>">
          <div style="width:32px;height:32px;min-width:32px;border-radius:8px;background:rgba(<?php
            $hex = ltrim($config['color_accent'] ?? '#e8ff5a', '#');
            if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            echo hexdec(substr($hex,0,2)).','.hexdec(substr($hex,2,2)).','.hexdec(substr($hex,4,2));
          ?>,.1);display:flex;align-items:center;justify-content:center">
            <i data-feather="<?= $icon ?>" style="width:15px;height:15px;color:var(--accent)"></i>
          </div>
          <div>
            <div style="font-size:.88rem;font-weight:700;margin-bottom:.2rem"><?= e($title) ?></div>
            <div style="font-size:.82rem;color:var(--muted);line-height:1.6"><?= e($desc) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Crédits -->
      <div class="card" style="padding:1.75rem">
        <div style="text-align:center">
          <div style="font-size:.72rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem">Réalisé par</div>
          <a href="https://robotetdragon.com" target="_blank" rel="noopener" style="display:inline-block;transition:opacity .15s" class="rd-logo-link">
            <img src="<?= url('/admin/assets/rd_logo.svg') ?>" alt="Robot &amp; Dragon" style="height:72px;width:auto" class="rd-logo-dark">
            <img src="<?= url('/admin/assets/rd_logo_black.svg') ?>" alt="Robot &amp; Dragon" style="height:72px;width:auto;display:none" class="rd-logo-light">
          </a>
        </div>
      </div>
      <script>
      (function(){
        function updateRdLogo(){
          var light = document.documentElement.getAttribute('data-theme') === 'light';
          document.querySelectorAll('.rd-logo-dark').forEach(function(el){ el.style.display = light ? 'none' : ''; });
          document.querySelectorAll('.rd-logo-light').forEach(function(el){ el.style.display = light ? '' : 'none'; });
        }
        updateRdLogo();
        new MutationObserver(updateRdLogo).observe(document.documentElement, {attributes:true, attributeFilter:['data-theme']});
      })();
      </script>

    </div>

  </div>
</div>

</body>
</html>
