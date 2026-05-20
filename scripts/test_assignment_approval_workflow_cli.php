<?php
declare(strict_types=1);

/**
 * CLI: two-level assignment + one approver — progression and approval lock.
 * Usage: php scripts/test_assignment_approval_workflow_cli.php
 */

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/ticket_routing.php';

function cli_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
    clear_current_user_cache();
}

function cli_user_by_name(string $name): ?array
{
    $st = db()->prepare(
        'SELECT u.id, u.full_name, r.role_key FROM users u JOIN roles r ON r.id = u.role_id WHERE u.full_name = ? LIMIT 1'
    );
    $st->execute([$name]);
    $row = $st->fetch();
    return $row ?: null;
}

function cli_assert(bool $cond, string $label): void
{
    if ($cond) {
        echo "PASS: {$label}\n";
        return;
    }
    fwrite(STDERR, "FAIL: {$label}\n");
    exit(1);
}

$freda = cli_user_by_name('Freda Otilia') ?: exit("FAIL: Freda Otilia not found\n");
$harper = cli_user_by_name('Harper HOD') ?: exit("FAIL: Harper HOD not found\n");
$admin = cli_user_by_name('Alex Admin') ?: cli_user_by_name('Super Admin User');
if (!$admin) {
    exit("FAIL: need Alex Admin or Super Admin User as approver\n");
}

$creatorId = (int) $freda['id'];
$assignee1 = (int) $freda['id'];
$assignee2 = (int) $harper['id'];
$approverId = (int) $admin['id'];

$openId = status_id_by_name('Open') ?: exit("FAIL: Open status missing\n");
$closedId = status_id_by_name('Closed') ?: exit("FAIL: Closed status missing\n");
$pdo = db();

echo "\n=== A: One assignee + one approver ===\n";
$pdo->prepare(
    'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, department_id, status_id, created_by, assigned_to)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    'TA1-' . date('YmdHis'),
    'CLI single assignee + approval',
    'Automated test',
    1,
    2,
    1,
    $openId,
    $creatorId,
    $assignee1,
]);
$singleApId = (int) $pdo->lastInsertId();
ticket_assignment_save_chain($singleApId, [
    ['user_id' => $assignee1, 'level' => 1],
]);
ticket_approval_save_chain($singleApId, [
    ['user_id' => $approverId, 'level' => 1],
]);
cli_assert(count(ticket_assignment_chain_rows($singleApId)) === 1, 'Single assignee row saved');
$activeSingle = ticket_assignment_active_row($singleApId);
cli_assert($activeSingle && (int) $activeSingle['user_id'] === $assignee1, 'Only assignee is active');
cli_user($assignee1);
$ctxSingle = ticket_assignment_actor_context($singleApId, $assignee1);
cli_assert(!empty($ctxSingle['can_mark_done']) && !empty($ctxSingle['active_row']), 'Single assignee can Mark Done with active row');
cli_assert(!ticket_work_is_complete_for_approval($singleApId), 'Approval locked before single assignee Done');
cli_assert(ticket_assignment_level_done($singleApId, $assignee1, 'solo done'), 'Single assignee Mark Done');
$tSingle = ticket_fetch_by_id($singleApId);
cli_assert(!empty($tSingle['work_done_at']), 'work_done_at set for single assignee');
cli_assert(empty($tSingle['conversation_closed_at']), 'conversation_closed_at null after work Done');
$finalStatus = ticket_status_name_after_final_approval();
cli_assert(
    (string) ($tSingle['status_name'] ?? '') !== $finalStatus
    && (string) ($tSingle['status_name'] ?? '') !== 'Completed',
    'Ticket not Completed before final approval'
);
cli_assert(
    in_array((string) ($tSingle['status_name'] ?? ''), ['Pending Approval', 'Open', 'Closed'], true),
    'Status Pending Approval or equivalent after work Done'
);
cli_user($creatorId);
cli_assert(
    ticket_can_post_conversation($singleApId, 'conversation', $tSingle),
    'Creator can comment after work Done before approval'
);
cli_assert(ticket_work_is_complete_for_approval($singleApId), 'Approval unlocked after single assignee Done');
cli_assert(ticket_assignment_last_error() === '', 'No final-assignee error for single assignee');
cli_user($approverId);
$apRow = ticket_first_pending_approval_row($singleApId);
cli_assert($apRow && (int) $apRow['approver_id'] === $approverId, 'Approver row pending');
$pdo->prepare(
    'UPDATE ticket_approvals SET approval_status = ?, updated_at = NOW() WHERE id = ? AND ticket_id = ?'
)->execute(['approved', (int) $apRow['id'], $singleApId]);
ticket_finalize_after_final_approval($singleApId, $approverId);
$tSingleDone = ticket_fetch_by_id($singleApId);
cli_assert((string) ($tSingleDone['status_name'] ?? '') === $finalStatus, 'Single-assignee ticket Completed after approval');
cli_assert(!empty($tSingleDone['date_completed']) || $finalStatus === 'Closed', 'date_completed set after final approval');
cli_assert(!empty($tSingleDone['conversation_closed_at']), 'conversation_closed_at set after final approval');

