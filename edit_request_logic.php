<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('edit_template');

if (!request_logic_tables_exist()) {
    redirect('ticket_templates.php');
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$logic = request_logic_fetch_by_id($id);
if (!$logic) {
    flash_set('danger', 'Request logic not found.');
    redirect('ticket_templates.php');
}

$pdo = db();
$errors = [];
$success = '';

$builderFields = request_logic_fields_load($id, false);

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'delete') {
            $pwd = (string) ($_POST['confirm_password'] ?? '');
            if (super_admin_require_password($pwd, $errors, 'deleting request logic')) {
                request_logic_soft_delete($id, (int) current_user()['id']);
                flash_set('success', 'Request logic removed.');
                redirect('ticket_templates.php');
            }
        } else {
            $rt = trim((string) ($_POST['request_type'] ?? ''));
            $s1 = trim((string) ($_POST['step1'] ?? ''));
            $s2 = trim((string) ($_POST['step2'] ?? ''));
            if ($rt === '') {
                $errors[] = 'Request Type required.';
            } else {
                $pdo->prepare(
                    'UPDATE request_logic SET request_type=?, step1=?, step2=?, display_order=?, is_active=?, updated_at=NOW() WHERE id=?'
                )->execute([
                    $rt,
                    $s1,
                    $s2 !== '' ? $s2 : null,
                    max(0, (int) ($_POST['display_order'] ?? 0)),
                    isset($_POST['is_active']) ? 1 : 0,
                    $id,
                ]);
                $raw = (string) ($_POST['fields_json'] ?? '[]');
                $fields = json_decode($raw, true);
                if ($raw !== '' && $raw !== '[]' && !is_array($fields)) {
                    $errors[] = 'Request fields could not be read. Please try again.';
                } else {
                    request_logic_fields_save($id, is_array($fields) ? $fields : [], (int) current_user()['id'], false);
                    $fieldCount = count(request_logic_fields_load($id));
                    $success = 'Request logic path and fields saved.' . ($fieldCount > 0 ? ' ' . $fieldCount . ' field(s).' : '');
                    if (!isset($_POST['is_active'])) {
                        $success .= ' Path is inactive and hidden from New Request until activated.';
                    }
                    $logic = request_logic_fetch_by_id($id);
                    $builderFields = request_logic_fields_load($id, false);
                }
            }
        }
    }
}

$fieldsJson = json_encode($builderFields, JSON_UNESCAPED_UNICODE);

$pageTitle = 'Edit Request Logic';
$activeNav = 'ticket_templates';
require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="mb-0">Edit Request Logic</h1>
            <p class="text-muted small mb-0"><?php echo e((string) $logic['request_type']); ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="request_logic_detail.php?id=<?php echo $id; ?>" class="btn btn-outline-muted btn-sm">View detail</a>
            <a href="ticket_templates.php" class="btn btn-outline-muted btn-sm">Back to list</a>
        </div>
    </div>
    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) {
            echo '<div>' . e($e) . '</div>';
        } ?></div>
    <?php endif; ?>
    <?php if ($success) : ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
    <?php endif; ?>

    <form method="post" id="rlEditForm">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="action" value="save">
        <textarea name="fields_json" id="rlFieldsJson" class="d-none" aria-hidden="true"><?php echo e($fieldsJson); ?></textarea>

        <div class="card-surface p-3 mb-3">
            <h2 class="h6 fw-bold mb-3">Logic Path</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small">Request Type</label>
                    <input class="form-control" name="request_type" required value="<?php echo e((string) $logic['request_type']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Step 1</label>
                    <input class="form-control" name="step1" value="<?php echo e((string) $logic['step1']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Step 2</label>
                    <input class="form-control" name="step2" value="<?php echo e((string) ($logic['step2'] ?? '')); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Display order</label>
                    <input class="form-control" name="display_order" type="number" value="<?php echo (int) $logic['display_order']; ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" <?php echo !empty($logic['is_active']) ? 'checked' : ''; ?>>
                        <label class="form-check-label">Active for new requests <span class="text-muted">(required for New Request dropdown)</span></label>
                    </div>
                </div>
            </div>
        </div>

        <?php require __DIR__ . '/includes/request_logic_builder_partial.php'; ?>

        <div class="d-flex gap-2 mb-4">
            <button class="btn btn-accent" type="submit">Save changes</button>
        </div>
    </form>

    <form method="post" class="card-surface p-3 border-danger" onsubmit="return confirm('Remove this logic path? Existing tickets keep saved responses.');">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="action" value="delete">
        <h2 class="h6 text-danger">Remove logic path</h2>
        <p class="small text-muted">Requires your Super Admin password. Fields with ticket responses are deactivated, not deleted.</p>
        <input type="password" name="confirm_password" class="form-control form-control-sm mb-2" style="max-width:240px;" required placeholder="Super Admin password">
        <button class="btn btn-outline-danger btn-sm" type="submit">Remove path</button>
    </form>
</div>

<script src="assets/js/request_logic_builder.js?v=<?php echo (int) @filemtime(__DIR__ . '/assets/js/request_logic_builder.js'); ?>"></script>
<?php require __DIR__ . '/includes/shell_end.php'; ?>
