-- Analytics support: account route on tickets, optional user_level on users (no workflow change).
-- Run once: mysql -u root -p botll < database/migration_014_analytics_support.sql

SET @db = DATABASE();

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'account_route') = 0,
    'ALTER TABLE tickets ADD COLUMN account_route VARCHAR(20) NULL DEFAULT NULL AFTER request_step2,
     ADD INDEX idx_tickets_account_route (account_route)',
    'SELECT ''skip tickets.account_route'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'archived_at') = 0,
    'ALTER TABLE tickets ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at',
    'SELECT ''skip tickets.archived_at'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'user_level') = 0,
    'ALTER TABLE users ADD COLUMN user_level VARCHAR(50) NULL DEFAULT NULL AFTER department_id,
     ADD INDEX idx_users_user_level (user_level)',
    'SELECT ''skip users.user_level'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
