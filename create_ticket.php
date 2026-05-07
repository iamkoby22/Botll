<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('create_ticket');

$pdo = db();
$u = current_user();

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
$categories = $pdo->query('SELECT * FROM ticket_categories ORDER BY category_name')->fetchAll();
$priorities = $pdo->query('SELECT * FROM ticket_priorities ORDER BY priority_level')->fetchAll();
$usersList = $pdo->query('SELECT id, full_name FROM users WHERE status="active" ORDER BY full_name')->fetchAll();
$templates = $pdo->query(
    'SELECT tt.*, c.category_name, p.priority_name, d.department_name
     FROM ticket_templates tt
     JOIN ticket_categories c ON c.id = tt.category_id
     JOIN ticket_priorities p ON p.id = tt.priority_id
     JOIN departments d ON d.id = tt.department_id
     ORDER BY tt.template_title'
)->fetchAll();

$errors = [];
$values = [
    'category_id' => (string) ($_POST['category_id'] ?? ''),
    'priority_id' => (string) ($_POST['priority_id'] ?? ''),
    'department_id' => (string) ($_POST['department_id'] ?? ''),
    'subject' => trim((string) ($_POST['subject'] ?? '')),
    'description' => trim((string) ($_POST['description'] ?? '')),
    'account_number' => trim((string) ($_POST['account_number'] ?? '')),
];

