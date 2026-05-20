<?php
declare(strict_types=1);

/**
 * CLI smoke test for 3-level assignment Done + reassignment permissions.
 * Usage: php scripts/test_assignment_workflow_cli.php
 */

require_once dirname(__DIR__) . '/includes/init.php';

function cli_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
    clear_current_user_cache();
}

function cli_user_by_name(string $name): ?array
{
    $st = db()->prepare('SELECT u.id, u.full_name, r.role_key FROM users u JOIN roles r ON r.id = u.role_id WHERE u.full_name = ? LIMIT 1');
    $st->execute([$name]);
    $row = $st->fetch();
    return $row ?: null;
}

function cli_fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function cli_ok(string $msg): void
{
    echo "OK: {$msg}\n";
}

$freda = cli_user_by_name('Freda Otilia') ?: cli_fail('User Freda Otilia not found');
$harper = cli_user_by_name('Harper HOD') ?: cli_fail('User Harper HOD not found');
$testSt = db()->prepare('SELECT u.id, u.full_name, r.role_key FROM users u JOIN roles r ON r.id = u.role_id WHERE u.full_name LIKE ? LIMIT 1');
$testSt->execute(['Test User%']);
$testUser = $testSt->fetch();
if (!$testUser) {
    $pdo = db();
    $roleId = (int) $pdo->query('SELECT id FROM roles WHERE role_key = "user" LIMIT 1')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO users (full_name, email, username, password_hash, role_id, department_id, status)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        'Test User',
        'testuser@botll.local',
        'testuser',
        '$2y$12$CvZsjhO60soXPObScnvnLegC7Ep9tot5HFhgfAyCl6/b4O9vg1/1G',
        $roleId,
        1,
        'active',
    ]);
    $testId = (int) $pdo->lastInsertId();
    $testUser = ['id' => $testId, 'full_name' => 'Test User', 'role_key' => 'user'];
    cli_ok('Created Test User (id ' . $testId . ')');
}

$creatorId = (int) $freda['id'];
$openId = status_id_by_name('Open') ?: cli_fail('Open status missing');
$closedId = status_id_by_name('Closed') ?: cli_fail('Closed status missing');
$pdo = db();
$pdo->prepare(
    'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, department_id, status_id, created_by, assigned_to)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    'TST-' . date('YmdHis'),
    'CLI 3-level assignment test',
    'Automated test ticket',
    1,
    2,
    1,
    $openId,
    $creatorId,
    (int) $freda['id'],
]);
$ticketId = (int) $pdo->lastInsertId();
cli_ok('Created ticket #' . $ticketId);

ticket_assignment_save_chain($ticketId, [
    ['user_id' => (int) $freda['id'], 'level' => 1],
    ['user_id' => (int) $harper['id'], 'level' => 2],
    ['user_id' => (int) $testUser['id'], 'level' => 3],
]);

$rows = ticket_assignment_chain_rows($ticketId);
if (count($rows) !== 3) {
    cli_fail('Expected 3 assignee rows, got ' . count($rows));
}
$active = ticket_assignment_active_row($ticketId);
if (!$active || (int) $active['user_id'] !== (int) $freda['id']) {
    cli_fail('Level 1 should be active (Freda)');
}
cli_ok('Level 1 active, L2/L3 waiting');

cli_user((int) $freda['id']);
if (!ticket_assignment_level_done($ticketId, (int) $freda['id'], 'L1 done')) {
    cli_fail('Level 1 Done failed');
}
$t = ticket_fetch_by_id($ticketId);
if ((int) ($t['status_id'] ?? 0) === $closedId) {
    cli_fail('Ticket closed after L1 Done');
}
$active = ticket_assignment_active_row($ticketId);
if (!$active || (int) $active['user_id'] !== (int) $harper['id']) {
    cli_fail('Level 2 should be active after L1 Done');
}
cli_ok('L1 Done → L2 active, ticket still open');

