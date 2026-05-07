-- Botll seed data — run after schema.sql
-- mysql -u root -p < database/seed.sql

SET NAMES utf8mb4;
USE botll;

-- Demo password for all accounts: password123
-- Bcrypt hash generated with PHP password_hash(..., PASSWORD_BCRYPT)
SET @pwd := '$2y$12$CvZsjhO60soXPObScnvnLegC7Ep9tot5HFhgfAyCl6/b4O9vg1/1G';

INSERT INTO roles (id, role_name, role_key, description) VALUES
(1, 'Super Admin', 'super_admin', 'Full system access'),
(2, 'Administrator', 'admin', 'Manage tickets, users, templates, reports'),
(3, 'Director', 'director', 'Department oversight and approvals'),
(4, 'Head of Department', 'hod', 'Department tickets, assign and approve'),
(5, 'User', 'user', 'Create and track own tickets');

INSERT INTO departments (id, department_name) VALUES
(1, 'Finance'),
(2, 'Human Resource'),
(3, 'IT'),
(4, 'Operations');

INSERT INTO users (id, full_name, email, username, password_hash, role_id, department_id, status) VALUES
(1, 'Super Admin User', 'superadmin@botll.local', 'superadmin', @pwd, 1, 3, 'active'),
(2, 'Alex Admin', 'admin@botll.local', 'admin', @pwd, 2, 3, 'active'),
(3, 'Dana Director', 'director@botll.local', 'director', @pwd, 3, 1, 'active'),
(4, 'Harper HOD', 'hod@botll.local', 'hod', @pwd, 4, 2, 'active'),
(5, 'Jordan User', 'user@botll.local', 'user', @pwd, 5, 2, 'active'),
(6, 'Freda Otilia', 'freda@botll.local', 'fredaotil', @pwd, 5, 1, 'active'),
(7, 'Joe Slack', 'joe@botll.local', 'joeslack', @pwd, 4, 1, 'active'),
(8, 'Kings D1', 'kings@botll.local', 'kingsd1', @pwd, 5, 3, 'active'),
(9, 'Trinkle, Maggie Diane', 'mtrinkle@botll.local', 'mtrinkle', @pwd, 5, 2, 'active');

INSERT INTO ticket_categories (id, category_name) VALUES
(1, 'Expense'),
(2, 'Human Resource Function'),
(3, 'System Access'),
(4, 'Procurement'),
(5, 'Payroll');

INSERT INTO ticket_priorities (id, priority_name, priority_level) VALUES
(1, 'Low', 1),
(2, 'Medium', 2),
(3, 'High', 3),
(4, 'Critical', 4);

INSERT INTO ticket_statuses (id, status_name) VALUES
(1, 'Open'),
(2, 'Completed'),
(3, 'Pending Approval'),
(4, 'Stuck'),
(5, 'Cancelled');

INSERT INTO ticket_templates (id, template_title, category_id, priority_id, department_id, description, created_by) VALUES
(1, 'Human Resource Functions', 2, 4, 2, 'Standard HR request template covering policy and access questions.', 2),
(2, 'Expense Reimbursement', 1, 3, 1, 'Use for employee reimbursements with receipts.', 2),
(3, 'IT Access Request', 3, 2, 3, 'Request VPN, SSO, or application access.', 2),
(4, 'New Hire Equipment', 4, 3, 4, 'Order workstations and peripherals for onboarding.', 2),
(5, 'Payroll Correction', 5, 2, 1, 'Request corrections to payroll or tax withholdings.', 2),
(6, 'Facilities Maintenance', 4, 2, 4, 'Report office maintenance or building access issues.', 2);

INSERT INTO assistant_faqs (question, answer, category) VALUES
('How do I create a ticket?', 'Go to Create Ticket from the left sidebar, choose a category and priority, enter the subject, account, and description, attach supporting files if needed, assign the request, then click Create.', 'tickets'),
('How do I check my ticket status?', 'Open All Tickets and locate your ticket by ID or use the search box. Status column shows the current state, including Pending Approval.', 'tickets'),
('What does SLA Breach mean?', 'SLA Breach means a ticket has passed the expected response or resolution time defined for the request type or priority level.', 'sla'),
('How do approvals work?', 'Approvers listed on your ticket may appear as Pending or Approved. Multiple approvers can be assigned; each line shows the current approval status.', 'approvals'),
('How do I use ticket templates?', 'Open Ticket Templates from the sidebar to browse saved templates. On Create Ticket you can apply a template to prefill category, priority, and description.', 'templates');

INSERT INTO notifications (user_id, title, message, notification_type, is_read) VALUES
(1, 'New ticket pending', 'Ticket TKT-2026-0042 requires your approval.', 'approval', 0),
(1, 'SLA warning', 'Ticket TKT-2026-0098 is approaching its SLA deadline.', 'warning', 0),
(5, 'Ticket updated', 'Your ticket TKT-2026-0007 was assigned to Kingsd1.', 'info', 1);

