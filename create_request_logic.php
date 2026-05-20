<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('create_template');

if (!request_logic_tables_exist()) {
    flash_set('danger', 'Run migration 011 first.');
    redirect('ticket_templates.php');
}

$pdo = db();
$errors = [];
$vals = [
    'request_type' => trim((string) ($_POST['request_type'] ?? '')),
    'step1' => trim((string) ($_POST['step1'] ?? '')),
    'step2' => trim((string) ($_POST['step2'] ?? '')),
    'display_order' => (string) ($_POST['display_order'] ?? '100'),
    'is_active' => is_post() ? (isset($_POST['is_active']) ? 1 : 0) : 1,
];

$builderFields = [];
if (is_post()) {
    $raw = (string) ($_POST['fields_json'] ?? '[]');
    $decoded = json_decode($raw, true);
    if ($raw !== '' && $raw !== '[]' && !is_array($decoded)) {
        $errors[] = 'Request fields could not be read. Please try again.';
        error_log('create_request_logic: invalid fields_json: ' . substr($raw, 0, 200));
    } else {
        $builderFields = is_array($decoded) ? $decoded : [];
    }
}

if (is_post() && !$errors) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } elseif ($vals['request_type'] === '') {
        $errors[] = 'Request Type is required.';
    } else {
        try {
            $cat = (int) ($pdo->query('SELECT id FROM ticket_categories WHERE category_name="Expense" LIMIT 1')->fetchColumn() ?: 1);
            $pri = (int) ($pdo->query('SELECT id FROM ticket_priorities ORDER BY priority_level LIMIT 1')->fetchColumn() ?: 1);
            $pdo->prepare(
                'INSERT INTO request_logic (request_type, step1, step2, display_order, default_category_id, default_priority_id, is_active)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $vals['request_type'],
                $vals['step1'],
                $vals['step2'] !== '' ? $vals['step2'] : null,
                max(0, (int) $vals['display_order']),
                $cat,
                $pri,
                $vals['is_active'] ? 1 : 0,
            ]);
            $newId = (int) $pdo->lastInsertId();
            if ($newId < 1) {
                throw new RuntimeException('Insert did not return a new id.');
            }
            request_logic_fields_save($newId, $builderFields, (int) current_user()['id'], true);
            $fieldCount = count(request_logic_fields_load($newId));
            $msg = 'Request Logic created successfully.';
            if ($fieldCount > 0) {
                $msg .= ' ' . $fieldCount . ' field(s) saved.';
            }
            if (!$vals['is_active']) {
                $msg .= ' Note: path is inactive and will not appear on New Request until activated.';
            }
            flash_set('success', $msg);
            redirect('request_logic_detail.php?id=' . $newId);
        } catch (Throwable $e) {
            error_log('create_request_logic save failed: ' . $e->getMessage());
            $errors[] = 'Request Logic could not be saved: ' . $e->getMessage();
        }
    }
}

$fieldsJson = json_encode($builderFields, JSON_UNESCAPED_UNICODE);

$pageTitle = 'Create Request Logic';
$activeNav = 'ticket_templates';
require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="mb-0">Create Request Logic</h1>
        <a href="ticket_templates.php" class="btn btn-outline-muted btn-sm"><i class="bi bi-arrow-left"></i> Back to list</a>
    </div>
    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) {
            echo '<div>' . e($e) . '</div>';
        } ?></div>
    <?php endif; ?>

    <form method="post" id="rlCreateForm">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <textarea name="fields_json" id="rlFieldsJson" class="d-none" aria-hidden="true"><?php echo e($fieldsJson); ?></textarea>

        <div class="card-surface p-3 mb-3">
            <h2 class="h6 fw-bold mb-3">Logic Path</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small">Request Type</label>
                    <input class="form-control" name="request_type" required value="<?php echo e($vals['request_type']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Step 1</label>
                    <input class="form-control" name="step1" value="<?php echo e($vals['step1']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Step 2</label>
                    <input class="form-control" name="step2" value="<?php echo e($vals['step2']); ?>" placeholder="Optional">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Display order</label>
                    <input class="form-control" name="display_order" type="number" value="<?php echo e($vals['display_order']); ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $vals['is_active'] ? 'checked' : ''; ?>>
                        <label class="form-check-label">Active for new requests <span class="text-muted">(required to show in New Request dropdown)</span></label>
                    </div>
                </div>
            </div>
        </div>

        <?php require __DIR__ . '/includes/request_logic_builder_partial.php'; ?>

        <div class="d-flex gap-2">
            <a href="ticket_templates.php" class="btn btn-outline-muted">Cancel</a>
            <button class="btn btn-accent" type="submit">Save logic path &amp; fields</button>
        </div>
    </form>
</div>

<script src="assets/js/request_logic_builder.js?v=<?php echo (int) @filemtime(__DIR__ . '/assets/js/request_logic_builder.js'); ?>"></script>
<?php require __DIR__ . '/includes/shell_end.php'; ?>
