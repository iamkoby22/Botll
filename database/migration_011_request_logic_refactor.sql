-- Botll: Request Logic refactor — departments, request_logic, dynamic intake (additive).
-- mysql -u root -p botll < database/migration_011_request_logic_refactor.sql
--
-- If this migration fails partway (e.g. ERROR 1060 duplicate column), do NOT re-run it.
-- Use instead: database/migration_012_request_logic_repair.sql

SET NAMES utf8mb4;
USE botll;

-- Departments: extended reference data
ALTER TABLE departments
  ADD COLUMN department_number VARCHAR(20) NULL DEFAULT NULL AFTER department_name,
  ADD COLUMN organization_code VARCHAR(20) NULL DEFAULT NULL AFTER department_number;

ALTER TABLE departments
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE departments
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL;

-- Tickets: business request path snapshots
ALTER TABLE tickets
  ADD COLUMN request_logic_id INT UNSIGNED NULL DEFAULT NULL AFTER department_id,
  ADD COLUMN requester_name_snapshot VARCHAR(160) NULL DEFAULT NULL,
  ADD COLUMN requester_email_snapshot VARCHAR(180) NULL DEFAULT NULL,
  ADD COLUMN request_type VARCHAR(200) NULL DEFAULT NULL,
  ADD COLUMN request_step1 VARCHAR(200) NULL DEFAULT NULL,
  ADD COLUMN request_step2 VARCHAR(200) NULL DEFAULT NULL;

-- Field values: support request logic answers (template_id kept for legacy)
ALTER TABLE ticket_field_values
  MODIFY template_id INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE ticket_field_values
  ADD COLUMN request_logic_id INT UNSIGNED NULL DEFAULT NULL AFTER template_id,
  ADD COLUMN request_logic_field_id INT UNSIGNED NULL DEFAULT NULL AFTER request_logic_id;

CREATE TABLE IF NOT EXISTS request_logic (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_type VARCHAR(200) NOT NULL,
  step1 VARCHAR(200) NOT NULL DEFAULT '',
  step2 VARCHAR(200) NULL DEFAULT NULL,
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS request_logic_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_logic_id INT UNSIGNED NOT NULL,
  field_label VARCHAR(200) NOT NULL,
  field_key VARCHAR(80) NOT NULL,
  field_type VARCHAR(40) NOT NULL DEFAULT 'text',
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
  CONSTRAINT fk_rlf_logic FOREIGN KEY (request_logic_id) REFERENCES request_logic(id) ON DELETE CASCADE,
  INDEX idx_rlf_logic (request_logic_id)
) ENGINE=InnoDB;

SET @cat_expense := (SELECT id FROM ticket_categories WHERE category_name = 'Expense' LIMIT 1);
SET @pri_normal := (SELECT id FROM ticket_priorities WHERE priority_name = 'Medium' LIMIT 1);
SET @pri_normal := IFNULL(@pri_normal, (SELECT id FROM ticket_priorities ORDER BY priority_level LIMIT 1));

-- SBS department seed (upsert by name)
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
  is_active = 1;

-- Request logic paths (only if table empty)
INSERT INTO request_logic (request_type, step1, step2, display_order, default_category_id, default_priority_id, is_active)
SELECT * FROM (
  SELECT 'Purchasing and Financial Support' AS request_type, 'Pcard' AS step1, NULL AS step2, 10 AS display_order, @cat_expense, @pri_normal, 1
  UNION SELECT 'Purchasing and Financial Support', 'Purchase Order', NULL, 11, @cat_expense, @pri_normal, 1
  UNION SELECT 'Purchasing and Financial Support', 'Operational Advance', NULL, 12, @cat_expense, @pri_normal, 1
  UNION SELECT 'Purchasing and Financial Support', 'Pay an Invoice', NULL, 13, @cat_expense, @pri_normal, 1
  UNION SELECT 'Purchasing and Financial Support', 'Deposit Cash/Checks', NULL, 14, @cat_expense, @pri_normal, 1
  UNION SELECT 'Non-Travel Reimbursement', '', NULL, 20, @cat_expense, @pri_normal, 1
  UNION SELECT 'Travel & Related Reimbursements', 'Travel', 'Travel Advance Support', 30, @cat_expense, @pri_normal, 1
  UNION SELECT 'Travel & Related Reimbursements', 'Travel', 'Travel Advance Reimbursement', 31, @cat_expense, @pri_normal, 1
  UNION SELECT 'Travel & Related Reimbursements', 'Travel', 'Concur Support', 32, @cat_expense, @pri_normal, 1
  UNION SELECT 'Travel & Related Reimbursements', 'Travel', 'Other', 33, @cat_expense, @pri_normal, 1
  UNION SELECT 'Grant Support', 'Pre-Award', NULL, 40, @cat_expense, @pri_normal, 1
  UNION SELECT 'Grant Support', 'Post-Award', NULL, 41, @cat_expense, @pri_normal, 1
  UNION SELECT 'Human Resources Functions', 'HR Functions', 'Recruitment and New Hires', 50, @cat_expense, @pri_normal, 1
  UNION SELECT 'Human Resources Functions', 'HR Functions', 'Funding Changes', 51, @cat_expense, @pri_normal, 1
  UNION SELECT 'Human Resources Functions', 'HR Functions', 'Job Attribute Changes/Modifications', 52, @cat_expense, @pri_normal, 1
  UNION SELECT 'Human Resources Functions', 'HR Functions', 'Additional Compensation Request', 53, @cat_expense, @pri_normal, 1
  UNION SELECT 'Human Resources Functions', 'HR Functions', 'Termination', 54, @cat_expense, @pri_normal, 1
  UNION SELECT 'Human Resources Functions', 'HR Functions', 'DCC Assistance', 55, @cat_expense, @pri_normal, 1
  UNION SELECT 'Other Financial Support', 'Budget Remaining Inquiry', NULL, 60, @cat_expense, @pri_normal, 1
  UNION SELECT 'Other Financial Support', 'Contract Signature Request', NULL, 61, @cat_expense, @pri_normal, 1
  UNION SELECT 'Other Financial Support', 'Other Not Listed', NULL, 62, @cat_expense, @pri_normal, 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM request_logic LIMIT 1);

