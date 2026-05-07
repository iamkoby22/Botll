-- Backfill assignee-based approvals (additive; safe to run once).
-- mysql -u root -p botll < database/migration_004_assignee_approval_backfill.sql

SET NAMES utf8mb4;
USE botll;

-- If a ticket has an assigned user (tickets.assigned_to) but no approval records, create a pending approval for that assignee.
INSERT INTO ticket_approvals (ticket_id, approver_id, approval_level, approval_status)
SELECT t.id, t.assigned_to, 1, 'pending'
FROM tickets t
LEFT JOIN ticket_approvals ta ON ta.ticket_id = t.id
WHERE t.assigned_to IS NOT NULL
  AND ta.id IS NULL;

-- If a ticket has no tickets.assigned_to but has ticket_assignees, pick the lowest sort_order assignee as approver when no approvals exist.
INSERT INTO ticket_approvals (ticket_id, approver_id, approval_level, approval_status)
SELECT x.ticket_id, x.user_id, 1, 'pending'
FROM (
  SELECT ta.ticket_id, ta.user_id
  FROM ticket_assignees ta
  JOIN (
    SELECT ticket_id, MIN(sort_order) min_sort
    FROM ticket_assignees
    GROUP BY ticket_id
  ) m ON m.ticket_id = ta.ticket_id AND m.min_sort = ta.sort_order
) x
LEFT JOIN ticket_approvals ex ON ex.ticket_id = x.ticket_id
WHERE ex.id IS NULL;

-- If approvals were backfilled, make sure ticket status reflects it (do not overwrite completed/cancelled/stuck).
UPDATE tickets t
JOIN ticket_statuses s ON s.id = t.status_id
SET t.status_id = (SELECT id FROM ticket_statuses WHERE status_name='Pending Approval' LIMIT 1)
WHERE s.status_name = 'Open'
  AND EXISTS (SELECT 1 FROM ticket_approvals tap WHERE tap.ticket_id = t.id AND tap.approval_status='pending');

