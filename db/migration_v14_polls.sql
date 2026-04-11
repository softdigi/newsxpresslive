-- ============================================================
-- v14 — Polls & Daily Quiz
-- ============================================================
-- Run AFTER migrations.sql through migration_v13_languages.sql
-- ============================================================

-- ── Article Polls ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS news_polls (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id     INT UNSIGNED NULL COMMENT 'Linked article (nullable = standalone poll)',
    question    VARCHAR(500) NOT NULL,
    -- JSON array of option strings, e.g. ["Yes","No","Maybe"]
    options     JSON         NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    -- NULL = no expiry
    ends_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_news_id  (news_id),
    INDEX idx_active   (is_active),
    INDEX idx_ends_at  (ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS news_poll_votes (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    poll_id      INT UNSIGNED NOT NULL,
    firebase_uid VARCHAR(128) NOT NULL,
    option_index TINYINT UNSIGNED NOT NULL COMMENT '0-based index into options JSON',
    voted_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_poll_user   (poll_id, firebase_uid),
    INDEX  idx_poll_id        (poll_id),
    CONSTRAINT fk_pv_poll FOREIGN KEY (poll_id) REFERENCES news_polls (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Daily Quiz ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS daily_quiz (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question       TEXT         NOT NULL,
    -- JSON array of exactly 4 option strings
    options        JSON         NOT NULL,
    correct_index  TINYINT UNSIGNED NOT NULL COMMENT '0-based index of correct option',
    explanation    TEXT         NULL COMMENT 'Shown after answering',
    -- quiz_date enforces one quiz per day
    quiz_date      DATE         NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quiz_date (quiz_date),
    INDEX  idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS daily_quiz_attempts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id        INT UNSIGNED NOT NULL,
    firebase_uid   VARCHAR(128) NOT NULL,
    selected_index TINYINT UNSIGNED NOT NULL,
    is_correct     TINYINT(1)   NOT NULL,
    -- points: 10 for correct, 0 for wrong
    score          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    attempted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quiz_user (quiz_id, firebase_uid),
    INDEX  idx_quiz_id     (quiz_id),
    INDEX  idx_uid         (firebase_uid),
    CONSTRAINT fk_qa_quiz FOREIGN KEY (quiz_id) REFERENCES daily_quiz (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Sample Data ──────────────────────────────────────────────────────────────

INSERT IGNORE INTO news_polls (question, options, is_active) VALUES
('क्या आपको लगता है कि भारत 2024 ओलंपिक में 10+ मेडल जीतेगा?',
 '["हाँ, जरूर","शायद","मुझे नहीं लगता","कोई राय नहीं"]',
 1);

INSERT IGNORE INTO daily_quiz (question, options, correct_index, explanation, quiz_date, is_active) VALUES
('भारत का राष्ट्रीय खेल कौन सा है?',
 '["क्रिकेट","हॉकी","कबड्डी","बैडमिंटन"]',
 1,
 'हॉकी भारत का राष्ट्रीय खेल है। भारतीय पुरुष हॉकी टीम ने 8 ओलंपिक गोल्ड मेडल जीते हैं।',
 CURDATE(),
 1);
