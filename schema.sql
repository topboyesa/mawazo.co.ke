-- Run this once in phpMyAdmin (or the mysql command line) to create the database.

CREATE DATABASE IF NOT EXISTS ai_slop
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ai_slop;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(30)  NOT NULL UNIQUE,
    email         VARCHAR(254) NOT NULL,          -- not unique: one email can have several accounts
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed log-in attempts, used to slow down password guessing.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(254) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, attempted_at),
    INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Views, likes and dislikes (added for the "sort" menu).
-- Safe to run again: existing tables and data are left alone.

-- How many times each article has been opened. post_id matches the article's id on the page (post-1, post-2, ...).
CREATE TABLE IF NOT EXISTS post_views (
    post_id VARCHAR(20)  NOT NULL PRIMARY KEY,
    views   INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One vote per member per article: 1 = like, -1 = dislike.
CREATE TABLE IF NOT EXISTS post_votes (
    user_id    INT UNSIGNED NOT NULL,
    post_id    VARCHAR(20)  NOT NULL,
    vote       TINYINT      NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id),
    INDEX idx_post (post_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comments under each article. Members write them; the owner (and admins) can delete any.
CREATE TABLE IF NOT EXISTS comments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id    VARCHAR(20)  NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    body       TEXT         NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_post_time (post_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admins can delete anyone's comments. Everyone else can only delete their own.
CREATE TABLE IF NOT EXISTS admins (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- To make yourself an admin, sign up on the site first, then run this
-- with your own username in place of yourusername:
-- INSERT IGNORE INTO admins (user_id) SELECT id FROM users WHERE username = 'yourusername';

-- Posts written from the site (write.php). Your three original posts stay in index.html;
-- new posts start at id 4 so they never clash with post-1, post-2 and post-3.
CREATE TABLE IF NOT EXISTS posts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(200) NOT NULL,
    body       TEXT         NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who follows the site owner (the "Follow @name" option in the ... menu on each post).
CREATE TABLE IF NOT EXISTS follows (
    follower_id INT UNSIGNED NOT NULL,
    followed_id INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, followed_id),
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who wrote each post. Posts without a row here were written by the site owner (the first admin).
CREATE TABLE IF NOT EXISTS post_authors (
    post_id INT UNSIGNED NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications: someone liked, disliked or commented on your post, or followed you.
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,           -- who gets the notification
    actor_id   INT UNSIGNED NOT NULL,           -- who did it
    type       VARCHAR(10)  NOT NULL,           -- like, dislike, comment or follow
    post_id    VARCHAR(20)  NULL,               -- post-4 and so on (empty for follows)
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_unread (user_id, is_read, id),
    FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset links. Only a scrambled copy of each token is stored, and each one expires after an hour.
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token_hash),
    INDEX idx_user_time (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When each member was last active on the site (used for "online now" on the admin users page).
CREATE TABLE IF NOT EXISTS user_activity (
    user_id   INT UNSIGNED NOT NULL PRIMARY KEY,
    last_seen DATETIME     NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profile photos. Each one is stored as a small square JPEG (256 by 256 pixels).
CREATE TABLE IF NOT EXISTS user_avatars (
    user_id    INT UNSIGNED NOT NULL PRIMARY KEY,
    image      MEDIUMBLOB   NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every spelling and grammar check, so each member's daily limit can be counted.
CREATE TABLE IF NOT EXISTS ai_checks (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_time (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pictures and GIFs on posts. The files live in the media folder; this table remembers which post each one belongs to.
CREATE TABLE IF NOT EXISTS post_media (
    id         INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    post_id    INT UNSIGNED      NOT NULL,
    file       VARCHAR(40)       NOT NULL,
    mime       VARCHAR(20)       NOT NULL,
    width      SMALLINT UNSIGNED NOT NULL,
    height     SMALLINT UNSIGNED NOT NULL,
    size       INT UNSIGNED      NOT NULL,
    position   TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    created_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_post (post_id, position),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports: a member flags a post or a comment for the admin to look at.
CREATE TABLE IF NOT EXISTS reports (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT UNSIGNED NOT NULL,
    type        VARCHAR(10)  NOT NULL,                 -- post or comment
    target_id   INT UNSIGNED NOT NULL,                 -- the post's or the comment's id
    reason      VARCHAR(10)  NOT NULL,                 -- spam, abuse, false or other
    details     VARCHAR(300) NULL,
    status      VARCHAR(10)  NOT NULL DEFAULT 'open',  -- open, dismissed or removed
    handled_by  INT UNSIGNED NULL,
    handled_at  DATETIME     NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_report (reporter_id, type, target_id),   -- one report per person per item
    INDEX idx_status (status, id),
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Edit history: every saved version of a post, oldest first, like commits in a Git history.
-- Version 1 is the post as it was first published; each edit adds the next version.
CREATE TABLE IF NOT EXISTS post_versions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id    INT UNSIGNED NOT NULL,
    version    INT UNSIGNED NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT         NOT NULL,
    edited_by  INT UNSIGNED NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_version (post_id, version),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
