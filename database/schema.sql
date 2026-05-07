-- Botll ticketing platform — MySQL / MariaDB schema
-- Run: mysql -u root -p < database/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS botll;
CREATE DATABASE botll CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE botll;

CREATE TABLE roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(80) NOT NULL,
  role_key VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_name VARCHAR(120) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(180) NOT NULL UNIQUE,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_users_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  INDEX idx_users_role (role_id),
  INDEX idx_users_dept (department_id)
) ENGINE=InnoDB;

CREATE TABLE ticket_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(120) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE ticket_priorities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  priority_name VARCHAR(40) NOT NULL,
  priority_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_priority_name (priority_name)
) ENGINE=InnoDB;

CREATE TABLE ticket_statuses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status_name VARCHAR(50) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_number VARCHAR(32) NOT NULL UNIQUE,
  subject VARCHAR(255) NOT NULL,
  description MEDIUMTEXT NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  priority_id INT UNSIGNED NOT NULL,
  account_number VARCHAR(64) NULL,
  status_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  date_completed DATE NULL,
  sla_breach TINYINT(1) NOT NULL DEFAULT 0,
  attachments_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  response_time_minutes SMALLINT UNSIGNED NULL,
  is_late TINYINT(1) NOT NULL DEFAULT 0,
  csat_score DECIMAL(3,1) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tickets_cat FOREIGN KEY (category_id) REFERENCES ticket_categories(id),
  CONSTRAINT fk_tickets_pri FOREIGN KEY (priority_id) REFERENCES ticket_priorities(id),
  CONSTRAINT fk_tickets_stat FOREIGN KEY (status_id) REFERENCES ticket_statuses(id),
  CONSTRAINT fk_tickets_dept FOREIGN KEY (department_id) REFERENCES departments(id),
  CONSTRAINT fk_tickets_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_tickets_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tickets_status (status_id),
  INDEX idx_tickets_dept (department_id),
  INDEX idx_tickets_created (created_at),
  INDEX idx_tickets_creator (created_by),
  INDEX idx_tickets_number (ticket_number)
) ENGINE=InnoDB;

CREATE TABLE ticket_assignees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ta_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_ticket_user (ticket_id, user_id),
  INDEX idx_ta_ticket (ticket_id)
) ENGINE=InnoDB;

CREATE TABLE ticket_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_title VARCHAR(180) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  priority_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  description MEDIUMTEXT NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tt_cat FOREIGN KEY (category_id) REFERENCES ticket_categories(id),
  CONSTRAINT fk_tt_pri FOREIGN KEY (priority_id) REFERENCES ticket_priorities(id),
  CONSTRAINT fk_tt_dept FOREIGN KEY (department_id) REFERENCES departments(id),
  CONSTRAINT fk_tt_user FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_tt_dept (department_id)
) ENGINE=InnoDB;

CREATE TABLE ticket_approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  approver_id INT UNSIGNED NOT NULL,
  approval_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  comments TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tap_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tap_approver FOREIGN KEY (approver_id) REFERENCES users(id),
  INDEX idx_tap_ticket (ticket_id)
) ENGINE=InnoDB;

CREATE TABLE ticket_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_type VARCHAR(120) NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tatt_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tatt_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
  INDEX idx_tatt_ticket (ticket_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message VARCHAR(500) NOT NULL,
  notification_type VARCHAR(40) NOT NULL DEFAULT 'info',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notif_user_read (user_id, is_read),
  INDEX idx_notif_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE assistant_faqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(500) NOT NULL,
  answer MEDIUMTEXT NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'general',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
