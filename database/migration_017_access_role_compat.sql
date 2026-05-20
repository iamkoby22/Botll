-- Ensure superadmin role alias and core role keys exist (idempotent).
INSERT INTO roles (role_name, role_key, description)
SELECT 'Super Admin', 'super_admin', 'Full platform access'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key IN ('super_admin', 'superadmin'));

UPDATE users u
JOIN roles r ON r.role_key IN ('super_admin', 'superadmin')
SET u.role_id = r.id
WHERE u.username IN ('superadmin', 'super_admin')
  AND u.role_id IS NULL;

UPDATE users u
SET u.role_id = (SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1)
WHERE u.username IN ('superadmin', 'super_admin')
  AND EXISTS (SELECT 1 FROM roles WHERE role_key = 'super_admin')
  AND (u.role_id IS NULL OR u.role_id NOT IN (SELECT id FROM roles WHERE role_key IN ('super_admin', 'superadmin')));
