<?php
ob_start();
// =============================================================================
//  admin/episode_delete.php — Suppression d'un épisode
//
//  Reçoit une requête POST depuis le formulaire de suppression présent dans
//  episodes.php et episode_edit.php.
//
//  Actions :
//    1. Authentification + vérification CSRF
//    2. Suppression du fichier .md via EpisodeParser::delete()
//    3. Suppression de la transcription associée via TranscriptManager::delete()
//    4. Régénération du sitemap
//    5. Redirection vers la liste des épisodes avec un message flash
//
//  Note : le fichier audio n'est PAS supprimé automatiquement pour éviter
//  toute perte accidentelle. Il doit être retiré manuellement du dossier audio/.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->requireLogin();
csrf_check();

$slug = trim($_POST['slug'] ?? '');

if ($slug) {
    $parser = new EpisodeParser($config['content_dir']);
    $tm     = new TranscriptManager($config['content_dir']);

    if ($parser->delete($slug)) {
        // Supprimer la transcription si elle existe (sans bloquer en cas d'échec)
        $tm->delete($slug);

        // Mettre à jour le sitemap pour retirer la page supprimée
        $sitemap = new SitemapGenerator($config['base_url'], ROOT_DIR);
        $sitemap->generate($parser->getAll());

        $_SESSION['flash'] = ['type' => 'success', 'message' => __('ep_deleted')];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => __('ep_delete_error')];
    }
}

redirect(url('/admin/episodes.php'));
