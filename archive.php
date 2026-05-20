<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_login();

$scope = tickets_scope_sql('t');
$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$orgCode = trim((string) ($_GET['org_code'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$wheres = [$scope];
if (sbs_column_exists('tickets', 'archived_at')) {
    $wheres[] = 't.archived_at IS NOT NULL';
} else {
    $wheres[] = '1=0';
}
$params = [];

if ($q !== '') {
    $wheres[] = '(t.ticket_number LIKE ? OR t.subject LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($orgCode !== '') {
    [$orgSql, $orgParams] = sbs_org_code_filter_sql('t', $orgCode);
    if ($orgSql !== '') {
        $wheres[] = $orgSql;
        array_push($params, ...$orgParams);
    }
}

$whereSql = implode(' AND ', $wheres);
$countSt = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE $whereSql");
$countSt->execute($params);
$total = (int) $countSt->fetchColumn();

$sql = "SELECT t.*, s.status_name, p.priority_name, d.department_name, d.organization_code,
        cu.full_name AS created_name, au.full_name AS assignee_name
        FROM tickets t
        JOIN ticket_statuses s ON s.id = t.status_id
        JOIN ticket_priorities p ON p.id = t.priority_id
        LEFT JOIN departments d ON d.id = t.department_id
        JOIN users cu ON cu.id = t.created_by
        LEFT JOIN users au ON au.id = t.assigned_to
        WHERE $whereSql
        ORDER BY t.archived_at DESC, t.id DESC
        LIMIT $perPage OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'Archive';
$activeNav = 'archive';
require __DIR__ . '/includes/shell_begin.php';
$f = flash_get();
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1>Archive</h1>
        <div class="subtitle">Archived tickets remain available for audit and are excluded from active lists.</div>
    </div>

    <?php if ($f) : ?>
        <div class="alert alert-<?php echo e($f['type'] === 'success' ? 'success' : 'info'); ?>"><?php echo e($f['message']); ?></div>
    <?php endif; ?>

    <form class="card-surface p-3 mb-3" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" value="<?php echo e($q); ?>" placeholder="Ticket # or subject">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Org Code</label>
                <input type="text" name="org_code" class="form-control form-control-sm" value="<?php echo e($orgCode); ?>" placeholder="Filter by org code">
            </div>
            <div class="col-md-2">
                <button class="btn btn-accent btn-sm w-100" type="submit">Filter</button>
            </div>
        </div>
    </form>

    <div class="card-surface p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-modern mb-0">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Status</th>
                        <th>Route</th>
                        <th>Department</th>
                        <th>Archived</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo e($r['ticket_number']); ?></div>
                            <div class="small text-muted"><?php echo e($r['subject']); ?></div>
                        </td>
                        <td><?php echo e($r['status_name']); ?></td>
                        <td><?php echo e(sbs_route_label((string) ($r['account_route'] ?? ''))); ?></td>
                        <td class="small"><?php echo e((string) ($r['department_name'] ?? '')); ?></td>
                        <td class="small"><?php echo !empty($r['archived_at']) ? e(date('m/d/Y', strtotime((string) $r['archived_at']))) : '—'; ?></td>
                        <td><a class="btn btn-sm btn-outline-muted" href="ticket_detail.php?id=<?php echo (int) $r['id']; ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows) : ?>
                    <tr><td colspan="6" class="text-muted p-4">No archived tickets in your scope.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
