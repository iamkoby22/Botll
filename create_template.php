<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('create_template');

$pdo = db();
$u = current_user();
$errors = [];
$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
$categories = $pdo->query('SELECT * FROM ticket_categories ORDER BY category_name')->fetchAll();
$priorities = $pdo->query('SELECT * FROM ticket_priorities ORDER BY priority_level')->fetchAll();
$usersList = $pdo->query('SELECT id, full_name FROM users WHERE status="active" ORDER BY full_name')->fetchAll();

$vals = [
    'template_title' => trim((string) ($_POST['template_title'] ?? '')),
    'department_id' => (string) ($_POST['department_id'] ?? ''),
    'category_id' => (string) ($_POST['category_id'] ?? ''),
    'priority_id' => (string) ($_POST['priority_id'] ?? ''),
    'description' => trim((string) ($_POST['description'] ?? '')),
    'default_account_number' => trim((string) ($_POST['default_account_number'] ?? '')),
    'default_assignee_user_id' => (string) ($_POST['default_assignee_user_id'] ?? ''),
    'instructions' => trim((string) ($_POST['instructions'] ?? '')),
    'is_active' => isset($_POST['is_active']) ? 1 : 0,
];

$fieldsPayload = [];
if (is_post()) {
    $rawFields = (string) ($_POST['fields_json'] ?? '[]');
    $decoded = json_decode($rawFields, true);
    $fieldsPayload = is_array($decoded) ? $decoded : [];
}

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $dept = (int) $vals['department_id'];
        $cat = (int) $vals['category_id'];
        $pri = (int) $vals['priority_id'];
        if ($vals['template_title'] === '' || $vals['description'] === '' || $dept < 1 || $cat < 1 || $pri < 1) {
            $errors[] = 'Title, description, department, category, and priority are required.';
        }
        if (!$errors) {
            $defAssign = (int) $vals['default_assignee_user_id'];
            $defAssign = $defAssign > 0 ? $defAssign : null;
            $acct = $vals['default_account_number'] === '' ? null : $vals['default_account_number'];
            $saveAction = (string) ($_POST['save_action'] ?? 'publish');
            $tplStatus = $saveAction === 'draft' ? 'draft' : 'published';
            $newId = 0;
            try {
                $ins = $pdo->prepare(
                    'INSERT INTO ticket_templates (template_title, category_id, priority_id, department_id, description, created_by, default_account_number, default_assignee_user_id, instructions, is_active, template_status, last_edited_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $vals['template_title'],
                    $cat,
                    $pri,
                    $dept,
                    $vals['description'],
                    (int) $u['id'],
                    $acct,
                    $defAssign,
                    $vals['instructions'] === '' ? null : $vals['instructions'],
                    $vals['is_active'] ? 1 : 0,
                    $tplStatus,
                    (int) $u['id'],
                ]);
                $newId = (int) $pdo->lastInsertId();
            } catch (Throwable $e) {
                $ins = $pdo->prepare(
                    'INSERT INTO ticket_templates (template_title, category_id, priority_id, department_id, description, created_by, default_account_number, default_assignee_user_id, instructions, is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $vals['template_title'],
                    $cat,
                    $pri,
                    $dept,
                    $vals['description'],
                    (int) $u['id'],
                    $acct,
                    $defAssign,
                    $vals['instructions'] === '' ? null : $vals['instructions'],
                    $vals['is_active'] ? 1 : 1,
                ]);
                $newId = (int) $pdo->lastInsertId();
            }
            ticket_template_fields_replace($newId, $fieldsPayload, (int) $u['id']);
            try {
                $pdo->prepare('UPDATE ticket_templates SET last_edited_by = ?, template_version = IFNULL(template_version, 1) WHERE id = ?')->execute([(int) $u['id'], $newId]);
            } catch (Throwable $e) {
            }
            flash_set('success', $saveAction === 'draft' ? 'Template saved as draft.' : 'Template published.');
            redirect('template_detail.php?id=' . $newId);
        }
    }
}

