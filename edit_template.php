<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('edit_template');

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    redirect('ticket_templates.php');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM ticket_templates WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$tpl = $stmt->fetch();
if (!$tpl) {
    redirect('ticket_templates.php');
}

$u = current_user();
$errors = [];
$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
$categories = $pdo->query('SELECT * FROM ticket_categories ORDER BY category_name')->fetchAll();
$priorities = $pdo->query('SELECT * FROM ticket_priorities ORDER BY priority_level')->fetchAll();
$usersList = $pdo->query('SELECT id, full_name FROM users WHERE status="active" ORDER BY full_name')->fetchAll();

$existingFields = [];
try {
    $fr = $pdo->prepare(
        'SELECT field_name, field_label, field_type, is_required, field_order, field_options, placeholder, help_text, default_value
         FROM template_fields WHERE template_id = ? ORDER BY field_order ASC, id ASC'
    );
    $fr->execute([$id]);
    foreach ($fr->fetchAll() as $r) {
        $existingFields[] = [
            'field_name' => (string) $r['field_name'],
            'field_label' => (string) $r['field_label'],
            'field_type' => (string) $r['field_type'],
            'is_required' => !empty($r['is_required']),
            'field_options' => (string) ($r['field_options'] ?? ''),
            'placeholder' => (string) ($r['placeholder'] ?? ''),
            'help_text' => (string) ($r['help_text'] ?? ''),
            'default_value' => (string) ($r['default_value'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $existingFields = [];
}

$fieldsPayload = $existingFields;
if (is_post()) {
    $rawFields = (string) ($_POST['fields_json'] ?? '[]');
    $decoded = json_decode($rawFields, true);
    $fieldsPayload = is_array($decoded) ? $decoded : $existingFields;
}

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $title = trim((string) ($_POST['template_title'] ?? ''));
        $dept = (int) ($_POST['department_id'] ?? 0);
        $cat = (int) ($_POST['category_id'] ?? 0);
        $pri = (int) ($_POST['priority_id'] ?? 0);
        $desc = trim((string) ($_POST['description'] ?? ''));
        $acct = trim((string) ($_POST['default_account_number'] ?? ''));
        $defAssign = (int) ($_POST['default_assignee_user_id'] ?? 0);
        $instr = trim((string) ($_POST['instructions'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;
        $saveAction = (string) ($_POST['save_action'] ?? 'publish');
        $tplStatus = $saveAction === 'draft' ? 'draft' : 'published';
        if ($title === '' || $desc === '' || $dept < 1 || $cat < 1 || $pri < 1) {
            $errors[] = 'Title, description, department, category, and priority are required.';
        }
        if (!$errors) {
            try {
                $pdo->prepare(
                    'UPDATE ticket_templates SET template_title=?, category_id=?, priority_id=?, department_id=?, description=?,
                     default_account_number=?, default_assignee_user_id=?, instructions=?, is_active=?, template_status=?, last_edited_by=? WHERE id=?'
                )->execute([
                    $title,
                    $cat,
                    $pri,
                    $dept,
                    $desc,
                    $acct === '' ? null : $acct,
                    $defAssign > 0 ? $defAssign : null,
                    $instr === '' ? null : $instr,
                    $active,
                    $tplStatus,
                    (int) $u['id'],
                    $id,
                ]);
            } catch (Throwable $e) {
                try {
                    $pdo->prepare(
                        'UPDATE ticket_templates SET template_title=?, category_id=?, priority_id=?, department_id=?, description=?,
                         default_account_number=?, default_assignee_user_id=?, instructions=?, is_active=? WHERE id=?'
                    )->execute([
                        $title,
                        $cat,
                        $pri,
                        $dept,
                        $desc,
                        $acct === '' ? null : $acct,
                        $defAssign > 0 ? $defAssign : null,
                        $instr === '' ? null : $instr,
                        $active,
                        $id,
                    ]);
                } catch (Throwable $e2) {
                    $pdo->prepare(
                        'UPDATE ticket_templates SET template_title=?, category_id=?, priority_id=?, department_id=?, description=? WHERE id=?'
                    )->execute([$title, $cat, $pri, $dept, $desc, $id]);
                }
            }
            ticket_template_fields_replace($id, $fieldsPayload, (int) $u['id']);
            try {
                $pdo->prepare('UPDATE ticket_templates SET last_edited_by = ?, template_version = IFNULL(template_version, 0) + 1 WHERE id = ?')->execute([(int) $u['id'], $id]);
            } catch (Throwable $e) {
            }
            flash_set('success', 'Template updated.');
            redirect('template_detail.php?id=' . $id);
        }
    }
}

$ver = (int) ($tpl['template_version'] ?? 1);
$pageTitle = 'Edit Template';
$activeNav = 'ticket_templates';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
$fieldsJsonOut = json_encode(is_post() ? $fieldsPayload : $existingFields, JSON_UNESCAPED_UNICODE);
?>

<div class="container-fluid px-3 px-lg-4" id="tplBuilderRoot">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div class="page-title-block mb-0">
            <h1 class="mb-0">Edit Ticket Template</h1>
            <div class="subtitle">Design a template to make working with tickets easier</div>
            <div class="small text-muted mt-1">Version <span class="fw-semibold"><?php echo $ver; ?></span> · Last edited: <?php echo e($u['full_name']); ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-outline-muted btn-sm" href="template_detail.php?id=<?php echo $id; ?>">Back</a>
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
        <input type="hidden" name="fields_json" id="tplFieldsJson" value="<?php echo e($fieldsJsonOut); ?>">

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
                <input class="form-control form-control-sm mb-2" name="template_title" required value="<?php echo e((string) $tpl['template_title']); ?>">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?php echo !isset($tpl['is_active']) || !empty($tpl['is_active']) ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="is_active">Active</label>
                </div>
                <label class="form-label small">Department</label>
                <select class="form-select form-select-sm mb-2" name="department_id" required>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo ((int) $tpl['department_id'] === (int) $d['id']) ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small">Category</label>
                <select class="form-select form-select-sm mb-2" name="category_id" required>
                    <?php foreach ($categories as $c) : ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo ((int) $tpl['category_id'] === (int) $c['id']) ? 'selected' : ''; ?>><?php echo e($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small">Priority</label>
                <select class="form-select form-select-sm mb-2" name="priority_id" required>
                    <?php foreach ($priorities as $p) : ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo ((int) $tpl['priority_id'] === (int) $p['id']) ? 'selected' : ''; ?>><?php echo e($p['priority_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small">Default account</label>
                <input class="form-control form-control-sm mb-2" name="default_account_number" value="<?php echo e((string) ($tpl['default_account_number'] ?? '')); ?>">
                <label class="form-label small">Default assignee</label>
                <select class="form-select form-select-sm mb-2" name="default_assignee_user_id">
                    <option value="">None</option>
                    <?php foreach ($usersList as $uu) : ?>
                        <option value="<?php echo (int) $uu['id']; ?>" <?php echo ((int) ($tpl['default_assignee_user_id'] ?? 0) === (int) $uu['id']) ? 'selected' : ''; ?>><?php echo e($uu['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small">Body / default description</label>
                <textarea class="form-control form-control-sm mb-2" name="description" rows="4" required><?php echo e((string) $tpl['description']); ?></textarea>
                <label class="form-label small">Instructions</label>
                <textarea class="form-control form-control-sm" name="instructions" rows="2"><?php echo e((string) ($tpl['instructions'] ?? '')); ?></textarea>
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