cli_user((int) $harper['id']);
if (!ticket_assignment_level_done($ticketId, (int) $harper['id'], 'L2 done')) {
    cli_fail('Level 2 Done failed');
}
$t = ticket_fetch_by_id($ticketId);
if ((int) ($t['status_id'] ?? 0) === $closedId) {
    cli_fail('Ticket closed after L2 Done');
}
$active = ticket_assignment_active_row($ticketId);
if (!$active || (int) $active['user_id'] !== (int) $testUser['id']) {
    cli_fail('Level 3 should be active after L2 Done');
}
cli_ok('L2 Done → L3 active, ticket still open');

cli_user((int) $testUser['id']);
$ctx = ticket_assignment_actor_context($ticketId, (int) $testUser['id']);
if (empty($ctx['can_mark_done']) && empty($ctx['is_final_level'])) {
    cli_fail('L3 context: expected can_mark_done or is_final_level (got pass=' . (int) !empty($ctx['can_pass']) . ')');
}
if (!ticket_assignment_level_done($ticketId, (int) $testUser['id'], 'L3 final done')) {
    cli_fail('Level 3 Done failed: ' . ticket_assignment_last_error());
}
$t = ticket_fetch_by_id($ticketId);
if ((int) ($t['status_id'] ?? 0) !== $closedId) {
    cli_fail('Ticket should be Closed after L3 Done, status=' . ($t['status_name'] ?? ''));
}
if (empty($t['work_done_at'])) {
    cli_fail('work_done_at not set');
}
if (!empty($t['conversation_closed_at'])) {
    cli_fail('conversation_closed_at must stay null until final approval (no approver on this ticket)');
}
if ((int) ($t['work_done_by'] ?? 0) !== (int) $testUser['id']) {
    cli_fail('work_done_by should be Test User');
}
$activeAfter = ticket_assignment_active_row($ticketId);
if ($activeAfter !== null) {
    cli_fail('No assignment row should be active after final Done (row id=' . (int) $activeAfter['id'] . ')');
}
$rowsAfter = ticket_assignment_fetch_chain_rows($ticketId);
$l1 = $rowsAfter[0] ?? null;
if ($l1 && (string) ($l1['assignment_status'] ?? '') === 'active') {
    cli_fail('Level 1 must not be active after final Done');
}
cli_ok('L3 Done → Closed audit status, work_done set, conversation still open; no row reactivated');

// Simulate page refresh (chain load + repair must not reopen L1)
ticket_assignment_chain_rows($ticketId);
$activeRefresh = ticket_assignment_active_row($ticketId);
if ($activeRefresh !== null) {
    cli_fail('After refresh, Level 1 reopened (active row id=' . (int) $activeRefresh['id'] . ')');
}
cli_ok('Refresh does not reactivate any assignment level');

// Reassignment permissions
$ticket = ticket_fetch_by_id($ticketId);
cli_user((int) $testUser['id']);
if (ticket_can_reassign($ticketId, $ticket)) {
    cli_fail('Assignee Test User should not reassign');
}
cli_ok('Assignee cannot reassign');

cli_user($creatorId);
if (!ticket_can_reassign($ticketId, $ticket)) {
    cli_fail('Creator should reassign (ticket not Completed/Rejected)');
}
cli_ok('Creator can reassign');

cli_user((int) $harper['id']);
$deptTicket = ticket_fetch_by_id($ticketId);
if ((int) $deptTicket['department_id'] !== 2 && !ticket_can_reassign($ticketId, $deptTicket)) {
    // Harper is HR dept 2; ticket is dept 1 — should NOT reassign
    cli_ok('HOD cannot reassign out-of-department ticket');
} elseif ((int) $deptTicket['department_id'] === 2 && !ticket_can_reassign($ticketId, $deptTicket)) {
    cli_fail('HOD should reassign in-department ticket');
}

$admin = cli_user_by_name('Alex Admin');
if ($admin) {
    cli_user((int) $admin['id']);
    if (!ticket_can_reassign($ticketId, $ticket)) {
        cli_fail('Admin should reassign');
    }
    cli_ok('Admin can reassign');
}

$sa = cli_user_by_name('Super Admin User');
if ($sa) {
    cli_user((int) $sa['id']);
    if (!ticket_can_reassign($ticketId, $ticket)) {
        cli_fail('Super Admin should reassign');
    }
    cli_ok('Super Admin can reassign');
}

echo "\nAll assignment workflow CLI checks passed (ticket_id={$ticketId}).\n";