if (!is_post() && isset($_GET['template_id'])) {
    $tid = (int) $_GET['template_id'];
    if ($tid > 0) {
        $tp = $pdo->prepare('SELECT * FROM ticket_templates WHERE id = ? LIMIT 1');
        $tp->execute([$tid]);
        $tpl = $tp->fetch();
        if ($tpl) {
            $values['category_id'] = (string) $tpl['category_id'];
            $values['priority_id'] = (string) $tpl['priority_id'];
            $values['department_id'] = (string) $tpl['department_id'];
            $values['description'] = (string) $tpl['description'];
            $values['subject'] = (string) $tpl['template_title'];
            if (!empty($tpl['default_account_number'])) {
                $values['account_number'] = (string) $tpl['default_account_number'];
            }
        }
    }
}

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($templateId > 0) {
            $tp = $pdo->prepare('SELECT * FROM ticket_templates WHERE id = ? LIMIT 1');
            $tp->execute([$templateId]);
            $tpl = $tp->fetch();
            if ($tpl) {
                $values['category_id'] = (string) $tpl['category_id'];
                $values['priority_id'] = (string) $tpl['priority_id'];
                $values['department_id'] = (string) $tpl['department_id'];
                $values['description'] = (string) $tpl['description'];
                if ($values['subject'] === '') {
                    $values['subject'] = (string) $tpl['template_title'];
                }
                if (!empty($tpl['default_account_number']) && $values['account_number'] === '') {
                    $values['account_number'] = (string) $tpl['default_account_number'];
                }
            }
        }

        $cat = (int) ($values['category_id'] ?? 0);
        $pri = (int) ($values['priority_id'] ?? 0);
        $dept = (int) ($values['department_id'] ?? 0);

        if ($cat < 1 || $pri < 1 || $dept < 1) {
            $errors[] = 'Choose category, priority, and department.';
        }
        if ($values['subject'] === '' || $values['description'] === '') {
            $errors[] = 'Subject and description are required.';
        }

        $assignees = isset($_POST['assignee_id']) && is_array($_POST['assignee_id'])
            ? array_values(array_filter(array_map('intval', $_POST['assignee_id'])))
            : [];

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $max = (int) $pdo->query('SELECT IFNULL(MAX(id),0) m FROM tickets')->fetch()['m'];
                $ticketNumber = sprintf('TKT-%s-%04d', date('Y'), $max + 1);

                $creator = (int) $u['id'];
                $primaryAssignee = $assignees[0] ?? null;
                $statusOpen = (int) $pdo->query('SELECT id FROM ticket_statuses WHERE status_name="Open" LIMIT 1')->fetch()['id'];
                $statusPending = (int) $pdo->query('SELECT id FROM ticket_statuses WHERE status_name="Pending Approval" LIMIT 1')->fetch()['id'];
                $needsApproval = $primaryAssignee !== null && (int) $primaryAssignee > 0;
                $statusId = $needsApproval ? $statusPending : $statusOpen;

                $ins = $pdo->prepare(
                    'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, account_number, status_id, department_id, created_by, assigned_to, date_completed, sla_breach, attachments_count, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NULL,0,0,NOW())'
                );
                $ins->execute([
                    $ticketNumber,
                    $values['subject'],
                    $values['description'],
                    $cat,
                    $pri,
                    $values['account_number'] === '' ? null : $values['account_number'],
                    $statusId,
                    $dept,
                    $creator,
                    $primaryAssignee,
                ]);
                $tid = (int) $pdo->lastInsertId();

                $dir = UPLOAD_PATH . '/' . $tid;
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                $atts = 0;
                if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
                    $names = $_FILES['attachments']['name'];
                    $tmp = $_FILES['attachments']['tmp_name'];
                    $sizes = $_FILES['attachments']['size'];
                    $types = $_FILES['attachments']['type'];
                    $count = count($names);
                    for ($i = 0; $i < $count; $i++) {
                        if (empty($tmp[$i]) || !is_uploaded_file($tmp[$i])) {
                            continue;
                        }
                        $orig = (string) $names[$i];
                        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
                        $target = $dir . '/' . $safe;
                        if (move_uploaded_file($tmp[$i], $target)) {
                            $atts++;
                            $pst = $pdo->prepare(
                                'INSERT INTO ticket_attachments (ticket_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?)'
                            );
                            $pst->execute([
                                $tid,
                                $orig,
                                'uploads/tickets/' . $tid . '/' . $safe,
                                (string) ($types[$i] ?? ''),
                                (int) ($sizes[$i] ?? 0),
                                $creator,
                            ]);
                        }
                    }
                }

                if ($atts > 0) {
                    $pdo->prepare('UPDATE tickets SET attachments_count = ? WHERE id = ?')->execute([$atts, $tid]);
                }

                $sort = 1;
                foreach ($assignees as $aid) {
                    if ($aid < 1) {
                        continue;
                    }
                    $pdo->prepare(
                        'INSERT INTO ticket_assignees (ticket_id, user_id, approval_status, sort_order) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)'
                    )->execute([$tid, $aid, 'pending', $sort++]);
                }

                // Business rule: when a ticket is assigned and no separate approver is selected,
                // the primary assignee becomes the pending approver.
                if ($needsApproval) {
                    // Ensure we don't duplicate approvals if one already exists.
                    $ac = $pdo->prepare('SELECT COUNT(*) FROM ticket_approvals WHERE ticket_id = ?');
                    $ac->execute([$tid]);
                    $apCount = (int) $ac->fetchColumn();
                    if ($apCount === 0) {
                        $pdo->prepare(
                            'INSERT INTO ticket_approvals (ticket_id, approver_id, approval_level, approval_status) VALUES (?,?,1,\"pending\")'
                        )->execute([$tid, (int) $primaryAssignee]);
                    }
                    notify_user(
                        (int) $primaryAssignee,
                        'Approval required',
                        'Ticket ' . $ticketNumber . ' is waiting for your approval.',
                        'approval',
                        'ticket_detail.php?id=' . $tid
                    );
                }

                try {
                    ticket_log_comment($tid, $creator, 'Ticket created.', 'system');
                } catch (Throwable $e) {
                    // ticket_comments table may be missing until migration
                }

                $detailUrl = 'ticket_detail.php?id=' . $tid;
                foreach ($assignees as $aid) {
                    if ($aid < 1) {
                        continue;
                    }
                    notify_user(
                        $aid,
                        'New ticket assigned',
                        'You were assigned to ' . $ticketNumber,
                        'assignment',
                        $detailUrl
                    );
                }

                $pdo->commit();
                flash_set('success', 'Ticket ' . $ticketNumber . ' created. View details below.');
                redirect($detailUrl);
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Could not save ticket. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create Ticket';
$activeNav = 'create_ticket';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1>Create Ticket</h1>
        <div class="subtitle">Submit a new ticket for approval</div>
    </div>

    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>

    <form class="card-surface p-3 p-lg-4" method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">

        <div class="d-flex flex-wrap gap-2 mb-4">
            <div class="dropdown">
                <button class="btn btn-outline-muted btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    Use an existing Template
                </button>
                <ul class="dropdown-menu dropdown-menu-start" style="max-height:280px;overflow:auto;">
                    <?php foreach ($templates as $tpl) : ?>
                        <li>
                            <a class="dropdown-item" href="create_ticket.php?template_id=<?php echo (int) $tpl['id']; ?>">
                                <?php echo e($tpl['template_title']); ?> · <?php echo e($tpl['department_name']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Category</label>
                <select name="category_id" class="form-select" required>
                    <option value="">Select a Category</option>
                    <?php foreach ($categories as $c) : ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo ((string) $c['id'] === $values['category_id']) ? 'selected' : ''; ?>><?php echo e($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Priority</label>
                <select name="priority_id" class="form-select" required>
                    <option value="">Select priority level</option>
                    <?php foreach ($priorities as $p) : ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo ((string) $p['id'] === $values['priority_id']) ? 'selected' : ''; ?>><?php echo e($p['priority_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Department</label>
                <select name="department_id" class="form-select" required>
                    <option value="">Select department</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo ((string) $d['id'] === $values['department_id']) ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Account</label>
                <input class="form-control" name="account_number" placeholder="Account number" value="<?php echo e($values['account_number']); ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold small">Subject</label>
                <input class="form-control" name="subject" required value="<?php echo e($values['subject']); ?>" placeholder="Brief summary of your request">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold small">Description</label>
                <textarea class="form-control" name="description" rows="5" required placeholder="Brief summary of your request"><?php echo e($values['description']); ?></textarea>
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold small">Assigned to:</label>
                <div id="assigneeRows" class="d-flex flex-column gap-2">
                    <div class="d-flex gap-2 align-items-center assignee-row">
                        <select class="form-select" name="assignee_id[]">
                            <option value="">Select assignee</option>
                            <?php foreach ($usersList as $uu) : ?>
                                <option value="<?php echo (int) $uu['id']; ?>"><?php echo e($uu['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="button" class="btn btn-link btn-sm px-0 mt-2" id="addAssigneeRow"><i class="bi bi-plus-circle"></i> Add new</button>
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold small">Attachment</label>
                <div class="upload-box">
                    <div class="mb-2"><i class="bi bi-cloud-arrow-up fs-3 text-secondary"></i></div>
                    <div class="fw-semibold">Click to upload or drag and drop</div>
                    <div class="small text-muted">PDF, DOC, DOCX, or images up to 10MB</div>
                    <input class="form-control mt-3" type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,image/*">
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end gap-2 pt-2">
                <a class="btn btn-outline-muted" href="all_tickets.php">Cancel</a>
                <button type="submit" class="btn btn-accent">Create</button>
            </div>
        </div>
    </form>
</div>

<script>
(function(){
  const wrap=document.getElementById('assigneeRows');
  const add=document.getElementById('addAssigneeRow');
  if(!wrap||!add) return;
  add.addEventListener('click',()=>{
    const row=wrap.querySelector('.assignee-row');
    if(!row) return;
    const clone=row.cloneNode(true);
    clone.querySelectorAll('select').forEach(s=>s.selectedIndex=0);
    wrap.appendChild(clone);
  });
})();
</script>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
