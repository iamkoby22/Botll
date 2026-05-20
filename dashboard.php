<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('dashboard');

$scope = tickets_active_scope_sql('t');
$pdo = db();
if (function_exists('ticket_apply_sla_status_batch')) {
    ticket_apply_sla_status_batch();
}

$tot = (int) $pdo->query('SELECT COUNT(*) c FROM tickets t WHERE ' . $scope)->fetch()['c'];

$statusCounts = $pdo->query(
    'SELECT s.status_name, COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $scope . ' GROUP BY s.id, s.status_name'
)->fetchAll();

$map = [];
foreach ($statusCounts as $row) {
    $map[(string) $row['status_name']] = (int) $row['c'];
}

$open = $map['Open'] ?? 0;
$closedStatus = $map['Closed'] ?? 0;
$completed = $map['Completed'] ?? 0;
$stuck = $map['Stuck'] ?? 0;
$canceled = $map['Cancelled'] ?? 0;
$pending = $map['Pending Approval'] ?? 0;

$awaitingAudit = 0;
try {
    $awaitingAudit = (int) $pdo->query(
        'SELECT COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $scope . ' AND ' . tickets_awaiting_audit_sql('t')
    )->fetch()['c'];
} catch (Throwable $e) {
    $awaitingAudit = $closedStatus;
}

$roleKey = (string) current_user()['role_key'];
$scopeLabel = match ($roleKey) {
    'user' => 'Your tickets and assignments',
    'hod', 'director' => 'Your department',
    default => 'Platform-wide',
};

$late = (int) $pdo->query('SELECT COUNT(*) c FROM tickets t WHERE ' . $scope . ' AND t.is_late = 1')->fetch()['c'];
$wf = ticket_assignment_workflow_metrics($scope);

$avgRow = $pdo->query(
    'SELECT AVG(t.response_time_minutes) avg_rt, AVG(t.csat_score) avg_csat FROM tickets t WHERE ' . $scope . ' AND t.status_id = (SELECT id FROM ticket_statuses WHERE status_name = "Completed" LIMIT 1)'
)->fetch();

$avgRt = (float) ($avgRow['avg_rt'] ?? 0);
$avgCsat = (float) ($avgRow['avg_csat'] ?? 0);

$deptChart = $pdo->query(
    'SELECT d.department_name label, COUNT(*) v FROM tickets t JOIN departments d ON d.id = t.department_id WHERE ' . $scope . ' GROUP BY d.id, d.department_name ORDER BY v DESC'
)->fetchAll();

$priChart = $pdo->query(
    'SELECT p.priority_name label, COUNT(*) v FROM tickets t JOIN ticket_priorities p ON p.id = t.priority_id WHERE ' . $scope . ' GROUP BY p.id, p.priority_name ORDER BY p.priority_level DESC'
)->fetchAll();

$resolvedTrend = $pdo->query(
    'SELECT DATE_FORMAT(t.date_completed, "%Y-%m") m, COUNT(*) c FROM tickets t WHERE ' . $scope . ' AND t.date_completed IS NOT NULL GROUP BY m ORDER BY m DESC LIMIT 6'
)->fetchAll();
$createdTrend = $pdo->query(
    'SELECT DATE_FORMAT(t.created_at, "%Y-%m") m, COUNT(*) c FROM tickets t WHERE ' . $scope . ' GROUP BY m ORDER BY m DESC LIMIT 6'
)->fetchAll();

$deptLabels = array_column($deptChart, 'label');
$deptValues = array_map('intval', array_column($deptChart, 'v'));
$priLabels = array_column($priChart, 'label');
$priValues = array_map('intval', array_column($priChart, 'v'));

