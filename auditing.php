<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('auditing');

$pdo = db();

$kpi = static function (string $sql) use ($pdo): int {
    return (int) ($pdo->query($sql)->fetch()['c'] ?? 0);
};

$total = $kpi('SELECT COUNT(*) c FROM tickets');
$open = $kpi('SELECT COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE s.status_name="Open"');
$closedAwait = 0;
try {
    $closedAwait = $kpi('SELECT COUNT(*) c FROM tickets t WHERE ' . tickets_awaiting_audit_sql('t'));
} catch (Throwable $e) {
    $closedAwait = $kpi('SELECT COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE s.status_name="Closed"');
}
$completed = $kpi('SELECT COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE s.status_name="Completed"');
$rejected = $kpi('SELECT COUNT(*) c FROM tickets t WHERE EXISTS (SELECT 1 FROM ticket_approvals ta WHERE ta.ticket_id=t.id AND ta.approval_status="rejected")');
$stuck = $kpi('SELECT COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE s.status_name="Stuck"');
$pending = $kpi('SELECT COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE s.status_name="Pending Approval"');
$slaRisk = $kpi('SELECT COUNT(*) c FROM tickets t WHERE t.sla_breach=1 OR t.is_late=1');
$noAssignee = $kpi('SELECT COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE t.assigned_to IS NULL AND s.status_name IN ("Open","Pending Approval")');

$staleLevel = [];
try {
    $staleLevel = $pdo->query(
        'SELECT t.id, t.ticket_number, t.subject, ta.assignment_level, u.full_name, t.updated_at
         FROM tickets t
         JOIN ticket_assignees ta ON ta.ticket_id = t.id AND ta.assignment_status = "active"
         JOIN users u ON u.id = ta.user_id
         WHERE t.updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
         ORDER BY t.updated_at ASC
         LIMIT 20'
    )->fetchAll();
} catch (Throwable $e) {
    $staleLevel = [];
}

$flags = [];

$doneNoAudit = $pdo->query(
    'SELECT t.id, t.ticket_number, t.subject, s.status_name, t.work_done_at, t.updated_at
     FROM tickets t
     JOIN ticket_statuses s ON s.id = t.status_id
     WHERE s.status_name = "Closed"
     ORDER BY t.work_done_at DESC, t.updated_at DESC
     LIMIT 25'
)->fetchAll();
foreach ($doneNoAudit as $r) {
    $flags[] = [
        'severity' => 'Warning',
        'issue' => 'Done — awaiting admin audit',
        'ticket' => $r,
    ];
}

foreach ($staleLevel as $r) {
    $flags[] = [
        'severity' => 'Warning',
        'issue' => 'Stale at assignment Level ' . (int) ($r['assignment_level'] ?? 1) . ' (' . e((string) $r['full_name']) . ')',
        'ticket' => $r,
    ];
}

$completedNoWork = $pdo->query(
    'SELECT t.id, t.ticket_number, t.subject, s.status_name
     FROM tickets t
     JOIN ticket_statuses s ON s.id = t.status_id
     WHERE s.status_name = "Completed" AND t.work_done_at IS NULL AND t.assigned_to IS NOT NULL
     LIMIT 15'
)->fetchAll();
foreach ($completedNoWork as $r) {
    $flags[] = [
        'severity' => 'Critical',
        'issue' => 'Completed without assigned work marked Done',
        'ticket' => $r,
    ];
}

$stale = $pdo->query(
    'SELECT t.id, t.ticket_number, t.subject, s.status_name, t.created_at,
            DATEDIFF(NOW(), t.created_at) days_open
     FROM tickets t
     JOIN ticket_statuses s ON s.id = t.status_id
     WHERE s.status_name IN ("Open","Pending Approval","Stuck")
       AND t.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
     ORDER BY t.created_at ASC
     LIMIT 15'
)->fetchAll();
foreach ($stale as $r) {
    $flags[] = [
        'severity' => 'Warning',
        'issue' => 'Long open duration (' . (int) $r['days_open'] . ' days)',
        'ticket' => $r,
    ];
}

