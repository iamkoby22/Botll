-- Botll platform completion migration (additive; run on existing botll DB)
-- mysql -u root -p botll < database/migration_002_platform_completion.sql

SET NAMES utf8mb4;
USE botll;

-- Notifications: deep links + read timestamp
ALTER TABLE notifications
  ADD COLUMN action_url VARCHAR(500) NULL DEFAULT NULL AFTER message,
  ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER is_read;

-- Ticket templates: defaults + lifecycle
ALTER TABLE ticket_templates
  ADD COLUMN default_account_number VARCHAR(64) NULL DEFAULT NULL AFTER description,
  ADD COLUMN default_assignee_user_id INT UNSIGNED NULL DEFAULT NULL AFTER default_account_number,
  ADD COLUMN instructions TEXT NULL AFTER default_assignee_user_id,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER instructions,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE ticket_templates
  ADD CONSTRAINT fk_tt_default_assignee FOREIGN KEY (default_assignee_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Activity / audit
CREATE TABLE IF NOT EXISTS ticket_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  activity_type VARCHAR(50) NOT NULL DEFAULT 'comment',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_tc_ticket (ticket_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  field_changed VARCHAR(80) NOT NULL,
  old_value VARCHAR(500) NULL,
  new_value VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_th_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_th_user FOREIGN KEY (changed_by) REFERENCES users(id),
  INDEX idx_th_ticket (ticket_id)
) ENGINE=InnoDB;

-- Optional structured template fields (for future dynamic forms)
CREATE TABLE IF NOT EXISTS template_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  field_name VARCHAR(80) NOT NULL,
  field_label VARCHAR(120) NOT NULL,
  field_type VARCHAR(40) NOT NULL DEFAULT 'text',
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  field_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_tf_template FOREIGN KEY (template_id) REFERENCES ticket_templates(id) ON DELETE CASCADE,
  INDEX idx_tf_tpl (template_id)
) ENGINE=InnoDB;

-- Key/value settings for Settings UI
CREATE TABLE IF NOT EXISTS system_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(120) NOT NULL UNIQUE,
  setting_value MEDIUMTEXT NOT NULL,
  setting_group VARCHAR(60) NOT NULL DEFAULT 'general',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Public FAQ content (FAQ page); Tilia may also read assistant_faqs
CREATE TABLE IF NOT EXISTS faqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(500) NOT NULL,
  answer MEDIUMTEXT NOT NULL,
  category VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_faq_cat (category),
  INDEX idx_faq_active (is_active)
) ENGINE=InnoDB;

-- Seed defaults (idempotent inserts)
INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group) VALUES
('sla_response_hours', '24', 'sla'),
('sla_resolution_hours', '72', 'sla'),
('notify_assignments', '1', 'notifications'),
('notify_approvals', '1', 'notifications'),
('default_csat_target', '4.0', 'reports'),
('theme_accent', '#e31b8d', 'branding');

INSERT INTO faqs (question, answer, category, is_active) VALUES
('How do I log in?', 'Use your username and password on the Login page. Demo accounts are listed in README.', 'Getting Started', 1),
('Where do I see tickets assigned to me?', 'Open Requests and use the Assigned to Me tab, or go to My Tickets for a quick list.', 'Tracking Tickets', 1),
('How do I create a ticket?', 'Use Create Ticket in the sidebar, fill required fields, assign teammates, then submit.', 'Creating Tickets', 1),
('What is Pending My Approval?', 'Tickets where you are listed as an approver and your decision is still pending.', 'Approvals', 1),
('How do templates work?', 'Ticket Templates store defaults. Create or edit a template, then Use Template on Create Ticket.', 'Templates', 1),
('How do I export a report?', 'Reports includes Export CSV / PDF buttons as placeholders for future export jobs.', 'Reports', 1),
('How do I change my password?', 'Open Account from the profile menu and use the change password form.', 'Account & Settings', 1),
('What is Tilia?', 'Tilia is the in-app assistant for navigation and platform help only.', 'Tilia Assistant', 1);

-- Backfill notification links (point to latest ticket for demo clicks)
SET @demo_ticket_id := (SELECT id FROM tickets ORDER BY id DESC LIMIT 1);
UPDATE notifications SET action_url = CONCAT('ticket_detail.php?id=', @demo_ticket_id) WHERE action_url IS NULL;
