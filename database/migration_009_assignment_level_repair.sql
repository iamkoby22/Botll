-- Repair assignment workflow columns and active level (additive).
-- Run after 007. Ignore "Duplicate column" errors if columns already exist.
-- mysql -u root -p botll < database/migration_009_assignment_level_repair.sql

SET NAMES utf8mb4;
USE botll;

-- Sync level from sort_order
UPDATE ticket_assignees
SET assignment_level = GREATEST(COALESCE(NULLIF(sort_order, 0), 1), 1)
WHERE COALESCE(assignment_level, 0) < 1;

UPDATE ticket_assignees
SET assignment_level = sort_order
WHERE sort_order > 0 AND assignment_level = 1 AND sort_order <> 1;

UPDATE ticket_assignees SET assignment_status = 'pending'
WHERE assignment_status IS NULL OR assignment_status = '';

-- Tickets with multiple assignees and no active row: activate lowest sort_order
UPDATE ticket_assignees ta
INNER JOIN (
  SELECT ticket_id, MIN(COALESCE(NULLIF(sort_order, 0), assignment_level, 1)) AS min_sort
  FROM ticket_assignees
  GROUP BY ticket_id
  HAVING COUNT(*) > 1
) m ON m.ticket_id = ta.ticket_id
SET ta.assignment_status = 'pending'
WHERE ta.ticket_id IN (
  SELECT ticket_id FROM (
    SELECT ticket_id FROM ticket_assignees GROUP BY ticket_id HAVING SUM(assignment_status = 'active') = 0
  ) x
);

UPDATE ticket_assignees ta
INNER JOIN (
  SELECT ticket_id, MIN(COALESCE(NULLIF(sort_order, 0), assignment_level, 1)) AS min_sort
  FROM ticket_assignees
  GROUP BY ticket_id
  HAVING COUNT(*) > 1
) m ON m.ticket_id = ta.ticket_id
   AND COALESCE(NULLIF(ta.sort_order, 0), ta.assignment_level, 1) = m.min_sort
SET ta.assignment_status = 'active'
WHERE ta.ticket_id IN (
  SELECT ticket_id FROM (
    SELECT ticket_id FROM ticket_assignees GROUP BY ticket_id HAVING SUM(assignment_status = 'active') = 0
  ) x
);
