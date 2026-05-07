<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('all_tickets');

$scope = tickets_scope_sql('t');
$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$statusId = (int) ($_GET['status_id'] ?? 0);
$priorityId = (int) ($_GET['priority_id'] ?? 0);
$categoryId = (int) ($_GET['category_id'] ?? 0);
$assigneeId = (int) ($_GET['assignee_id'] ?? 0);
$departmentId = (int) ($_GET['department_id'] ?? 0);
$sla = trim((string) ($_GET['sla'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$assignedFilter = trim((string) ($_GET['assigned'] ?? ''));
$duration = trim((string) ($_GET['duration'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$wheres = [$scope];
$params = [];

if ($q !== '') {
    $wheres[] = '(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR t.account_number LIKE ?
        OR cu.full_name LIKE ? OR cu.email LIKE ? OR au.full_name LIKE ? OR d.department_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($statusId > 0) {
    $wheres[] = 't.status_id = ?';
    $params[] = $statusId;
}
if ($priorityId > 0) {
    $wheres[] = 't.priority_id = ?';
    $params[] = $priorityId;
}
if ($categoryId > 0) {
    $wheres[] = 't.category_id = ?';
    $params[] = $categoryId;
}
if ($assigneeId > 0) {
    $wheres[] = 't.assigned_to = ?';
    $params[] = $assigneeId;
}
if ($departmentId > 0) {
    $wheres[] = 't.department_id = ?';
    $params[] = $departmentId;
}
if ($sla === '1') {
    $wheres[] = 't.sla_breach = 1';
} elseif ($sla === '0') {
    $wheres[] = 't.sla_breach = 0';
}
if ($dateFrom !== '') {
    $wheres[] = 'DATE(t.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $wheres[] = 'DATE(t.created_at) <= ?';
    $params[] = $dateTo;
}
if ($assignedFilter === 'me') {
    $uid = (int) current_user()['id'];
    $wheres[] = '(t.assigned_to = ? OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = ?))';
    $params[] = $uid;
    $params[] = $uid;
}
if ($duration === '7d') {
    $wheres[] = 't.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($duration === '30d') {
    $wheres[] = 't.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
} elseif ($duration === '90d') {
    $wheres[] = 't.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
}

$whereSql = implode(' AND ', $wheres);

$countSql = 'SELECT COUNT(*) c FROM tickets t
        JOIN users cu ON cu.id = t.created_by
        LEFT JOIN users au ON au.id = t.assigned_to
        JOIN departments d ON d.id = t.department_id
        WHERE ' . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) ($countStmt->fetch()['c'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$sql = 'SELECT t.*,
        s.status_name, p.priority_name, c.category_name, d.department_name,
        cu.full_name AS created_name, cu.email AS created_email,
        au.full_name AS assignee_name,
        ap.full_name AS approver_name
        FROM tickets t
        JOIN ticket_statuses s ON s.id = t.status_id
        JOIN ticket_priorities p ON p.id = t.priority_id
        JOIN ticket_categories c ON c.id = t.category_id
        JOIN departments d ON d.id = t.department_id
        JOIN users cu ON cu.id = t.created_by
        LEFT JOIN users au ON au.id = t.assigned_to
        LEFT JOIN users ap ON ap.id = t.approved_by
        WHERE ' . $whereSql . '
        ORDER BY t.created_at DESC
        LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$stats = $pdo->query(
    'SELECT s.status_name, COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $scope . ' GROUP BY s.id, s.status_name'
)->fetchAll();
$statMap = [];
foreach ($stats as $r) {
    $statMap[$r['status_name']] = (int) $r['c'];
}

$statuses = $pdo->query('SELECT * FROM ticket_statuses ORDER BY id')->fetchAll();
$priorities = $pdo->query('SELECT * FROM ticket_priorities ORDER BY priority_level')->fetchAll();
$categories = $pdo->query('SELECT * FROM ticket_categories ORDER BY category_name')->fetchAll();
$assignees = $pdo->query('SELECT id, full_name FROM users WHERE status="active" ORDER BY full_name LIMIT 200')->fetchAll();
$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

$filtersOpen = $statusId || $priorityId || $categoryId || $assigneeId || $departmentId || $duration || $sla !== '' || $dateFrom || $dateTo;

$pageTitle = 'All Tickets';
$activeNav = 'all_tickets';
$includeCharts = false;
$topbarSearchQuery = $q;

require __DIR__ . '/includes/shell_begin.php';
$f = flash_get();
if ($f) :
    ?>
    <div class="container-fluid px-3 px-lg-4"><div class="alert alert-<?php echo e($f['type'] === 'success' ? 'success' : 'info'); ?>"><?php echo e($f['message']); ?></div></div>
<?php endif; ?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-3">
        <div class="page-title-block">
            <h1>All Tickets</h1>
            <div class="subtitle">Track and manage all your support requests.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-muted btn-sm" href="create_template.php"><i class="bi bi-journal-plus"></i> Create Ticket Template</a>
        </div>
    </div>

    <form class="mb-3" method="get" action="all_tickets.php">
        <div class="card-surface p-2 px-3 d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1" style="min-width:220px;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input class="form-control" name="q" placeholder="Search tickets, accounts, people, departments" value="<?php echo e($q); ?>">
                </div>
            </div>
            <button class="btn btn-outline-muted btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#filtersRow" aria-expanded="<?php echo $filtersOpen ? 'true' : 'false'; ?>">
                <i class="bi bi-sliders"></i> Filters
            </button>
            <button type="submit" class="btn btn-accent btn-sm">Apply</button>
            <a href="all_tickets.php" class="btn btn-link btn-sm">Reset</a>
        </div>

        <div class="collapse <?php echo $filtersOpen ? 'show' : ''; ?> mt-2" id="filtersRow">
            <div class="card-surface p-3 filters-panel">
                <div class="row g-2">
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Status</label>
                        <select class="form-select form-select-sm" name="status_id">
                            <option value="0">All Status</option>
                            <?php foreach ($statuses as $s) : ?>
                                <option value="<?php echo (int) $s['id']; ?>" <?php echo $statusId === (int) $s['id'] ? 'selected' : ''; ?>><?php echo e($s['status_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Priority</label>
                        <select class="form-select form-select-sm" name="priority_id">
                            <option value="0">All Priority</option>
                            <?php foreach ($priorities as $p) : ?>
                                <option value="<?php echo (int) $p['id']; ?>" <?php echo $priorityId === (int) $p['id'] ? 'selected' : ''; ?>><?php echo e($p['priority_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Category</label>
                        <select class="form-select form-select-sm" name="category_id">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $c) : ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo $categoryId === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e($c['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Assignee</label>
                        <select class="form-select form-select-sm" name="assignee_id">
                            <option value="0">All Assignees</option>
                            <?php foreach ($assignees as $a) : ?>
                                <option value="<?php echo (int) $a['id']; ?>" <?php echo $assigneeId === (int) $a['id'] ? 'selected' : ''; ?>><?php echo e($a['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Department</label>
                        <select class="form-select form-select-sm" name="department_id">
                            <option value="0">All Departments</option>
                            <?php foreach ($departments as $d) : ?>
                                <option value="<?php echo (int) $d['id']; ?>" <?php echo $departmentId === (int) $d['id'] ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">SLA breach</label>
                        <select class="form-select form-select-sm" name="sla">
                            <option value="" <?php echo $sla === '' ? 'selected' : ''; ?>>Any</option>
                            <option value="1" <?php echo $sla === '1' ? 'selected' : ''; ?>>Yes</option>
                            <option value="0" <?php echo $sla === '0' ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Assigned</label>
                        <select class="form-select form-select-sm" name="assigned">
                            <option value="">All</option>
                            <option value="me" <?php echo $assignedFilter === 'me' ? 'selected' : ''; ?>>Assigned to me</option>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Duration</label>
                        <select class="form-select form-select-sm" name="duration">
                            <option value="">Any</option>
                            <option value="7d" <?php echo $duration === '7d' ? 'selected' : ''; ?>>Last 7 days</option>
                            <option value="30d" <?php echo $duration === '30d' ? 'selected' : ''; ?>>Last 30 days</option>
                            <option value="90d" <?php echo $duration === '90d' ? 'selected' : ''; ?>>Last 90 days</option>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">From</label>
                        <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo e($dateFrom); ?>">
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">To</label>
                        <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo e($dateTo); ?>">
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="label">Open Tickets</div>
                <div class="value"><?php echo (int) ($statMap['Open'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="label">Closed Tickets</div>
                <div class="value"><?php echo (int) ($statMap['Completed'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="label">Stuck Tickets</div>
                <div class="value"><?php echo (int) ($statMap['Stuck'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="label">Cancelled Tickets</div>
                <div class="value"><?php echo (int) ($statMap['Cancelled'] ?? 0); ?></div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 small text-muted">
        <div><?php echo (int) $totalRows; ?> tickets · Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></div>
        <div class="d-flex gap-2">
            <?php if ($page > 1) : ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(build_query_url('all_tickets.php', array_merge($_GET, ['page' => $page - 1]))); ?>">Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages) : ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(build_query_url('all_tickets.php', array_merge($_GET, ['page' => $page + 1]))); ?>">Next</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-surface p-0 overflow-auto">
        <table class="table table-modern table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th></th>
                    <th>ID</th>
                    <th>Summary</th>
                    <th>Priority</th>
                    <th>Category</th>
                    <th>Account</th>
                    <th>Created by</th>
                    <th>Assignee</th>
                    <th>Approved by</th>
                    <th>Date Created</th>
                    <th>Days elapsed</th>
                    <th>Date Completed</th>
                    <th>Status</th>
                    <th>Email</th>
                    <th>Att.</th>
                    <th>SLA</th>
                    <th>Updated</th>
                    <th>Department</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $t) :
                $tid = (int) $t['id'];
                $detail = 'ticket_detail.php?id=' . $tid;
                $elapsed = '';
                if (!empty($t['created_at'])) {
                    $cdate = new DateTimeImmutable((string) $t['created_at']);
                    $end = !empty($t['date_completed']) ? new DateTimeImmutable((string) $t['date_completed']) : new DateTimeImmutable('now');
                    $elapsed = (string) $cdate->diff($end)->days . 'd';
                }
                ?>
                <tr class="ticket-row" data-href="<?php echo e($detail); ?>" role="button" tabindex="0">
                    <td><a class="btn btn-sm btn-accent" href="<?php echo e($detail); ?>">View</a></td>
                    <td class="fw-semibold"><a class="text-decoration-none text-dark" href="<?php echo e($detail); ?>"><?php echo e($t['ticket_number']); ?></a></td>
                    <td><?php echo e(short_text((string) $t['subject'], 56)); ?></td>
                    <td><span class="badge rounded-pill text-bg-light border border-secondary-subtle"><?php echo e($t['priority_name']); ?></span></td>
                    <td><?php echo e($t['category_name']); ?></td>
                    <td><?php echo e((string) ($t['account_number'] ?? '')); ?></td>
                    <td><?php echo e((string) $t['created_name']); ?></td>
                    <td><?php echo e((string) ($t['assignee_name'] ?? '')); ?></td>
                    <td><?php echo e((string) ($t['approver_name'] ?? '')); ?></td>
                    <td><?php echo e(date('m/d/Y', strtotime((string) $t['created_at']))); ?></td>
                    <td><?php echo e($elapsed); ?></td>
                    <td><?php echo !empty($t['date_completed']) ? e(date('m/d/Y', strtotime((string) $t['date_completed']))) : '—'; ?></td>
                    <td><span class="badge rounded-pill bg-light text-dark border"><?php echo e($t['status_name']); ?></span></td>
                    <td><?php echo e((string) $t['created_email']); ?></td>
                    <td><?php echo (int) $t['attachments_count']; ?></td>
                    <td><?php echo !empty($t['sla_breach']) ? '<span class="badge bg-danger">Yes</span>' : '<span class="badge bg-success-subtle text-success">No</span>'; ?></td>
                    <td><?php echo e(date('m/d/Y', strtotime((string) $t['updated_at']))); ?></td>
                    <td><?php echo e($t['department_name']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tickets) : ?>
                <tr><td colspan="18" class="text-center text-muted py-5">No tickets match the current filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.querySelectorAll('tr.ticket-row').forEach(function(row){
  row.addEventListener('click', function(e){
    if (e.target.closest('a,button,input,select,textarea')) return;
    window.location.href = row.getAttribute('data-href');
  });
  row.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); window.location.href = row.getAttribute('data-href'); }
  });
});
</script>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
