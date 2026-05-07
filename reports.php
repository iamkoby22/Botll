<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('reports');

if (isset($_GET['export']) && (string) $_GET['export'] === 'csv') {
    $scope = tickets_scope_sql('t');
    $pdo = db();
    $df = trim((string) ($_GET['date_from'] ?? ''));
    $dt = trim((string) ($_GET['date_to'] ?? ''));
    $deptId = (int) ($_GET['department_id'] ?? 0);
    $catId = (int) ($_GET['category_id'] ?? 0);
    $priId = (int) ($_GET['priority_id'] ?? 0);
    $w = [$scope];
    $params = [];
    if ($df !== '') {
        $w[] = 'DATE(t.created_at) >= ?';
        $params[] = $df;
    }
    if ($dt !== '') {
        $w[] = 'DATE(t.created_at) <= ?';
        $params[] = $dt;
    }
    if ($deptId > 0) {
        $w[] = 't.department_id = ?';
        $params[] = $deptId;
    }
    if ($catId > 0) {
        $w[] = 't.category_id = ?';
        $params[] = $catId;
    }
    if ($priId > 0) {
        $w[] = 't.priority_id = ?';
        $params[] = $priId;
    }
    $where = implode(' AND ', $w);
    $summary = $pdo->prepare(
        'SELECT d.department_name,
                COUNT(*) total_tickets,
                SUM(CASE WHEN s.status_name = "Open" THEN 1 ELSE 0 END) open_cnt,
                SUM(CASE WHEN s.status_name = "Completed" THEN 1 ELSE 0 END) done_cnt,
                SUM(CASE WHEN t.sla_breach = 1 THEN 1 ELSE 0 END) sla_cnt
         FROM tickets t
         JOIN departments d ON d.id=t.department_id
         JOIN ticket_statuses s ON s.id=t.status_id
         WHERE ' . $where . '
         GROUP BY d.id, d.department_name
         ORDER BY total_tickets DESC'
    );
    $summary->execute($params);
    $rows = $summary->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="botll-report-summary.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Department', 'Total', 'Open', 'Completed', 'SLA breaches']);
    foreach ($rows as $sr) {
        fputcsv($out, [
            (string) $sr['department_name'],
            (string) $sr['total_tickets'],
            (string) $sr['open_cnt'],
            (string) $sr['done_cnt'],
            (string) $sr['sla_cnt'],
        ]);
    }
    fclose($out);
    exit;
}

$scope = tickets_scope_sql('t');
$pdo = db();

$df = trim((string) ($_GET['date_from'] ?? ''));
$dt = trim((string) ($_GET['date_to'] ?? ''));
$deptId = (int) ($_GET['department_id'] ?? 0);
$catId = (int) ($_GET['category_id'] ?? 0);
$priId = (int) ($_GET['priority_id'] ?? 0);

$w = [$scope];
$params = [];
if ($df !== '') {
    $w[] = 'DATE(t.created_at) >= ?';
    $params[] = $df;
}
if ($dt !== '') {
    $w[] = 'DATE(t.created_at) <= ?';
    $params[] = $dt;
}
if ($deptId > 0) {
    $w[] = 't.department_id = ?';
    $params[] = $deptId;
}
if ($catId > 0) {
    $w[] = 't.category_id = ?';
    $params[] = $catId;
}
if ($priId > 0) {
    $w[] = 't.priority_id = ?';
    $params[] = $priId;
}
$where = implode(' AND ', $w);

function rep_count(PDO $pdo, string $where, array $params, string $extraSql = '', bool $joinStatus = false): int
{
    $join = $joinStatus ? 'JOIN ticket_statuses s ON s.id = t.status_id ' : '';
    $sql = 'SELECT COUNT(*) c FROM tickets t ' . $join . 'WHERE ' . $where . ($extraSql !== '' ? ' AND ' . $extraSql : '');
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) ($st->fetch()['c'] ?? 0);
}

$stc = $pdo->prepare('SELECT COUNT(*) c FROM tickets t WHERE ' . $where);
$stc->execute($params);
$tot = (int) ($stc->fetch()['c'] ?? 0);

$open = rep_count($pdo, $where, $params, 's.status_name = "Open"', true);
$completed = rep_count($pdo, $where, $params, 's.status_name = "Completed"', true);
$sla = rep_count($pdo, $where, $params, 't.sla_breach = 1', false);
$pending = rep_count($pdo, $where, $params, 's.status_name = "Pending Approval"', true);

$stAvg = $pdo->prepare(
    'SELECT AVG(t.response_time_minutes) v FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE ' . $where . ' AND s.status_name="Completed"'
);
$stAvg->execute($params);
$avgRt = (float) ($stAvg->fetch()['v'] ?? 0);

