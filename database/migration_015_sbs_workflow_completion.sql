-- SBS workflow: columns, subscriptions, assignment history, demo users (idempotent).
-- Run: mysql -u root -p botll < database/migration_015_sbs_workflow_completion.sql

SET @db = DATABASE();

-- users.user_level
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'user_level') = 0,
    'ALTER TABLE users ADD COLUMN user_level VARCHAR(64) NULL DEFAULT NULL AFTER department_id, ADD INDEX idx_users_user_level (user_level)',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tickets columns
SET @cols = 'account_route VARCHAR(32) NULL, routed_pillar VARCHAR(64) NULL, routed_at DATETIME NULL, assigned_type VARCHAR(32) NULL,
    final_completed_at DATETIME NULL, final_completed_by INT UNSIGNED NULL,
    archived_at DATETIME NULL, archived_by INT UNSIGNED NULL, archive_reason VARCHAR(255) NULL,
    response_target_hours INT UNSIGNED NULL DEFAULT 168, resolution_target_hours INT UNSIGNED NULL DEFAULT 336,
    sla_risk TINYINT(1) NOT NULL DEFAULT 0, last_activity_at DATETIME NULL';

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'account_route') = 0,
    CONCAT('ALTER TABLE tickets ADD COLUMN ', REPLACE(@cols, ',', ', ADD COLUMN ')),
    'SELECT 1'
);
-- Simpler: add one by one via procedure below is error-prone; use individual IFs

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='account_route')=0,
    'ALTER TABLE tickets ADD COLUMN account_route VARCHAR(32) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='routed_pillar')=0,
    'ALTER TABLE tickets ADD COLUMN routed_pillar VARCHAR(64) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='routed_at')=0,
    'ALTER TABLE tickets ADD COLUMN routed_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='assigned_type')=0,
    'ALTER TABLE tickets ADD COLUMN assigned_type VARCHAR(32) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='final_completed_at')=0,
    'ALTER TABLE tickets ADD COLUMN final_completed_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='final_completed_by')=0,
    'ALTER TABLE tickets ADD COLUMN final_completed_by INT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='archived_at')=0,
    'ALTER TABLE tickets ADD COLUMN archived_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='archived_by')=0,
    'ALTER TABLE tickets ADD COLUMN archived_by INT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='archive_reason')=0,
    'ALTER TABLE tickets ADD COLUMN archive_reason VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='response_target_hours')=0,
    'ALTER TABLE tickets ADD COLUMN response_target_hours INT UNSIGNED NULL DEFAULT 168', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='resolution_target_hours')=0,
    'ALTER TABLE tickets ADD COLUMN resolution_target_hours INT UNSIGNED NULL DEFAULT 336', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='sla_risk')=0,
    'ALTER TABLE tickets ADD COLUMN sla_risk TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tickets' AND COLUMN_NAME='last_activity_at')=0,
    'ALTER TABLE tickets ADD COLUMN last_activity_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS ticket_notification_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ticket_user (ticket_id, user_id),
  KEY idx_tns_user (user_id),
  CONSTRAINT fk_tns_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tns_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_assignment_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  assigned_type VARCHAR(32) NOT NULL,
  assigned_by INT UNSIGNED NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tah_ticket (ticket_id),
  KEY idx_tah_user (user_id),
  CONSTRAINT fk_tah_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tah_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Statuses for SBS workflow
INSERT IGNORE INTO ticket_statuses (status_name) VALUES ('Assigned'), ('Overdue');

-- Demo users (password: password123)
SET @pwd = '$2y$12$CvZsjhO60soXPObScnvnLegC7Ep9tot5HFhgfAyCl6/b4O9vg1/1G';
SET @role_user = (SELECT id FROM roles WHERE role_key = 'user' LIMIT 1);

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'Restricted Pillar Admin', 'restricted.pillar@botll.local', 'restricted_pillar', @pwd, @role_user, 'restricted_pillar_admin', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'restricted_pillar');

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'Unrestricted Pillar Admin', 'unrestricted.pillar@botll.local', 'unrestricted_pillar', @pwd, @role_user, 'unrestricted_pillar_admin', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'unrestricted_pillar');

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'General Pillar Admin', 'general.pillar@botll.local', 'general_pillar', @pwd, @role_user, 'general_pillar_admin', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'general_pillar');

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'Business Admin One', 'business.admin1@botll.local', 'business_admin1', @pwd, @role_user, 'business_admin', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'business_admin1');

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'Business Admin Two', 'business.admin2@botll.local', 'business_admin2', @pwd, @role_user, 'business_admin', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'business_admin2');

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'Coordinator One', 'coordinator1@botll.local', 'coordinator1', @pwd, @role_user, 'coordinator', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'coordinator1');

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'Coordinator Two', 'coordinator2@botll.local', 'coordinator2', @pwd, @role_user, 'coordinator', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'coordinator2');

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'Faculty User One', 'faculty.user1@botll.local', 'faculty_user1', @pwd, @role_user, 'faculty_staff', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'faculty_user1');

INSERT INTO users (full_name, email, username, password_hash, role_id, user_level, status)
SELECT 'Faculty User Two', 'faculty.user2@botll.local', 'faculty_user2', @pwd, @role_user, 'faculty_staff', 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'faculty_user2');

UPDATE users u JOIN roles r ON r.id = u.role_id SET u.user_level = 'super_admin' WHERE r.role_key = 'super_admin' AND (u.user_level IS NULL OR u.user_level = '');
