<?php
/**
 * hooks.php
 * WHMCS auto-loads any file named hooks.php inside an active addon
 * module's folder. This registers:
 *   1. A client-area nav tab pointing at the forum
 *   2. A "Recent Forum Activity" dashboard widget
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use ClientForum\Forum;

require_once __DIR__ . '/lib/Forum.php';

add_hook('ClientAreaPrimaryNavbar', 1, function ($primaryNavbar) {
    $primaryNavbar->addChild('Forum', [
        'label' => 'Forum',
        'uri' => 'index.php?m=clientforum',
        'order' => 60,
    ]);
});

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    return '<link rel="stylesheet" type="text/css" href="modules/addons/clientforum/css/forum.css?v=2.2.3">';
});

add_hook('ClientAreaHomepagePanels', 1, function ($vars) {
    if (!isset($_SESSION['uid'])) {
        return '';
    }

    $clientId = (int) $_SESSION['uid'];
    $recent = Forum::getRecentActivity($clientId, 5);

    if (empty($recent)) {
        return '';
    }

    $html = '<div class="panel panel-default clientforum-widget">';
    $html .= '<div class="panel-heading"><h3 class="panel-title">Recent Forum Activity</h3></div>';
    $html .= '<div class="panel-body"><ul class="list-unstyled">';
    foreach ($recent as $thread) {
        $title = htmlspecialchars($thread->title);
        $html .= "<li><a href=\"index.php?m=clientforum&forumaction=thread&thread_id={$thread->id}\">{$title}</a></li>";
    }
    $html .= '</ul>';
    $html .= '<a href="index.php?m=clientforum" class="btn btn-sm btn-default">View Forum</a>';
    $html .= '</div></div>';

    return $html;
});