$deptChart = $pdo->prepare(
    'SELECT d.department_name label, COUNT(*) v FROM tickets t JOIN departments d ON d.id=t.department_id WHERE ' . $where . ' GROUP BY d.id, d.department_name ORDER BY v DESC'
);
$deptChart->execute($params);
$deptRows = $deptChart->fetchAll();

$priChart = $pdo->prepare(
    'SELECT p.priority_name label, COUNT(*) v FROM tickets t JOIN ticket_priorities p ON p.id=t.priority_id WHERE ' . $where . ' GROUP BY p.id, p.priority_name ORDER BY v DESC'
);
$priChart->execute($params);
$priRows = $priChart->fetchAll();

$statusChart = $pdo->prepare(
    'SELECT s.status_name label, COUNT(*) v FROM tickets t JOIN ticket_statuses s ON s.id=t.status_id WHERE ' . $where . ' GROUP BY s.id, s.status_name'
);
$statusChart->execute($params);
$statusRows = $statusChart->fetchAll();

$trendCreated = $pdo->prepare(
    'SELECT DATE_FORMAT(t.created_at,"%Y-%m") m, COUNT(*) c FROM tickets t WHERE ' . $where . ' GROUP BY m ORDER BY m DESC LIMIT 8'
);
$trendCreated->execute($params);
$tcRows = $trendCreated->fetchAll();

$trendResolved = $pdo->prepare(
    'SELECT DATE_FORMAT(t.date_completed,"%Y-%m") m, COUNT(*) c FROM tickets t WHERE ' . $where . ' AND t.date_completed IS NOT NULL GROUP BY m ORDER BY m DESC LIMIT 8'
);
$trendResolved->execute($params);
$trRows = $trendResolved->fetchAll();

$slaDept = $pdo->prepare(
    'SELECT d.department_name label, SUM(t.sla_breach=1) v FROM tickets t JOIN departments d ON d.id=t.department_id WHERE ' . $where . ' GROUP BY d.id, d.department_name ORDER BY v DESC'
);
$slaDept->execute($params);
$slaRows = $slaDept->fetchAll();

$summary = $pdo->prepare(
    'SELECT d.department_name,
            COUNT(*) total_tickets,
            SUM(CASE WHEN s.status_name = "Open" THEN 1 ELSE 0 END) open_cnt,
            SUM(CASE WHEN s.status_name = "Completed" THEN 1 ELSE 0 END) done_cnt,
            SUM(CASE WHEN t.sla_breach = 1 THEN 1 ELSE 0 END) sla_cnt,
            AVG(CASE WHEN s.status_name = "Completed" AND t.date_completed IS NOT NULL THEN DATEDIFF(t.date_completed, DATE(t.created_at)) END) avg_days
     FROM tickets t
     JOIN departments d ON d.id=t.department_id
     JOIN ticket_statuses s ON s.id=t.status_id
     WHERE ' . $where . '
     GROUP BY d.id, d.department_name
     ORDER BY total_tickets DESC'
);
$summary->execute($params);
$summaryRows = $summary->fetchAll();

$deptLabels = array_column($deptRows, 'label');
$deptValues = array_map('intval', array_column($deptRows, 'v'));
$priLabels = array_column($priRows, 'label');
$priValues = array_map('intval', array_column($priRows, 'v'));
$statusLabels = array_column($statusRows, 'label');
$statusValues = array_map('intval', array_column($statusRows, 'v'));
$slaLabels = array_column($slaRows, 'label');
$slaValues = array_map('intval', array_column($slaRows, 'v'));

$months = array_values(array_unique(array_merge(array_column($tcRows, 'm'), array_column($trRows, 'm'))));
sort($months);
$createdMap = [];
foreach ($tcRows as $r) {
    $createdMap[$r['m']] = (int) $r['c'];
}
$resolvedMap = [];
foreach ($trRows as $r) {
    $resolvedMap[$r['m']] = (int) $r['c'];
}
$createdLine = [];
$resolvedLine = [];
foreach ($months as $m) {
    $createdLine[] = $createdMap[$m] ?? 0;
    $resolvedLine[] = $resolvedMap[$m] ?? 0;
}

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
$categories = $pdo->query('SELECT * FROM ticket_categories ORDER BY category_name')->fetchAll();
$priorities = $pdo->query('SELECT * FROM ticket_priorities ORDER BY priority_level')->fetchAll();

