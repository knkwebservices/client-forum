<?php
/**
 * lib/Forum.php
 * Data-access layer for the clientforum module. Everything that touches
 * the mod_forum_* tables goes through here, so the client-area dispatcher
 * and the admin area both call the same, single-source-of-truth logic
 * (e.g. "is this board visible to this client" is defined once).
 */

namespace ClientForum;

use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Forum
{
    const RATE_LIMIT_MAX_POSTS = 5;      // max posts...
    const RATE_LIMIT_WINDOW_SECONDS = 60; // ...per this many seconds

    // Mirror the DB column sizes so we reject with a clean error message
    // instead of letting MySQL silently truncate or throw a data-too-long
    // error. Keep these in sync with sql/schema.sql if columns change.
    const MAX_TITLE_LENGTH = 255;
    const MAX_BODY_LENGTH = 20000;
    const MAX_CATEGORY_NAME_LENGTH = 191;
    const MAX_BOARD_NAME_LENGTH = 191;
    const MAX_BOARD_DESCRIPTION_LENGTH = 500;

    // Karma awarded for each activity. Kept as constants (not admin
    // config) for v1 — simple and predictable; easy to promote to a
    // config field later if you want per-install tuning.
    const KARMA_THREAD_CREATE = 1;
    const KARMA_REPLY_CREATE = 1;
    const KARMA_BEST_ANSWER = 10;

    /** Categories with their boards nested, filtered to what this client can see. */
    public static function getCategoriesWithBoards($clientId)
    {
        $categories = Capsule::table('mod_forum_categories')
            ->orderBy('sort_order')
            ->get();

        $result = [];
        foreach ($categories as $cat) {
            $boards = Capsule::table('mod_forum_boards')
                ->where('category_id', $cat->id)
                ->orderBy('sort_order')
                ->get();

            $visibleBoards = [];
            foreach ($boards as $board) {
                if (self::clientCanAccessBoard($clientId, $board->id, $board->restricted)) {
                    $visibleBoards[] = $board;
                }
            }

            if (count($visibleBoards) > 0) {
                $result[] = [
                    'category' => $cat,
                    'boards' => $visibleBoards,
                ];
            }
        }
        return $result;
    }

    /** Whether a given client has access to a (possibly restricted) board. */
    public static function clientCanAccessBoard($clientId, $boardId, $restricted = null)
    {
        if ($restricted === null) {
            $board = Capsule::table('mod_forum_boards')->where('id', $boardId)->first();
            if (!$board) return false;
            $restricted = $board->restricted;
        }

        if (!$restricted) {
            return true; // open board, any logged-in client can see it
        }

        $allowedProductIds = Capsule::table('mod_forum_board_product_access')
            ->where('board_id', $boardId)
            ->pluck('product_id')
            ->toArray();

        if (empty($allowedProductIds)) {
            return false; // restricted but nothing configured = nobody gets in
        }

        $clientHasProduct = Capsule::table('tblhosting')
            ->where('userid', $clientId)
            ->whereIn('packageid', $allowedProductIds)
            ->whereIn('domainstatus', ['Active', 'Suspended']) // still counts as "their product"
            ->exists();

        return $clientHasProduct;
    }

    public static function getBoard($boardId)
    {
        return Capsule::table('mod_forum_boards')->where('id', $boardId)->first();
    }

    public static function getThreads($boardId, $page = 1, $perPage = 20)
    {
        return Capsule::table('mod_forum_threads')
            ->where('board_id', $boardId)
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_post_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();
    }

    public static function getThread($threadId)
    {
        return Capsule::table('mod_forum_threads')->where('id', $threadId)->first();
    }

    public static function getPosts($threadId)
    {
        return Capsule::table('mod_forum_posts')
            ->join('tblclients', 'tblclients.id', '=', 'mod_forum_posts.client_id')
            ->where('thread_id', $threadId)
            ->orderBy('mod_forum_posts.created_at')
            ->select(
                'mod_forum_posts.*',
                'tblclients.firstname',
                'tblclients.lastname'
            )
            ->get();
    }

    public static function getPost($postId)
    {
        return Capsule::table('mod_forum_posts')->where('id', $postId)->first();
    }

    /** The thread's opening post — used to decide whether editing this post should also let the client update the thread title. */
    public static function getFirstPostId($threadId)
    {
        return Capsule::table('mod_forum_posts')->where('thread_id', $threadId)->min('id');
    }

    /** Same ownership rule as best-answer marking: the post's own author, or staff. */
    public static function canEditPost($post, $clientId)
    {
        if (!$post) return false;
        return ((int) $post->client_id === (int) $clientId) || self::isStaffSession();
    }

    /**
     * Edits a post's body (and, if it's the thread's opening post, the
     * thread title too — the title lives on mod_forum_threads, not the
     * post, so it needs its own update). No time-window restriction —
     * an owner can edit their own post at any point; the visible
     * "edited" timestamp is the record of that, not a permission gate.
     * Doesn't touch karma or the rate limiter — editing isn't new
     * content creation in the sense either of those exist to govern.
     */
    public static function updatePost($postId, $clientId, $body, $title = null)
    {
        $post = self::getPost($postId);
        if (!self::canEditPost($post, $clientId)) {
            return ['success' => false, 'message' => 'You can only edit your own posts.'];
        }

        $body = mb_substr(trim($body), 0, self::MAX_BODY_LENGTH);
        if ($body === '') {
            return ['success' => false, 'message' => 'Message cannot be empty.'];
        }

        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_forum_posts')->where('id', $postId)->update([
            'body' => $body,
            'edited_at' => $now,
        ]);

        if ($title !== null && self::getFirstPostId($post->thread_id) == $postId) {
            $title = mb_substr(trim($title), 0, self::MAX_TITLE_LENGTH);
            if ($title !== '') {
                Capsule::table('mod_forum_threads')->where('id', $post->thread_id)->update(['title' => $title]);
            }
        }

        return ['success' => true, 'message' => 'Post updated.'];
    }

    public static function incrementThreadViews($threadId)
    {
        Capsule::table('mod_forum_threads')->where('id', $threadId)->increment('view_count');
    }

    /** Board IDs this client can currently see — shared by getRecentActivity() and searchThreads() so the visibility rule lives in one place. */
    public static function getVisibleBoardIds($clientId)
    {
        $ids = [];
        foreach (self::getCategoriesWithBoards($clientId) as $group) {
            foreach ($group['boards'] as $board) {
                $ids[] = $board->id;
            }
        }
        return $ids;
    }

    /** Recent activity across boards the client can see — used by the dashboard widget. */
    public static function getRecentActivity($clientId, $limit = 5)
    {
        $visibleBoardIds = self::getVisibleBoardIds($clientId);
        if (empty($visibleBoardIds)) return [];

        return Capsule::table('mod_forum_threads')
            ->whereIn('board_id', $visibleBoardIds)
            ->orderByDesc('last_post_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Search thread titles and post bodies, restricted to boards the
     * searching client can actually see — a match inside a restricted
     * board they don't have access to must never surface here, even as
     * a title in a result list.
     *
     * Uses MySQL FULLTEXT indexes (mod_forum_threads.title,
     * mod_forum_posts.body) with MATCH() ... AGAINST() in boolean mode,
     * rather than a LIKE '%term%' table scan — this scales far better as
     * post/thread volume grows, since the index does the work instead of
     * reading every row.
     *
     * Behavior difference worth knowing: FULLTEXT matches whole words
     * (with a trailing wildcard per word here, so "host" matches
     * "hosting"), not arbitrary substrings the way LIKE did — searching
     * "ost" will no longer match "hosting" the way a LIKE '%ost%' would
     * have. It also silently ignores words shorter than
     * innodb_ft_min_token_size (3 characters by default in MySQL 5.7+).
     * Both are standard FULLTEXT tradeoffs, not bugs.
     */
    public static function searchThreads($clientId, $query, $limit = 30)
    {
        $query = trim($query);
        if ($query === '') return [];

        $visibleBoardIds = self::getVisibleBoardIds($clientId);
        if (empty($visibleBoardIds)) return [];

        $booleanQuery = self::buildBooleanFulltextQuery($query);
        if ($booleanQuery === '') return [];

        $limit = (int) $limit;
        $boardPlaceholders = implode(',', array_fill(0, count($visibleBoardIds), '?'));

        // DISTINCT + LEFT JOIN against posts so a thread with several
        // matching replies still comes back as one row, not several.
        // LIMIT is inlined (not bound) after an explicit (int) cast
        // above — some PDO/MySQL driver combinations don't reliably
        // accept LIMIT as a bound parameter, and there's no injection
        // risk once it's been through (int).
        $sql = "SELECT DISTINCT t.* FROM mod_forum_threads t
                LEFT JOIN mod_forum_posts p ON p.thread_id = t.id
                WHERE t.board_id IN ({$boardPlaceholders})
                AND (
                    MATCH(t.title) AGAINST (? IN BOOLEAN MODE)
                    OR MATCH(p.body) AGAINST (? IN BOOLEAN MODE)
                )
                ORDER BY t.last_post_at DESC
                LIMIT {$limit}";

        $bindings = array_merge($visibleBoardIds, [$booleanQuery, $booleanQuery]);

        return Capsule::select($sql, $bindings);
    }

    /**
     * Turns free-text user input into a MySQL BOOLEAN MODE fulltext
     * query: each word becomes a required, prefix-matched term (e.g.
     * "email forward" -> "+email* +forward*"), so multi-word searches
     * behave like an AND rather than pulling back anything matching any
     * one word. Strips boolean-mode operator characters out of each
     * word first, so user input like `+foo -"bar` can't accidentally
     * form unintended fulltext syntax — not a security issue (this is a
     * bound parameter, not concatenated SQL), just a correctness one.
     */
    protected static function buildBooleanFulltextQuery($query)
    {
        $tokens = preg_split('/\s+/', trim($query));
        $terms = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/[+\-><()~*"@]+/', '', $token);
            if ($token === '') continue;
            $terms[] = '+' . $token . '*';
        }
        return implode(' ', $terms);
    }

    /**
     * Atomically check-and-consume one "slot" in the client's rate limit
     * window. Returns true if the post is allowed (and counts against the
     * limit), false if the client is currently rate limited.
     *
     * This replaces an earlier design that did a separate COUNT check
     * followed by a separate INSERT log — under concurrent requests
     * (e.g. a double-click, or two browser tabs) both requests could pass
     * the check before either one logged, letting a client briefly exceed
     * the limit. Using a single row per client with a row lock inside a
     * transaction makes the check-and-increment atomic: a second
     * concurrent request has to wait for the first transaction to commit
     * before it can even read the row, so it always sees the up-to-date
     * count. It also avoids the old design's other problem — a
     * log table that grew by one row per post forever, with nothing ever
     * pruning it. This table holds exactly one row per client who has
     * ever posted.
     */
    public static function checkAndConsumeRateLimit($clientId)
    {
        return Capsule::transaction(function () use ($clientId) {
            $row = Capsule::table('mod_forum_rate_limit')
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->first();

            $now = time();

            if (!$row) {
                // Race-safe insert: if another concurrent transaction beat
                // us to creating this client's row, fall back to the
                // update path instead of erroring on a duplicate key.
                try {
                    Capsule::table('mod_forum_rate_limit')->insert([
                        'client_id' => $clientId,
                        'window_start' => date('Y-m-d H:i:s', $now),
                        'post_count' => 1,
                    ]);
                    return true;
                } catch (\Exception $e) {
                    $row = Capsule::table('mod_forum_rate_limit')
                        ->where('client_id', $clientId)
                        ->lockForUpdate()
                        ->first();
                    if (!$row) {
                        throw $e; // genuinely unexpected — surface it
                    }
                    // fall through to the normal update-path logic below
                }
            }

            $windowStart = strtotime($row->window_start);

            if ($now - $windowStart >= self::RATE_LIMIT_WINDOW_SECONDS) {
                // Window has expired — reset the counter for a fresh window.
                Capsule::table('mod_forum_rate_limit')->where('client_id', $clientId)->update([
                    'window_start' => date('Y-m-d H:i:s', $now),
                    'post_count' => 1,
                ]);
                return true;
            }

            if ($row->post_count >= self::RATE_LIMIT_MAX_POSTS) {
                return false; // still within window, at/over the cap
            }

            Capsule::table('mod_forum_rate_limit')->where('client_id', $clientId)->increment('post_count');
            return true;
        });
    }

    /** True if the currently logged-in session is a WHMCS admin browsing the client area (used for staff badges). */
    public static function isStaffSession()
    {
        return isset($_SESSION['adminid']) && (int) $_SESSION['adminid'] > 0;
    }

    public static function createThread($boardId, $clientId, $title, $body)
    {
        $now = date('Y-m-d H:i:s');
        $title = mb_substr(trim($title), 0, self::MAX_TITLE_LENGTH);
        $body = mb_substr(trim($body), 0, self::MAX_BODY_LENGTH);

        $threadId = Capsule::table('mod_forum_threads')->insertGetId([
            'board_id' => $boardId,
            'client_id' => $clientId,
            'title' => $title,
            'post_count' => 1,
            'last_post_at' => $now,
            'created_at' => $now,
        ]);

        Capsule::table('mod_forum_posts')->insert([
            'thread_id' => $threadId,
            'client_id' => $clientId,
            'body' => $body,
            'is_staff_post' => self::isStaffSession() ? 1 : 0,
            'created_at' => $now,
        ]);

        self::touchBoardCounters($boardId);
        self::addKarma($clientId, self::KARMA_THREAD_CREATE);

        return $threadId;
    }

    public static function createPost($threadId, $clientId, $body)
    {
        $thread = self::getThread($threadId);
        if (!$thread || $thread->is_locked) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $body = mb_substr(trim($body), 0, self::MAX_BODY_LENGTH);

        Capsule::table('mod_forum_posts')->insert([
            'thread_id' => $threadId,
            'client_id' => $clientId,
            'body' => $body,
            'is_staff_post' => self::isStaffSession() ? 1 : 0,
            'created_at' => $now,
        ]);

        Capsule::table('mod_forum_threads')->where('id', $threadId)->update([
            'post_count' => Capsule::raw('post_count + 1'),
            'last_post_at' => $now,
        ]);

        self::touchBoardCounters($thread->board_id);
        self::addKarma($clientId, self::KARMA_REPLY_CREATE);

        return true;
    }

    protected static function touchBoardCounters($boardId)
    {
        $threadCount = Capsule::table('mod_forum_threads')->where('board_id', $boardId)->count();
        $postCount = Capsule::table('mod_forum_posts')
            ->join('mod_forum_threads', 'mod_forum_threads.id', '=', 'mod_forum_posts.thread_id')
            ->where('mod_forum_threads.board_id', $boardId)
            ->count();

        Capsule::table('mod_forum_boards')->where('id', $boardId)->update([
            'thread_count' => $threadCount,
            'post_count' => $postCount,
            'last_post_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ---- Karma / points ---------------------------------------------------

    /**
     * Add (or subtract, with a negative delta) points for a client.
     * Upsert via ON DUPLICATE KEY UPDATE so this is a single atomic
     * statement rather than a separate exists-check + insert/update —
     * safe under concurrent posts from the same client.
     */
    public static function addKarma($clientId, $delta)
    {
        $delta = (int) $delta;
        if ($delta === 0) return;

        Capsule::statement(
            'INSERT INTO mod_forum_karma (client_id, points) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE points = points + VALUES(points)',
            [$clientId, $delta]
        );
    }

    public static function getKarma($clientId)
    {
        $row = Capsule::table('mod_forum_karma')->where('client_id', $clientId)->first();
        return $row ? (int) $row->points : 0;
    }

    /** Batch lookup for a list of client IDs — avoids one query per post when rendering a thread. */
    public static function getKarmaBulk(array $clientIds)
    {
        if (empty($clientIds)) return [];

        $rows = Capsule::table('mod_forum_karma')->whereIn('client_id', array_unique($clientIds))->get();
        $map = [];
        foreach ($rows as $row) {
            $map[$row->client_id] = (int) $row->points;
        }
        // Clients with no karma row yet default to 0.
        foreach ($clientIds as $id) {
            if (!isset($map[$id])) $map[$id] = 0;
        }
        return $map;
    }

    /**
     * Top clients by karma, joined against tblclients for display names.
     * Only clients with at least one karma point show up — someone who's
     * never posted has no row in mod_forum_karma at all, so there's
     * nothing to rank them by.
     */
    public static function getLeaderboard($limit = 20)
    {
        return Capsule::table('mod_forum_karma')
            ->join('tblclients', 'tblclients.id', '=', 'mod_forum_karma.client_id')
            ->where('mod_forum_karma.points', '>', 0)
            ->orderByDesc('mod_forum_karma.points')
            ->limit($limit)
            ->select('mod_forum_karma.client_id', 'mod_forum_karma.points', 'tblclients.firstname', 'tblclients.lastname')
            ->get();
    }

    /** This client's own rank (1-based) and point total, for "you are #N" context above the table. Null points if they've never earned any. */
    public static function getClientRank($clientId)
    {
        $points = self::getKarma($clientId);
        if ($points <= 0) {
            return ['rank' => null, 'points' => 0];
        }

        $rank = Capsule::table('mod_forum_karma')->where('points', '>', $points)->count() + 1;
        return ['rank' => $rank, 'points' => $points];
    }

    // ---- Best-answer marking -----------------------------------------------

    /**
     * Mark a post as the thread's best answer. Only the thread's original
     * poster or a staff member may do this (enforced here, not just in
     * the UI — the caller should not need to duplicate this check, but
     * defense-in-depth is cheap). Moves the karma bonus if a different
     * post was previously marked; marking the same post twice is a no-op.
     */
    public static function markBestAnswer($threadId, $postId, $actingClientId)
    {
        $thread = self::getThread($threadId);
        if (!$thread) {
            return ['success' => false, 'message' => 'Thread not found.'];
        }

        $isOwner = ((int) $thread->client_id === (int) $actingClientId);
        if (!$isOwner && !self::isStaffSession()) {
            return ['success' => false, 'message' => 'Only the thread starter or staff can mark a best answer.'];
        }

        $post = Capsule::table('mod_forum_posts')->where('id', $postId)->where('thread_id', $threadId)->first();
        if (!$post) {
            return ['success' => false, 'message' => 'Post not found in this thread.'];
        }

        if ($thread->best_answer_post_id == $postId) {
            return ['success' => true, 'message' => 'Already marked as the best answer.'];
        }

        Capsule::transaction(function () use ($thread, $post, $threadId, $postId) {
            // Moving the mark from a previous post to a new one: reverse
            // the previous author's bonus (unless it's the same person
            // answering their own thread twice, which self-cancels).
            if ($thread->best_answer_post_id) {
                $previousPost = Capsule::table('mod_forum_posts')->where('id', $thread->best_answer_post_id)->first();
                if ($previousPost) {
                    Capsule::table('mod_forum_posts')->where('id', $previousPost->id)->update(['is_best_answer' => 0]);
                    if ($previousPost->client_id != $post->client_id) {
                        self::addKarma($previousPost->client_id, -self::KARMA_BEST_ANSWER);
                    }
                }
            }

            Capsule::table('mod_forum_posts')->where('id', $postId)->update(['is_best_answer' => 1]);
            Capsule::table('mod_forum_threads')->where('id', $threadId)->update(['best_answer_post_id' => $postId]);
            self::addKarma($post->client_id, self::KARMA_BEST_ANSWER);
        });

        return ['success' => true, 'message' => 'Marked as the best answer.'];
    }

    public static function unmarkBestAnswer($threadId, $actingClientId)
    {
        $thread = self::getThread($threadId);
        if (!$thread) {
            return ['success' => false, 'message' => 'Thread not found.'];
        }

        $isOwner = ((int) $thread->client_id === (int) $actingClientId);
        if (!$isOwner && !self::isStaffSession()) {
            return ['success' => false, 'message' => 'Only the thread starter or staff can unmark a best answer.'];
        }

        if (!$thread->best_answer_post_id) {
            return ['success' => true, 'message' => 'No best answer was set.'];
        }

        Capsule::transaction(function () use ($thread) {
            $post = Capsule::table('mod_forum_posts')->where('id', $thread->best_answer_post_id)->first();
            if ($post) {
                Capsule::table('mod_forum_posts')->where('id', $post->id)->update(['is_best_answer' => 0]);
                self::addKarma($post->client_id, -self::KARMA_BEST_ANSWER);
            }
            Capsule::table('mod_forum_threads')->where('id', $thread->id)->update(['best_answer_post_id' => null]);
        });

        return ['success' => true, 'message' => 'Best answer removed.'];
    }

    // ---- Ticket-to-thread promotion ----------------------------------------

    /**
     * Promote a WHMCS support ticket into a forum thread. Uses WHMCS's
     * own local API (GetTicket, AddTicketNote) rather than querying
     * tbltickets directly, so it keeps working across WHMCS versions
     * the way a raw table read wouldn't. Requires the ticket API
     * commands to be available in the current WHMCS install (standard
     * since early WHMCS versions).
     *
     * Returns the new thread ID on success, or an error array — never
     * throws, so a promotion failure can't 500 the admin page.
     */
    public static function promoteTicketToThread($boardId, $ticketId, $customTitle = null)
    {
        if (!function_exists('localAPI')) {
            return ['success' => false, 'message' => 'WHMCS local API is not available in this context.'];
        }

        $result = localAPI('GetTicket', ['ticketid' => (int) $ticketId]);
        if (!isset($result['result']) || $result['result'] !== 'success') {
            $message = $result['message'] ?? 'Unknown error fetching the ticket.';
            return ['success' => false, 'message' => "Could not load ticket: {$message}"];
        }

        $title = $customTitle !== null && trim($customTitle) !== '' ? $customTitle : $result['subject'];

        // The first reply (replyid 0, or simply the earliest by date) is
        // the ticket's original message.
        $firstMessage = '';
        if (!empty($result['replies']['reply']) && is_array($result['replies']['reply'])) {
            $firstMessage = $result['replies']['reply'][0]['message'] ?? '';
        }
        if ($firstMessage === '') {
            $firstMessage = '(No message content found on the original ticket.)';
        }

        $threadId = self::createThread($boardId, (int) $result['userid'], $title, $firstMessage);
        Capsule::table('mod_forum_threads')->where('id', $threadId)->update(['source_ticket_id' => (int) $ticketId]);

        // Best-effort: leave an internal note on the ticket linking back
        // to the new thread. A failure here shouldn't undo the thread
        // that was already created — the promotion has still succeeded
        // from the forum's perspective either way.
        $noteFailed = false;
        try {
            $noteResult = localAPI('AddTicketNote', [
                'ticketid' => (int) $ticketId,
                'message' => "This ticket was promoted to a forum thread (thread ID {$threadId}).",
            ]);
            if (!isset($noteResult['result']) || $noteResult['result'] !== 'success') {
                $noteFailed = true;
            }
        } catch (\Exception $e) {
            $noteFailed = true;
        }

        return [
            'success' => true,
            'thread_id' => $threadId,
            'note_failed' => $noteFailed,
            'message' => $noteFailed
                ? 'Thread created, but could not add a note back to the original ticket.'
                : 'Thread created and linked from the original ticket.',
        ];
    }

    // ---- Admin-side CRUD -------------------------------------------------

    public static function createCategory($name, $sortOrder = 0)
    {
        return Capsule::table('mod_forum_categories')->insertGetId([
            'name' => mb_substr(trim($name), 0, self::MAX_CATEGORY_NAME_LENGTH),
            'sort_order' => (int) $sortOrder,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Deletes a category. Refuses if any boards still belong to it —
     * deleting a category out from under its boards would either orphan
     * them or silently cascade-delete threads/posts a client wrote,
     * neither of which an admin should hit by accident. Move or delete
     * the boards first; the error message says so.
     */
    public static function deleteCategory($categoryId)
    {
        $boardCount = Capsule::table('mod_forum_boards')->where('category_id', $categoryId)->count();
        if ($boardCount > 0) {
            return [
                'success' => false,
                'message' => "This category still has {$boardCount} board(s) in it. Move or delete them first.",
            ];
        }

        Capsule::table('mod_forum_categories')->where('id', $categoryId)->delete();
        return ['success' => true, 'message' => 'Category deleted.'];
    }

    /**
     * Swap this category's position with the one immediately above (or
     * below) it. Rather than swapping raw sort_order values directly
     * (which breaks if two categories share the same value — e.g. several
     * created without an explicit order, all defaulting to 0), this
     * re-numbers every category sequentially in its current display order
     * first, then swaps the two adjacent positions and persists all of
     * it. That guarantees the move is always visible and ties can't
     * silently prevent reordering.
     */
    public static function moveCategory($categoryId, $direction)
    {
        // Tie-break by id so the "current display order" is deterministic
        // even when sort_order values collide.
        $categories = Capsule::table('mod_forum_categories')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values();

        $index = null;
        foreach ($categories as $i => $cat) {
            if ($cat->id == $categoryId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return false;
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapIndex < 0 || $swapIndex >= count($categories)) {
            return false; // already at the top/bottom, nothing to do
        }

        // Reindex sequentially, then swap the two target positions.
        $orderedIds = $categories->pluck('id')->toArray();
        [$orderedIds[$index], $orderedIds[$swapIndex]] = [$orderedIds[$swapIndex], $orderedIds[$index]];

        Capsule::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $position => $id) {
                Capsule::table('mod_forum_categories')->where('id', $id)->update(['sort_order' => $position]);
            }
        });

        return true;
    }

    public static function createBoard($categoryId, $name, $description, $restricted, $productIds = [])
    {
        $boardId = Capsule::table('mod_forum_boards')->insertGetId([
            'category_id' => $categoryId,
            'name' => mb_substr(trim($name), 0, self::MAX_BOARD_NAME_LENGTH),
            'description' => mb_substr(trim($description), 0, self::MAX_BOARD_DESCRIPTION_LENGTH),
            'restricted' => $restricted ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        foreach ($productIds as $pid) {
            Capsule::table('mod_forum_board_product_access')->insert([
                'board_id' => $boardId,
                'product_id' => (int) $pid,
            ]);
        }

        return $boardId;
    }

    /**
     * Deletes a board. Refuses if any threads still exist in it — same
     * reasoning as deleteCategory(): a client may have written that
     * content, so cascade-deleting it silently on a board removal isn't
     * something an admin should be able to hit by accident.
     */
    public static function deleteBoard($boardId)
    {
        $threadCount = Capsule::table('mod_forum_threads')->where('board_id', $boardId)->count();
        if ($threadCount > 0) {
            return [
                'success' => false,
                'message' => "This board still has {$threadCount} thread(s) in it. Move or delete them first.",
            ];
        }

        Capsule::table('mod_forum_board_product_access')->where('board_id', $boardId)->delete();
        Capsule::table('mod_forum_boards')->where('id', $boardId)->delete();
        return ['success' => true, 'message' => 'Board deleted.'];
    }

    /**
     * Same reindex-then-swap approach as moveCategory(), but scoped to
     * boards within the same category — a board's position only makes
     * sense relative to its siblings under that category, not globally.
     */
    public static function moveBoard($boardId, $direction)
    {
        $board = self::getBoard($boardId);
        if (!$board) {
            return false;
        }

        $siblings = Capsule::table('mod_forum_boards')
            ->where('category_id', $board->category_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values();

        $index = null;
        foreach ($siblings as $i => $b) {
            if ($b->id == $boardId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return false;
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapIndex < 0 || $swapIndex >= count($siblings)) {
            return false; // already at the top/bottom of its category
        }

        $orderedIds = $siblings->pluck('id')->toArray();
        [$orderedIds[$index], $orderedIds[$swapIndex]] = [$orderedIds[$swapIndex], $orderedIds[$index]];

        Capsule::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $position => $id) {
                Capsule::table('mod_forum_boards')->where('id', $id)->update(['sort_order' => $position]);
            }
        });

        return true;
    }

    public static function deleteThread($threadId)
    {
        $thread = self::getThread($threadId);
        if (!$thread) return false;

        // If this thread had a best answer, the karma bonus it earned
        // should go away along with the post — otherwise deleting a
        // thread would leave a "phantom" bonus on someone's total.
        if ($thread->best_answer_post_id) {
            $bestPost = Capsule::table('mod_forum_posts')->where('id', $thread->best_answer_post_id)->first();
            if ($bestPost) {
                self::addKarma($bestPost->client_id, -self::KARMA_BEST_ANSWER);
            }
        }

        Capsule::table('mod_forum_posts')->where('thread_id', $threadId)->delete();
        Capsule::table('mod_forum_threads')->where('id', $threadId)->delete();
        self::touchBoardCounters($thread->board_id);
        return true;
    }

    public static function setThreadFlag($threadId, $field, $value)
    {
        if (!in_array($field, ['is_pinned', 'is_locked'], true)) {
            return false;
        }
        return Capsule::table('mod_forum_threads')->where('id', $threadId)->update([$field => $value ? 1 : 0]);
    }

    public static function getAllCategoriesRaw()
    {
        return Capsule::table('mod_forum_categories')->orderBy('sort_order')->get();
    }

    public static function getAllBoardsRaw()
    {
        return Capsule::table('mod_forum_boards')->orderBy('category_id')->orderBy('sort_order')->get();
    }
}
