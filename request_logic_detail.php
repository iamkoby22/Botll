<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('ticket_templates');

$id = (int) ($_GET['id'] ?? 0);
$logic = request_logic_fetch_by_id($id);
if (!$logic) {
    flash_set('danger', 'Request logic not found.');
    redirect('ticket_templates.php');
}

$fields = request_logic_fields_load($id, false);

$pageTitle = 'Request Logic Detail';
$activeNav = 'ticket_templates';
require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="mb-0">Request Logic Detail</h1>
            <p class="text-muted small mb-0">Path #<?php echo $id; ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="edit_request_logic.php?id=<?php echo $id; ?>" class="btn btn-accent btn-sm">Edit</a>
            <a href="ticket_templates.php" class="btn btn-outline-muted btn-sm">Back</a>
        </div>
    </div>

    <div class="card-surface p-3 mb-3">
        <h2 class="h6 fw-bold mb-3">Logic path</h2>
        <dl class="row small mb-0">
            <dt class="col-sm-3">Request Type</dt><dd class="col-sm-9"><?php echo e((string) $logic['request_type']); ?></dd>
            <dt class="col-sm-3">Step 1</dt><dd class="col-sm-9"><?php echo e((string) ($logic['step1'] !== '' ? $logic['step1'] : '—')); ?></dd>
            <dt class="col-sm-3">Step 2</dt><dd class="col-sm-9"><?php echo e((string) ($logic['step2'] ?? '—')); ?></dd>
            <dt class="col-sm-3">Display order</dt><dd class="col-sm-9"><?php echo (int) $logic['display_order']; ?></dd>
            <dt class="col-sm-3">Active</dt><dd class="col-sm-9"><?php echo !empty($logic['is_active']) ? 'Yes' : 'No'; ?></dd>
        </dl>
    </div>

    <div class="card-surface p-3">
        <h2 class="h6 fw-bold mb-3">Fields (<?php echo count($fields); ?>)</h2>
        <?php if (!$fields) : ?>
            <p class="text-muted small mb-0">No fields defined.</p>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-sm table-modern mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order</th>
                            <th>Label</th>
                            <th>Key</th>
                            <th>Type</th>
                            <th>Required</th>
                            <th>Active</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fields as $f) : ?>
                        <tr>
                            <td><?php echo (int) ($f['display_order'] ?? 0); ?></td>
                            <td><?php echo e((string) $f['field_label']); ?></td>
                            <td><code><?php echo e((string) $f['field_key']); ?></code></td>
                            <td><?php echo e((string) $f['field_type']); ?></td>
                            <td><?php echo !empty($f['is_required']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo !empty($f['is_active']) ? 'Yes' : 'No'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
