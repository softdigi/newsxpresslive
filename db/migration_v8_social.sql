-- ============================================================
-- migration_v8_social.sql
-- Social Layer — Follow / Unfollow System
-- ============================================================

-- 1. Follows table (one row per follower→following relationship)
CREATE TABLE IF NOT EXISTS user_follows (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  follower_id  VARCHAR(128) NOT NULL,   -- Firebase UID of the person following
  following_id VARCHAR(128) NOT NULL,   -- Firebase UID of the person being followed
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_follow   (follower_id, following_id),
  INDEX idx_follower   (follower_id),
  INDEX idx_following  (following_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Denormalised counts table (fast O(1) lookup)
CREATE TABLE IF NOT EXISTS user_follow_counts (
  user_id          VARCHAR(128) PRIMARY KEY,
  followers_count  INT DEFAULT 0,
  following_count  INT DEFAULT 0,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Triggers to keep counts in sync automatically
DELIMITER $$

-- When a new follow is inserted, increment both parties' counts
CREATE TRIGGER after_follow_insert
AFTER INSERT ON user_follows
FOR EACH ROW
BEGIN
  -- Increment the "being-followed" user's followers_count
  INSERT INTO user_follow_counts (user_id, followers_count)
  VALUES (NEW.following_id, 1)
  ON DUPLICATE KEY UPDATE
    followers_count = followers_count + 1;

  -- Increment the follower's following_count
  INSERT INTO user_follow_counts (user_id, following_count)
  VALUES (NEW.follower_id, 1)
  ON DUPLICATE KEY UPDATE
    following_count = following_count + 1;
END$$

-- When a follow row is deleted, decrement (floor at 0)
CREATE TRIGGER after_follow_delete
AFTER DELETE ON user_follows
FOR EACH ROW
BEGIN
  UPDATE user_follow_counts
  SET    followers_count = GREATEST(followers_count - 1, 0)
  WHERE  user_id = OLD.following_id;

  UPDATE user_follow_counts
  SET    following_count = GREATEST(following_count - 1, 0)
  WHERE  user_id = OLD.follower_id;
END$$

DELIMITER ;

-- 4. Optional: expose reporter public profiles (display_name, bio, avatar)
CREATE TABLE IF NOT EXISTS user_profiles (
  firebase_uid  VARCHAR(128) PRIMARY KEY,
  display_name  VARCHAR(120) DEFAULT NULL,
  bio           TEXT         DEFAULT NULL,
  avatar_url    VARCHAR(512) DEFAULT NULL,
  is_reporter   TINYINT(1)   DEFAULT 0,
  is_verified   TINYINT(1)   DEFAULT 0,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_reporter (is_reporter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
