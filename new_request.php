<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/service_catalog.php';
require_page('new_request');

$pdo = db();
$u = current_user();
$uid = (int) $u['id'];

$catalog = service_catalog_rows();
$byId = [];
foreach ($catalog as $row) {
    $byId[(int) $row['id']] = $row;
}

$serviceId = (int) ($_GET['service_id'] ?? $_POST['service_id'] ?? 0);
$svc = $byId[$serviceId] ?? null;
if (!$svc) {
    flash_set('danger', 'Pick a service from the Requests catalog.');
    redirect('requests.php');
}

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
$errors = [];
$vals = [
    'title' => trim((string) ($_POST['title'] ?? '')),
    'employee_name' => trim((string) ($_POST['employee_name'] ?? $u['full_name'])),
    'department_id' => (string) ($_POST['department_id'] ?? (string) ($svc['default_department_id'] ?? $u['department_id'] ?? '')),
    'detail_field' => trim((string) ($_POST['detail_field'] ?? '')),
    'justification' => trim((string) ($_POST['justification'] ?? '')),
];

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $action = (string) ($_POST['form_action'] ?? 'submit');
        if ($action === 'draft') {
            $_SESSION['new_request_draft_' . $serviceId] = $vals;
            flash_set('success', 'Draft saved locally in your session.');
            redirect('new_request.php?service_id=' . $serviceId);
        }
        $dept = (int) $vals['department_id'];
        if ($vals['justification'] === '' || $dept < 1) {
            $errors[] = 'Department and justification are required.';
        }
        if ($vals['title'] === '') {
            $vals['title'] = (string) $svc['title'];
        }
        if (!$errors) {
            $cat = (int) ($svc['default_category_id'] ?? 1);
            $pri = (int) ($svc['default_priority_id'] ?? 2);
            $pendId = (int) ($pdo->query('SELECT id FROM ticket_statuses WHERE status_name="Pending Approval" LIMIT 1')->fetch()['id'] ?: 1);
            $desc = "Service: {$svc['title']}\nRequester: {$vals['employee_name']}\n";
            if ($vals['detail_field'] !== '') {
                $desc .= "Details: {$vals['detail_field']}\n";
            }
            $desc .= "\nJustification:\n{$vals['justification']}";

            $pdo->beginTransaction();
            try {
                $max = (int) $pdo->query('SELECT IFNULL(MAX(id),0) m FROM tickets')->fetch()['m'];
                $ticketNumber = sprintf('TKT-%s-%04d', date('Y'), $max + 1);
                $subj = '[' . $svc['title'] . '] ' . $vals['title'];

                $pdo->prepare(
                    'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, account_number, status_id, department_id, created_by, assigned_to, date_completed, sla_breach, attachments_count, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NULL,0,0,NOW())'
                )->execute([
                    $ticketNumber,
                    $subj,
                    $desc,
                    $cat,
                    $pri,
                    null,
                    $pendId,
                    $dept,
                    $uid,
                    null,
                ]);
                $tid = (int) $pdo->lastInsertId();
                try {
                    $pdo->prepare('UPDATE tickets SET service_catalog_id = ?, is_draft = 0 WHERE id = ?')->execute([$serviceId, $tid]);
                } catch (Throwable $e) {
                    /* columns missing until migration */
                }

                $dir = UPLOAD_PATH . '/' . $tid;
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
                    $names = $_FILES['attachments']['name'];
                    $tmp = $_FILES['attachments']['tmp_name'];
                    $sizes = $_FILES['attachments']['size'];
                    $types = $_FILES['attachments']['type'];
                    $atts = 0;
                    $count = count($names);
                    for ($i = 0; $i < $count; $i++) {
                        if (empty($tmp[$i]) || !is_uploaded_file($tmp[$i])) {
                            continue;
                        }
                        $orig = (string) $names[$i];
                        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
                        if (move_uploaded_file($tmp[$i], $dir . '/' . $safe)) {
                            $atts++;
                            $pdo->prepare(
                                'INSERT INTO ticket_attachments (ticket_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?)'
                            )->execute([$tid, $orig, 'uploads/tickets/' . $tid . '/' . $safe, (string) ($types[$i] ?? ''), (int) ($sizes[$i] ?? 0), $uid]);
                        }
                    }
                    if ($atts > 0) {
                        $pdo->prepare('UPDATE tickets SET attachments_count = ? WHERE id = ?')->execute([$atts, $tid]);
                    }
                }

                $hod = $pdo->prepare(
                    'SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                     WHERE r.role_key = "hod" AND u.department_id = ? AND u.status="active" ORDER BY u.id ASC LIMIT 1'
                );
                $hod->execute([$dept]);
                $hodId = (int) ($hod->fetch()['id'] ?? 0);
                if ($hodId > 0) {
                    $pdo->prepare(
                        'INSERT INTO ticket_approvals (ticket_id, approver_id, approval_level, approval_status) VALUES (?,?,1,"pending")'
                    )->execute([$tid, $hodId]);
                    notify_user(
                        $hodId,
                        'Approval required',
                        $ticketNumber . ' — ' . $subj,
                        'approval',
                        'ticket_detail.php?id=' . $tid
                    );
                }

                try {
                    ticket_log_comment($tid, $uid, 'Service request submitted.', 'system');
                } catch (Throwable $e) {
                }

                $pdo->commit();
                unset($_SESSION['new_request_draft_' . $serviceId]);
                flash_set('success', 'Request submitted.');
                redirect('ticket_detail.php?id=' . $tid);
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Could not save request.';
            }
        }
    }
}

