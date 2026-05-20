<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('ticket_templates');

$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));
$errors = [];
$success = '';

if (is_post() && request_logic_tables_exist()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } elseif ((string) ($_POST['action'] ?? '') === 'delete_logic') {
        $logicId = (int) ($_POST['logic_id'] ?? 0);
        $pwd = (string) ($_POST['confirm_password'] ?? '');
        if (super_admin_require_password($pwd, $errors, 'deleting request logic')) {
            if (request_logic_soft_delete($logicId, (int) current_user()['id'])) {
                $success = 'Request logic path removed.';
            } else {
                $errors[] = 'Could not remove path.';
            }
        }
    }
}

$useLogic = request_logic_tables_exist();
$rows = $useLogic ? request_logic_admin_list($q !== '' ? $q : null) : [];
$fieldCounts = $useLogic ? request_logic_field_counts_by_logic() : [];

if (!$useLogic) {
    $wheres = ['1=1'];
    $params = [];
    if ($q !== '') {
        $wheres[] = '(tt.template_title LIKE ? OR tt.description LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql = 'SELECT tt.*, c.category_name, p.priority_name, d.department_name
            FROM ticket_templates tt
            JOIN ticket_categories c ON c.id = tt.category_id
            JOIN ticket_priorities p ON p.id = tt.priority_id
            JOIN departments d ON d.id = tt.department_id
            WHERE ' . implode(' AND ', $wheres) . ' ORDER BY tt.template_title ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$pageTitle = 'Request Logic';
$activeNav = 'ticket_templates';
$includeCharts = false;
$topbarSearchQuery = $q;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="page-title-block mb-0">
            <h1 class="mb-0">Request Logic</h1>
            <div class="subtitle">Request Type, Step 1, Step 2, and request fields for the intake form</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-muted btn-sm" href="dashboard.php"><i class="bi bi-arrow-left"></i> Back</a>
            <?php if ($useLogic) : ?>
                <a class="btn btn-accent btn-sm" href="create_request_logic.php"><i class="bi bi-plus-lg"></i> Create New Ticket Logic</a>
            <?php else : ?>
                <a class="btn btn-accent btn-sm" href="create_template.php"><i class="bi bi-plus-lg"></i> Create (legacy)</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>
    <?php if ($success !== '') : ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
    <?php endif; ?>

    <?php if (!$useLogic) : ?>
        <div class="alert alert-warning">Run <code>database/migration_011_request_logic_refactor.sql</code> to enable Request Logic.</div>
    <?php endif; ?>

    <form class="card-surface p-3 mb-3" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Search</label>
                <input class="form-control form-control-sm" name="q" value="<?php echo e($q); ?>" placeholder="Request type, step…">
            </div>
            <div class="col-md-2">
                <button class="btn btn-accent btn-sm w-100" type="submit">Search</button>
            </div>
        </div>
    </form>

    <div class="card-surface p-0 overflow-auto">
        <table class="table table-modern table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <?php if ($useLogic) : ?>
                        <th>Request Type</th>
                        <th>Step 1</th>
                        <th>Step 2</th>
                        <th>Fields</th>
                        <th>Active</th>
                        <th></th>
                    <?php else : ?>
                        <th>Title</th>
                        <th>Department</th>
                        <th></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($useLogic) : ?>
                <?php foreach ($rows as $r) :
                    $rowActive = !empty($r['is_active']);
                    ?>
                    <tr class="<?php echo $rowActive ? '' : 'table-warning'; ?>">
                        <td class="fw-semibold"><?php echo e((string) $r['request_type']); ?></td>
                        <td><?php echo e((string) ($r['step1'] !== '' ? $r['step1'] : '—')); ?></td>
                        <td><?php echo e((string) ($r['step2'] ?? '—')); ?></td>
                        <td><?php echo (int) ($fieldCounts[(int) $r['id']] ?? 0); ?></td>
                        <td>
                            <?php if ($rowActive) : ?>
                                <span class="badge text-bg-success">Active</span>
                            <?php else : ?>
                                <span class="badge text-bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-muted" href="request_logic_detail.php?id=<?php echo (int) $r['id']; ?>">View</a>
                            <a class="btn btn-sm btn-accent" href="edit_request_logic.php?id=<?php echo (int) $r['id']; ?>">Edit</a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Remove this logic path?');">
                                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete_logic">
                                <input type="hidden" name="logic_id" value="<?php echo (int) $r['id']; ?>">
                                <input type="password" name="confirm_password" class="form-control form-control-sm d-inline-block" style="width:140px;" placeholder="Your password" required>
                                <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td><?php echo e((string) $r['template_title']); ?></td>
                        <td><?php echo e((string) $r['department_name']); ?></td>
                        <td><a href="template_detail.php?id=<?php echo (int) $r['id']; ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!$rows) : ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No request logic paths found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>