-- Bulk ticket generation: 156 tickets with distribution and KPI-friendly fields
DELIMITER $$
DROP PROCEDURE IF EXISTS botll_seed_ticket_bulk $$
CREATE PROCEDURE botll_seed_ticket_bulk()
BEGIN
  DECLARE i INT DEFAULT 1;
  DECLARE st INT;
  DECLARE dept INT;
  DECLARE cat INT;
  DECLARE pri INT;
  DECLARE creator INT;
  DECLARE assignee INT;
  DECLARE approver INT;
  DECLARE num VARCHAR(32);
  DECLARE completed DATE;
  DECLARE breach TINYINT;
  DECLARE late TINYINT;
  DECLARE resp SMALLINT;
  DECLARE attachc TINYINT;

  WHILE i <= 156 DO
    SET num = CONCAT('TKT-2026-', LPAD(CAST(i AS CHAR), 4, '0'));
    SET dept = 1 + (i MOD 4);
    SET cat = 1 + (i MOD 5);
    SET pri = 1 + (i MOD 4);

    -- Distribution: 24 Open, 118 Completed, 9 Stuck, 3 Cancelled, 2 Pending Approval = 156
    IF i <= 24 THEN SET st = 1;
    ELSEIF i <= 142 THEN SET st = 2;
    ELSEIF i <= 151 THEN SET st = 4;
    ELSEIF i <= 154 THEN SET st = 5;
    ELSE SET st = 3;
    END IF;

    SET creator = 5 + (i MOD 4);
    SET assignee = IF(i MOD 3 = 0, NULL, 8);
    SET approver = IF(st = 2 OR st = 3, 7, NULL);
    SET completed = IF(st = 2, DATE_SUB(CURDATE(), INTERVAL (i MOD 30) DAY), NULL);

    -- ~17 late tickets in the completed bucket band
    IF (st = 2) AND (i MOD 9 = 0) THEN
      SET late = 1; SET breach = 1; SET resp = 45 + (i MOD 20);
    ELSEIF st = 4 THEN
      SET late = 1; SET breach = IF(i MOD 2 = 0, 1, 0); SET resp = 60 + (i MOD 30);
    ELSE
      SET late = IF(st = 2 AND (i MOD 7 = 0), 1, 0);
      SET breach = IF(late = 1, 1, IF(i MOD 11 = 0, 1, 0));
      SET resp = IF(st = 2, 20 + (i MOD 25), NULL);
    END IF;

    IF st = 2 THEN
      SET attachc = IF(i MOD 4 = 0, 3, IF(i MOD 3 = 0, 1, 0));
    ELSE
      SET attachc = IF(i MOD 5 = 0, 1, 0);
    END IF;

    INSERT INTO tickets (
      ticket_number, subject, description, category_id, priority_id, account_number,
      status_id, department_id, created_by, assigned_to, approved_by,
      date_completed, sla_breach, attachments_count, response_time_minutes, is_late, csat_score,
      created_at
    ) VALUES (
      num,
      CONCAT('Request regarding ', cat, ' item ', i),
      CONCAT('Automated seed description for ticket #', i, '. Please review details and attachments.'),
      cat,
      pri,
      IF(i MOD 2 = 0, '35045554', NULL),
      st,
      dept,
      creator,
      assignee,
      approver,
      completed,
      breach,
      attachc,
      resp,
      late,
      IF(st = 2, 2.0 + ((i MOD 12) / 10), NULL),
      TIMESTAMP(DATE_SUB(NOW(), INTERVAL (i MOD 90) DAY))
    );

    SET i = i + 1;
  END WHILE;
END $$
DELIMITER ;

CALL botll_seed_ticket_bulk();
DROP PROCEDURE botll_seed_ticket_bulk;

-- Filled Create Ticket example (assignee approval rows)
UPDATE tickets SET subject = 'Infinite amount of money', description = 'I would like to have infinite amount of money that nobody ever bothers to ask what I am doing or researching. This is a long-form request used for UI demonstration.', category_id = 2, priority_id = 4, account_number = '35045554', status_id = 3 WHERE ticket_number = 'TKT-2026-0156';

INSERT INTO ticket_assignees (ticket_id, user_id, approval_status, sort_order)
SELECT id, 9, 'pending', 1 FROM tickets WHERE ticket_number = 'TKT-2026-0156'
ON DUPLICATE KEY UPDATE approval_status = VALUES(approval_status);

INSERT INTO ticket_assignees (ticket_id, user_id, approval_status, sort_order)
SELECT id, 6, 'approved', 2 FROM tickets WHERE ticket_number = 'TKT-2026-0156'
ON DUPLICATE KEY UPDATE approval_status = VALUES(approval_status);

-- Approvals row for sample
INSERT INTO ticket_approvals (ticket_id, approver_id, approval_level, approval_status)
SELECT id, 7, 1, 'pending' FROM tickets WHERE ticket_number = 'TKT-2026-0156';