if (isset($_SESSION['new_request_draft_' . $serviceId]) && is_array($_SESSION['new_request_draft_' . $serviceId]) && !is_post()) {
    $vals = array_merge($vals, $_SESSION['new_request_draft_' . $serviceId]);
}

$pageTitle = 'New Request';
$activeNav = 'requests';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
$f = flash_get();
?>

<div class="container-fluid px-3 px-lg-4">
    <?php if ($f) : ?>
        <div class="alert alert-<?php echo e($f['type'] === 'success' ? 'success' : 'danger'); ?>"><?php echo e($f['message']); ?></div>
    <?php endif; ?>
    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div class="page-title-block mb-0">
            <h1 class="mb-0">New Request</h1>
            <div class="subtitle">Submit a service request for approval and processing</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-muted btn-sm" href="requests.php">Cancel</a>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="service_id" value="<?php echo (int) $serviceId; ?>">

        <div class="col-lg-8">
            <div class="card-surface p-3 p-lg-4 mb-3">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="submit" name="form_action" value="draft" class="btn btn-outline-muted btn-sm">Save Draft</button>
                    <button type="submit" name="form_action" value="submit" class="btn btn-accent btn-sm">Submit Request</button>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Request type</label>
                    <div class="fw-bold"><?php echo e((string) $svc['title']); ?></div>
                    <div class="text-muted small"><?php echo e((string) $svc['description']); ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Short title</label>
                    <input class="form-control" name="title" value="<?php echo e($vals['title']); ?>" placeholder="Brief title for this request">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Employee name</label>
                    <input class="form-control" name="employee_name" value="<?php echo e($vals['employee_name']); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Department</label>
                    <select class="form-select" name="department_id" required>
                        <?php foreach ($departments as $d) : ?>
                            <option value="<?php echo (int) $d['id']; ?>" <?php echo $vals['department_id'] === (string) $d['id'] ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Request details (model, software name, dates, etc.)</label>
                    <input class="form-control" name="detail_field" value="<?php echo e($vals['detail_field']); ?>" placeholder="e.g. laptop model or system name">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Justification</label>
                    <textarea class="form-control" name="justification" rows="5" required placeholder="Business reason and urgency"><?php echo e($vals['justification']); ?></textarea>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold">Attachments</label>
                    <input type="file" class="form-control" name="attachments[]" multiple accept=".pdf,.doc,.docx,image/*">
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Summary</h2>
                <div class="small">
                    <div class="mb-2"><span class="text-muted">Type</span><div class="fw-semibold"><?php echo e((string) $svc['title']); ?></div></div>
                    <div class="mb-2"><span class="text-muted">ETA</span><div class="fw-semibold"><?php echo e((string) $svc['est_duration']); ?></div></div>
                    <div class="mb-2"><span class="text-muted">Approval</span><div class="fw-semibold">Routed to department Head of Department when available.</div></div>
                    <div class="mb-2"><span class="text-muted">Department</span><div class="fw-semibold">Selected in form</div></div>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
