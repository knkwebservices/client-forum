-- clientforum module schema
-- Run automatically on activation (see clientforum_activate() in clientforum.php).
-- Kept here as a standalone reference too.

CREATE TABLE IF NOT EXISTS `mod_forum_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(191) NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mod_forum_boards` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `description` VARCHAR(500) NULL,
    `restricted` TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = only visible to clients with matching product access
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `thread_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `post_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_post_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mod_forum_board_product_access` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `board_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,  -- references tblproducts.id
    PRIMARY KEY (`id`),
    KEY `board_id` (`board_id`),
    KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mod_forum_threads` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `board_id` INT UNSIGNED NOT NULL,
    `client_id` INT UNSIGNED NOT NULL,   -- references tblclients.id
    `title` VARCHAR(255) NOT NULL,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `post_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `best_answer_post_id` INT UNSIGNED NULL,       -- references mod_forum_posts.id
    `source_ticket_id` INT UNSIGNED NULL,          -- set if this thread was promoted from a support ticket (tbltickets.id)
    `last_post_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `board_id` (`board_id`),
    KEY `client_id` (`client_id`),
    KEY `is_pinned` (`is_pinned`),
    KEY `source_ticket_id` (`source_ticket_id`),
    FULLTEXT KEY `ft_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mod_forum_posts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `thread_id` INT UNSIGNED NOT NULL,
    `client_id` INT UNSIGNED NOT NULL,
    `body` TEXT NOT NULL,
    `is_staff_post` TINYINT(1) NOT NULL DEFAULT 0,
    `is_best_answer` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    `edited_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `thread_id` (`thread_id`),
    KEY `client_id` (`client_id`),
    FULLTEXT KEY `ft_body` (`body`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per client — a running point total, not a transaction log.
-- Updated atomically via Forum::addKarma() (INSERT ... ON DUPLICATE KEY
-- UPDATE) whenever a client creates a thread/reply or has a post marked
-- as the best answer.
CREATE TABLE IF NOT EXISTS `mod_forum_karma` (
    `client_id` INT UNSIGNED NOT NULL,
    `points` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per client who has ever posted — a rolling window counter,
-- not a log. Checked and incremented atomically (row-locked in a
-- transaction) by Forum::checkAndConsumeRateLimit(), so it never grows
-- unbounded the way a per-post log table would.
CREATE TABLE IF NOT EXISTS `mod_forum_rate_limit` (
    `client_id` INT UNSIGNED NOT NULL,
    `window_start` DATETIME NOT NULL,
    `post_count` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
