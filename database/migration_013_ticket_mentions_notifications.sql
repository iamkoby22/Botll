-- Mention access + notification indexes (idempotent).
-- mysql -u root -p botll < database/migration_013_ticket_mentions_notifications.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_mention_access (
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  first_comment_id INT UNSIGNED NULL DEFAULT NULL,
  mentioned_by_user_id INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ticket_id, user_id),
  INDEX idx_tma_user (user_id),
  CONSTRAINT fk_tma_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tma_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_mentions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  mentioned_user_id INT UNSIGNED NOT NULL,
  mentioned_by_user_id INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cm_ticket (ticket_id),
  INDEX idx_cm_user (mentioned_user_id),
  INDEX idx_cm_comment (comment_id),
  CONSTRAINT fk_cm_comment FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_user FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add columns if table existed from migration_007 without them
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'comment_mentions' AND COLUMN_NAME = 'mentioned_by_user_id') = 0,
  'ALTER TABLE comment_mentions ADD COLUMN mentioned_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER mentioned_user_id',
  'SELECT ''skip comment_mentions.mentioned_by_user_id'' AS info'
);
PREPARE s1 FROM @sql; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'comment_mentions' AND INDEX_NAME = 'uniq_cm_comment_user') = 0,
  'ALTER TABLE comment_mentions ADD UNIQUE KEY uniq_cm_comment_user (comment_id, mentioned_user_id)',
  'SELECT ''skip uniq_cm_comment_user'' AS info'
);
PREPARE s2 FROM @sql; EXECUTE s2; DEALLOCATE PREPARE s2;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'comment_id') = 0,
  'ALTER TABLE notifications ADD COLUMN comment_id INT UNSIGNED NULL DEFAULT NULL AFTER related_ticket_id',
  'SELECT ''skip notifications.comment_id'' AS info'
);
PREPARE s3 FROM @sql; EXECUTE s3; DEALLOCATE PREPARE s3;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_user_read') = 0,
  'ALTER TABLE notifications ADD INDEX idx_notifications_user_read (user_id, is_read, created_at)',
  'SELECT ''skip idx_notifications_user_read'' AS info'
);
PREPARE s4 FROM @sql; EXECUTE s4; DEALLOCATE PREPARE s4;

-- Backfill mention access from existing comment mentions
INSERT IGNORE INTO ticket_mention_access (ticket_id, user_id, first_comment_id, mentioned_by_user_id, created_at)
SELECT cm.ticket_id, cm.mentioned_user_id, MIN(cm.comment_id), MIN(cm.mentioned_by_user_id), MIN(cm.created_at)
FROM comment_mentions cm
GROUP BY cm.ticket_id, cm.mentioned_user_id;