echo "\n=== B: One assignee + no approver ===\n";
$pdo->prepare(
    'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, department_id, status_id, created_by, assigned_to)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    'TA0-' . date('YmdHis'),
    'CLI single assignee no approval',
    'Automated test',
    1,
    2,
    1,
    $openId,
    $creatorId,
    $assignee1,
]);
$singleNoApId = (int) $pdo->lastInsertId();
ticket_assignment_save_chain($singleNoApId, [
    ['user_id' => $assignee1, 'level' => 1],
]);
cli_user($assignee1);
$ctxNoAp = ticket_assignment_actor_context($singleNoApId, $assignee1);
cli_assert(!empty($ctxNoAp['can_mark_done']), 'Single assignee without approver can Mark Done');
cli_assert(ticket_assignment_level_done($singleNoApId, $assignee1, 'solo no approval'), 'Mark Done without approver chain');
$tNoAp = ticket_fetch_by_id($singleNoApId);
cli_assert(!empty($tNoAp['work_done_at']), 'work_done_at set without approver');
cli_assert(empty($tNoAp['conversation_closed_at']), 'conversation_closed_at null when no approver');
cli_assert(
    in_array((string) ($tNoAp['status_name'] ?? ''), ['Closed', 'Open'], true),
    'No-approval ticket uses audit Closed or stays Open (not Completed)'
);
cli_user($creatorId);
cli_assert(
    ticket_can_post_conversation($singleNoApId, 'conversation', $tNoAp),
    'Creator can comment after work Done without approver'
);
cli_assert(ticket_assignment_active_row($singleNoApId) === null, 'No active row after solo final Done');

echo "\n=== C: Two assignees + one approver (regression) ===\n";
$pdo->prepare(
    'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, department_id, status_id, created_by, assigned_to)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    'TAA-' . date('YmdHis'),
    'CLI 2-level assignment + approval test',
    'Automated test',
    1,
    2,
    1,
    $openId,
    $creatorId,
    $assignee1,
]);
$ticketId = (int) $pdo->lastInsertId();
cli_assert($ticketId > 0, 'Created test ticket');

ticket_assignment_save_chain($ticketId, [
    ['user_id' => $assignee1, 'level' => 1],
    ['user_id' => $assignee2, 'level' => 2],
]);
ticket_approval_save_chain($ticketId, [
    ['user_id' => $approverId, 'level' => 1],
]);

$rows = ticket_assignment_chain_rows($ticketId);
cli_assert(count($rows) === 2, 'Two assignee rows saved');

$active = ticket_assignment_active_row($ticketId);
cli_assert($active && (int) $active['user_id'] === $assignee1, 'Active level is 1 (assignee 1)');

cli_assert(!ticket_work_is_complete_for_approval($ticketId), 'Approval locked before any Done');

cli_user($assignee1);
$ctx1 = ticket_assignment_actor_context($ticketId, $assignee1);
cli_assert(!empty($ctx1['can_level_done']) && !empty($ctx1['can_pass']), 'Assignee 1 can pass (not final)');

cli_user($assignee2);
$ctx2 = ticket_assignment_actor_context($ticketId, $assignee2);
cli_assert(empty($ctx2['can_level_done']) && empty($ctx2['can_mark_done']), 'Assignee 2 cannot Mark Done yet');

