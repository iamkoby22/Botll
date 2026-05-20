-- SBS access via roles.role_key (not users.user_level). Idempotent.
-- mysql -u root -p botll < database/migration_016_roles_based_sbs_access.sql

SET @pwd = '$2y$12$CvZsjhO60soXPObScnvnLegC7Ep9tot5HFhgfAyCl6/b4O9vg1/1G';

INSERT INTO roles (role_name, role_key, description)
SELECT 'Faculty/Staff', 'faculty_staff', 'Submit and track own SBS requests'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key = 'faculty_staff');

INSERT INTO roles (role_name, role_key, description)
SELECT 'Restricted Pillar Admin', 'restricted_pillar_admin', 'Restricted account route pillar admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key = 'restricted_pillar_admin');

INSERT INTO roles (role_name, role_key, description)
SELECT 'Unrestricted Pillar Admin', 'unrestricted_pillar_admin', 'Unrestricted account route pillar admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key = 'unrestricted_pillar_admin');

INSERT INTO roles (role_name, role_key, description)
SELECT 'General Pillar Admin', 'general_pillar_admin', 'General / not sure account route pillar admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key = 'general_pillar_admin');

INSERT INTO roles (role_name, role_key, description)
SELECT 'Business Admin', 'business_admin', 'Assigned business admin worker'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key = 'business_admin');

INSERT INTO roles (role_name, role_key, description)
SELECT 'Coordinator', 'coordinator', 'Assigned coordinator worker'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key = 'coordinator');

-- Grant-funds radio: Yes / No / Not Sure on all matching fields
UPDATE request_logic_fields
SET field_options = 'Yes\nNo\nNot Sure'
WHERE field_key = 'grant_funds'
  AND (field_options IS NULL OR field_options NOT LIKE '%Not Sure%');

UPDATE request_logic_fields
SET field_options = 'Yes\nNo\nNot Sure'
WHERE field_label LIKE '%utilizing funds from Grant, Sponsored, TRIF%'
  AND field_type IN ('radio', 'select')
  AND (field_options IS NULL OR field_options NOT LIKE '%Not Sure%');

-- Demo users: assign role_id by username (roles are authority, not user_level)
UPDATE users u
JOIN roles r ON r.role_key = 'super_admin'
SET u.role_id = r.id
WHERE u.username IN ('superadmin', 'super_admin');

UPDATE users u JOIN roles r ON r.role_key = 'restricted_pillar_admin' SET u.role_id = r.id
WHERE u.username IN ('restricted_pillar', 'restricted_pillar2');

UPDATE users u JOIN roles r ON r.role_key = 'unrestricted_pillar_admin' SET u.role_id = r.id
WHERE u.username IN ('unrestricted_pillar', 'unrestricted_pillar2');

UPDATE users u JOIN roles r ON r.role_key = 'general_pillar_admin' SET u.role_id = r.id
WHERE u.username IN ('general_pillar', 'general_pillar2');

UPDATE users u JOIN roles r ON r.role_key = 'business_admin' SET u.role_id = r.id
WHERE u.username IN ('business_admin1', 'business_admin2');

UPDATE users u JOIN roles r ON r.role_key = 'coordinator' SET u.role_id = r.id
WHERE u.username IN ('coordinator1', 'coordinator2');

UPDATE users u JOIN roles r ON r.role_key = 'faculty_staff' SET u.role_id = r.id
WHERE u.username IN ('faculty_user1', 'faculty_user2');

-- Seed demo users if missing (role via role_id only)
INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Restricted Pillar Admin', 'restricted.pillar@botll.local', 'restricted_pillar', @pwd,
       (SELECT id FROM roles WHERE role_key='restricted_pillar_admin' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='restricted_pillar');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Restricted Pillar Admin Two', 'restricted.pillar2@botll.local', 'restricted_pillar2', @pwd,
       (SELECT id FROM roles WHERE role_key='restricted_pillar_admin' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='restricted_pillar2');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Unrestricted Pillar Admin', 'unrestricted.pillar@botll.local', 'unrestricted_pillar', @pwd,
       (SELECT id FROM roles WHERE role_key='unrestricted_pillar_admin' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='unrestricted_pillar');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Unrestricted Pillar Admin Two', 'unrestricted.pillar2@botll.local', 'unrestricted_pillar2', @pwd,
       (SELECT id FROM roles WHERE role_key='unrestricted_pillar_admin' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='unrestricted_pillar2');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'General Pillar Admin', 'general.pillar@botll.local', 'general_pillar', @pwd,
       (SELECT id FROM roles WHERE role_key='general_pillar_admin' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='general_pillar');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'General Pillar Admin Two', 'general.pillar2@botll.local', 'general_pillar2', @pwd,
       (SELECT id FROM roles WHERE role_key='general_pillar_admin' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='general_pillar2');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Business Admin One', 'business.admin1@botll.local', 'business_admin1', @pwd,
       (SELECT id FROM roles WHERE role_key='business_admin' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='business_admin1');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Business Admin Two', 'business.admin2@botll.local', 'business_admin2', @pwd,
       (SELECT id FROM roles WHERE role_key='business_admin' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='business_admin2');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Coordinator One', 'coordinator1@botll.local', 'coordinator1', @pwd,
       (SELECT id FROM roles WHERE role_key='coordinator' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='coordinator1');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Coordinator Two', 'coordinator2@botll.local', 'coordinator2', @pwd,
       (SELECT id FROM roles WHERE role_key='coordinator' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='coordinator2');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Faculty User One', 'faculty.user1@botll.local', 'faculty_user1', @pwd,
       (SELECT id FROM roles WHERE role_key='faculty_staff' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='faculty_user1');

INSERT INTO users (full_name, email, username, password_hash, role_id, status)
SELECT 'Faculty User Two', 'faculty.user2@botll.local', 'faculty_user2', @pwd,
       (SELECT id FROM roles WHERE role_key='faculty_staff' LIMIT 1), 'active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='faculty_user2');
