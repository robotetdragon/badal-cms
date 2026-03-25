<?php
ob_start();
// =============================================================================
//  admin/episode_delete.php — Episode deletion
//
//  Receives a POST request from the deletion form present in
//  episodes.php and episode_edit.php.
//
//  Actions:
//    1. Authentication + CSRF verification
//    2. Deletion of the .md file via EpisodeParser::delete()
//    3. Deletion of the associated transcript and chapters
//    4. Sitemap regeneration
//    5. Redirect to the episode list with a flash message
//
//  Note: the audio file is NOT automatically deleted to prevent
//  accidental loss. It must be manually removed from the audio/ folder.
// =============================================================================

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($config);
$auth->requireLogin();
csrf_check();

$slug = trim($_POST['slug'] ?? '');

if ($slug) {
    $parser = new EpisodeParser($config['content_dir']);
    $tm     = new TranscriptManager($config['content_dir']);
    $cm     = new ChaptersManager($config['content_dir']);

    if ($parser->delete($slug)) {
        // Delete transcript and chapters if they exist
        $tm->delete($slug);
        $cm->delete($slug);

        // Update the sitemap to remove the deleted page
        $sitemap = new SitemapGenerator($config['base_url'], ROOT_DIR);
        $sitemap->generate($parser->getAll());

        $_SESSION['flash'] = ['type' => 'success', 'message' => __('ep_deleted')];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => __('ep_delete_error')];
    }
}

redirect(url('/admin/episodes.php'));
