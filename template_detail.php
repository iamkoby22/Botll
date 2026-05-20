<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('ticket_templates');

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    redirect('ticket_templates.php');
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT tt.*, c.category_name, p.priority_name, d.department_name
     FROM ticket_templates tt
     JOIN ticket_categories c ON c.id = tt.category_id
     JOIN ticket_priorities p ON p.id = tt.priority_id
     JOIN departments d ON d.id = tt.department_id
     WHERE tt.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$tpl = $stmt->fetch();
if (!$tpl) {
    redirect('ticket_templates.php');
}

$canEdit = can_access('edit_template');
$pageTitle = 'Template';
$activeNav = 'ticket_templates';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div class="page-title-block mb-0">
            <h1><?php echo e($tpl['template_title']); ?></h1>
            <div class="subtitle"><?php echo e($tpl['department_name']); ?> · <?php echo e($tpl['category_name']); ?> · <?php echo e($tpl['priority_name']); ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-muted btn-sm" href="ticket_templates.php">Back</a>
            <?php if ($canEdit) : ?>
                <a class="btn btn-accent btn-sm" href="edit_template.php?id=<?php echo $id; ?>">Edit</a>
            <?php endif; ?>
            <a class="btn btn-outline-muted btn-sm" href="create_ticket.php?template_id=<?php echo $id; ?>">Use template</a>
        </div>
    </div>

    <div class="card-surface p-3 p-lg-4 mb-3">
        <h2 class="h6 fw-bold mb-2">Description</h2>
        <div class="small" style="white-space:pre-wrap;"><?php echo e((string) $tpl['description']); ?></div>
    </div>

    <?php if (!empty($tpl['instructions'])) : ?>
        <div class="card-surface p-3 p-lg-4 mb-3">
            <h2 class="h6 fw-bold mb-2">Instructions</h2>
            <div class="small" style="white-space:pre-wrap;"><?php echo e((string) $tpl['instructions']); ?></div>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="template-card">
                <div class="small text-muted">Default account</div>
                <div class="fw-semibold"><?php echo e((string) ($tpl['default_account_number'] ?? '—')); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="template-card">
                <div class="small text-muted">Status</div>
                <div class="fw-semibold"><?php echo (!isset($tpl['is_active']) || !empty($tpl['is_active'])) ? 'Active' : 'Inactive'; ?></div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
