-- ============================================================
-- Migration v33 — Community Groups, Reporter Academy,
--                 Hyperlocal Events, AI Assistant
-- NewsXpressLive
-- ============================================================

-- ── Feature 6: Community Groups ──────────────────────────────

CREATE TABLE IF NOT EXISTS `community_groups` (
  `id`                BIGINT        AUTO_INCREMENT PRIMARY KEY,
  `name`              VARCHAR(120)  NOT NULL,
  `name_hi`           VARCHAR(120)  NULL,
  `description`       TEXT          NULL,
  `cover_image_url`   VARCHAR(500)  NULL,
  `group_type`        ENUM('local','state','national','topic') NOT NULL DEFAULT 'local',
  `category_id`       INT           NULL,
  `state_id`          INT           NULL,
  `district_id`       INT           NULL,
  `latitude`          DECIMAL(9,6)  NULL,
  `longitude`         DECIMAL(9,6)  NULL,
  `members_count`     INT           NOT NULL DEFAULT 0,
  `posts_count`       INT           NOT NULL DEFAULT 0,
  `active_today`      TINYINT(1)    NOT NULL DEFAULT 0,
  `is_public`         TINYINT(1)    NOT NULL DEFAULT 1,
  `join_approval`     TINYINT(1)    NOT NULL DEFAULT 0,
  `post_approval`     TINYINT(1)    NOT NULL DEFAULT 0,
  `is_premium`        TINYINT(1)    NOT NULL DEFAULT 0,
  `monthly_fee`       DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
  `created_by_uid`    VARCHAR(128)  NOT NULL,
  `status`            ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_state`    (`state_id`),
  INDEX `idx_district` (`district_id`),
  INDEX `idx_category` (`category_id`),
  INDEX `idx_type`     (`group_type`),
  INDEX `idx_geo`      (`latitude`, `longitude`),
  INDEX `idx_members`  (`members_count` DESC),
  FULLTEXT INDEX `ft_name` (`name`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_members` (
  `id`        BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `group_id`  BIGINT       NOT NULL,
  `user_uid`  VARCHAR(128) NOT NULL,
  `role`      ENUM('admin','moderator','member') NOT NULL DEFAULT 'member',
  `status`    ENUM('active','pending','banned')  NOT NULL DEFAULT 'active',
  `joined_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_group_user` (`group_id`, `user_uid`),
  INDEX `idx_user`   (`user_uid`),
  INDEX `idx_status` (`group_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_posts` (
  `id`                BIGINT        AUTO_INCREMENT PRIMARY KEY,
  `group_id`          BIGINT        NOT NULL,
  `author_uid`        VARCHAR(128)  NOT NULL,
  `post_type`         ENUM('text','image','video','poll','article_share') NOT NULL DEFAULT 'text',
  `content`           TEXT          NULL,
  `media_urls`        JSON          NULL COMMENT 'Array of media URLs',
  `shared_article_id` INT           NULL,
  `poll_id`           INT           NULL,
  `likes_count`       INT           NOT NULL DEFAULT 0,
  `comments_count`    INT           NOT NULL DEFAULT 0,
  `shares_count`      INT           NOT NULL DEFAULT 0,
  `is_pinned`         TINYINT(1)    NOT NULL DEFAULT 0,
  `is_approved`       TINYINT(1)    NOT NULL DEFAULT 1,
  `status`            ENUM('active','removed','flagged') NOT NULL DEFAULT 'active',
  `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_group_created` (`group_id`, `created_at` DESC),
  INDEX `idx_author`        (`author_uid`),
  INDEX `idx_pinned`        (`group_id`, `is_pinned`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_post_comments` (
  `id`          BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `post_id`     BIGINT       NOT NULL,
  `group_id`    BIGINT       NOT NULL,
  `author_uid`  VARCHAR(128) NOT NULL,
  `parent_id`   BIGINT       NULL COMMENT 'NULL for top-level comments',
  `content`     TEXT         NOT NULL,
  `likes_count` INT          NOT NULL DEFAULT 0,
  `status`      ENUM('active','removed') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_post`   (`post_id`, `created_at` ASC),
  INDEX `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_post_likes` (
  `id`         BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `post_id`    BIGINT       NOT NULL,
  `user_uid`   VARCHAR(128) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_post_user` (`post_id`, `user_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 7: Reporter Academy ───────────────────────────────

CREATE TABLE IF NOT EXISTS `academy_courses` (
  `id`               INT          AUTO_INCREMENT PRIMARY KEY,
  `title`            VARCHAR(200) NOT NULL,
  `title_hi`         VARCHAR(200) NULL,
  `description`      TEXT         NULL,
  `thumbnail_url`    VARCHAR(500) NULL,
  `difficulty`       ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner',
  `duration_minutes` INT          NOT NULL DEFAULT 0,
  `lessons_count`    INT          NOT NULL DEFAULT 0,
  `is_free`          TINYINT(1)   NOT NULL DEFAULT 1,
  `price`            DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `sort_order`       INT          NOT NULL DEFAULT 0,
  `status`           ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`),
  INDEX `idx_sort`   (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `academy_lessons` (
  `id`               INT          AUTO_INCREMENT PRIMARY KEY,
  `course_id`        INT          NOT NULL,
  `title`            VARCHAR(200) NOT NULL,
  `title_hi`         VARCHAR(200) NULL,
  `content`          LONGTEXT     NULL,
  `video_url`        VARCHAR(500) NULL,
  `duration_minutes` INT          NOT NULL DEFAULT 0,
  `has_quiz`         TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`       INT          NOT NULL DEFAULT 0,
  `status`           ENUM('active','draft') NOT NULL DEFAULT 'active',
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_course_order` (`course_id`, `sort_order` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `academy_progress` (
  `id`                BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `user_uid`          VARCHAR(128) NOT NULL,
  `course_id`         INT          NOT NULL,
  `lessons_completed` JSON         NULL COMMENT 'Array of completed lesson IDs',
  `progress_percent`  TINYINT      NOT NULL DEFAULT 0,
  `is_completed`      TINYINT(1)   NOT NULL DEFAULT 0,
  `certificate_url`   VARCHAR(500) NULL,
  `started_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`      TIMESTAMP    NULL,
  `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_course` (`user_uid`, `course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `academy_certificates` (
  `id`                 BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `user_uid`           VARCHAR(128) NOT NULL,
  `course_id`          INT          NOT NULL,
  `certificate_number` VARCHAR(50)  NOT NULL,
  `certificate_url`    VARCHAR(500) NULL,
  `issued_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_valid`           TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_cert_number` (`certificate_number`),
  UNIQUE KEY `uk_user_course` (`user_uid`, `course_id`),
  INDEX `idx_user`            (`user_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 8: Hyperlocal Events ──────────────────────────────

CREATE TABLE IF NOT EXISTS `local_events` (
  `id`               BIGINT        AUTO_INCREMENT PRIMARY KEY,
  `title`            VARCHAR(250)  NOT NULL,
  `description`      TEXT          NULL,
  `event_type`       ENUM('cultural','political','sports','religious','educational','business','entertainment','other')
                                   NOT NULL DEFAULT 'other',
  `cover_image_url`  VARCHAR(500)  NULL,
  `venue_name`       VARCHAR(200)  NULL,
  `address`          VARCHAR(400)  NULL,
  `city`             VARCHAR(100)  NULL,
  `state_id`         INT           NULL,
  `district_id`      INT           NULL,
  `latitude`         DECIMAL(9,6)  NULL,
  `longitude`        DECIMAL(9,6)  NULL,
  `pincode`          VARCHAR(10)   NULL,
  `event_date`       DATE          NOT NULL,
  `start_time`       TIME          NOT NULL,
  `end_time`         TIME          NULL,
  `is_all_day`       TINYINT(1)    NOT NULL DEFAULT 0,
  `going_count`      INT           NOT NULL DEFAULT 0,
  `interested_count` INT           NOT NULL DEFAULT 0,
  `views_count`      INT           NOT NULL DEFAULT 0,
  `is_free`          TINYINT(1)    NOT NULL DEFAULT 1,
  `ticket_price`     DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
  `ticket_url`       VARCHAR(500)  NULL,
  `is_featured`      TINYINT(1)    NOT NULL DEFAULT 0,
  `organizer_uid`    VARCHAR(128)  NOT NULL,
  `status`           ENUM('active','cancelled','completed','pending') NOT NULL DEFAULT 'active',
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_date`     (`event_date` ASC),
  INDEX `idx_state`    (`state_id`),
  INDEX `idx_district` (`district_id`),
  INDEX `idx_geo`      (`latitude`, `longitude`),
  INDEX `idx_type`     (`event_type`),
  INDEX `idx_featured` (`is_featured`, `event_date` ASC),
  FULLTEXT INDEX `ft_title` (`title`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_responses` (
  `id`           BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `event_id`     BIGINT       NOT NULL,
  `user_uid`     VARCHAR(128) NOT NULL,
  `response`     ENUM('going','interested','not_going') NOT NULL,
  `responded_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_event_user` (`event_id`, `user_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 9: AI Assistant ───────────────────────────────────

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id`              BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `user_uid`        VARCHAR(128) NOT NULL,
  `session_id`      VARCHAR(64)  NOT NULL,
  `started_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `message_count`   INT          NOT NULL DEFAULT 0,
  UNIQUE KEY `uk_session`      (`session_id`),
  INDEX `idx_user_session`     (`user_uid`, `last_message_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id`         BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `session_id` VARCHAR(64)  NOT NULL,
  `user_uid`   VARCHAR(128) NOT NULL,
  `role`       ENUM('user','assistant') NOT NULL,
  `message`    TEXT         NOT NULL,
  `response`   TEXT         NULL,
  `intent`     VARCHAR(50)  NULL,
  `sources`    JSON         NULL COMMENT 'Array of referenced article IDs/titles',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_session`  (`session_id`, `created_at` ASC),
  INDEX `idx_user_ts`  (`user_uid`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Default Academy Courses ───────────────────────────────────

INSERT INTO `academy_courses`
  (`title`, `title_hi`, `description`, `difficulty`, `duration_minutes`, `lessons_count`, `is_free`, `sort_order`, `status`)
VALUES
  (
    'Journalism Basics',
    'पत्रकारिता की बुनियाद',
    'Learn the fundamentals of news reporting, writing structure, and ethical journalism.',
    'beginner', 120, 8, 1, 1, 'active'
  ),
  (
    'Mobile Reporting & Field Coverage',
    'मोबाइल रिपोर्टिंग और फील्ड कवरेज',
    'Master smartphone journalism — shooting, editing, and publishing from the field.',
    'beginner', 90, 6, 1, 2, 'active'
  ),
  (
    'Fact-Checking & Verification',
    'फैक्ट-चेकिंग और सत्यापन',
    'Techniques to verify news, detect misinformation, and build credible reports.',
    'intermediate', 150, 10, 1, 3, 'active'
  ),
  (
    'Investigative Journalism',
    'खोजी पत्रकारिता',
    'Deep-dive investigations: data journalism, source protection, and impact stories.',
    'advanced', 200, 12, 0, 4, 'active'
  ),
  (
    'Digital & Social Media Journalism',
    'डिजिटल और सोशल मीडिया पत्रकारिता',
    'Build your audience, monetize content, and report across digital platforms.',
    'intermediate', 110, 7, 1, 5, 'active'
  );
