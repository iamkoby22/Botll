-- Botll repair: complete request_logic setup after partial migration_011 failure.
-- Safe to run multiple times (checks information_schema before ALTER).
-- Uses utf8mb4_unicode_ci on all string compares (avoids MySQL 8 mixed-collation errors).
-- mysql -u root -p botll < database/migration_012_request_logic_repair.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
USE botll;

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- Helper: add column only when missing
-- ---------------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'department_number') = 0,
    'ALTER TABLE departments ADD COLUMN department_number VARCHAR(20) NULL DEFAULT NULL AFTER department_name',
    'SELECT ''skip departments.department_number'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'organization_code') = 0,
    'ALTER TABLE departments ADD COLUMN organization_code VARCHAR(20) NULL DEFAULT NULL AFTER department_number',
    'SELECT ''skip departments.organization_code'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'is_active') = 0,
    'ALTER TABLE departments ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT ''skip departments.is_active'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'deleted_at') = 0,
    'ALTER TABLE departments ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL',
    'SELECT ''skip departments.deleted_at'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'deleted_by') = 0,
    'ALTER TABLE departments ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL',
    'SELECT ''skip departments.deleted_by'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

-- tickets: request path columns
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'request_logic_id') = 0,
    'ALTER TABLE tickets ADD COLUMN request_logic_id INT UNSIGNED NULL DEFAULT NULL AFTER department_id',
    'SELECT ''skip tickets.request_logic_id'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'requester_name_snapshot') = 0,
    'ALTER TABLE tickets ADD COLUMN requester_name_snapshot VARCHAR(160) NULL DEFAULT NULL',
    'SELECT ''skip tickets.requester_name_snapshot'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'requester_email_snapshot') = 0,
    'ALTER TABLE tickets ADD COLUMN requester_email_snapshot VARCHAR(180) NULL DEFAULT NULL',
    'SELECT ''skip tickets.requester_email_snapshot'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'request_type') = 0,
    'ALTER TABLE tickets ADD COLUMN request_type VARCHAR(200) NULL DEFAULT NULL',
    'SELECT ''skip tickets.request_type'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'request_step1') = 0,
    'ALTER TABLE tickets ADD COLUMN request_step1 VARCHAR(200) NULL DEFAULT NULL',
    'SELECT ''skip tickets.request_step1'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'request_step2') = 0,
    'ALTER TABLE tickets ADD COLUMN request_step2 VARCHAR(200) NULL DEFAULT NULL',
    'SELECT ''skip tickets.request_step2'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

-- ticket_field_values (table may exist from migration_005)
SET @tfv_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ticket_field_values'
);

