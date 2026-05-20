-- Self-registration + workflow fixes (additive).
-- mysql -u root -p botll < database/migration_008_self_registration_and_workflow_fixes.sql

SET NAMES utf8mb4;
USE botll;

ALTER TABLE users
  ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER status,
  ADD COLUMN approved_by INT UNSIGNED NULL DEFAULT NULL AFTER approval_status,
  ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by,
  ADD COLUMN rejection_reason VARCHAR(500) NULL DEFAULT NULL AFTER approved_at;

ALTER TABLE users
  ADD CONSTRAINT fk_users_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE users SET approval_status = 'approved' WHERE approval_status = '' OR approval_status IS NULL;
