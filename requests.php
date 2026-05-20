<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('requests');

$st = (string) ($_GET['st'] ?? 'all');
$queue = (string) ($_GET['queue'] ?? 'any');
$allowedSt = ['all', 'pending_approval', 'in_progress', 'completed', 'rejected'];
$allowedQueue = ['any', 'created', 'assigned', 'pending', 'stuck'];
if (!in_array($st, $allowedSt, true)) {
    $st = 'all';
}
if (!in_array($queue, $allowedQueue, true)) {
    $queue = 'any';
}

$uid = (int) current_user()['id'];
$pdo = db();
$orgCode = trim((string) ($_GET['org_code'] ?? ''));
$scope = tickets_active_scope_sql('t');
if (function_exists('ticket_apply_archive_thresholds')) {
    ticket_apply_archive_thresholds(current_user());
}
if (function_exists('ticket_apply_sla_status_batch')) {
    ticket_apply_sla_status_batch();
}

$base = 'SELECT DISTINCT t.*, s.status_name, p.priority_name, c.category_name, cu.full_name AS created_name,
          au.full_name AS assignee_name,
          EXISTS (SELECT 1 FROM ticket_approvals tap2 WHERE tap2.ticket_id = t.id AND tap2.approver_id = ' . (int) $uid . ' AND tap2.approval_status = "pending") AS approval_required_for_me
          FROM tickets t
          JOIN ticket_statuses s ON s.id = t.status_id
          JOIN ticket_priorities p ON p.id = t.priority_id
          JOIN ticket_categories c ON c.id = t.category_id
          JOIN users cu ON cu.id = t.created_by
          LEFT JOIN users au ON au.id = t.assigned_to';

$wheres = [$scope];
$params = [];

$involvedSql = '(t.created_by = ? OR t.assigned_to = ? OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = ?)
 OR EXISTS (SELECT 1 FROM ticket_approvals tap WHERE tap.ticket_id = t.id AND tap.approver_id = ?))';

$privileged = is_super_admin_role() || current_user_role_key() === 'admin';
$sbsScopedRole = false;
if (function_exists('sbs_workflow_enabled') && sbs_workflow_enabled()) {
    $rk = current_user_role_key();
    $sbsScopedRole = is_super_admin_role($rk) || in_array($rk, [
        'restricted_pillar_admin',
        'unrestricted_pillar_admin',
        'general_pillar_admin',
    ], true);
}

if (!$privileged && !$sbsScopedRole) {
    $wheres[] = $involvedSql;
    array_push($params, $uid, $uid, $uid, $uid);
}

if ($queue === 'created') {
    $wheres[] = 't.created_by = ?';
    $params[] = $uid;
} elseif ($queue === 'assigned') {
    $wheres[] = '(t.assigned_to = ? OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = ?))';
    $params[] = $uid;
    $params[] = $uid;
} elseif ($queue === 'pending') {
    $wheres[] = 'EXISTS (SELECT 1 FROM ticket_approvals tap WHERE tap.ticket_id = t.id AND tap.approver_id = ? AND tap.approval_status = "pending")';
    $params[] = $uid;
} elseif ($queue === 'stuck') {
    $wheres[] = 's.status_name IN ("Cancelled","Stuck")';
}

if ($st === 'pending_approval') {
    $wheres[] = 's.status_name = "Pending Approval"';
} elseif ($st === 'in_progress') {
    $wheres[] = 's.status_name IN ("Open","Stuck")';
} elseif ($st === 'completed') {
    $wheres[] = 's.status_name = "Completed"';
} elseif ($st === 'rejected') {
    $wheres[] = '(s.status_name = "Cancelled" OR EXISTS (SELECT 1 FROM ticket_approvals tx WHERE tx.ticket_id=t.id AND tx.approval_status="rejected"))';
}
if ($orgCode !== '' && function_exists('sbs_org_code_filter_sql')) {
    [$orgSql, $orgParams] = sbs_org_code_filter_sql('t', $orgCode);
    if ($orgSql !== '') {
        $wheres[] = $orgSql;
        array_push($params, ...$orgParams);
    }
}

$sql = $base . ' WHERE ' . implode(' AND ', $wheres) . ' ORDER BY t.created_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Requests';
$activeNav = 'requests';
$includeCharts = false;
$topbarSearchQuery = '';

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="page-title-block mb-0">
            <h1 class="mb-0">Requests</h1>
            <div class="subtitle">Submit and track service requests</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-muted btn-sm" href="create_ticket.php"><i class="bi bi-plus-lg"></i> New Request (advanced)</a>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <a class="btn btn-accent" href="new_request.php"><i class="bi bi-plus-lg"></i> New Request</a>
        <form method="get" class="d-flex gap-2 ms-auto">
            <input type="hidden" name="st" value="<?php echo e($st); ?>">
            <input type="hidden" name="queue" value="<?php echo e($queue); ?>">
            <input type="text" name="org_code" class="form-control form-control-sm" placeholder="Org Code" value="<?php echo e($orgCode); ?>">
            <button class="btn btn-outline-muted btn-sm" type="submit">Filter</button>
        </form>
    </div>

    <div class="alert alert-light border small mb-3">
        <strong>Assigned to Me</strong> means you are responsible for working the ticket.
        <strong>Pending My Approval</strong> means you must approve or reject — open the ticket to see Approve/Reject when you are on the approval chain (or have an override role).
    </div>

    <h2 class="h5 fw-bold mb-2">My requests</h2>
    <ul class="nav nav-pills flex-wrap gap-2 mb-2">
        <?php
        $mainTabs = [
            'all' => 'All',
            'pending_approval' => 'Pending Approval',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'rejected' => 'Rejected',
        ];
        foreach ($mainTabs as $key => $label) :
            $active = $st === $key ? 'active' : '';
            $href = 'requests.php?st=' . rawurlencode($key) . '&queue=' . rawurlencode($queue);
            ?>
            <li class="nav-item">
                <a class="nav-link <?php echo e($active); ?>" href="<?php echo e($href); ?>"><?php echo e($label); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <ul class="nav nav-tabs flex-wrap gap-1 mb-3 small">
        <?php
        $qTabs = [
            'any' => 'All involvement',
            'created' => 'Created by Me',
            'assigned' => 'Assigned to Me',
            'pending' => 'Pending My Approval',
            'stuck' => 'Stuck / Cancelled',
        ];
        foreach ($qTabs as $key => $label) :
            $active = $queue === $key ? 'active' : '';
            $href = 'requests.php?st=' . rawurlencode($st) . '&queue=' . rawurlencode($key);
            ?>
            <li class="nav-item">
                <a class="nav-link py-1 px-2 <?php echo e($active); ?>" href="<?php echo e($href); ?>"><?php echo e($label); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="card-surface p-0 overflow-auto">
        <table class="table table-modern table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Request ID</th>
                    <th>Request type</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>ETA</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr>
                    <td class="fw-semibold"><?php echo e($r['ticket_number']); ?></td>
                    <td><?php echo e(short_text((string) $r['subject'], 60)); ?></td>
                    <td><?php echo e(date('m/d/Y H:i', strtotime((string) $r['created_at']))); ?></td>
                    <td>
                        <span class="badge text-bg-light border"><?php echo e($r['status_name']); ?></span>
                        <?php if (!empty($r['approval_required_for_me'])) : ?>
                            <span class="badge bg-warning text-dark ms-1">Approval Required</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">—</td>
                    <td><a class="btn btn-sm btn-accent" href="ticket_detail.php?id=<?php echo (int) $r['id']; ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows) : ?>
                <tr><td colspan="6" class="text-center text-muted py-5">No requests in this view.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
