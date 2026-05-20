<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

echo "=== Access Diagnosis ===\n\n";

echo "A. Roles table\n";
echo str_pad('id', 6) . str_pad('role_key', 28) . "role_name\n";
echo str_repeat('-', 70) . "\n";
$roles = db()->query('SELECT id, role_key, role_name FROM roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($roles as $r) {
    echo str_pad((string) $r['id'], 6)
        . str_pad((string) ($r['role_key'] ?? ''), 28)
        . (string) ($r['role_name'] ?? '') . "\n";
}

function diag_user_row(string $label, ?string $username): void
{
    echo "\n$label\n";
    if ($username === null) {
        echo "  (not found)\n";
        return;
    }
    $st = db()->prepare(
        'SELECT u.username, u.role_id, u.status, COALESCE(r.role_key, \'(null)\') AS role_key
         FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1'
    );
    $st->execute([$username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "  user not found: $username\n";
        return;
    }
    echo '  username: ' . $row['username'] . "\n";
    echo '  role_id: ' . $row['role_id'] . "\n";
    echo '  role_key: ' . $row['role_key'] . "\n";
    echo '  status: ' . $row['status'] . "\n";
}

echo "\nB. Demo users\n";
foreach ([
    'restricted_pillar', 'unrestricted_pillar', 'general_pillar',
    'business_admin1', 'coordinator1', 'faculty_user1', 'superadmin',
] as $u) {
    diag_user_row("  [$u]", $u);
}

echo "\nC. Existing admin / superadmin\n";
$admins = db()->query(
    "SELECT u.username FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE u.status = 'active' AND COALESCE(r.role_key, '') IN ('admin', 'super_admin', 'superadmin')
     ORDER BY u.username LIMIT 10"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($admins as $uname) {
    diag_user_row('  admin/super', (string) $uname);
}
$anyUser = db()->query(
    "SELECT u.username FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE u.status = 'active' AND COALESCE(r.role_key, '') IN ('user', 'faculty_staff')
     ORDER BY u.id LIMIT 1"
)->fetchColumn();
diag_user_row('  sample user', $anyUser !== false ? (string) $anyUser : null);

$pages = [
    'dashboard' => 'dashboard.php',
    'my_tickets' => 'my_tickets.php',
    'new_request' => 'new_request.php',
    'requests' => 'requests.php',
    'all_tickets' => 'all_tickets.php',
    'archive' => 'archive.php',
    'reports' => 'reports.php',
];

echo "\nD. Page access simulation (can_access)\n";
$testUsers = [
    'superadmin', 'restricted_pillar', 'unrestricted_pillar', 'general_pillar',
    'business_admin1', 'coordinator1', 'faculty_user1',
];
if ($admins) {
    $testUsers[] = (string) $admins[0];
}
if ($anyUser) {
    $testUsers[] = (string) $anyUser;
}
$testUsers = array_values(array_unique($testUsers));

echo str_pad('user', 22);
foreach (array_keys($pages) as $pk) {
    echo str_pad($pk, 14);
}
echo "\n" . str_repeat('-', 22 + 14 * count($pages)) . "\n";

foreach ($testUsers as $uname) {
    $st = db()->prepare(
        'SELECT u.id FROM users u WHERE u.username = ? AND u.status = "active" LIMIT 1'
    );
    $st->execute([$uname]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if ($id < 1) {
        continue;
    }
    $_SESSION['user_id'] = $id;
    clear_current_user_cache();
    $cu = current_user();
    if (!$cu) {
        echo str_pad($uname, 22) . "(no session user)\n";
        continue;
    }
    echo str_pad($uname, 22);
    foreach ($pages as $pk => $_file) {
        $ok = can_access($pk) ? 'ALLOW' : 'DENY';
        echo str_pad($ok, 14);
    }
    echo "\n";
}

echo "\nLogin-only pages: " . implode(', ', access_login_only_page_keys()) . "\n";
echo "\nDone.\n";