$lineLabels = array_values(array_unique(array_merge(
    array_column($resolvedTrend, 'm'),
    array_column($createdTrend, 'm')
)));
sort($lineLabels);
$createdMap = [];
foreach ($createdTrend as $r) {
    $createdMap[$r['m']] = (int) $r['c'];
}
$resolvedMap = [];
foreach ($resolvedTrend as $r) {
    $resolvedMap[$r['m']] = (int) $r['c'];
}
$createdLine = [];
$resolvedLine = [];
foreach ($lineLabels as $m) {
    $createdLine[] = $createdMap[$m] ?? 0;
    $resolvedLine[] = $resolvedMap[$m] ?? 0;
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$includeCharts = true;

require __DIR__ . '/includes/shell_begin.php';
$f = flash_get();
if ($f) :
    ?>
    <div class="container-fluid px-3 px-lg-4">
        <div class="alert alert-<?php echo $f['type'] === 'danger' ? 'danger' : ($f['type'] === 'success' ? 'success' : 'info'); ?>"><?php echo e($f['message']); ?></div>
    </div>
<?php endif; ?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1 class="mb-0">Dashboard</h1>
        <div class="subtitle">Operational overview — <?php echo e($scopeLabel); ?></div>
    </div>

    <div class="alert-strip px-3 py-2 mb-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <div class="d-flex align-items-center gap-2 small fw-semibold text-dark">
            <i class="bi bi-exclamation-octagon text-danger"></i>
            Critical Tickets Alert — review overdue and SLA-risk items
        </div>
        <span class="badge text-bg-light border"><?php echo (int) $late; ?> late</span>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="label">Total Tickets</div>
                <div class="value"><?php echo (int) $tot; ?></div>
                <div class="meta">Across all categories</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="label">Number of Late Tickets</div>
                <div class="value"><?php echo (int) $late; ?></div>
                <div class="meta"><?php echo $late ? 'Review SLA targets' : 'No late items'; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="label">Average Response Time</div>
                <div class="value"><?php echo e(number_format($avgRt, 0)); ?> <span class="fs-6">min</span></div>
                <div class="meta">Completed tickets</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="label">CSAT Score</div>
                <div class="value"><?php echo e(number_format($avgCsat, 1)); ?> <span class="fs-6">/ 5.0</span></div>
                <div class="meta">Survey sample</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="label">Open Tickets</div>
                <div class="value"><?php echo (int) $open; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="label">Done / Closed (awaiting audit)</div>
                <div class="value"><?php echo (int) $awaitingAudit; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="label">Stuck Tickets</div>
                <div class="value"><?php echo (int) $stuck; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="label">Canceled Tickets</div>
                <div class="value"><?php echo (int) $canceled; ?></div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/includes/dashboard_workflow_metrics.php'; ?>

    <?php
    $analyticsPayload = analytics_dashboard_payload();
    require __DIR__ . '/includes/dashboard_analytics_section.php';
    ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100 chart-card">
                <div class="fw-bold mb-2">Tickets Created by Dept</div>
                <div class="chart-container"><canvas id="chartDept"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100 chart-card">
                <div class="fw-bold mb-2">Tickets created and Resolved</div>
                <div class="chart-container"><canvas id="chartTrend"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100 chart-card">
                <div class="fw-bold mb-2">Tickets created by Priority</div>
                <div class="chart-container"><canvas id="chartPriority"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-bold">Recent tickets</div>
                    <a class="small" href="all_tickets.php">View all</a>
                </div>
                <ul class="list-unstyled small mb-0">
                    <?php
                    $recent = $pdo->query(
                        'SELECT t.id, t.ticket_number, t.subject, s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE ' . $scope . ' ORDER BY t.created_at DESC LIMIT 6'
                    )->fetchAll();
                    foreach ($recent as $r) :
                        ?>
                        <li class="py-1 border-bottom">
                            <a class="text-decoration-none" href="ticket_detail.php?id=<?php echo (int) $r['id']; ?>">
                                <strong><?php echo e($r['ticket_number']); ?></strong>
                                <span class="text-muted"> · <?php echo e(short_text((string) $r['subject'], 42)); ?></span>
                            </a>
                            <div class="text-muted" style="font-size:0.72rem;"><?php echo e($r['status_name']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-bold">Assigned to me</div>
                    <a class="small" href="requests.php?queue=assigned&amp;st=all">Open queue</a>
                </div>
                <ul class="list-unstyled small mb-0">
                    <?php
                    $uid = (int) current_user()['id'];
                    $mine = $pdo->prepare(
                        'SELECT t.id, t.ticket_number, t.subject, s.status_name FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id
                         WHERE (' . $scope . ') AND (t.assigned_to = ? OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id=t.id AND ta.user_id=?))
                         ORDER BY t.created_at DESC LIMIT 6'
                    );
                    $mine->execute([$uid, $uid]);
                    foreach ($mine->fetchAll() as $r) :
                        ?>
                        <li class="py-1 border-bottom">
                            <a class="text-decoration-none" href="ticket_detail.php?id=<?php echo (int) $r['id']; ?>">
                                <strong><?php echo e($r['ticket_number']); ?></strong>
                                <span class="text-muted"> · <?php echo e(short_text((string) $r['subject'], 42)); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-bold">Notifications</div>
                    <a class="small" href="requests.php?queue=pending&amp;st=pending_approval">Approvals</a>
                </div>
                <ul class="list-unstyled small mb-0">
                    <?php
                    $ns = $pdo->prepare('SELECT id, title, message, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
                    $ns->execute([(int) current_user()['id']]);
                    foreach ($ns->fetchAll() as $n) :
                        ?>
                        <li class="py-1 border-bottom <?php echo empty($n['is_read']) ? 'bg-soft-unread' : ''; ?>">
                            <a class="text-decoration-none" href="notifications_go.php?id=<?php echo (int) $n['id']; ?>">
                                <strong><?php echo e($n['title']); ?></strong>
                                <div class="text-muted"><?php echo e(short_text((string) $n['message'], 80)); ?></div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
window.__DASHBOARD__ = {
  deptLabels: <?php echo json_encode($deptLabels, JSON_UNESCAPED_UNICODE); ?>,
  deptValues: <?php echo json_encode($deptValues); ?>,
  priLabels: <?php echo json_encode($priLabels, JSON_UNESCAPED_UNICODE); ?>,
  priValues: <?php echo json_encode($priValues); ?>,
  trendLabels: <?php echo json_encode($lineLabels); ?>,
  createdLine: <?php echo json_encode($createdLine); ?>,
  resolvedLine: <?php echo json_encode($resolvedLine); ?>,
  pendingApproval: <?php echo (int) $pending; ?>,
  analytics: <?php echo json_encode($analyticsPayload, JSON_UNESCAPED_UNICODE); ?>
};
</script>

<?php require __DIR__ . '/includes/shell_end.php'; ?>

