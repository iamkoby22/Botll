-- Botll UI / builder / requests (additive). Run after migration_002.
-- mysql -u root -p botll < database/migration_003_platform_ui.sql
-- If a column already exists, skip that ALTER line and continue.

SET NAMES utf8mb4;
USE botll;

CREATE TABLE IF NOT EXISTS service_catalog (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_name VARCHAR(80) NOT NULL,
  title VARCHAR(160) NOT NULL,
  description VARCHAR(500) NOT NULL,
  icon_class VARCHAR(80) NOT NULL DEFAULT 'bi-grid',
  est_duration VARCHAR(80) NOT NULL DEFAULT '2–5 business days',
  default_category_id INT UNSIGNED NOT NULL,
  default_priority_id INT UNSIGNED NOT NULL DEFAULT 2,
  default_department_id INT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sc_cat FOREIGN KEY (default_category_id) REFERENCES ticket_categories(id),
  CONSTRAINT fk_sc_pri FOREIGN KEY (default_priority_id) REFERENCES ticket_priorities(id),
  CONSTRAINT fk_sc_dept FOREIGN KEY (default_department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO service_catalog (id, group_name, title, description, icon_class, est_duration, default_category_id, default_priority_id, default_department_id, sort_order) VALUES
(1, 'IT Services', 'Laptop Request', 'Standard laptop refresh or replacement.', 'bi-laptop', '3–7 business days', 3, 3, 3, 1),
(2, 'IT Services', 'Software Access', 'Request access to licensed applications or SSO groups.', 'bi-window-stack', '1–3 business days', 3, 2, 3, 2),
(3, 'IT Services', 'VPN Access', 'Remote access and VPN profile provisioning.', 'bi-shield-lock', '1–2 business days', 3, 3, 3, 3),
(4, 'HR Services', 'Leave Request', 'Paid time off and leave workflow.', 'bi-calendar-check', '2–4 business days', 2, 2, 2, 10),
(5, 'HR Services', 'Expense Reimbursement', 'Submit receipts for reimbursement.', 'bi-receipt', '5–10 business days', 1, 2, 1, 11),
(6, 'HR Services', 'HR Document Request', 'Letters, verifications, and policy documents.', 'bi-file-earmark-text', '2–5 business days', 2, 2, 2, 12),
(7, 'HR Services', 'VP Office Request', 'Executive office routing and approvals.', 'bi-building', '5–10 business days', 2, 4, 1, 13);

ALTER TABLE tickets
  ADD COLUMN service_catalog_id INT UNSIGNED NULL DEFAULT NULL AFTER category_id,
  ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER attachments_count;

ALTER TABLE notifications
  ADD COLUMN related_ticket_id INT UNSIGNED NULL DEFAULT NULL AFTER action_url;

ALTER TABLE template_fields
  ADD COLUMN field_options TEXT NULL DEFAULT NULL COMMENT 'newline or JSON options for select/radio/checkbox' AFTER field_type,
  ADD COLUMN placeholder VARCHAR(255) NULL DEFAULT NULL AFTER is_required,
  ADD COLUMN help_text VARCHAR(500) NULL DEFAULT NULL AFTER placeholder,
  ADD COLUMN default_value VARCHAR(500) NULL DEFAULT NULL AFTER help_text;

ALTER TABLE ticket_templates
  ADD COLUMN template_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER template_title,
  ADD COLUMN template_status VARCHAR(20) NOT NULL DEFAULT 'published' COMMENT 'draft|published' AFTER is_active,
  ADD COLUMN last_edited_by INT UNSIGNED NULL DEFAULT NULL AFTER updated_at;
