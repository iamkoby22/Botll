<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

$fail = 0;
function cli_ok(bool $cond, string $msg): void
{
    global $fail;
    if ($cond) {
        echo "[PASS] $msg\n";
    } else {
        echo "[FAIL] $msg\n";
        $fail++;
    }
}

function cli_user(string $username): ?array
{
    $st = db()->prepare(
        'SELECT u.*, r.role_key, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1'
    );
    $st->execute([$username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cli_login(array $user): void
{
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['_user_cache_cleared'] = true;
    clear_current_user_cache();
}

function cli_in_scope(int $ticketId): bool
{
    $st = db()->prepare('SELECT 1 FROM tickets t WHERE t.id = ? AND ' . tickets_scope_sql('t') . ' LIMIT 1');
    $st->execute([$ticketId]);
    return (bool) $st->fetchColumn();
}

function cli_requests_count(): int
{
    $uid = (int) current_user()['id'];
    $scope = tickets_active_scope_sql('t');
    $role = current_user_role_key();
    $privileged = is_super_admin_role($role) || $role === 'admin';
    $sbsScopedRole = is_super_admin_role($role) || in_array($role, [
        'restricted_pillar_admin', 'unrestricted_pillar_admin', 'general_pillar_admin',
    ], true);
    $wheres = [$scope];
    $params = [];
    if (!$privileged && !$sbsScopedRole) {
        $wheres[] = '(t.created_by = ? OR t.assigned_to = ? OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = ?)
         OR EXISTS (SELECT 1 FROM ticket_approvals tap WHERE tap.ticket_id = t.id AND tap.approver_id = ?))';
        array_push($params, $uid, $uid, $uid, $uid);
    }
    $sql = 'SELECT COUNT(DISTINCT t.id) FROM tickets t WHERE ' . implode(' AND ', $wheres);
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

echo "=== SBS Full Workflow CLI Test (role-based) ===\n\n";

$strictFiles = [
    'requests.php', 'all_tickets.php', 'archive.php', 'dashboard.php', 'reports.php',
    'new_request.php', 'ticket_detail.php', 'includes/sbs_workflow.php', 'includes/sbs_roles.php',
    'includes/ticket_detail_sbs.php', 'includes/analytics_metrics.php',
];
foreach ($strictFiles as $rel) {
    $path = dirname(__DIR__) . '/' . $rel;
    $raw = file_get_contents($path);
    if ($raw === false) {
        cli_ok(false, "strict_types scan: missing $rel");
        continue;
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $ok = (bool) preg_match('/^<\?php\r?\ndeclare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $raw);
    cli_ok($ok, "strict_types first statement: $rel");
}

cli_ok(sbs_workflow_enabled(), 'SBS workflow schema enabled');
cli_ok(sbs_column_exists('tickets', 'archived_at'), 'archived_at column exists for archive');

function cli_can_page(?array $user, string $pageKey): bool
{
    if (!$user) {
        return false;
    }
    cli_login($user);
    return can_access($pageKey);
}

$corePages = ['dashboard', 'my_tickets', 'requests', 'new_request', 'archive', 'reports'];
foreach ($corePages as $pk) {
    cli_ok(cli_can_page(cli_user('faculty_user1'), $pk), "Page access: faculty_user1 → $pk");
    cli_ok(cli_can_page(cli_user('restricted_pillar'), $pk), "Page access: restricted_pillar → $pk");
    cli_ok(cli_can_page(cli_user('business_admin1'), $pk), "Page access: business_admin1 → $pk");
}
$superRow = db()->query(
    "SELECT u.*, r.role_key FROM users u LEFT JOIN roles r ON r.id=u.role_id
     WHERE u.username IN ('superadmin','super_admin') AND u.status='active' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($superRow) {
    cli_ok(cli_can_page($superRow, 'dashboard'), 'superadmin account: dashboard');
    cli_ok(is_super_admin_role((string) ($superRow['role_key'] ?? '')), 'superadmin/super_admin role compatibility');
}
cli_ok(cli_can_page(cli_user('faculty_user1'), 'archive'), 'archive page loads (scoped), not 403 for faculty');

foreach (array_keys(sbs_required_roles()) as $rk) {
    $id = role_id_by_key($rk);
    cli_ok($id > 0, "Required role exists: $rk");
}

$demoRoleMap = [
    'restricted_pillar' => 'restricted_pillar_admin',
    'restricted_pillar2' => 'restricted_pillar_admin',
    'unrestricted_pillar' => 'unrestricted_pillar_admin',
    'unrestricted_pillar2' => 'unrestricted_pillar_admin',
    'general_pillar' => 'general_pillar_admin',
    'general_pillar2' => 'general_pillar_admin',
    'business_admin1' => 'business_admin',
    'business_admin2' => 'business_admin',
    'coordinator1' => 'coordinator',
    'coordinator2' => 'coordinator',
    'faculty_user1' => 'faculty_staff',
    'faculty_user2' => 'faculty_staff',
];
foreach ($demoRoleMap as $uname => $expectedRole) {
    $row = cli_user($uname);
    cli_ok($row !== null, "Demo user exists: $uname");
    if ($row) {
        cli_ok((string) ($row['role_key'] ?? '') === $expectedRole, "Demo user $uname has role $expectedRole via role_id");
        cli_ok(user_role_key((int) $row['id']) === $expectedRole, "user_role_key() for $uname");
    }
}

$st = db()->query(
    "SELECT field_options FROM request_logic_fields
     WHERE field_key = 'grant_funds'
        OR field_label LIKE '%utilizing funds from Grant, Sponsored, TRIF%'"
);
$grantOk = true;
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $opts = (string) ($row['field_options'] ?? '');
    if (!str_contains($opts, 'Not Sure')) {
        $grantOk = false;
    }
}
cli_ok($grantOk, 'All grant_funds fields include Yes / No / Not Sure');

$logicId = (int) (db()->query('SELECT id FROM request_logic WHERE COALESCE(is_active,1)=1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
cli_ok($logicId > 0, 'Request logic available');

function cli_create_ticket(array $user, string $grantAnswer): int
{
    $dept = (int) (db()->query('SELECT id FROM departments ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    $pri = (int) (db()->query('SELECT id FROM ticket_priorities ORDER BY priority_level LIMIT 1')->fetchColumn() ?: 1);
    $cat = (int) (db()->query('SELECT id FROM ticket_categories ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
    $open = (int) (db()->query('SELECT id FROM ticket_statuses WHERE status_name="Open" LIMIT 1')->fetchColumn() ?: 1);
    $route = sbs_route_from_grant_answer($grantAnswer);
    db()->prepare(
        'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, status_id, department_id,
         created_by, request_logic_id, account_route, routed_pillar, routed_at, response_target_hours, resolution_target_hours, last_activity_at, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),168,336,NOW(),NOW())'
    )->execute([
        'TKT-SBS-' . uniqid(), 'SBS route test', 'SBS Support Request', $cat, $pri, $open, $dept,
        (int) $user['id'], (int) ($GLOBALS['logicId'] ?? 0),
        $route['account_route'], $route['routed_pillar'],
    ]);
    $tid = (int) db()->lastInsertId();
    db()->prepare(
        'INSERT INTO ticket_field_values (ticket_id, request_logic_id, field_label, field_key, field_type, field_value)
         VALUES (?,?,?,?,?,?)'
    )->execute([$tid, (int) ($GLOBALS['logicId'] ?? 0), sbs_grant_funds_labels()[1], 'grant_funds', 'radio', $grantAnswer]);
    if (function_exists('sbs_notify_routing_created')) {
        sbs_notify_routing_created($tid);
    }
    return $tid;
}

function cli_user_notifications(int $userId, string $titleLike = ''): int
{
    if ($titleLike === '') {
        $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $st->execute([$userId]);
    } else {
        $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title LIKE ?');
        $st->execute([$userId, $titleLike]);
    }
    return (int) $st->fetchColumn();
}

$GLOBALS['logicId'] = $logicId;
$faculty = cli_user('faculty_user1');
cli_ok($faculty !== null, 'Faculty user for intake');

$tidYes = cli_create_ticket($faculty, 'Yes');
$tidNo = cli_create_ticket($faculty, 'No');
$tidNs = cli_create_ticket($faculty, 'Not Sure');
$tidUnsure = cli_create_ticket($faculty, 'I am not sure');

cli_ok((db()->query("SELECT account_route FROM tickets WHERE id=$tidYes")->fetchColumn() ?: '') === 'restricted', 'Yes → restricted');
cli_ok((db()->query("SELECT routed_pillar FROM tickets WHERE id=$tidYes")->fetchColumn() ?: '') === 'restricted_pillar_admin', 'Yes → restricted_pillar_admin');
cli_ok((db()->query("SELECT account_route FROM tickets WHERE id=$tidNo")->fetchColumn() ?: '') === 'unrestricted', 'No → unrestricted');
cli_ok((db()->query("SELECT account_route FROM tickets WHERE id=$tidNs")->fetchColumn() ?: '') === 'general', 'Not Sure → general');
cli_ok((db()->query("SELECT account_route FROM tickets WHERE id=$tidUnsure")->fetchColumn() ?: '') === 'general', 'I am not sure → general');

foreach ([$tidYes, $tidNo, $tidNs] as $tid) {
    $ac = (int) db()->query("SELECT COUNT(*) FROM ticket_assignees WHERE ticket_id=$tid")->fetchColumn();
    $ap = (int) db()->query("SELECT COUNT(*) FROM ticket_approvals WHERE ticket_id=$tid")->fetchColumn();
    cli_ok($ac === 0 && $ap === 0, "Ticket $tid: no assignee/approval rows at creation");
}

$rp1 = cli_user('restricted_pillar');
$rp2 = cli_user('restricted_pillar2');
if ($rp1 && $rp2) {
    cli_ok(cli_user_notifications((int) $rp1['id'], '%restricted request routed%') >= 1, 'Initial routing notifies restricted_pillar');
    cli_ok(cli_user_notifications((int) $rp2['id'], '%restricted request routed%') >= 1, 'Initial routing notifies restricted_pillar2');
    $beforeRoutine = cli_user_notifications((int) $rp2['id']);
    sbs_send_ticket_notifications($tidYes, 'comment_added', 'Routine update', 'Test comment', (int) $faculty['id']);
    $afterRoutine = cli_user_notifications((int) $rp2['id']);
    cli_ok($afterRoutine === $beforeRoutine, 'Unsubscribed pillar admin excluded from routine comment notifications');
    cli_login($rp2);
    sbs_toggle_notify($tidYes, (int) $rp2['id']);
    cli_ok(sbs_is_subscribed($tidYes, (int) $rp2['id']), 'Notify Me subscription active for pillar admin');
    $recipients = sbs_ticket_notification_recipients($tidYes, 'comment_added', (int) $faculty['id']);
    cli_ok(in_array((int) $rp2['id'], $recipients, true), 'Subscribed pillar admin in routine notification recipients');
}

$tChain = cli_create_ticket($faculty, 'No');
cli_ok(!sbs_can_show_notify_me(ticket_fetch_by_id($tChain), cli_user('business_admin1')), 'Business Admin: no Notify Me');
cli_ok(!sbs_can_show_notify_me(ticket_fetch_by_id($tChain), cli_user('coordinator1')), 'Coordinator: no Notify Me');
cli_ok(sbs_can_show_notify_me(ticket_fetch_by_id($tChain), cli_user('unrestricted_pillar')), 'Pillar Admin: Notify Me available');

cli_login(cli_user('unrestricted_pillar'));
cli_ok(sbs_can_reassign(ticket_fetch_by_id($tChain)), 'Pillar can reassign before Completed');
cli_ok(sbs_assign_ticket($tChain, (int) cli_user('business_admin1')['id'], 'business_admin', (int) cli_user('unrestricted_pillar')['id']), 'Pillar assigns BA → Pending');
cli_ok((string) (db()->query('SELECT s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.id=' . $tChain)->fetchColumn() ?: '') === 'Pending', 'Status Pending after pillar assigns BA');

cli_login(cli_user('business_admin1'));
$tChainTicket = ticket_fetch_by_id($tChain);
cli_ok(!empty($tChainTicket['priority_name']), 'Coordinator/chain can see priority on ticket');
cli_ok(sbs_can_change_priority($tChainTicket), 'BA in chain can change priority');
cli_ok(sbs_can_reassign_to_coordinator($tChainTicket), 'BA can reassign to Coordinator before Completed');
cli_ok(sbs_assign_ticket($tChain, (int) cli_user('coordinator1')['id'], 'coordinator', (int) cli_user('business_admin1')['id']), 'BA reassigns to Coordinator (Pending)');

cli_login(cli_user('coordinator1'));
cli_ok(!sbs_can_change_priority(ticket_fetch_by_id($tChain)), 'Coordinator cannot change priority');
cli_ok(!sbs_can_reassign(ticket_fetch_by_id($tChain), cli_user('coordinator1')), 'Coordinator cannot reassign');

cli_login(cli_user('business_admin1'));
cli_ok(cli_in_scope($tChain), 'BA still sees ticket in scope after reassigned to Coordinator');
cli_login(cli_user('coordinator1'));
cli_ok(cli_in_scope($tChain), 'Coordinator sees assigned ticket');

cli_login(cli_user('unrestricted_pillar'));
cli_ok(sbs_can_reassign(ticket_fetch_by_id($tChain)), 'Pillar can reassign while not Completed');
cli_login(cli_user('coordinator1'));
sbs_mark_work_done($tChain, (int) cli_user('coordinator1')['id']);
cli_login(cli_user('unrestricted_pillar'));
sbs_complete_ticket($tChain, (int) cli_user('unrestricted_pillar')['id']);
cli_ok(!sbs_can_reassign(ticket_fetch_by_id($tChain)), 'Reassign unavailable after Completed');
cli_ok(!sbs_can_change_priority(ticket_fetch_by_id($tChain)), 'Priority edit unavailable after Completed');

cli_login(cli_user('business_admin1'));
cli_ok(cli_in_scope($tChain), 'BA still sees Completed ticket in My Tickets scope');
cli_login(cli_user('coordinator1'));
cli_ok(cli_in_scope($tChain), 'Coordinator still sees Completed ticket in scope');
cli_login(cli_user('unrestricted_pillar'));
cli_ok(cli_in_scope($tChain), 'Pillar still sees Completed ticket in scope');

$report = sbs_ticket_chain_report($tChain);
cli_ok(!empty($report['assignment_chain']), 'Report chain includes assignment history');
cli_ok((string) ($report['account_route'] ?? '') === 'unrestricted', 'Report traceability: account route');
cli_ok((string) ($report['work_done_by'] ?? '') !== '', 'Report traceability: work done by');
cli_ok(in_array((int) cli_user('business_admin1')['id'], $report['chain_user_ids'] ?? [], true), 'Chain includes Business Admin');
cli_ok(in_array((int) cli_user('coordinator1')['id'], $report['chain_user_ids'] ?? [], true), 'Chain includes Coordinator');

cli_login(cli_user('business_admin1'));
cli_ok(sbs_user_in_ticket_chain($tChain, (int) cli_user('business_admin1')['id']), 'BA in ticket chain after reassignment');
cli_ok(!sbs_can_post_conversation(ticket_fetch_by_id($tChain), cli_user('business_admin1')), 'BA in chain cannot post after Completed (history still visible)');

if (function_exists('analytics_chain_performance_by_user')) {
    $scope = analytics_scope_for_user(cli_user('unrestricted_pillar'));
    $perf = analytics_chain_performance_by_user($scope);
    cli_ok(is_array($perf), 'Performance analytics includes chain assignment data');
}

cli_login(cli_user('restricted_pillar'));
cli_ok(cli_in_scope($tidYes), 'restricted_pillar_admin sees restricted');
cli_ok(!cli_in_scope($tidNo), 'restricted_pillar_admin cannot see unrestricted');
cli_login(cli_user('restricted_pillar2'));
cli_ok(cli_in_scope($tidYes), 'second restricted_pillar_admin sees restricted (role group)');

cli_login(cli_user('unrestricted_pillar'));
cli_ok(cli_in_scope($tidNo), 'unrestricted_pillar_admin sees unrestricted');
cli_ok(!cli_in_scope($tidYes), 'unrestricted_pillar_admin cannot see restricted');
cli_login(cli_user('unrestricted_pillar2'));
cli_ok(cli_in_scope($tidNo), 'second unrestricted_pillar_admin sees unrestricted (role group)');

cli_login(cli_user('general_pillar'));
cli_ok(cli_in_scope($tidNs), 'general_pillar_admin sees general');
cli_ok(!cli_in_scope($tidYes), 'general_pillar_admin cannot see restricted');
cli_login(cli_user('general_pillar2'));
cli_ok(cli_in_scope($tidNs), 'second general_pillar_admin sees general (role group)');

cli_login(cli_user('restricted_pillar'));
cli_ok(cli_requests_count() >= 1, 'requests.php scope: restricted pillar role sees routed tickets');
cli_login(cli_user('unrestricted_pillar'));
cli_ok(cli_requests_count() >= 1, 'requests.php scope: unrestricted pillar role sees routed tickets');
cli_login(cli_user('general_pillar'));
cli_ok(cli_requests_count() >= 1, 'requests.php scope: general pillar role sees routed tickets');

$baList = sbs_users_with_role('business_admin');
$coList = sbs_users_with_role('coordinator');
cli_ok(count($baList) >= 2, 'Assignment list: multiple business_admin by role_key');
cli_ok(count($coList) >= 2, 'Assignment list: multiple coordinator by role_key');

$newReq = file_get_contents(dirname(__DIR__) . '/new_request.php') ?: '';
cli_ok(
    !preg_match('/name=["\'](assigned_to|assignee|approver_id|approver|priority_id)["\']/i', $newReq),
    'New Request form has no assignee/approver/priority controls'
);

cli_login(cli_user('restricted_pillar'));
$ba = cli_user('business_admin1');
cli_ok(sbs_assign_ticket($tidYes, (int) $ba['id'], 'business_admin', (int) cli_user('restricted_pillar')['id']), 'Pillar assigns BA');
cli_ok((string) (db()->query('SELECT s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.id=' . $tidYes)->fetchColumn() ?: '') === 'Pending', 'Status Pending after pillar assigns BA');
cli_ok(user_role_key((int) $ba['id']) === 'business_admin', 'Assignee has business_admin role');
cli_ok(sbs_can_reassign(ticket_fetch_by_id($tidYes)), 'Pillar can reassign before Completed');

cli_login($ba);
cli_ok(sbs_can_change_priority(ticket_fetch_by_id($tidYes)), 'BA can change priority');
cli_ok(sbs_can_reassign_to_coordinator(ticket_fetch_by_id($tidYes)), 'BA can reassign to coordinator');
cli_ok(!sbs_can_complete(ticket_fetch_by_id($tidYes), $ba), 'BA cannot Complete');
cli_ok(sbs_assign_ticket($tidYes, (int) cli_user('coordinator1')['id'], 'coordinator', (int) $ba['id']), 'BA reassigns to coordinator');

cli_login(cli_user('coordinator1'));
cli_ok(sbs_can_mark_done(ticket_fetch_by_id($tidYes)), 'Coordinator can Done');
cli_ok(sbs_mark_work_done($tidYes, (int) cli_user('coordinator1')['id']), 'Done → Closed');
cli_ok((string) (db()->query('SELECT s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.id=' . $tidYes)->fetchColumn() ?: '') === 'Closed', 'Status Closed after Done');
cli_ok(!sbs_can_change_priority(ticket_fetch_by_id($tidYes)), 'Coordinator cannot change priority');
cli_ok(!sbs_can_complete(ticket_fetch_by_id($tidYes), cli_user('coordinator1')), 'Coordinator cannot Complete');

cli_login(cli_user('restricted_pillar'));
cli_ok(sbs_can_complete(ticket_fetch_by_id($tidYes)), 'Matching pillar can Complete');
cli_ok(sbs_complete_ticket($tidYes, (int) cli_user('restricted_pillar')['id']), 'Complete succeeds');
cli_ok(!sbs_can_reassign(ticket_fetch_by_id($tidYes)), 'Reassign blocked after Completed');
$sa = cli_user('superadmin');
if ($sa) {
    cli_ok(!sbs_can_reassign(ticket_fetch_by_id($tidYes), $sa), 'Super Admin reassign blocked after Completed');
}

cli_login(cli_user('unrestricted_pillar'));
sbs_toggle_notify($tidNo, (int) cli_user('unrestricted_pillar')['id']);
$c1 = (int) db()->query('SELECT COUNT(*) FROM ticket_notification_subscriptions WHERE ticket_id=' . $tidNo)->fetchColumn();
sbs_toggle_notify($tidNo, (int) cli_user('unrestricted_pillar')['id']);
cli_ok($c1 === 1, 'Notify Me subscription is per user');

$ticketArc = ticket_fetch_by_id($tidNs);
cli_ok(ticket_can_archive($ticketArc, cli_user('general_pillar')), 'Pillar can archive own route');
cli_ok(!ticket_can_archive($ticketArc, cli_user('business_admin1')), 'BA cannot archive');
cli_ok(!ticket_can_archive($ticketArc, cli_user('coordinator1')), 'Coordinator cannot archive');
cli_ok(!ticket_can_archive($ticketArc, cli_user('faculty_user1')), 'Faculty cannot archive');
cli_ok(!ticket_can_archive($ticketArc, cli_user('restricted_pillar')), 'Wrong pillar cannot archive general');

cli_login(cli_user('general_pillar'));
$result = ticket_bulk_archive([$tidNs, $tidUnsure], (int) cli_user('general_pillar')['id'], 'bulk');
cli_ok($result['ok'] >= 1, 'Bulk archive works for matching pillar role');
cli_ok(sbs_can_bulk_archive_user(cli_user('business_admin1')) === false, 'BA cannot bulk archive');

cli_ok(defined('APP_DISPLAY_NAME') && APP_DISPLAY_NAME === 'SBS Support Requests', 'System name');
cli_ok(!user_can_view_full_analytics(cli_user('business_admin1')), 'BA no full analytics');
cli_ok(user_can_view_full_analytics(cli_user('restricted_pillar')), 'Pillar full analytics by role');
cli_ok(!user_can_view_full_analytics(cli_user('faculty_user1')), 'Faculty no full analytics');

$adminUser = db()->query(
    "SELECT u.*, r.role_key FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key='admin' AND u.status='active' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($adminUser) {
    cli_login($adminUser);
    cli_ok(!cli_in_scope($tidYes), 'admin role does not auto-see all SBS routed tickets');
}

cli_ok(
    str_contains(sbs_routing_success_flash_message('TKT-TEST', 'restricted'), 'Restricted Pillar Admin team'),
    'Routing flash: Yes → Restricted Pillar Admin team'
);
cli_ok(
    str_contains(sbs_routing_success_flash_message('TKT-TEST', 'unrestricted'), 'Unrestricted Pillar Admin team'),
    'Routing flash: No → Unrestricted Pillar Admin team'
);
cli_ok(
    str_contains(sbs_routing_success_flash_message('TKT-TEST', 'general'), 'General Pillar Admin team'),
    'Routing flash: Not Sure → General Pillar Admin team'
);

cli_ok(sbs_can_bulk_complete_user(cli_user('superadmin')), 'Super Admin can bulk complete');
cli_ok(!sbs_can_bulk_complete_user(cli_user('restricted_pillar')), 'Pillar Admin cannot bulk complete');
cli_ok(!sbs_can_bulk_complete_user(cli_user('business_admin1')), 'Business Admin cannot bulk complete');
cli_ok(!sbs_can_bulk_complete_user(cli_user('coordinator1')), 'Coordinator cannot bulk complete');
cli_ok(!sbs_can_bulk_complete_user(cli_user('faculty_user1')), 'Faculty cannot bulk complete');

$tidOpenBulk = cli_create_ticket($faculty, 'Yes');
$tidClosedBulk1 = cli_create_ticket($faculty, 'Yes');
$tidClosedBulk2 = cli_create_ticket($faculty, 'Yes');
$closedId = sbs_status_id_by_name('Closed');
$coordId = (int) cli_user('coordinator1')['id'];
db()->prepare('UPDATE tickets SET status_id=?, work_done_at=NOW(), work_done_by=? WHERE id=?')
    ->execute([$closedId, $coordId, $tidClosedBulk1]);
db()->prepare('UPDATE tickets SET status_id=?, work_done_at=NOW(), work_done_by=? WHERE id=?')
    ->execute([$closedId, $coordId, $tidClosedBulk2]);

cli_login(cli_user('superadmin'));
$badBulk = ticket_bulk_complete([$tidOpenBulk, $tidClosedBulk1], (int) cli_user('superadmin')['id']);
cli_ok(($badBulk['error'] ?? '') === 'ineligible', 'Bulk complete rejected when mix includes Open');
cli_ok((string) (db()->query('SELECT s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.id=' . $tidOpenBulk)->fetchColumn() ?: '') === 'Open', 'Open ticket not completed after failed bulk');
cli_ok((string) (db()->query('SELECT s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.id=' . $tidClosedBulk1)->fetchColumn() ?: '') === 'Closed', 'Closed ticket not completed after failed bulk');

$goodBulk = ticket_bulk_complete([$tidClosedBulk1, $tidClosedBulk2], (int) cli_user('superadmin')['id']);
cli_ok($goodBulk['ok'] === 2, 'Bulk complete succeeds for two Closed tickets');
cli_ok((string) (db()->query('SELECT s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.id=' . $tidClosedBulk1)->fetchColumn() ?: '') === 'Completed', 'Bulk completed ticket 1 is Completed');
cli_ok((string) (db()->query('SELECT s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.id=' . $tidClosedBulk2)->fetchColumn() ?: '') === 'Completed', 'Bulk completed ticket 2 is Completed');

$tidAlreadyDone = cli_create_ticket($faculty, 'No');
db()->prepare('UPDATE tickets SET status_id=?, work_done_at=NOW(), work_done_by=? WHERE id=?')
    ->execute([$closedId, (int) cli_user('coordinator1')['id'], $tidAlreadyDone]);
sbs_complete_ticket($tidAlreadyDone, (int) cli_user('superadmin')['id'], true);
$rejectCompleted = ticket_bulk_complete([$tidAlreadyDone], (int) cli_user('superadmin')['id']);
cli_ok(($rejectCompleted['error'] ?? '') === 'ineligible', 'Bulk complete rejects already Completed ticket');

$up = cli_user('unrestricted_pillar');
$doneRecipients = sbs_ticket_notification_recipients($tChain, 'done_closed', 0);
cli_ok(count(array_intersect(sbs_active_user_ids_by_role('unrestricted_pillar_admin'), $doneRecipients)) >= 1, 'done_closed notifies matching Pillar Admin role users');

db()->prepare('UPDATE tickets SET created_at = DATE_SUB(NOW(), INTERVAL 200 HOUR), last_activity_at = DATE_SUB(NOW(), INTERVAL 200 HOUR) WHERE id=?')->execute([$tidNo]);
ticket_apply_sla_status($tidNo);
$slaStatus = (string) (db()->query('SELECT s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.id=' . $tidNo)->fetchColumn() ?: '');
cli_ok(in_array($slaStatus, ['Stuck', 'Overdue'], true), 'SLA Stuck/Overdue applied');

$phpBin = PHP_BINARY ?: 'php';
$lint = shell_exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg(dirname(__DIR__) . '/requests.php'));
cli_ok($lint !== null && str_contains((string) $lint, 'No syntax errors'), 'php -l requests.php');

echo "\n=== Done. Failures: $fail ===\n";
exit($fail > 0 ? 1 : 0);
