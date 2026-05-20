-- Botll: persisted answers for template custom fields on tickets (additive).
-- mysql -u root -p botll < database/migration_005_template_field_values.sql

SET NAMES utf8mb4;
USE botll;

CREATE TABLE IF NOT EXISTS ticket_field_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  template_id INT UNSIGNED NOT NULL,
  template_field_id INT UNSIGNED NULL DEFAULT NULL,
  field_label VARCHAR(120) NOT NULL,
  field_key VARCHAR(80) NOT NULL,
  field_type VARCHAR(40) NOT NULL,
  field_value MEDIUMTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tfv_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tfv_template FOREIGN KEY (template_id) REFERENCES ticket_templates(id) ON DELETE CASCADE,
  INDEX idx_tfv_ticket (ticket_id),
  INDEX idx_tfv_template (template_id)
) ENGINE=InnoDB;
