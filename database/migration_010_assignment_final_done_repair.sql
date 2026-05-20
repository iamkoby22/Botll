-- Repair assignment chains corrupted by final-Done failure (Level 1 wrongly reactivated).
-- Safe: does not reset passed/done rows to pending.
-- mysql -u root -p botll < database/migration_010_assignment_final_done_repair.sql

SET NAMES utf8mb4;
USE botll;

INSERT IGNORE INTO ticket_statuses (status_name) VALUES ('Closed');

-- Level 1 must not stay active when a higher sort_order row is already passed/done.
UPDATE ticket_assignees l1
INNER JOIN ticket_assignees later
  ON later.ticket_id = l1.ticket_id AND later.sort_order > l1.sort_order
SET l1.assignment_status = 'approved',
    l1.acted_at = COALESCE(l1.acted_at, NOW())
WHERE l1.assignment_status IN ('active', 'pending')
  AND later.assignment_status IN ('approved', 'done');

-- Close open tickets whose full assignment chain is already approved/done (awaiting audit).
UPDATE tickets t
INNER JOIN ticket_statuses s ON s.id = t.status_id
INNER JOIN (
  SELECT ta.ticket_id
  FROM ticket_assignees ta
  GROUP BY ta.ticket_id
  HAVING COUNT(*) > 1
  AND SUM(
    CASE WHEN ta.assignment_status NOT IN ('approved', 'done', 'completed') THEN 1 ELSE 0 END
  ) = 0
) chain_done ON chain_done.ticket_id = t.id
SET
  t.status_id = (SELECT id FROM ticket_statuses WHERE status_name = 'Closed' LIMIT 1),
  t.work_done_at = COALESCE(t.work_done_at, NOW()),
  t.conversation_closed_at = COALESCE(t.conversation_closed_at, NOW()),
  t.work_done_by = COALESCE(
    t.work_done_by,
    (
      SELECT ta2.user_id
      FROM ticket_assignees ta2
      WHERE ta2.ticket_id = t.id
      ORDER BY ta2.sort_order DESC, ta2.id DESC
      LIMIT 1
    )
  ),
  t.updated_at = NOW()
WHERE s.status_name NOT IN ('Closed', 'Completed', 'Rejected', 'Cancelled')
  AND (t.work_done_at IS NULL OR t.conversation_closed_at IS NULL);