$noActivity = $pdo->query(
    'SELECT t.id, t.ticket_number, t.subject, s.status_name
     FROM tickets t
     JOIN ticket_statuses s ON s.id = t.status_id
     WHERE s.status_name = "Open"
       AND NOT EXISTS (SELECT 1 FROM ticket_comments tc WHERE tc.ticket_id = t.id AND tc.created_at > DATE_SUB(NOW(), INTERVAL 14 DAY))
     LIMIT 15'
)->fetchAll();
foreach ($noActivity as $r) {
    $flags[] = [
        'severity' => 'Warning',
        'issue' => 'No conversation activity in 14 days',
        'ticket' => $r,
    ];
}

$recentEvents = [];
try {
    $recentEvents = $pdo->query(
        'SELECT th.*, u.full_name, t.ticket_number
         FROM ticket_history th
         JOIN users u ON u.id = th.changed_by
         JOIN tickets t ON t.id = th.ticket_id
         ORDER BY th.id DESC
         LIMIT 30'
    )->fetchAll();
} catch (Throwable $e) {
    $recentEvents = [];
}

$pageTitle = 'Auditing';
$activeNav = 'auditing';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1>Auditing</h1>
        <div class="subtitle">Platform-wide workflow health and flagged processes (Super Admin)</div>
    </div>

    <div class="row g-3 mb-3">
        <?php
        $cards = [
            ['Total tickets', $total, 'Healthy'],
            ['Open / in progress', $open, ''],
            ['Done / Closed awaiting audit', $closedAwait, $closedAwait > 0 ? 'Warning' : 'Healthy'],
            ['Completed (admin)', $completed, ''],
            ['Pending approval', $pending, ''],
            ['Rejected (chain)', $rejected, ''],
            ['Stuck', $stuck, $stuck > 0 ? 'Critical' : 'Healthy'],
            ['SLA / late risk', $slaRisk, $slaRisk > 0 ? 'Warning' : 'Healthy'],
            ['Open with no assignee', $noAssignee, $noAssignee > 0 ? 'Warning' : 'Healthy'],
        ];
        foreach ($cards as $c) :
            ?>
            <div class="col-md-6 col-xl-4 col-xxl-3">
                <div class="stat-card h-100">
                    <div class="label"><?php echo e($c[0]); ?></div>
                    <div class="value"><?php echo (int) $c[1]; ?></div>
                    <?php if ($c[2] !== '') : ?>
                        <span class="badge <?php echo $c[2] === 'Critical' ? 'bg-danger' : ($c[2] === 'Warning' ? 'bg-warning text-dark' : 'bg-success'); ?>"><?php echo e($c[2]); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card-surface p-3">
                <h2 class="h6 fw-bold mb-3">Flagged processes</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-modern mb-0">
                        <thead><tr><th>Severity</th><th>Issue</th><th>Ticket</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$flags) : ?>
                            <tr><td colspan="4" class="text-muted">No flagged items — platform looks healthy.</td></tr>
                        <?php else : ?>
                            <?php foreach (array_slice($flags, 0, 40) as $f) :
                                $t = $f['ticket'];
                                $sev = (string) $f['severity'];
                                ?>
                                <tr>
                                    <td><span class="badge <?php echo $sev === 'Critical' ? 'bg-danger' : 'bg-warning text-dark'; ?>"><?php echo e($sev); ?></span></td>
                                    <td class="small"><?php echo e((string) $f['issue']); ?></td>
                                    <td class="small"><strong><?php echo e((string) $t['ticket_number']); ?></strong><br><?php echo e(short_text((string) ($t['subject'] ?? ''), 40)); ?></td>
                                    <td><a class="btn btn-sm btn-outline-muted" href="ticket_detail.php?id=<?php echo (int) $t['id']; ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card-surface p-3">
                <h2 class="h6 fw-bold mb-3">Recent audit events</h2>
                <?php if (!$recentEvents) : ?>
                    <p class="small text-muted mb-0">No history entries yet.</p>
                <?php else : ?>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($recentEvents as $ev) : ?>
                            <li class="border-bottom py-2">
                                <a href="ticket_detail.php?id=<?php echo (int) $ev['ticket_id']; ?>"><?php echo e((string) $ev['ticket_number']); ?></a>
                                · <strong><?php echo e((string) $ev['field_changed']); ?></strong>
                                <div class="text-muted d-block"><?php echo e((string) $ev['full_name']); ?> · <?php echo e(date('m/d/Y H:i', strtotime((string) $ev['created_at']))); ?></div>
                                <span class="text-muted"><?php echo e((string) ($ev['old_value'] ?? '')); ?> → <?php echo e((string) ($ev['new_value'] ?? '')); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
