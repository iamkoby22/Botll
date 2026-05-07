<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('ticket_templates');

$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));
$dept = (int) ($_GET['department_id'] ?? 0);
$cat = (int) ($_GET['category_id'] ?? 0);
$pri = (int) ($_GET['priority_id'] ?? 0);

$wheres = ['1=1'];
$params = [];
if ($q !== '') {
    $wheres[] = '(tt.template_title LIKE ? OR tt.description LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($dept > 0) {
    $wheres[] = 'tt.department_id = ?';
    $params[] = $dept;
}
if ($cat > 0) {
    $wheres[] = 'tt.category_id = ?';
    $params[] = $cat;
}
if ($pri > 0) {
    $wheres[] = 'tt.priority_id = ?';
    $params[] = $pri;
}

$whereSql = implode(' AND ', $wheres);
$sql = 'SELECT tt.*, c.category_name, p.priority_name, d.department_name
        FROM ticket_templates tt
        JOIN ticket_categories c ON c.id = tt.category_id
        JOIN ticket_priorities p ON p.id = tt.priority_id
        JOIN departments d ON d.id = tt.department_id
        WHERE ' . $whereSql . '
        ORDER BY tt.template_title ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
$categories = $pdo->query('SELECT * FROM ticket_categories ORDER BY category_name')->fetchAll();
$priorities = $pdo->query('SELECT * FROM ticket_priorities ORDER BY priority_level')->fetchAll();

$canEdit = can_access('create_template');
$pageTitle = 'Ticket Templates';
$activeNav = 'ticket_templates';
$includeCharts = false;
$topbarSearchQuery = $q;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="page-title-block mb-0">
            <h1 class="mb-0">Ticket Templates</h1>
            <div class="subtitle">Track and manage all your ticket templates</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-muted btn-sm" href="dashboard.php"><i class="bi bi-arrow-left"></i> Back</a>
            <?php if ($canEdit) : ?>
                <a class="btn btn-accent btn-sm" href="create_template.php"><i class="bi bi-plus-lg"></i> Create Template</a>
            <?php endif; ?>
        </div>
    </div>

    <form class="card-surface p-3 mb-3" method="get" action="ticket_templates.php">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search</label>
                <input class="form-control form-control-sm" name="q" value="<?php echo e($q); ?>" placeholder="Search templates">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Department</label>
                <select class="form-select form-select-sm" name="department_id">
                    <option value="0">All</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo $dept === (int) $d['id'] ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Category</label>
                <select class="form-select form-select-sm" name="category_id">
                    <option value="0">All</option>
                    <?php foreach ($categories as $c) : ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $cat === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Priority</label>
                <select class="form-select form-select-sm" name="priority_id">
                    <option value="0">All</option>
                    <?php foreach ($priorities as $p) : ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $pri === (int) $p['id'] ? 'selected' : ''; ?>><?php echo e($p['priority_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-accent btn-sm w-100" type="submit">Apply</button>
                <a class="btn btn-outline-muted btn-sm w-100" href="ticket_templates.php">Reset</a>
            </div>
        </div>
    </form>

    <div class="row g-3">
        <?php foreach ($rows as $r) : ?>
            <div class="col-md-6 col-xl-4">
                <div class="template-card h-100 d-flex flex-column">
                    <div class="small text-muted mb-1">Template Title</div>
                    <div class="fw-bold mb-2"><?php echo e($r['template_title']); ?></div>
                    <div class="small text-muted mb-1">Priority Level:</div>
                    <div class="fw-semibold mb-2"><?php echo e($r['priority_name']); ?></div>
                    <div class="small text-muted mb-1">Department</div>
                    <div class="fw-semibold mb-3"><?php echo e($r['department_name']); ?></div>
                    <div class="mt-auto d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-muted btn-sm" href="template_detail.php?id=<?php echo (int) $r['id']; ?>">View</a>
                        <a class="btn btn-accent btn-sm" href="create_ticket.php?template_id=<?php echo (int) $r['id']; ?>">Use</a>
                        <?php if ($canEdit) : ?>
                            <a class="btn btn-outline-secondary btn-sm" href="edit_template.php?id=<?php echo (int) $r['id']; ?>">Edit</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$rows) : ?>
            <div class="col-12 text-center text-muted py-5">No templates match your filters.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