SET @sql := (
  SELECT IF(
    @tfv_exists > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ticket_field_values' AND COLUMN_NAME = 'template_id') > 0
    AND (SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ticket_field_values' AND COLUMN_NAME = 'template_id') = 'NO',
    'ALTER TABLE ticket_field_values MODIFY template_id INT UNSIGNED NULL DEFAULT NULL',
    'SELECT ''skip ticket_field_values.template_id modify'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    @tfv_exists > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ticket_field_values' AND COLUMN_NAME = 'request_logic_id') = 0,
    'ALTER TABLE ticket_field_values ADD COLUMN request_logic_id INT UNSIGNED NULL DEFAULT NULL',
    'SELECT ''skip ticket_field_values.request_logic_id'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @sql := (
  SELECT IF(
    @tfv_exists > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ticket_field_values' AND COLUMN_NAME = 'request_logic_field_id') = 0,
    'ALTER TABLE ticket_field_values ADD COLUMN request_logic_field_id INT UNSIGNED NULL DEFAULT NULL',
    'SELECT ''skip ticket_field_values.request_logic_field_id'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

-- ---------------------------------------------------------------------------
-- Core tables
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS request_logic (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_type VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  step1 VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  step2 VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  default_category_id INT UNSIGNED NULL DEFAULT NULL,
  default_priority_id INT UNSIGNED NULL DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  deleted_by INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rl_type (request_type),
  INDEX idx_rl_active (is_active),
  INDEX idx_rl_path (request_type, step1, step2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_logic_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_logic_id INT UNSIGNED NOT NULL,
  field_label VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  field_key VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  field_type VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  field_options TEXT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  help_text TEXT NULL,
  instruction_text MEDIUMTEXT NULL,
  display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  deleted_by INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rlf_logic (request_logic_id),
  INDEX idx_rlf_logic_key (request_logic_id, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Normalize collation if tables were created earlier with a different default
ALTER TABLE request_logic CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE request_logic_fields CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Add FK only if missing (table may have been created without it)
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db
    AND TABLE_NAME = 'request_logic_fields'
    AND CONSTRAINT_NAME = 'fk_rlf_logic'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := (
  SELECT IF(
    @fk_exists = 0,
    'ALTER TABLE request_logic_fields ADD CONSTRAINT fk_rlf_logic FOREIGN KEY (request_logic_id) REFERENCES request_logic(id) ON DELETE CASCADE',
    'SELECT ''skip fk_rlf_logic'' AS info'
  )
);
PREPARE botll_stmt FROM @sql;
EXECUTE botll_stmt;
DEALLOCATE PREPARE botll_stmt;

SET @cat_expense := (
  SELECT id FROM ticket_categories
  WHERE category_name COLLATE utf8mb4_unicode_ci = 'Expense' COLLATE utf8mb4_unicode_ci
  LIMIT 1
);
SET @pri_normal := (
  SELECT id FROM ticket_priorities
  WHERE priority_name COLLATE utf8mb4_unicode_ci = 'Medium' COLLATE utf8mb4_unicode_ci
  LIMIT 1
);
SET @pri_normal := IFNULL(@pri_normal, (SELECT id FROM ticket_priorities ORDER BY priority_level LIMIT 1));

-- ---------------------------------------------------------------------------
-- Departments (22 SBS rows) — upsert by unique department_name
-- ---------------------------------------------------------------------------
INSERT INTO departments (department_name, department_number, organization_code, is_active) VALUES
('School of Anthropology', '410', '0410', 1),
('Bur of Applied Rsch in Anthro', '414', '0414', 1),
('History', '415', '0415', 1),
('Sch Middle E/N African Studies', '416', '0416', 1),
('Sociology', '418', '0418', 1),
('Journalism', '419', '0419', 1),
('Philosophy', '428', '0428', 1),
('English', '429', '0429', 1),
('Linguistics', '431', '0431', 1),
('Mexican American Studies', '432', '0432', 1),
('Gender and Womens Studies', '433', '0433', 1),
('Latin American Area Center', '437', '0437', 1),
('Social & Behavioral Sci Admin', '443', '0443', 1),
('SW Institute for Rsch on Women', '445', '0445', 1),
('Southwest Studies Center', '447', '0447', 1),
('Ctr for Middle Eastern Studies', '448', '0448', 1),
('AZ Center for Judaic Studies', '457', '0457', 1),
('School of Govt & Public Policy', '465', '0465', 1),
('Political Economy & Moral Sci', '476', '0476', 1),
('Sch Geography, Dev & Environ', '3008', '3008', 1),
('Communication', '3505', '3505', 1),
('American Indian Studies Prog', '9006', '9006', 1),
('Global Studies', '477', '0477', 1)
ON DUPLICATE KEY UPDATE
  department_number = VALUES(department_number),
  organization_code = VALUES(organization_code),
  is_active = COALESCE(VALUES(is_active), 1);

-- Backfill number/org on existing rows matched by name
UPDATE departments d
INNER JOIN (
  SELECT 'School of Anthropology' AS department_name, '410' AS department_number, '0410' AS organization_code UNION ALL
  SELECT 'Bur of Applied Rsch in Anthro', '414', '0414' UNION ALL
  SELECT 'History', '415', '0415' UNION ALL
  SELECT 'Sch Middle E/N African Studies', '416', '0416' UNION ALL
  SELECT 'Sociology', '418', '0418' UNION ALL
  SELECT 'Journalism', '419', '0419' UNION ALL
  SELECT 'Philosophy', '428', '0428' UNION ALL
  SELECT 'English', '429', '0429' UNION ALL
  SELECT 'Linguistics', '431', '0431' UNION ALL
  SELECT 'Mexican American Studies', '432', '0432' UNION ALL
  SELECT 'Gender and Womens Studies', '433', '0433' UNION ALL
  SELECT 'Latin American Area Center', '437', '0437' UNION ALL
  SELECT 'Social & Behavioral Sci Admin', '443', '0443' UNION ALL
  SELECT 'SW Institute for Rsch on Women', '445', '0445' UNION ALL
  SELECT 'Southwest Studies Center', '447', '0447' UNION ALL
  SELECT 'Ctr for Middle Eastern Studies', '448', '0448' UNION ALL
  SELECT 'AZ Center for Judaic Studies', '457', '0457' UNION ALL
  SELECT 'School of Govt & Public Policy', '465', '0465' UNION ALL
  SELECT 'Political Economy & Moral Sci', '476', '0476' UNION ALL
  SELECT 'Sch Geography, Dev & Environ', '3008', '3008' UNION ALL
  SELECT 'Communication', '3505', '3505' UNION ALL
  SELECT 'American Indian Studies Prog', '9006', '9006' UNION ALL
  SELECT 'Global Studies', '477', '0477'
) seed ON seed.department_name COLLATE utf8mb4_unicode_ci = d.department_name COLLATE utf8mb4_unicode_ci
SET
  d.department_number = seed.department_number,
  d.organization_code = seed.organization_code,
  d.is_active = 1
WHERE d.department_number IS NULL OR d.department_number = '' OR d.organization_code IS NULL OR d.organization_code = '';

-- ---------------------------------------------------------------------------
-- Request logic paths (21) — insert only missing paths
-- ---------------------------------------------------------------------------
INSERT INTO request_logic (request_type, step1, step2, display_order, default_category_id, default_priority_id, is_active)
SELECT s.request_type, s.step1, s.step2, s.display_order, @cat_expense, @pri_normal, 1
FROM (
  SELECT 'Purchasing and Financial Support' AS request_type, 'Pcard' AS step1, CAST(NULL AS CHAR(200)) AS step2, 10 AS display_order
  UNION ALL SELECT 'Purchasing and Financial Support', 'Purchase Order', NULL, 11
  UNION ALL SELECT 'Purchasing and Financial Support', 'Operational Advance', NULL, 12
  UNION ALL SELECT 'Purchasing and Financial Support', 'Pay an Invoice', NULL, 13
  UNION ALL SELECT 'Purchasing and Financial Support', 'Deposit Cash/Checks', NULL, 14
  UNION ALL SELECT 'Non-Travel Reimbursement', '', NULL, 20
  UNION ALL SELECT 'Travel & Related Reimbursements', 'Travel', 'Travel Advance Support', 30
  UNION ALL SELECT 'Travel & Related Reimbursements', 'Travel', 'Travel Advance Reimbursement', 31
  UNION ALL SELECT 'Travel & Related Reimbursements', 'Travel', 'Concur Support', 32
  UNION ALL SELECT 'Travel & Related Reimbursements', 'Travel', 'Other', 33
  UNION ALL SELECT 'Grant Support', 'Pre-Award', NULL, 40
  UNION ALL SELECT 'Grant Support', 'Post-Award', NULL, 41
  UNION ALL SELECT 'Human Resources Functions', 'HR Functions', 'Recruitment and New Hires', 50
  UNION ALL SELECT 'Human Resources Functions', 'HR Functions', 'Funding Changes', 51
  UNION ALL SELECT 'Human Resources Functions', 'HR Functions', 'Job Attribute Changes/Modifications', 52
  UNION ALL SELECT 'Human Resources Functions', 'HR Functions', 'Additional Compensation Request', 53
  UNION ALL SELECT 'Human Resources Functions', 'HR Functions', 'Termination', 54
  UNION ALL SELECT 'Human Resources Functions', 'HR Functions', 'DCC Assistance', 55
  UNION ALL SELECT 'Other Financial Support', 'Budget Remaining Inquiry', NULL, 60
  UNION ALL SELECT 'Other Financial Support', 'Contract Signature Request', NULL, 61
  UNION ALL SELECT 'Other Financial Support', 'Other Not Listed', NULL, 62
) s
LEFT JOIN request_logic rl
  ON rl.request_type COLLATE utf8mb4_unicode_ci = s.request_type COLLATE utf8mb4_unicode_ci
 AND COALESCE(rl.step1, '') COLLATE utf8mb4_unicode_ci = COALESCE(s.step1, '') COLLATE utf8mb4_unicode_ci
 AND COALESCE(rl.step2, '') COLLATE utf8mb4_unicode_ci = COALESCE(s.step2, '') COLLATE utf8mb4_unicode_ci
WHERE rl.id IS NULL;

-- ---------------------------------------------------------------------------
-- Request logic fields — per path + field_key
-- ---------------------------------------------------------------------------

-- Purchasing paths (each path row)
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, display_order)
SELECT rl.id, 'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?', 'grant_funds', 'radio', 'Yes\nNo', 1, 1
FROM request_logic rl
WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Purchasing and Financial Support' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'grant_funds' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Account/Sub-Account (If Known)', 'account_sub', 'text', 0, 2
FROM request_logic rl
WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Purchasing and Financial Support' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'account_sub' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 3
FROM request_logic rl
WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Purchasing and Financial Support' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'request_information' COLLATE utf8mb4_unicode_ci);

-- Non-Travel
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, display_order)
SELECT rl.id, 'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?', 'grant_funds', 'radio', 'Yes\nNo', 1, 1
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Non-Travel Reimbursement' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'grant_funds' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Account/Sub-Account (If Known)', 'account_sub', 'text', 0, 2
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Non-Travel Reimbursement' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'account_sub' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 3
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Non-Travel Reimbursement' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'request_information' COLLATE utf8mb4_unicode_ci);

-- Travel
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, display_order)
SELECT rl.id, 'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?', 'grant_funds', 'radio', 'Yes\nNo', 1, 1
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Travel & Related Reimbursements' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'grant_funds' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 2
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Travel & Related Reimbursements' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'request_information' COLLATE utf8mb4_unicode_ci);

-- Grant Pre-Award
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, instruction_text, is_required, display_order)
SELECT rl.id, 'Pre-Award guidance', 'pre_award_instruction', 'instruction',
'Pre-Award support is managed via the SBS Pre-Award team in conjunction with SBSRI. More information on the process, including Proposal Submission instructions, can be found here: https://sbsri.sbs.arizona.edu/proposal-submission. Please navigate to this link to continue the process. If you need additional assistance, please continue submitting this service ticket, including any additional information below. Otherwise, no submission of this service ticket is needed.',
0, 1
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Grant Support' COLLATE utf8mb4_unicode_ci AND rl.step1 COLLATE utf8mb4_unicode_ci = 'Pre-Award' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'pre_award_instruction' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 0, 2
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Grant Support' COLLATE utf8mb4_unicode_ci AND rl.step1 COLLATE utf8mb4_unicode_ci = 'Pre-Award' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'request_information' COLLATE utf8mb4_unicode_ci);

-- Grant Post-Award
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Account/Sub-Account (If Known)', 'account_sub', 'text', 0, 1
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Grant Support' COLLATE utf8mb4_unicode_ci AND rl.step1 COLLATE utf8mb4_unicode_ci = 'Post-Award' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'account_sub' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 2
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Grant Support' COLLATE utf8mb4_unicode_ci AND rl.step1 COLLATE utf8mb4_unicode_ci = 'Post-Award' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'request_information' COLLATE utf8mb4_unicode_ci);

-- HR
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, display_order)
SELECT rl.id, 'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?', 'grant_funds', 'radio', 'Yes\nNo', 1, 1
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Human Resources Functions' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'grant_funds' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 2
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Human Resources Functions' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'request_information' COLLATE utf8mb4_unicode_ci);

-- Other Financial Support
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Account/Sub-Account (If Known)', 'account_sub', 'text', 0, 1
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Other Financial Support' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'account_sub' COLLATE utf8mb4_unicode_ci);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 2
FROM request_logic rl WHERE rl.request_type COLLATE utf8mb4_unicode_ci = 'Other Financial Support' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key COLLATE utf8mb4_unicode_ci = 'request_information' COLLATE utf8mb4_unicode_ci);

-- ---------------------------------------------------------------------------
-- Verification summary (visible in mysql client)
-- ---------------------------------------------------------------------------
SELECT 'request_logic_table' AS check_name,
       IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'request_logic') > 0, 'OK', 'MISSING') AS status;

SELECT 'request_logic_paths' AS check_name, COUNT(*) AS path_count FROM request_logic;

SELECT 'request_logic_fields' AS check_name, COUNT(*) AS field_count FROM request_logic_fields;

SELECT 'request_types' AS check_name, COUNT(DISTINCT request_type) AS type_count FROM request_logic WHERE deleted_at IS NULL;
