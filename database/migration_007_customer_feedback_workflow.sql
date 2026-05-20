-- Botll Phase 2 — customer feedback workflow (additive).
-- mysql -u root -p botll < database/migration_007_customer_feedback_workflow.sql

SET NAMES utf8mb4;
USE botll;

-- Users: forced password change on first login
ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- Tickets: reopen audit
ALTER TABLE tickets
  ADD COLUMN reopened_at TIMESTAMP NULL DEFAULT NULL AFTER completion_note,
  ADD COLUMN reopened_by INT UNSIGNED NULL DEFAULT NULL AFTER reopened_at;

ALTER TABLE tickets
  ADD CONSTRAINT fk_tickets_reopened_by FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE SET NULL;

-- Assignment level workflow (separate from formal ticket_approvals)
ALTER TABLE ticket_assignees
  ADD COLUMN assignment_level SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER sort_order,
  ADD COLUMN assignment_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER assignment_level,
  ADD COLUMN acted_at TIMESTAMP NULL DEFAULT NULL AFTER assignment_status,
  ADD COLUMN remarks VARCHAR(500) NULL DEFAULT NULL AFTER acted_at;

-- Reference data soft-disable
ALTER TABLE departments
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER department_name;

ALTER TABLE ticket_categories
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER category_name;

ALTER TABLE ticket_priorities
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER priority_level;

-- Comment @mentions
CREATE TABLE IF NOT EXISTS comment_mentions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  mentioned_user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cm_comment FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_user FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_cm_ticket (ticket_id),
  INDEX idx_cm_user (mentioned_user_id)
) ENGINE=InnoDB;

-- Backfill assignment chain from existing rows
UPDATE ticket_assignees SET assignment_level = sort_order WHERE assignment_level = 1 AND sort_order > 0;

UPDATE ticket_assignees ta
JOIN (
  SELECT ticket_id, MIN(assignment_level) min_lvl
  FROM ticket_assignees
  GROUP BY ticket_id
) x ON x.ticket_id = ta.ticket_id
SET ta.assignment_status = 'active'
WHERE ta.assignment_level = x.min_lvl;

UPDATE ticket_assignees SET assignment_status = 'pending' WHERE assignment_status = '' OR assignment_status IS NULL;

UPDATE ticket_assignees SET assignment_status = 'approved' WHERE approval_status = 'approved' AND assignment_status = 'active';