$pageTitle = 'Reports';
$activeNav = 'reports';
$includeCharts = true;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3 d-flex flex-wrap justify-content-between gap-2">
        <div>
            <h1>Reports</h1>
            <div class="subtitle">Operational analytics and export-ready summaries</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-muted btn-sm" href="reports.php?<?php echo e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">Export CSV</a>
            <button type="button" class="btn btn-outline-muted btn-sm" onclick="alert('Export PDF is a placeholder in this prototype.');">Export PDF</button>
            <button type="button" class="btn btn-outline-muted btn-sm" onclick="window.print();">Print Report</button>
        </div>
    </div>

    <form class="card-surface p-3 mb-3" method="get" action="reports.php">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo e($df); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo e($dt); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Department</label>
                <select class="form-select form-select-sm" name="department_id">
                    <option value="0">All</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo $deptId === (int) $d['id'] ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Category</label>
                <select class="form-select form-select-sm" name="category_id">
                    <option value="0">All</option>
                    <?php foreach ($categories as $c) : ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $catId === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Priority</label>
                <select class="form-select form-select-sm" name="priority_id">
                    <option value="0">All</option>
                    <?php foreach ($priorities as $p) : ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $priId === (int) $p['id'] ? 'selected' : ''; ?>><?php echo e($p['priority_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-accent btn-sm w-100" type="submit">Apply</button>
                <a class="btn btn-outline-muted btn-sm w-100" href="reports.php">Reset</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-md-4 col-xl-2">
            <div class="stat-card"><div class="label">Total</div><div class="value"><?php echo $tot; ?></div></div>
        </div>
        <div class="col-md-4 col-xl-2">
            <div class="stat-card"><div class="label">Open</div><div class="value"><?php echo $open; ?></div></div>
        </div>
        <div class="col-md-4 col-xl-2">
            <div class="stat-card"><div class="label">Completed</div><div class="value"><?php echo $completed; ?></div></div>
        </div>
        <div class="col-md-4 col-xl-2">
            <div class="stat-card"><div class="label">SLA breaches</div><div class="value"><?php echo $sla; ?></div></div>
        </div>
        <div class="col-md-4 col-xl-2">
            <div class="stat-card"><div class="label">Avg response (min)</div><div class="value"><?php echo e(number_format($avgRt, 0)); ?></div></div>
        </div>
        <div class="col-md-4 col-xl-2">
            <div class="stat-card"><div class="label">Pending approval</div><div class="value"><?php echo $pending; ?></div></div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100 chart-card">
                <div class="fw-bold mb-2">Tickets by department</div>
                <div class="chart-container"><canvas id="repDept"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100 chart-card">
                <div class="fw-bold mb-2">Tickets by priority</div>
                <div class="chart-container"><canvas id="repPri"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-surface p-3 h-100 chart-card">
                <div class="fw-bold mb-2">Tickets by status</div>
                <div class="chart-container"><canvas id="repStatus"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card-surface p-3 h-100 chart-card chart-card--tall">
                <div class="fw-bold mb-2">Created vs completed</div>
                <div class="chart-container"><canvas id="repTrend"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card-surface p-3 h-100 chart-card chart-card--tall">
                <div class="fw-bold mb-2">SLA breaches by department</div>
                <div class="chart-container"><canvas id="repSla"></canvas></div>
            </div>
        </div>
    </div>

    <div class="card-surface p-0 overflow-auto">
        <table class="table table-modern mb-0">
            <thead class="table-light">
                <tr>
                    <th>Department</th>
                    <th>Total</th>
                    <th>Open</th>
                    <th>Completed</th>
                    <th>SLA breaches</th>
                    <th>Avg completion (days)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($summaryRows as $sr) : ?>
                <tr>
                    <td><?php echo e($sr['department_name']); ?></td>
                    <td><?php echo (int) $sr['total_tickets']; ?></td>
                    <td><?php echo (int) $sr['open_cnt']; ?></td>
                    <td><?php echo (int) $sr['done_cnt']; ?></td>
                    <td><?php echo (int) $sr['sla_cnt']; ?></td>
                    <td><?php echo e(number_format((float) ($sr['avg_days'] ?? 0), 1)); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$summaryRows) : ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No data for the selected filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.__REPORTS__ = {
  deptLabels: <?php echo json_encode($deptLabels, JSON_UNESCAPED_UNICODE); ?>,
  deptValues: <?php echo json_encode($deptValues); ?>,
  priLabels: <?php echo json_encode($priLabels, JSON_UNESCAPED_UNICODE); ?>,
  priValues: <?php echo json_encode($priValues); ?>,
  statusLabels: <?php echo json_encode($statusLabels, JSON_UNESCAPED_UNICODE); ?>,
  statusValues: <?php echo json_encode($statusValues); ?>,
  trendLabels: <?php echo json_encode($months); ?>,
  createdLine: <?php echo json_encode($createdLine); ?>,
  resolvedLine: <?php echo json_encode($resolvedLine); ?>,
  slaLabels: <?php echo json_encode($slaLabels, JSON_UNESCAPED_UNICODE); ?>,
  slaValues: <?php echo json_encode($slaValues); ?>
};
</script>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
