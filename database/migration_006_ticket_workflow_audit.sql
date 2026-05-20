-- Botll workflow: Closed status, work-done tracking, conversation close (additive).
-- mysql -u root -p botll < database/migration_006_ticket_workflow_audit.sql

SET NAMES utf8mb4;
USE botll;

INSERT IGNORE INTO ticket_statuses (status_name) VALUES ('Closed');

ALTER TABLE tickets
  ADD COLUMN work_done_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at,
  ADD COLUMN work_done_by INT UNSIGNED NULL DEFAULT NULL AFTER work_done_at,
  ADD COLUMN conversation_closed_at TIMESTAMP NULL DEFAULT NULL AFTER work_done_by,
  ADD COLUMN completion_note VARCHAR(500) NULL DEFAULT NULL AFTER conversation_closed_at;

ALTER TABLE tickets
  ADD CONSTRAINT fk_tickets_work_done_by FOREIGN KEY (work_done_by) REFERENCES users(id) ON DELETE SET NULL;
