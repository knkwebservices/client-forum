<?php
/**
 * modules/addons/clientforum/clientforum.php
 * WHMCS Addon Module entry point.
 *
 * WHMCS auto-loads this file when the "Client Forum" addon is active and
 * calls the clientforum_* functions by naming convention. Module name
 * (folder + this file's prefix) is "clientforum" throughout.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use ClientForum\Forum;
use ClientForum\Csrf;
use ClientForum\Markdown;
use ClientForum\Webhook;

require_once __DIR__ . '/lib/Forum.php';
require_once __DIR__ . '/lib/Csrf.php';
require_once __DIR__ . '/lib/Markdown.php';
require_once __DIR__ . '/lib/Webhook.php';

/**
 * WHMCS HTML-encodes all request input before modules see it, so a typed
 * " arrives as &quot;. Post text is stored raw and escaped once at render
 * time (lib/Markdown.php), so decode it here first, otherwise it is
 * escaped twice and clients see literal &quot; in their posts.
 */
function clientforum_decode($value)
{
    return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function clientforum_input($key)
{
    return trim(clientforum_decode($_POST[$key] ?? ''));
}

/**
 * Posts saved before v2.2.1 may already contain encoded entities;
 * decode them for the edit form so saving doesn't keep them.
 */
function clientforum_decode_post_for_edit($post, $thread)
{
    $post->body = clientforum_decode($post->body);
    $thread->title = clientforum_decode($thread->title);
    return $post;
}


/**
 * Reads a single configured value for this addon module (one of the
 * keys defined in clientforum_config()['fields']) from WHMCS's own
 * tbladdonmodules table — the same place the admin "Configure" screen
 * saves to. Returns null if unset.
 */
function clientforum_get_setting($name)
{
    return Capsule::table('tbladdonmodules')
        ->where('module', 'clientforum')
        ->where('setting', $name)
        ->value('value');
}

/**
 * Writes a value into the same tbladdonmodules storage the standard
 * config fields use. Used for values the module needs to persist that
 * aren't admin-editable config fields — specifically the license
 * addon's "local key", which is opaque cached verification data, not
 * something an admin should see or edit directly.
 */
function clientforum_set_setting($name, $value)
{
    $exists = Capsule::table('tbladdonmodules')
        ->where('module', 'clientforum')
        ->where('setting', $name)
        ->exists();

    if ($exists) {
        Capsule::table('tbladdonmodules')
            ->where('module', 'clientforum')
            ->where('setting', $name)
            ->update(['value' => $value]);
    } else {
        Capsule::table('tbladdonmodules')->insert([
            'module' => 'clientforum',
            'setting' => $name,
            'value' => $value,
        ]);
    }
}

/**
 * Fires the configured Slack/Discord webhook(s) for a newly created
 * thread, if notifications are enabled and at least one webhook URL is
 * set. Best-effort: any failure is logged via WHMCS's own activity log
 * rather than surfaced to the client — a webhook outage should never
 * be visible to the person posting, and it must never block the thread
 * they just successfully created.
 */
function clientforum_notify_new_thread($threadId, $boardName, $context = 'New thread')
{
    if (clientforum_get_setting('notify_new_thread') === 'no') {
        return;
    }

    $slackUrl = clientforum_get_setting('slack_webhook_url');
    $discordUrl = clientforum_get_setting('discord_webhook_url');
    if (empty($slackUrl) && empty($discordUrl)) {
        return; // nothing configured, nothing to do
    }

    $thread = Forum::getThread($threadId);
    if (!$thread) return;

    $client = Capsule::table('tblclients')->where('id', $thread->client_id)->first();
    $authorName = $client ? trim($client->firstname . ' ' . $client->lastname) : 'A client';

    // WHMCS\Config\Setting::getValue('SystemURL') is the documented way
    // to get the install's base URL from within a module — verified
    // against WHMCS's own developer docs rather than guessed.
    $systemUrl = '';
    if (class_exists('\WHMCS\Config\Setting')) {
        $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/';
    }
    $threadUrl = $systemUrl . "index.php?m=clientforum&forumaction=thread&thread_id={$threadId}";

    $errors = Webhook::notifyNewThread($slackUrl, $discordUrl, $thread->title, $boardName, $authorName, $threadUrl, $context);

    if (!empty($errors) && function_exists('logActivity')) {
        logActivity('Client Forum: webhook notification failed — ' . implode('; ', $errors));
    }
}

// ---------------------------------------------------------------------
// Module metadata / config
// ---------------------------------------------------------------------
function clientforum_config()
{
    return [
        'name' => 'Client Forum (Free)',
        'description' => 'Adds a native, SSO-integrated support forum to the client area — no separate login, optional product-gated boards.',
        'version' => '2.2.3-free',
        'author' => 'K & K Web Services',
        'fields' => [
            'default_language' => [
                'FriendlyName' => 'Default Language',
                'Type' => 'dropdown',
                'Options' => 'english,spanish',
                'Default' => 'english',
                'Description' => 'Fallback language if the client\'s selected language has no translation file.',
            ],
            'posts_per_page' => [
                'FriendlyName' => 'Posts Per Page',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '20',
            ],
            'require_verified_email' => [
                'FriendlyName' => 'Require Verified Email to Post',
                'Type' => 'yesno',
                'Default' => 'yes',
            ],
            'slack_webhook_url' => [
                'FriendlyName' => 'Slack Webhook URL',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Slack incoming webhook URL. Leave blank to disable Slack notifications. Create one under your Slack workspace\'s App settings → Incoming Webhooks.',
            ],
            'discord_webhook_url' => [
                'FriendlyName' => 'Discord Webhook URL',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Discord channel webhook URL. Leave blank to disable Discord notifications. Create one under a Discord channel\'s Settings → Integrations → Webhooks.',
            ],
            'notify_new_thread' => [
                'FriendlyName' => 'Notify on New Thread',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Ping the configured webhook(s) whenever a client posts a new thread, including threads created via ticket promotion.',
            ],
        ],
    ];
}

// ---------------------------------------------------------------------
// Lifecycle: activate creates tables, deactivate leaves data intact,
// upgrade handles version-to-version schema changes.
// ---------------------------------------------------------------------
function clientforum_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_forum_categories')) {
            Capsule::schema()->create('mod_forum_categories', function ($table) {
                $table->increments('id');
                $table->string('name', 191);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_forum_boards')) {
            Capsule::schema()->create('mod_forum_boards', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('category_id')->index();
                $table->string('name', 191);
                $table->string('description', 500)->nullable();
                $table->boolean('restricted')->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedInteger('thread_count')->default(0);
                $table->unsignedInteger('post_count')->default(0);
                $table->timestamp('last_post_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_forum_board_product_access')) {
            Capsule::schema()->create('mod_forum_board_product_access', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('board_id')->index();
                $table->unsignedInteger('product_id')->index();
            });
        }

        if (!Capsule::schema()->hasTable('mod_forum_threads')) {
            Capsule::schema()->create('mod_forum_threads', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('board_id')->index();
                $table->unsignedInteger('client_id')->index();
                $table->string('title', 255);
                $table->boolean('is_pinned')->default(0)->index();
                $table->boolean('is_locked')->default(0);
                $table->unsignedInteger('view_count')->default(0);
                $table->unsignedInteger('post_count')->default(1);
                $table->unsignedInteger('best_answer_post_id')->nullable();
                $table->unsignedInteger('source_ticket_id')->nullable()->index();
                $table->timestamp('last_post_at')->useCurrent();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_forum_posts')) {
            Capsule::schema()->create('mod_forum_posts', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('thread_id')->index();
                $table->unsignedInteger('client_id')->index();
                $table->text('body');
                $table->boolean('is_staff_post')->default(0);
                $table->boolean('is_best_answer')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('edited_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_forum_rate_limit')) {
            Capsule::schema()->create('mod_forum_rate_limit', function ($table) {
                $table->unsignedInteger('client_id')->primary();
                $table->timestamp('window_start')->useCurrent();
                $table->unsignedInteger('post_count')->default(0);
            });
        }

        if (!Capsule::schema()->hasTable('mod_forum_karma')) {
            Capsule::schema()->create('mod_forum_karma', function ($table) {
                $table->unsignedInteger('client_id')->primary();
                $table->integer('points')->default(0);
            });
        }

        clientforum_ensure_fulltext_indexes();

        return ['status' => 'success', 'description' => 'Client Forum tables created successfully.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

/**
 * Adds the FULLTEXT indexes search relies on, if they aren't already
 * there. Uses raw SQL rather than the schema builder's fullText() method
 * — that method's availability depends on the Illuminate version bundled
 * with a given WHMCS release, whereas a raw ALTER TABLE works the same
 * everywhere back to MySQL 5.6 (InnoDB FULLTEXT support). Safe to call
 * repeatedly: a "Duplicate key name" error (MySQL 1061) means the index
 * is already there, so it's swallowed rather than surfaced.
 */
function clientforum_ensure_fulltext_indexes()
{
    $indexes = [
        ['table' => 'mod_forum_threads', 'name' => 'ft_title', 'column' => 'title'],
        ['table' => 'mod_forum_posts', 'name' => 'ft_body', 'column' => 'body'],
    ];

    foreach ($indexes as $index) {
        try {
            Capsule::statement("ALTER TABLE {$index['table']} ADD FULLTEXT INDEX {$index['name']} ({$index['column']})");
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                throw $e; // anything other than "already exists" is a real problem
            }
        }
    }
}

function clientforum_deactivate()
{
    // Deliberately does NOT drop tables — deactivating shouldn't destroy
    // client-generated content. Uninstall via WHMCS's module removal
    // flow if you truly want the data gone.
    return ['status' => 'success', 'description' => 'Client Forum deactivated. Forum data was left intact.'];
}

function clientforum_upgrade($vars)
{
    $currentVersion = $vars['version'];

    if (version_compare($currentVersion, '1.1.0', '<')) {
        // v1.1.0 replaced the per-post mod_forum_rate_log table (which grew
        // by one row per post, forever, and had a check-then-log race
        // under concurrent requests) with mod_forum_rate_limit, a single
        // row per client updated atomically. Create the new table if it's
        // not already there (fresh activations on 1.1.0+ get it via
        // clientforum_activate() directly) and drop the old one.
        if (!Capsule::schema()->hasTable('mod_forum_rate_limit')) {
            Capsule::schema()->create('mod_forum_rate_limit', function ($table) {
                $table->unsignedInteger('client_id')->primary();
                $table->timestamp('window_start')->useCurrent();
                $table->unsignedInteger('post_count')->default(0);
            });
        }
        if (Capsule::schema()->hasTable('mod_forum_rate_log')) {
            Capsule::schema()->drop('mod_forum_rate_log');
        }
    }

    if (version_compare($currentVersion, '1.5.0', '<')) {
        // v1.5.0 added best-answer marking, points/karma, and
        // ticket-to-thread promotion. Add the new columns/table without
        // touching any existing rows.
        if (!Capsule::schema()->hasColumn('mod_forum_threads', 'best_answer_post_id')) {
            Capsule::schema()->table('mod_forum_threads', function ($table) {
                $table->unsignedInteger('best_answer_post_id')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_forum_threads', 'source_ticket_id')) {
            Capsule::schema()->table('mod_forum_threads', function ($table) {
                $table->unsignedInteger('source_ticket_id')->nullable()->index();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_forum_posts', 'is_best_answer')) {
            Capsule::schema()->table('mod_forum_posts', function ($table) {
                $table->boolean('is_best_answer')->default(0);
            });
        }
        if (!Capsule::schema()->hasTable('mod_forum_karma')) {
            Capsule::schema()->create('mod_forum_karma', function ($table) {
                $table->unsignedInteger('client_id')->primary();
                $table->integer('points')->default(0);
            });
        }
    }

    if (version_compare($currentVersion, '1.8.0', '<')) {
        // v1.8.0 replaced LIKE '%term%' search with MySQL FULLTEXT
        // indexes + MATCH() ... AGAINST() — needs the indexes to exist
        // on installs that were already past v1.7.0 (which shipped
        // search before the indexes existed).
        clientforum_ensure_fulltext_indexes();
    }
}

// ---------------------------------------------------------------------
// Admin area output — category/board management + moderation
// ---------------------------------------------------------------------
function clientforum_output($vars)
{
    $action = $_REQUEST['forumaction'] ?? '';

    // The admin area runs from WHMCS's admin folder, so the path steps up one level.
    echo '<link rel="stylesheet" href="../modules/addons/clientforum/css/forum.css?v=2.2.3">';
    echo '<div class="clientforum-admin">';

    if ($action === 'addcategory' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Csrf::validate('admin_addcategory', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } elseif (mb_strlen(trim($_POST['name'] ?? '')) === 0) {
            echo '<div class="alert alert-danger">Category name is required.</div>';
        } elseif (mb_strlen($_POST['name']) > Forum::MAX_CATEGORY_NAME_LENGTH) {
            echo '<div class="alert alert-danger">Category name is too long (max ' . Forum::MAX_CATEGORY_NAME_LENGTH . ' characters).</div>';
        } else {
            Forum::createCategory($_POST['name'], $_POST['sort_order'] ?? 0);
            echo '<div class="alert alert-success">Category created.</div>';
        }
    }

    if ($action === 'addboard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Csrf::validate('admin_addboard', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } elseif (mb_strlen(trim($_POST['name'] ?? '')) === 0) {
            echo '<div class="alert alert-danger">Board name is required.</div>';
        } elseif (mb_strlen($_POST['name']) > Forum::MAX_BOARD_NAME_LENGTH) {
            echo '<div class="alert alert-danger">Board name is too long (max ' . Forum::MAX_BOARD_NAME_LENGTH . ' characters).</div>';
        } elseif (mb_strlen($_POST['description'] ?? '') > Forum::MAX_BOARD_DESCRIPTION_LENGTH) {
            echo '<div class="alert alert-danger">Description is too long (max ' . Forum::MAX_BOARD_DESCRIPTION_LENGTH . ' characters).</div>';
        } else {
            $productIds = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : [];
            Forum::createBoard(
                $_POST['category_id'],
                $_POST['name'],
                $_POST['description'] ?? '',
                !empty($_POST['restricted']),
                $productIds
            );
            echo '<div class="alert alert-success">Board created.</div>';
        }
    }

    if ($action === 'newthread' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Csrf::validate('admin_newthread', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            $newThreadBoardId = (int) ($_POST['board_id'] ?? 0);
            $clientEmail = trim($_POST['client_email'] ?? '');
            $newThreadTitle = clientforum_input('title');
            $newThreadBody = clientforum_input('body');
            $newThreadBoard = $newThreadBoardId ? Forum::getBoard($newThreadBoardId) : null;

            if (!$newThreadBoard) {
                echo '<div class="alert alert-danger">Please choose a valid board.</div>';
            } elseif ($clientEmail === '') {
                echo '<div class="alert alert-danger">Client email is required — every thread needs a client author.</div>';
            } elseif ($newThreadTitle === '' || $newThreadBody === '') {
                echo '<div class="alert alert-danger">Please fill in both a title and a message.</div>';
            } elseif (mb_strlen($newThreadTitle) > Forum::MAX_TITLE_LENGTH) {
                echo '<div class="alert alert-danger">Title is too long (max ' . Forum::MAX_TITLE_LENGTH . ' characters).</div>';
            } elseif (mb_strlen($newThreadBody) > Forum::MAX_BODY_LENGTH) {
                echo '<div class="alert alert-danger">Message is too long (max ' . Forum::MAX_BODY_LENGTH . ' characters).</div>';
            } elseif (!function_exists('localAPI')) {
                echo '<div class="alert alert-danger">WHMCS local API is not available in this context — cannot look up the client.</div>';
            } else {
                $clientResult = localAPI('GetClientsDetails', ['email' => $clientEmail, 'stats' => false]);
                if (!isset($clientResult['result']) || $clientResult['result'] !== 'success') {
                    echo '<div class="alert alert-danger">No client found with that email address.</div>';
                } else {
                    $newThreadClientId = (int) $clientResult['userid'];
                    $newThreadId = Forum::createThread($newThreadBoardId, $newThreadClientId, $newThreadTitle, $newThreadBody);
                    clientforum_notify_new_thread($newThreadId, $newThreadBoard->name, 'New thread posted by admin');
                    echo '<div class="alert alert-success">Thread created. <a href="index.php?m=clientforum&forumaction=thread&thread_id=' . (int) $newThreadId . '" target="_blank">View it &rarr;</a></div>';
                }
            }
        }
    }

    if ($action === 'deletethread' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['thread_id'])) {
        if (!Csrf::validate('admin_moderate', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            Forum::deleteThread((int) $_POST['thread_id']);
            echo '<div class="alert alert-success">Thread deleted.</div>';
        }
    }

    if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['thread_id']) && !empty($_POST['field'])) {
        if (!Csrf::validate('admin_moderate', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            $thread = Forum::getThread((int) $_POST['thread_id']);
            $field = $_POST['field'];
            Forum::setThreadFlag((int) $_POST['thread_id'], $field, !$thread->$field);
            echo '<div class="alert alert-success">Thread updated.</div>';
        }
    }

    if ($action === 'deletecategory' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['category_id'])) {
        if (!Csrf::validate('admin_category_manage', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            $result = Forum::deleteCategory((int) $_POST['category_id']);
            $alertClass = $result['success'] ? 'alert-success' : 'alert-danger';
            echo "<div class=\"alert {$alertClass}\">" . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }

    if ($action === 'movecategory' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['category_id']) && !empty($_POST['direction'])) {
        if (!Csrf::validate('admin_category_manage', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            Forum::moveCategory((int) $_POST['category_id'], $_POST['direction'] === 'up' ? 'up' : 'down');
            // No success alert here on purpose — it's a reorder, not a
            // content change, and the updated table position below is
            // feedback enough without an extra banner on every click.
        }
    }

    if ($action === 'deleteboard' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['board_id'])) {
        if (!Csrf::validate('admin_board_manage', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            $result = Forum::deleteBoard((int) $_POST['board_id']);
            $alertClass = $result['success'] ? 'alert-success' : 'alert-danger';
            echo "<div class=\"alert {$alertClass}\">" . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }

    if ($action === 'moveboard' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['board_id']) && !empty($_POST['direction'])) {
        if (!Csrf::validate('admin_board_manage', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            Forum::moveBoard((int) $_POST['board_id'], $_POST['direction'] === 'up' ? 'up' : 'down');
        }
    }

    $promotedThreadId = null;
    if ($action === 'promoteticket' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ticket_id']) && !empty($_POST['board_id'])) {
        if (!Csrf::validate('admin_promote_ticket', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            $result = Forum::promoteTicketToThread(
                (int) $_POST['board_id'],
                (int) $_POST['ticket_id'],
                $_POST['custom_title'] ?? null
            );
            $alertClass = $result['success'] ? 'alert-success' : 'alert-danger';
            echo "<div class=\"alert {$alertClass}\">" . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</div>';
            if ($result['success']) {
                $promotedThreadId = $result['thread_id'];
                $promotedBoard = Forum::getBoard((int) $_POST['board_id']);
                clientforum_notify_new_thread($promotedThreadId, $promotedBoard->name ?? '', 'Ticket promoted to thread');
                echo '<div class="alert alert-success"><a href="index.php?m=clientforum&forumaction=thread&thread_id=' . (int) $promotedThreadId . '" target="_blank">View the new thread &rarr;</a></div>';
            }
        }
    }

    if ($action === 'testwebhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Csrf::validate('admin_test_webhook', $_POST['csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">Security check failed — please try again.</div>';
        } else {
            $slackUrl = clientforum_get_setting('slack_webhook_url');
            $discordUrl = clientforum_get_setting('discord_webhook_url');
            if (empty($slackUrl) && empty($discordUrl)) {
                echo '<div class="alert alert-warning">No webhook URL is configured yet — set one under Configure, save, then try the test again.</div>';
            } else {
                $errors = Webhook::notifyNewThread(
                    $slackUrl,
                    $discordUrl,
                    'Test Thread Title',
                    'Test Board',
                    'Test User',
                    'https://example.com/forum-test-link',
                    'Test notification'
                );
                if (empty($errors)) {
                    echo '<div class="alert alert-success">Test notification sent successfully.</div>';
                } else {
                    echo '<div class="alert alert-danger">Test notification failed — ' . htmlspecialchars(implode('; ', $errors), ENT_QUOTES, 'UTF-8') . '</div>';
                }
            }
        }
    }

    $categories = Forum::getAllCategoriesRaw();
    $boards = Forum::getAllBoardsRaw();
    $products = Capsule::table('tblproducts')->select('id', 'name')->orderBy('name')->get();
    $categoryManageToken = Csrf::token('admin_category_manage');

    echo '<h3>Categories</h3>';
    echo '<table class="table datatable">';
    echo '<tr><th>Name</th><th>Sort Order</th><th>Boards</th><th>Actions</th></tr>';
    $categoryCount = count($categories);
    foreach ($categories as $i => $cat) {
        $safeName = htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8');
        $boardCount = 0;
        foreach ($boards as $b) {
            if ($b->category_id == $cat->id) $boardCount++;
        }

        echo "<tr><td>{$safeName}</td><td>" . (int) $cat->sort_order . "</td><td>{$boardCount}</td><td>";

        $tokenField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($categoryManageToken, ENT_QUOTES, 'UTF-8') . '">';

        if ($i > 0) {
            echo '<form method="post" style="display:inline;">';
            echo '<input type="hidden" name="forumaction" value="movecategory">';
            echo '<input type="hidden" name="category_id" value="' . (int) $cat->id . '">';
            echo '<input type="hidden" name="direction" value="up">';
            echo $tokenField;
            echo '<button type="submit" class="btn-link-action">Move Up</button>';
            echo '</form> | ';
        }

        if ($i < $categoryCount - 1) {
            echo '<form method="post" style="display:inline;">';
            echo '<input type="hidden" name="forumaction" value="movecategory">';
            echo '<input type="hidden" name="category_id" value="' . (int) $cat->id . '">';
            echo '<input type="hidden" name="direction" value="down">';
            echo $tokenField;
            echo '<button type="submit" class="btn-link-action">Move Down</button>';
            echo '</form> | ';
        }

        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this category?\');">';
        echo '<input type="hidden" name="forumaction" value="deletecategory">';
        echo '<input type="hidden" name="category_id" value="' . (int) $cat->id . '">';
        echo $tokenField;
        echo '<button type="submit" class="btn-link-action' . ($boardCount > 0 ? ' btn-link-disabled' : '') . '"'
            . ($boardCount > 0 ? ' title="Move or delete its boards first"' : '') . '>Delete</button>';
        echo '</form>';

        echo '</td></tr>';
    }
    echo '</table>';

    echo '<h4>Add Category</h4>';
    echo '<form method="post">';
    echo '<input type="hidden" name="forumaction" value="addcategory">';
    echo Csrf::field('admin_addcategory');
    echo '<input type="text" name="name" placeholder="Category name" maxlength="' . Forum::MAX_CATEGORY_NAME_LENGTH . '" required> ';
    echo '<input type="number" name="sort_order" placeholder="Sort order" style="width:80px;"> ';
    echo '<button class="btn btn-primary" type="submit">Add Category</button>';
    echo '</form>';

    echo '<hr><h3>Boards</h3>';
    echo '<table class="table datatable">';
    echo '<tr><th>Category</th><th>Name</th><th>Restricted</th><th>Threads</th><th>Posts</th><th>Actions</th></tr>';

    $boardManageToken = Csrf::token('admin_board_manage');
    // Group boards by category so Move Up/Down respects category
    // boundaries (matches Forum::moveBoard(), which only reorders within
    // the same category).
    $boardsByCategory = [];
    foreach ($boards as $board) {
        $boardsByCategory[$board->category_id][] = $board;
    }

    foreach ($boardsByCategory as $categoryId => $categoryBoards) {
        $siblingCount = count($categoryBoards);
        foreach ($categoryBoards as $i => $board) {
            $catName = '';
            foreach ($categories as $c) {
                if ($c->id == $board->category_id) $catName = $c->name;
            }
            $safeCatName = htmlspecialchars($catName, ENT_QUOTES, 'UTF-8');
            $safeBoardName = htmlspecialchars($board->name, ENT_QUOTES, 'UTF-8');
            $restrictedLabel = $board->restricted ? 'Yes' : 'No';

            echo "<tr><td>{$safeCatName}</td><td>{$safeBoardName}</td><td>{$restrictedLabel}</td><td>" . (int) $board->thread_count . "</td><td>" . (int) $board->post_count . '</td><td>';

            $tokenField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($boardManageToken, ENT_QUOTES, 'UTF-8') . '">';

            if ($i > 0) {
                echo '<form method="post" style="display:inline;">';
                echo '<input type="hidden" name="forumaction" value="moveboard">';
                echo '<input type="hidden" name="board_id" value="' . (int) $board->id . '">';
                echo '<input type="hidden" name="direction" value="up">';
                echo $tokenField;
                echo '<button type="submit" class="btn-link-action">Move Up</button>';
                echo '</form> | ';
            }

            if ($i < $siblingCount - 1) {
                echo '<form method="post" style="display:inline;">';
                echo '<input type="hidden" name="forumaction" value="moveboard">';
                echo '<input type="hidden" name="board_id" value="' . (int) $board->id . '">';
                echo '<input type="hidden" name="direction" value="down">';
                echo $tokenField;
                echo '<button type="submit" class="btn-link-action">Move Down</button>';
                echo '</form> | ';
            }

            $hasThreads = $board->thread_count > 0;
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this board?\');">';
            echo '<input type="hidden" name="forumaction" value="deleteboard">';
            echo '<input type="hidden" name="board_id" value="' . (int) $board->id . '">';
            echo $tokenField;
            echo '<button type="submit" class="btn-link-action' . ($hasThreads ? ' btn-link-disabled' : '') . '"'
                . ($hasThreads ? ' title="Move or delete its threads first"' : '') . '>Delete</button>';
            echo '</form>';

            echo '</td></tr>';
        }
    }
    echo '</table>';

    echo '<h4>Add Board</h4>';
    echo '<form method="post">';
    echo '<input type="hidden" name="forumaction" value="addboard">';
    echo Csrf::field('admin_addboard');
    echo '<select name="category_id" required>';
    foreach ($categories as $cat) {
        $safeName = htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8');
        echo "<option value=\"{$cat->id}\">{$safeName}</option>";
    }
    echo '</select> ';
    echo '<input type="text" name="name" placeholder="Board name" maxlength="' . Forum::MAX_BOARD_NAME_LENGTH . '" required> ';
    echo '<input type="text" name="description" placeholder="Description" maxlength="' . Forum::MAX_BOARD_DESCRIPTION_LENGTH . '" style="width:220px;"> ';
    echo '<label><input type="checkbox" name="restricted" value="1"> Restrict to specific products</label><br><br>';
    echo '<div style="max-height:150px;overflow-y:auto;border:1px solid #ddd;padding:8px;max-width:400px;">';
    if (count($products) === 0) {
        echo '<span style="color:#888;">No products set up yet. Add products in WHMCS to restrict a board to them.</span>';
    }
    foreach ($products as $product) {
        $safeProductName = htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8');
        echo "<label style=\"display:block;\"><input type=\"checkbox\" name=\"product_ids[]\" value=\"{$product->id}\"> {$safeProductName}</label>";
    }
    echo '</div><br>';
    echo '<button class="btn btn-primary" type="submit">Add Board</button>';
    echo '</form>';

    echo '<hr><h3>Post a New Thread</h3>';
    echo '<p style="color:#888;font-size:13px;">Useful for announcements or seeding a new board. Every thread needs a client author, so enter the email of the client to post it as — it\'ll carry a staff badge since you\'re posting from the admin panel.</p>';

    if (empty($boards)) {
        echo '<div class="alert alert-warning">Create at least one board above before posting a thread.</div>';
    } else {
        echo '<form method="post">';
        echo '<input type="hidden" name="forumaction" value="newthread">';
        echo Csrf::field('admin_newthread');
        echo '<select name="board_id" required>';
        foreach ($boards as $b) {
            $safeBoardOptName = htmlspecialchars($b->name, ENT_QUOTES, 'UTF-8');
            echo "<option value=\"{$b->id}\">{$safeBoardOptName}</option>";
        }
        echo '</select> ';
        echo '<input type="email" name="client_email" placeholder="Client email (thread author)" required style="width:220px;"> ';
        echo '<input type="text" name="title" placeholder="Title" maxlength="' . Forum::MAX_TITLE_LENGTH . '" required style="width:220px;"><br><br>';
        echo '<textarea name="body" placeholder="Message" maxlength="' . Forum::MAX_BODY_LENGTH . '" rows="4" style="width:100%;max-width:500px;" required></textarea><br><br>';
        echo '<button class="btn btn-primary" type="submit">Create Thread</button>';
        echo '</form>';
    }

    echo '<hr><h3>Recent Threads (moderation)</h3>';
    $recentThreads = Capsule::table('mod_forum_threads')->orderByDesc('created_at')->limit(25)->get();
    $moderateToken = Csrf::token('admin_moderate');
    echo '<table class="table datatable">';
    echo '<tr><th>Title</th><th>Posts</th><th>Pinned</th><th>Locked</th><th>Actions</th></tr>';
    foreach ($recentThreads as $thread) {
        $safeTitle = htmlspecialchars($thread->title, ENT_QUOTES, 'UTF-8');
        echo '<tr>';
        echo "<td>{$safeTitle}</td><td>" . (int) $thread->post_count . "</td>";
        echo '<td>' . ($thread->is_pinned ? 'Yes' : 'No') . '</td>';
        echo '<td>' . ($thread->is_locked ? 'Yes' : 'No') . '</td>';
        echo '<td>';

        echo '<form method="post" style="display:inline;">';
        echo '<input type="hidden" name="forumaction" value="toggle">';
        echo '<input type="hidden" name="field" value="is_pinned">';
        echo '<input type="hidden" name="thread_id" value="' . (int) $thread->id . '">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($moderateToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<button type="submit" class="btn-link-action">Toggle Pin</button>';
        echo '</form> | ';

        echo '<form method="post" style="display:inline;">';
        echo '<input type="hidden" name="forumaction" value="toggle">';
        echo '<input type="hidden" name="field" value="is_locked">';
        echo '<input type="hidden" name="thread_id" value="' . (int) $thread->id . '">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($moderateToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<button type="submit" class="btn-link-action">Toggle Lock</button>';
        echo '</form> | ';

        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this thread and all replies?\');">';
        echo '<input type="hidden" name="forumaction" value="deletethread">';
        echo '<input type="hidden" name="thread_id" value="' . (int) $thread->id . '">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($moderateToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<button type="submit" class="btn-link-action">Delete</button>';
        echo '</form>';

        echo '</td></tr>';
    }
    echo '</table>';

    echo '<hr><h3>Promote a Support Ticket to the Forum</h3>';
    echo '<p style="color:#888;font-size:13px;">Search tickets, then promote one into a public thread. This copies the ticket\'s original message into a new thread and leaves a note on the ticket linking back to it — it does not close or modify the ticket itself.</p>';

    if (empty($boards)) {
        echo '<div class="alert alert-warning">Create at least one board above before promoting tickets — there\'s nowhere to put the new thread yet.</div>';
    }

    $ticketSearchSubject = trim($_GET['ticket_search'] ?? '');
    $ticketStatusFilter = $_GET['ticket_status'] ?? 'All Active Tickets';

    if (function_exists('localAPI')) {
        // Pull the install's actual configured statuses rather than
        // guessing common ones — custom statuses vary per install, and a
        // hardcoded list (or worse, a single hardcoded default like the
        // original v1.5.0 build shipped with) can silently hide every
        // ticket if none of them happen to be in that one status.
        $statusOptions = ['All Active Tickets', 'My Flagged Tickets'];
        $statusResult = localAPI('GetSupportStatuses');
        if (isset($statusResult['result']) && $statusResult['result'] === 'success' && !empty($statusResult['statuses']['status'])) {
            foreach ($statusResult['statuses']['status'] as $status) {
                $statusOptions[] = $status['title'];
            }
        }

        echo '<form method="get" style="margin-bottom:16px;display:flex;gap:8px;align-items:center;">';
        echo '<input type="hidden" name="module" value="clientforum">';
        echo '<select name="ticket_status">';
        foreach ($statusOptions as $statusOption) {
            $selected = ($statusOption === $ticketStatusFilter) ? ' selected' : '';
            $safeStatus = htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8');
            echo "<option value=\"{$safeStatus}\"{$selected}>{$safeStatus}</option>";
        }
        echo '</select>';
        echo '<input type="text" name="ticket_search" placeholder="...or search by subject" value="' . htmlspecialchars($ticketSearchSubject, ENT_QUOTES, 'UTF-8') . '" style="width:220px;"> ';
        echo '<button type="submit" class="btn btn-default">Search Tickets</button>';
        echo '</form>';

        $ticketParams = ['limitnum' => 25];
        if ($ticketSearchSubject !== '') {
            // A subject search intentionally looks across all statuses —
            // ignoring the status dropdown here matches how most people
            // expect "search" to behave (find it regardless of state).
            $ticketParams['subject'] = $ticketSearchSubject;
        } else {
            $ticketParams['status'] = $ticketStatusFilter;
        }
        $ticketResult = localAPI('GetTickets', $ticketParams);

        if (isset($ticketResult['result']) && $ticketResult['result'] === 'success' && !empty($ticketResult['tickets']['ticket'])) {
            $promoteToken = Csrf::token('admin_promote_ticket');
            echo '<table class="table datatable">';
            echo '<tr><th>Subject</th><th>Client</th><th>Status</th><th>Date</th><th>Promote to board</th></tr>';
            foreach ($ticketResult['tickets']['ticket'] as $ticket) {
                $safeSubject = htmlspecialchars($ticket['subject'], ENT_QUOTES, 'UTF-8');
                $safeName = htmlspecialchars($ticket['name'] ?? '', ENT_QUOTES, 'UTF-8');
                $safeStatus = htmlspecialchars($ticket['status'], ENT_QUOTES, 'UTF-8');
                echo "<tr><td>{$safeSubject}</td><td>{$safeName}</td><td>{$safeStatus}</td><td>{$ticket['date']}</td><td>";
                if (empty($boards)) {
                    echo '<span style="color:#999;">No boards yet</span>';
                } else {
                    echo '<form method="post" style="display:flex;gap:6px;">';
                    echo '<input type="hidden" name="forumaction" value="promoteticket">';
                    echo '<input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '">';
                    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($promoteToken, ENT_QUOTES, 'UTF-8') . '">';
                    echo '<select name="board_id" required style="max-width:160px;">';
                    foreach ($boards as $b) {
                        echo '<option value="' . (int) $b->id . '">' . htmlspecialchars($b->name, ENT_QUOTES, 'UTF-8') . '</option>';
                    }
                    echo '</select>';
                    echo '<button type="submit" class="btn btn-primary" style="padding:4px 10px;font-size:12px;">Promote</button>';
                    echo '</form>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        } else {
            $filterDescription = $ticketSearchSubject !== ''
                ? 'matching that subject'
                : 'with status "' . htmlspecialchars($ticketStatusFilter, ENT_QUOTES, 'UTF-8') . '"';
            echo "<div class=\"alert alert-info\">No tickets found {$filterDescription}.</div>";
        }
    } else {
        echo '<div class="alert alert-warning">WHMCS local API is not available in this context — ticket promotion is disabled.</div>';
    }

    echo '<hr><h3>Webhook Notifications</h3>';
    $slackConfigured = !empty(clientforum_get_setting('slack_webhook_url'));
    $discordConfigured = !empty(clientforum_get_setting('discord_webhook_url'));
    $notifyEnabled = clientforum_get_setting('notify_new_thread') !== 'no';

    echo '<p style="font-size:13px;">';
    echo 'Slack: ' . ($slackConfigured ? '<span style="color:#3c9a5f;">configured</span>' : '<span style="color:#999;">not configured</span>') . ' &nbsp;|&nbsp; ';
    echo 'Discord: ' . ($discordConfigured ? '<span style="color:#3c9a5f;">configured</span>' : '<span style="color:#999;">not configured</span>') . ' &nbsp;|&nbsp; ';
    echo 'New-thread notifications: ' . ($notifyEnabled ? '<span style="color:#3c9a5f;">on</span>' : '<span style="color:#999;">off</span>');
    echo '</p>';
    echo '<p style="color:#888;font-size:13px;">Set the webhook URLs under this module\'s <strong>Configure</strong> screen (Configuration → System Settings → Addon Modules). Once set, new threads — including ones created via ticket promotion — will ping the configured channel(s).</p>';

    if (!$slackConfigured && !$discordConfigured) {
        echo '<div class="alert alert-info">No webhook configured yet.</div>';
    } else {
        echo '<form method="post">';
        echo '<input type="hidden" name="forumaction" value="testwebhook">';
        echo Csrf::field('admin_test_webhook');
        echo '<button type="submit" class="btn btn-default">Send Test Notification</button>';
        echo '</form>';
    }

    echo '</div>'; // .clientforum-admin
}

// ---------------------------------------------------------------------
// Client area dispatcher — this is what renders the forum for logged-in
// clients. WHMCS calls this for requests to index.php?m=clientforum
// ---------------------------------------------------------------------
function clientforum_clientarea($vars)
{
    if (empty($_SESSION['uid'])) {
        // requirelogin makes WHMCS send the visitor to its own login page
        // and back here afterwards. (Before 2.2.3 this redirected to the
        // forum itself, which looped forever for logged-out visitors.)
        return [
            'pagetitle' => 'Forum',
            'breadcrumb' => ['index.php?m=clientforum' => 'Forum'],
            'templatefile' => 'error',
            'requirelogin' => true,
            'vars' => ['message' => 'Please log in to access the forum.'],
        ];
    }

    $clientId = (int) $_SESSION['uid'];
    $action = $_REQUEST['forumaction'] ?? 'list';

    switch ($action) {
        case 'board':
            return clientforum_view_board($clientId, (int) $_REQUEST['board_id'], (int) ($_REQUEST['page'] ?? 1));

        case 'thread':
            return clientforum_view_thread($clientId, (int) $_REQUEST['thread_id']);

        case 'newthread':
            return clientforum_new_thread($clientId, (int) $_REQUEST['board_id']);

        case 'reply':
            return clientforum_reply($clientId, (int) $_REQUEST['thread_id']);

        case 'editpost':
            return clientforum_edit_post($clientId, (int) $_REQUEST['post_id']);

        case 'markbestanswer':
            return clientforum_mark_best_answer($clientId, (int) $_REQUEST['thread_id']);

        case 'unmarkbestanswer':
            return clientforum_unmark_best_answer($clientId, (int) $_REQUEST['thread_id']);

        case 'leaderboard':
            return clientforum_view_leaderboard($clientId);

        case 'search':
            return clientforum_search($clientId);

        case 'list':
        default:
            return clientforum_view_list($clientId);
    }
}

function clientforum_view_list($clientId)
{
    $groups = Forum::getCategoriesWithBoards($clientId);
    return [
        'pagetitle' => 'Forum',
        'breadcrumb' => ['index.php?m=clientforum' => 'Forum'],
        'templatefile' => 'list',
        'requirelogin' => true,
        'vars' => ['groups' => $groups],
    ];
}

function clientforum_view_leaderboard($clientId)
{
    $leaderboard = Forum::getLeaderboard(20);
    $myRank = Forum::getClientRank($clientId);

    return [
        'pagetitle' => 'Leaderboard',
        'breadcrumb' => ['index.php?m=clientforum' => 'Forum', '#' => 'Leaderboard'],
        'templatefile' => 'leaderboard',
        'requirelogin' => true,
        'vars' => ['leaderboard' => $leaderboard, 'myRank' => $myRank, 'myClientId' => $clientId],
    ];
}

function clientforum_search($clientId)
{
    $query = trim($_GET['q'] ?? '');
    $results = $query !== '' ? Forum::searchThreads($clientId, $query, 30) : [];

    // Attach each result's board name for display — one pass over the
    // client's visible boards rather than a query per result row.
    $boardNames = [];
    foreach (Forum::getCategoriesWithBoards($clientId) as $group) {
        foreach ($group['boards'] as $board) {
            $boardNames[$board->id] = $board->name;
        }
    }
    foreach ($results as $thread) {
        $thread->board_name = $boardNames[$thread->board_id] ?? '';
    }

    return [
        'pagetitle' => 'Search',
        'breadcrumb' => ['index.php?m=clientforum' => 'Forum', '#' => 'Search'],
        'templatefile' => 'search',
        'requirelogin' => true,
        'vars' => ['query' => $query, 'results' => $results],
    ];
}

function clientforum_view_board($clientId, $boardId, $page)
{
    $board = Forum::getBoard($boardId);
    if (!$board || !Forum::clientCanAccessBoard($clientId, $boardId, $board->restricted)) {
        return ['pagetitle' => 'Forum', 'breadcrumb' => [], 'templatefile' => 'error', 'vars' => ['message' => 'You do not have access to this board.']];
    }

    $threads = Forum::getThreads($boardId, $page);
    return [
        'pagetitle' => $board->name,
        'breadcrumb' => ['index.php?m=clientforum' => 'Forum', '#' => $board->name],
        'templatefile' => 'board',
        'requirelogin' => true,
        'vars' => ['board' => $board, 'threads' => $threads, 'page' => $page],
    ];
}

function clientforum_view_thread($clientId, $threadId)
{
    $thread = Forum::getThread($threadId);
    if (!$thread) {
        return ['pagetitle' => 'Forum', 'breadcrumb' => [], 'templatefile' => 'error', 'vars' => ['message' => 'Thread not found.']];
    }
    $board = Forum::getBoard($thread->board_id);
    if (!Forum::clientCanAccessBoard($clientId, $board->id, $board->restricted)) {
        return ['pagetitle' => 'Forum', 'breadcrumb' => [], 'templatefile' => 'error', 'vars' => ['message' => 'You do not have access to this board.']];
    }

    Forum::incrementThreadViews($threadId);
    $posts = Forum::getPosts($threadId);

    $authorIds = [];
    foreach ($posts as $post) {
        $authorIds[] = $post->client_id;
    }
    $karmaMap = Forum::getKarmaBulk($authorIds);
    $firstPostId = Forum::getFirstPostId($threadId);

    foreach ($posts as $post) {
        // Rendered here (not in the template) so there's exactly one
        // place in the codebase that turns post Markdown into HTML —
        // easier to audit for XSS than spreading it across templates.
        $post->body_html = Markdown::toHtml(clientforum_decode($post->body));
        $post->author_karma = $karmaMap[$post->client_id] ?? 0;
        $post->can_edit = Forum::canEditPost($post, $clientId);
        $post->is_opening_post = ((int) $post->id === (int) $firstPostId);
    }

    // Only the thread starter or staff can mark/unmark a best answer —
    // computed once here so the template doesn't need its own copy of
    // this permission logic.
    $canMarkBestAnswer = ((int) $thread->client_id === (int) $clientId) || Forum::isStaffSession();

    return [
        'pagetitle' => $thread->title,
        'breadcrumb' => [
            'index.php?m=clientforum' => 'Forum',
            "index.php?m=clientforum&forumaction=board&board_id={$board->id}" => $board->name,
            '#' => $thread->title,
        ],
        'templatefile' => 'thread',
        'requirelogin' => true,
        'vars' => [
            'thread' => $thread,
            'board' => $board,
            'posts' => $posts,
            'csrfToken' => Csrf::token('client_reply_' . $threadId),
            'bestAnswerToken' => Csrf::token('client_bestanswer_' . $threadId),
            'maxBodyLength' => Forum::MAX_BODY_LENGTH,
            'canMarkBestAnswer' => $canMarkBestAnswer,
        ],
    ];
}

function clientforum_new_thread($clientId, $boardId)
{
    $board = Forum::getBoard($boardId);
    if (!$board || !Forum::clientCanAccessBoard($clientId, $boardId, $board->restricted)) {
        return ['pagetitle' => 'Forum', 'breadcrumb' => [], 'templatefile' => 'error', 'vars' => ['message' => 'You do not have access to this board.']];
    }

    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = clientforum_input('title');
        $body = clientforum_input('body');

        if (!Csrf::validate('client_newthread_' . $boardId, $_POST['csrf_token'] ?? '')) {
            $error = 'Security check failed — please try again.';
        } elseif ($title === '' || $body === '') {
            $error = 'Please fill in both a title and a message.';
        } elseif (mb_strlen($title) > Forum::MAX_TITLE_LENGTH) {
            $error = 'Title is too long (max ' . Forum::MAX_TITLE_LENGTH . ' characters).';
        } elseif (mb_strlen($body) > Forum::MAX_BODY_LENGTH) {
            $error = 'Message is too long (max ' . Forum::MAX_BODY_LENGTH . ' characters).';
        } elseif (!Forum::checkAndConsumeRateLimit($clientId)) {
            $error = 'You are posting too quickly. Please wait a moment and try again.';
        } else {
            $threadId = Forum::createThread($boardId, $clientId, $title, $body);
            clientforum_notify_new_thread($threadId, $board->name);
            header("Location: index.php?m=clientforum&forumaction=thread&thread_id={$threadId}");
            exit;
        }
    }

    return [
        'pagetitle' => 'New Thread — ' . $board->name,
        'breadcrumb' => ['index.php?m=clientforum' => 'Forum'],
        'templatefile' => 'newthread',
        'requirelogin' => true,
        'vars' => [
            'board' => $board,
            'error' => $error,
            'csrfToken' => Csrf::token('client_newthread_' . $boardId),
            'maxTitleLength' => Forum::MAX_TITLE_LENGTH,
            'maxBodyLength' => Forum::MAX_BODY_LENGTH,
        ],
    ];
}

function clientforum_reply($clientId, $threadId)
{
    $thread = Forum::getThread($threadId);
    if (!$thread) {
        return ['pagetitle' => 'Forum', 'breadcrumb' => [], 'templatefile' => 'error', 'vars' => ['message' => 'Thread not found.']];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = clientforum_input('body');
        $tokenOk = Csrf::validate('client_reply_' . $threadId, $_POST['csrf_token'] ?? '');

        if ($tokenOk
            && $body !== ''
            && mb_strlen($body) <= Forum::MAX_BODY_LENGTH
            && Forum::checkAndConsumeRateLimit($clientId)
        ) {
            Forum::createPost($threadId, $clientId, $body);
        }
        // Reply is a redirect-back-to-thread flow either way (no dedicated
        // error page for a reply) — a rejected reply just doesn't get
        // posted. If you want inline error messaging here later, switch
        // this to render the thread template directly with an $error var
        // instead of redirecting.
    }

    header("Location: index.php?m=clientforum&forumaction=thread&thread_id={$threadId}");
    exit;
}

function clientforum_edit_post($clientId, $postId)
{
    $post = Forum::getPost($postId);
    if (!$post) {
        return ['pagetitle' => 'Forum', 'breadcrumb' => [], 'templatefile' => 'error', 'vars' => ['message' => 'Post not found.']];
    }

    if (!Forum::canEditPost($post, $clientId)) {
        return ['pagetitle' => 'Forum', 'breadcrumb' => [], 'templatefile' => 'error', 'vars' => ['message' => 'You can only edit your own posts.']];
    }

    $thread = Forum::getThread($post->thread_id);
    if (!$thread) {
        return ['pagetitle' => 'Forum', 'breadcrumb' => [], 'templatefile' => 'error', 'vars' => ['message' => 'Thread not found.']];
    }

    $isFirstPost = (Forum::getFirstPostId($thread->id) == $postId);
    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = clientforum_input('body');
        $title = $isFirstPost ? clientforum_input('title') : null;

        if (!Csrf::validate('client_editpost_' . $postId, $_POST['csrf_token'] ?? '')) {
            $error = 'Security check failed — please try again.';
        } elseif ($body === '') {
            $error = 'Message cannot be empty.';
        } elseif (mb_strlen($body) > Forum::MAX_BODY_LENGTH) {
            $error = 'Message is too long (max ' . Forum::MAX_BODY_LENGTH . ' characters).';
        } elseif ($isFirstPost && $title === '') {
            $error = 'Title cannot be empty.';
        } elseif ($isFirstPost && mb_strlen($title) > Forum::MAX_TITLE_LENGTH) {
            $error = 'Title is too long (max ' . Forum::MAX_TITLE_LENGTH . ' characters).';
        } else {
            $result = Forum::updatePost($postId, $clientId, $body, $title);
            if ($result['success']) {
                header("Location: index.php?m=clientforum&forumaction=thread&thread_id={$thread->id}");
                exit;
            }
            $error = $result['message'];
        }
    }

    return [
        'pagetitle' => 'Edit Post',
        'breadcrumb' => [
            'index.php?m=clientforum' => 'Forum',
            "index.php?m=clientforum&forumaction=thread&thread_id={$thread->id}" => $thread->title,
            '#' => 'Edit',
        ],
        'templatefile' => 'editpost',
        'requirelogin' => true,
        'vars' => [
            'post' => clientforum_decode_post_for_edit($post, $thread),
            'thread' => $thread,
            'isFirstPost' => $isFirstPost,
            'error' => $error,
            'csrfToken' => Csrf::token('client_editpost_' . $postId),
            'maxTitleLength' => Forum::MAX_TITLE_LENGTH,
            'maxBodyLength' => Forum::MAX_BODY_LENGTH,
        ],
    ];
}

function clientforum_mark_best_answer($clientId, $threadId)
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && Csrf::validate('client_bestanswer_' . $threadId, $_POST['csrf_token'] ?? '')
        && !empty($_POST['post_id'])
    ) {
        // Result intentionally not surfaced to the user beyond the
        // redirect — Forum::markBestAnswer() already enforces the
        // permission check server-side, so a rejected attempt (e.g. a
        // non-owner poking the endpoint directly) just silently doesn't
        // apply, same posture as the reply rate-limit rejection above.
        Forum::markBestAnswer($threadId, (int) $_POST['post_id'], $clientId);
    }

    header("Location: index.php?m=clientforum&forumaction=thread&thread_id={$threadId}");
    exit;
}

function clientforum_unmark_best_answer($clientId, $threadId)
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && Csrf::validate('client_bestanswer_' . $threadId, $_POST['csrf_token'] ?? '')
    ) {
        Forum::unmarkBestAnswer($threadId, $clientId);
    }

    header("Location: index.php?m=clientforum&forumaction=thread&thread_id={$threadId}");
    exit;
}