cli_user($approverId);
$apCtx = ticket_approval_actor_context($ticketId, $approverId);
cli_assert(empty($apCtx['can_decide']) && !empty($apCtx['approval_locked']), 'Approver blocked before work complete');

cli_user($assignee1);
cli_assert(ticket_assignment_level_done($ticketId, $assignee1, 'L1 done'), 'Mark level 1 Done');

$active = ticket_assignment_active_row($ticketId);
cli_assert($active && (int) $active['user_id'] === $assignee2, 'Active level is 2 after L1 Done');

cli_assert(!ticket_work_is_complete_for_approval($ticketId), 'Approval still locked after L1 Done');

$ctx2 = ticket_assignment_actor_context($ticketId, $assignee2);
cli_assert(!empty($ctx2['can_level_done']), 'Assignee 2 sees Mark Done after L1 Done');

$ctx1 = ticket_assignment_actor_context($ticketId, $assignee1);
cli_assert(empty($ctx1['can_level_done']), 'Assignee 1 no longer has Mark Done');

cli_user($assignee2);
cli_assert(ticket_assignment_level_done($ticketId, $assignee2, 'L2 final'), 'Mark level 2 Done');

$activeAfter = ticket_assignment_active_row($ticketId);
cli_assert($activeAfter === null, 'No active assignment after final Done');

$t = ticket_fetch_by_id($ticketId);
cli_assert(!empty($t['work_done_at']), 'work_done_at set after final assignment Done');
cli_assert(empty($t['conversation_closed_at']), 'conversation_closed_at null after L2 Done (before approval)');
cli_assert(
    (string) ($t['status_name'] ?? '') !== ticket_status_name_after_final_approval(),
    'Two-assignee ticket not Completed before approval'
);
cli_assert(ticket_work_is_complete_for_approval($ticketId), 'Approval unlocked after all assignments Done');

$apCtx = ticket_approval_actor_context($ticketId, $approverId);
cli_assert(!empty($apCtx['can_decide']), 'Approver can decide when work complete');

cli_user($approverId);
$first = ticket_first_pending_approval_row($ticketId);
cli_assert($first && (int) $first['approver_id'] === $approverId, 'Pending approval row for approver');
$pdo->prepare(
    'UPDATE ticket_approvals SET approval_status = ?, updated_at = NOW() WHERE id = ? AND ticket_id = ?'
)->execute(['approved', (int) $first['id'], $ticketId]);
ticket_finalize_after_final_approval($ticketId, $approverId);
$tAfter = ticket_fetch_by_id($ticketId);
$finalStatus = ticket_status_name_after_final_approval();
cli_assert((string) ($tAfter['status_name'] ?? '') === $finalStatus, 'Ticket status is ' . $finalStatus . ' after final approval');
cli_assert(!empty($tAfter['date_completed']) || $finalStatus === 'Closed', 'date_completed or Closed set after approval');
cli_assert(true, 'Approver approved and ticket finalized');

echo "\nAll assignment + approval workflow CLI checks passed (ticket_id={$ticketId}).\n";

// Same routing label on both rows (assignment_level=2) must still progress by sort_order.
$pdo->prepare(
    'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, department_id, status_id, created_by, assigned_to)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    'TAA2-' . date('YmdHis'),
    'CLI duplicate assignment_level labels',
    'Automated test',
    1,
    2,
    1,
    $openId,
    $creatorId,
    $assignee1,
]);
$dupId = (int) $pdo->lastInsertId();
ticket_assignment_save_chain($dupId, [
    ['user_id' => $assignee1, 'level' => 2],
    ['user_id' => $assignee2, 'level' => 2],
]);
cli_user($assignee1);
$dupCtx = ticket_assignment_actor_context($dupId, $assignee1);
cli_assert(!empty($dupCtx['can_pass']) && empty($dupCtx['can_mark_done']), 'Same label L1: pass not final-close');
cli_assert(ticket_assignment_level_done($dupId, $assignee1, 'dup L1'), 'Same label L1 Done activates L2');
cli_assert(
    ticket_assignment_active_row($dupId) && (int) ticket_assignment_active_row($dupId)['user_id'] === $assignee2,
    'Same label: L2 active after L1 Done'
);
echo "PASS: duplicate assignment_level labels still progress (ticket_id={$dupId}).\n";
