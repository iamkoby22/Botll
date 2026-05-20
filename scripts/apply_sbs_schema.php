<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();

function col_exists(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $col]);
    return (bool) $st->fetchColumn();
}

function add_col(PDO $pdo, string $table, string $ddl): void
{
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
    echo "Added column on $table: $ddl\n";
}

$ticketCols = [
    'account_route' => 'VARCHAR(32) NULL',
    'routed_pillar' => 'VARCHAR(64) NULL',
    'routed_at' => 'DATETIME NULL',
    'assigned_type' => 'VARCHAR(32) NULL',
    'final_completed_at' => 'DATETIME NULL',
    'final_completed_by' => 'INT UNSIGNED NULL',
    'archived_at' => 'DATETIME NULL',
    'archived_by' => 'INT UNSIGNED NULL',
    'archive_reason' => 'VARCHAR(255) NULL',
    'response_target_hours' => 'INT UNSIGNED NULL DEFAULT 168',
    'resolution_target_hours' => 'INT UNSIGNED NULL DEFAULT 336',
    'sla_risk' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'last_activity_at' => 'DATETIME NULL',
];
foreach ($ticketCols as $col => $ddl) {
    if (!col_exists($pdo, 'tickets', $col)) {
        add_col($pdo, 'tickets', "$col $ddl");
    }
}

$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_notification_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ticket_user (ticket_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_assignment_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  assigned_type VARCHAR(32) NOT NULL,
  assigned_by INT UNSIGNED NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tah_ticket (ticket_id),
  KEY idx_tah_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$roleDefs = [
    ['Faculty/Staff', 'faculty_staff'],
    ['Restricted Pillar Admin', 'restricted_pillar_admin'],
    ['Unrestricted Pillar Admin', 'unrestricted_pillar_admin'],
    ['General Pillar Admin', 'general_pillar_admin'],
    ['Business Admin', 'business_admin'],
    ['Coordinator', 'coordinator'],
];
$insRole = $pdo->prepare(
    'INSERT INTO roles (role_name, role_key, description) SELECT ?, ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key = ?)'
);
foreach ($roleDefs as [$name, $key]) {
    $insRole->execute([$name, $key, $name, $key]);
}

$pwd = password_hash('password123', PASSWORD_BCRYPT);
$users = [
    ['restricted_pillar', 'Restricted Pillar Admin', 'restricted.pillar@botll.local', 'restricted_pillar_admin'],
    ['restricted_pillar2', 'Restricted Pillar Admin Two', 'restricted.pillar2@botll.local', 'restricted_pillar_admin'],
    ['unrestricted_pillar', 'Unrestricted Pillar Admin', 'unrestricted.pillar@botll.local', 'unrestricted_pillar_admin'],
    ['unrestricted_pillar2', 'Unrestricted Pillar Admin Two', 'unrestricted.pillar2@botll.local', 'unrestricted_pillar_admin'],
    ['general_pillar', 'General Pillar Admin', 'general.pillar@botll.local', 'general_pillar_admin'],
    ['general_pillar2', 'General Pillar Admin Two', 'general.pillar2@botll.local', 'general_pillar_admin'],
    ['business_admin1', 'Business Admin One', 'business.admin1@botll.local', 'business_admin'],
    ['business_admin2', 'Business Admin Two', 'business.admin2@botll.local', 'business_admin'],
    ['coordinator1', 'Coordinator One', 'coordinator1@botll.local', 'coordinator'],
    ['coordinator2', 'Coordinator Two', 'coordinator2@botll.local', 'coordinator'],
    ['faculty_user1', 'Faculty User One', 'faculty.user1@botll.local', 'faculty_staff'],
    ['faculty_user2', 'Faculty User Two', 'faculty.user2@botll.local', 'faculty_staff'],
];
$ins = $pdo->prepare(
    'INSERT INTO users (full_name, email, username, password_hash, role_id, status)
     SELECT ?,?,?,?, (SELECT id FROM roles WHERE role_key = ? LIMIT 1), "active"
     FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = ?)'
);
$upd = $pdo->prepare(
    'UPDATE users u SET u.role_id = (SELECT id FROM roles WHERE role_key = ? LIMIT 1) WHERE u.username = ?'
);
foreach ($users as [$user, $name, $email, $roleKey]) {
    $ins->execute([$name, $email, $user, $pwd, $roleKey, $user]);
    $upd->execute([$roleKey, $user]);
    echo "User $user → role $roleKey OK\n";
}

foreach (['Assigned', 'Overdue', 'Stuck'] as $sn) {
    $pdo->exec("INSERT IGNORE INTO ticket_statuses (status_name) VALUES (" . $pdo->quote($sn) . ")");
}

echo "Schema + demo users (role_id) applied.\n";