$pageTitle = 'Create Ticket Template';
$activeNav = 'ticket_templates';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4" id="tplBuilderRoot">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div class="page-title-block mb-0">
            <h1 class="mb-0">Create Ticket Template</h1>
            <div class="subtitle">Design a template to make working with tickets easier</div>
            <div class="small text-muted mt-1">Version <span class="fw-semibold">1</span> · Last edited: <?php echo e($u['full_name']); ?> · <?php echo e(date('M j, Y g:i a')); ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-outline-muted btn-sm" href="ticket_templates.php"><i class="bi bi-arrow-left"></i> Back</a>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary active" id="modeEdit">Edit Mode</button>
                <button type="button" class="btn btn-outline-secondary" id="modePreview">Preview Mode</button>
            </div>
        </div>
    </div>

    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="fields_json" id="tplFieldsJson" value="<?php echo e(json_encode(is_post() ? $fieldsPayload : [], JSON_UNESCAPED_UNICODE)); ?>">

        <div class="col-12 col-xl-3 tpl-builder-panel">
            <div class="card-surface p-3 mb-3">
                <div class="fw-bold small mb-2">Field components</div>
                <div class="d-grid gap-1">
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="text">+ Text Field</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="paragraph">+ Paragraph</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="dropdown">+ Dropdown</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="checkbox">+ Checkbox</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="radio">+ Radio</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="date">+ Date</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="file">+ File Upload</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="divider">+ Divider</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="section">+ Section</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="user_selector">+ User Selector</button>
                    <button type="button" class="btn btn-outline-muted btn-sm text-start" data-add-field="custom">+ Custom</button>
                </div>
            </div>
            <div class="card-surface p-3">
                <div class="fw-bold small mb-2">Template meta</div>
                <label class="form-label small">Template name</label>
                <input class="form-control form-control-sm mb-2" name="template_title" required value="<?php echo e($vals['template_title']); ?>">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?php echo $vals['is_active'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="is_active">Active</label>
                </div>
                <label class="form-label small">Department</label>
                <select class="form-select form-select-sm mb-2" name="department_id" required>
                    <option value="">Select</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo $vals['department_id'] === (string) $d['id'] ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small">Category</label>
                <select class="form-select form-select-sm mb-2" name="category_id" required>
                    <option value="">Select</option>
                    <?php foreach ($categories as $c) : ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $vals['category_id'] === (string) $c['id'] ? 'selected' : ''; ?>><?php echo e($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small">Priority</label>
                <select class="form-select form-select-sm mb-2" name="priority_id" required>
                    <option value="">Select</option>
                    <?php foreach ($priorities as $p) : ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $vals['priority_id'] === (string) $p['id'] ? 'selected' : ''; ?>><?php echo e($p['priority_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small">Default account</label>
                <input class="form-control form-control-sm mb-2" name="default_account_number" value="<?php echo e($vals['default_account_number']); ?>">
                <label class="form-label small">Default assignee</label>
                <select class="form-select form-select-sm mb-2" name="default_assignee_user_id">
                    <option value="">None</option>
                    <?php foreach ($usersList as $uu) : ?>
                        <option value="<?php echo (int) $uu['id']; ?>" <?php echo $vals['default_assignee_user_id'] === (string) $uu['id'] ? 'selected' : ''; ?>><?php echo e($uu['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small">Body / default description</label>
                <textarea class="form-control form-control-sm mb-2" name="description" rows="4" required><?php echo e($vals['description']); ?></textarea>
                <label class="form-label small">Instructions</label>
                <textarea class="form-control form-control-sm" name="instructions" rows="2"><?php echo e($vals['instructions']); ?></textarea>
            </div>
        </div>

        <div class="col-12 col-xl-5 tpl-builder-panel">
            <div class="card-surface p-3 h-100">
                <div class="fw-bold small mb-2">Builder canvas</div>
                <div id="tplFieldList" class="min-height-builder"></div>
            </div>
        </div>

        <div class="col-12 col-xl-4 tpl-builder-panel">
            <div class="card-surface p-3 mb-3">
                <div class="fw-bold small mb-2">Field settings</div>
                <div id="tplFieldSettings"></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" name="save_action" value="draft" class="btn btn-outline-muted">Save Draft</button>
                <button type="submit" name="save_action" value="publish" class="btn btn-accent">Publish</button>
                <button type="button" class="btn btn-outline-secondary" id="modePreviewMini">Preview</button>
            </div>
        </div>

        <div class="col-12 d-none" id="tplPrevWrap">
            <div class="card-surface p-4">
                <div class="fw-bold mb-3">Preview</div>
                <div id="tplPreview" class="small"></div>
            </div>
        </div>
    </form>
</div>

<style>.min-height-builder{min-height:220px}</style>
<script src="assets/js/template_builder.js?v=2" defer></script>
<script>
document.getElementById('modePreviewMini')?.addEventListener('click',function(){
  document.getElementById('modePreview')?.click();
});
</script>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