-- Helper: seed standard fields for a logic path id
-- grant + account + request (purchasing, other financial post paths)
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, display_order)
SELECT rl.id, 'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?', 'grant_funds', 'radio', 'Yes\nNo', 1, 1
FROM request_logic rl
WHERE rl.request_type = 'Purchasing and Financial Support'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Account/Sub-Account (If Known)', 'account_sub', 'text', 0, 2
FROM request_logic rl
WHERE rl.request_type = 'Purchasing and Financial Support'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'account_sub');

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 3
FROM request_logic rl
WHERE rl.request_type = 'Purchasing and Financial Support'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'request_information');

-- Non-Travel
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, display_order)
SELECT rl.id, 'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?', 'grant_funds', 'radio', 'Yes\nNo', 1, 1
FROM request_logic rl WHERE rl.request_type = 'Non-Travel Reimbursement'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Account/Sub-Account (If Known)', 'account_sub', 'text', 0, 2
FROM request_logic rl WHERE rl.request_type = 'Non-Travel Reimbursement'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'account_sub');

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 3
FROM request_logic rl WHERE rl.request_type = 'Non-Travel Reimbursement'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'request_information');

-- Travel paths
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, display_order)
SELECT rl.id, 'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?', 'grant_funds', 'radio', 'Yes\nNo', 1, 1
FROM request_logic rl WHERE rl.request_type = 'Travel & Related Reimbursements'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 2
FROM request_logic rl WHERE rl.request_type = 'Travel & Related Reimbursements'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'request_information');

-- Grant Pre-Award instruction
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, instruction_text, is_required, display_order)
SELECT rl.id, 'Pre-Award guidance', 'pre_award_instruction', 'instruction',
'Pre-Award support is managed via the SBS Pre-Award team in conjunction with SBSRI. More information on the process, including Proposal Submission instructions, can be found here: https://sbsri.sbs.arizona.edu/proposal-submission. Please navigate to this link to continue the process. If you need additional assistance, please continue submitting this service ticket, including any additional information below. Otherwise, no submission of this service ticket is needed.',
0, 1
FROM request_logic rl WHERE rl.request_type = 'Grant Support' AND rl.step1 = 'Pre-Award'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 0, 2
FROM request_logic rl WHERE rl.request_type = 'Grant Support' AND rl.step1 = 'Pre-Award'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'request_information');

-- Grant Post-Award
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Account/Sub-Account (If Known)', 'account_sub', 'text', 0, 1
FROM request_logic rl WHERE rl.request_type = 'Grant Support' AND rl.step1 = 'Post-Award'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 2
FROM request_logic rl WHERE rl.request_type = 'Grant Support' AND rl.step1 = 'Post-Award'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'request_information');

-- HR paths
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, display_order)
SELECT rl.id, 'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?', 'grant_funds', 'radio', 'Yes\nNo', 1, 1
FROM request_logic rl WHERE rl.request_type = 'Human Resources Functions'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 2
FROM request_logic rl WHERE rl.request_type = 'Human Resources Functions'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'request_information');

-- Other Financial Support
INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Account/Sub-Account (If Known)', 'account_sub', 'text', 0, 1
FROM request_logic rl WHERE rl.request_type = 'Other Financial Support'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id);

INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, is_required, display_order)
SELECT rl.id, 'Request Information', 'request_information', 'textarea', 1, 2
FROM request_logic rl WHERE rl.request_type = 'Other Financial Support'
  AND NOT EXISTS (SELECT 1 FROM request_logic_fields f WHERE f.request_logic_id = rl.id AND f.field_key = 'request_information');
