<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_login();

$ticketId = (int) ($_GET['id'] ?? 0);
if ($ticketId < 1) {
    redirect('all_tickets.php');
}

$pdo = db();
$u = current_user();
$uid = (int) $u['id'];

if (!ticket_can_view($ticketId)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:system-ui;padding:2rem;"><h1>403</h1><p><a href="all_tickets.php">Back</a></p></body></html>';
    exit;
}

$ticket = ticket_fetch_by_id($ticketId);
if (!$ticket) {
    redirect('all_tickets.php');
}

$errors = [];
$flashHandled = false;

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'comment') {
            $body = trim((string) ($_POST['comment'] ?? ''));
            if ($body === '') {
                $errors[] = 'Comment cannot be empty.';
            } else {
                ticket_log_comment($ticketId, $uid, $body, 'comment');
                flash_set('success', 'Comment added.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'status' && ticket_can_change_status($ticketId)) {
            $newStatusId = (int) ($_POST['status_id'] ?? 0);
            $st = $pdo->prepare('SELECT id, status_name FROM ticket_statuses WHERE id = ? LIMIT 1');
            $st->execute([$newStatusId]);
            $ns = $st->fetch();
            if ($ns) {
                $oldName = (string) $ticket['status_name'];
                $pdo->prepare('UPDATE tickets SET status_id = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatusId, $ticketId]);
                if ((string) $ns['status_name'] === 'Completed') {
                    $pdo->prepare('UPDATE tickets SET date_completed = CURDATE() WHERE id = ?')->execute([$ticketId]);
                }
                ticket_log_history($ticketId, $uid, 'status', $oldName, (string) $ns['status_name']);
                flash_set('success', 'Status updated.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'assign' && ticket_can_assign($ticketId)) {
            $assignee = (int) ($_POST['assigned_to'] ?? 0);
            if ($assignee > 0) {
                $old = (string) ($ticket['assignee_name'] ?? '');
                $pdo->prepare('UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?')->execute([$assignee, $ticketId]);
                $pdo->prepare(
                    'INSERT INTO ticket_assignees (ticket_id, user_id, approval_status, sort_order) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)'
                )->execute([$ticketId, $assignee, 'pending', 1]);
                ticket_log_history($ticketId, $uid, 'assigned_to', $old, (string) $assignee);
                notify_user(
                    $assignee,
                    'Ticket assigned',
                    'You were assigned to ticket ' . $ticket['ticket_number'],
                    'assignment',
                    'ticket_detail.php?id=' . $ticketId
                );
                flash_set('success', 'Assignee updated.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'approve') {
            $ctx = ticket_approval_actor_context($ticketId, $uid);
            $apRow = $ctx['approval_row'] ?? null;
            $postedRow = (int) ($_POST['approval_row_id'] ?? 0);
            if ($ctx['can_decide'] && $apRow && $postedRow === (int) $apRow['id']) {
                $decision = (string) ($_POST['decision'] ?? '');
                $new = $decision === 'reject' ? 'rejected' : 'approved';
                $note = trim((string) ($_POST['approval_comment'] ?? ''));
                $cid = (int) $apRow['id'];
                $pdo->prepare(
                    'UPDATE ticket_approvals SET approval_status = ?, comments = ?, updated_at = NOW() WHERE id = ? AND ticket_id = ?'
                )->execute([$new, $note !== '' ? $note : null, $cid, $ticketId]);
                ticket_log_history($ticketId, $uid, 'approval', 'pending', $new . ' (row ' . $cid . ')');
                $msg = 'Approval ' . $new . ' recorded.';
                if ($note !== '') {
                    $msg .= ' Note: ' . $note;
                }
                try {
                    ticket_log_comment($ticketId, $uid, $msg, 'approval');
                } catch (Throwable $e) {
                    // ticket_comments table may be missing
                }

                $openId = status_id_by_name('Open');
                $cancelId = status_id_by_name('Cancelled');

                $pc = $pdo->prepare('SELECT COUNT(*) FROM ticket_approvals WHERE ticket_id = ? AND approval_status = "pending"');
                $pc->execute([$ticketId]);
                $pendingCnt = (int) $pc->fetchColumn();

                if ($new === 'rejected' && $cancelId) {
                    $pdo->prepare('UPDATE tickets SET status_id = ?, updated_at = NOW() WHERE id = ?')->execute([$cancelId, $ticketId]);
                    ticket_log_history($ticketId, $uid, 'status', (string) $ticket['status_name'], 'Cancelled');
                } elseif ($new === 'approved' && $pendingCnt === 0 && $openId) {
                    $pdo->prepare('UPDATE tickets SET status_id = ?, approved_by = ?, updated_at = NOW() WHERE id = ?')->execute([$openId, $uid, $ticketId]);
                    ticket_log_history($ticketId, $uid, 'status', (string) $ticket['status_name'], 'Open');
                }

                $creatorId = (int) $ticket['created_by'];
                $assignId = (int) ($ticket['assigned_to'] ?? 0);
                notify_user(
                    $creatorId,
                    'Ticket ' . $ticket['ticket_number'] . ' — approval ' . $new,
                    'An approver updated the approval status.',
                    'approval',
                    'ticket_detail.php?id=' . $ticketId
                );
                if ($assignId > 0 && $assignId !== $creatorId) {
                    notify_user(
                        $assignId,
                        'Ticket ' . $ticket['ticket_number'] . ' — approval ' . $new,
                        'Approval progress changed.',
                        'approval',
                        'ticket_detail.php?id=' . $ticketId
                    );
                }
                $others = $pdo->prepare('SELECT DISTINCT approver_id FROM ticket_approvals WHERE ticket_id = ? AND approver_id NOT IN (?,?)');
                $others->execute([$ticketId, $uid, $creatorId]);
                foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $oid) {
                    $oid = (int) $oid;
                    if ($oid > 0) {
                        notify_user(
                            $oid,
                            'Ticket ' . $ticket['ticket_number'] . ' — approval ' . $new,
                            'Approval activity on a ticket you are involved with.',
                            'approval',
                            'ticket_detail.php?id=' . $ticketId
                        );
                    }
                }

                flash_set('success', 'Approval updated.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
            $errors[] = 'You cannot approve or reject this ticket with your current role.';
        } elseif ($action === 'attach') {
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $dir = UPLOAD_PATH . '/' . $ticketId;
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $orig = (string) ($_FILES['file']['name'] ?? 'file');
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
                $target = $dir . '/' . $safe;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                    $pdo->prepare(
                        'INSERT INTO ticket_attachments (ticket_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?)'
                    )->execute([
                        $ticketId,
                        $orig,
                        'uploads/tickets/' . $ticketId . '/' . $safe,
                        (string) ($_FILES['file']['type'] ?? ''),
                        (int) ($_FILES['file']['size'] ?? 0),
                        $uid,
                    ]);
                    $pdo->prepare('UPDATE tickets SET attachments_count = attachments_count + 1 WHERE id = ?')->execute([$ticketId]);
                    ticket_log_comment($ticketId, $uid, 'Uploaded attachment: ' . $orig, 'attachment');
                    flash_set('success', 'Attachment uploaded.');
                    redirect('ticket_detail.php?id=' . $ticketId);
                }
            }
            $errors[] = 'Upload failed.';
        }
    }
}

$ticket = ticket_fetch_by_id($ticketId);

$assignees = $pdo->prepare(
    'SELECT ta.*, u.full_name FROM ticket_assignees ta JOIN users u ON u.id = ta.user_id WHERE ta.ticket_id = ? ORDER BY ta.sort_order ASC, ta.id ASC'
);
$assignees->execute([$ticketId]);
$assigneeRows = $assignees->fetchAll();

$approvals = $pdo->prepare(
    'SELECT ta.*, u.full_name FROM ticket_approvals ta JOIN users u ON u.id = ta.approver_id WHERE ta.ticket_id = ? ORDER BY ta.approval_level ASC'
);
$approvals->execute([$ticketId]);
$approvalRows = $approvals->fetchAll();

$attachments = $pdo->prepare('SELECT * FROM ticket_attachments WHERE ticket_id = ? ORDER BY id DESC');
$attachments->execute([$ticketId]);
$attachmentRows = $attachments->fetchAll();

$comments = [];
try {
    $cst = $pdo->prepare(
        'SELECT tc.*, u.full_name FROM ticket_comments tc JOIN users u ON u.id = tc.user_id WHERE tc.ticket_id = ? ORDER BY tc.id ASC'
    );
    $cst->execute([$ticketId]);
    $comments = $cst->fetchAll();
} catch (Throwable $e) {
    $comments = [];
}

$history = [];
try {
    $hst = $pdo->prepare(
        'SELECT th.*, u.full_name FROM ticket_history th JOIN users u ON u.id = th.changed_by WHERE th.ticket_id = ? ORDER BY th.id DESC LIMIT 50'
    );
    $hst->execute([$ticketId]);
    $history = $hst->fetchAll();
} catch (Throwable $e) {
    $history = [];
}

$apCtx = ticket_approval_actor_context($ticketId, $uid);

$statuses = $pdo->query('SELECT * FROM ticket_statuses ORDER BY id')->fetchAll();
$usersList = $pdo->query('SELECT id, full_name FROM users WHERE status="active" ORDER BY full_name')->fetchAll();

$pageTitle = 'Ticket ' . $ticket['ticket_number'];
$activeNav = 'all_tickets';
$includeCharts = false;
$topbarSearchQuery = '';

require __DIR__ . '/includes/shell_begin.php';
$f = flash_get();
?>

<div class="container-fluid px-3 px-lg-4">
    <?php if ($f) : ?>
        <div class="alert alert-<?php echo e($f['type'] === 'success' ? 'success' : ($f['type'] === 'danger' ? 'danger' : 'info')); ?>"><?php echo e($f['message']); ?></div>
    <?php endif; ?>
    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="page-title-block mb-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h1 class="mb-0"><?php echo e($ticket['ticket_number']); ?></h1>
                <span class="badge text-bg-light border"><?php echo e($ticket['status_name']); ?></span>
                <span class="badge text-bg-light border"><?php echo e($ticket['priority_name']); ?></span>
                <?php if (!empty($ticket['sla_breach'])) : ?>
                    <span class="badge bg-danger">SLA Breach</span>
                <?php endif; ?>
            </div>
            <div class="subtitle"><?php echo e($ticket['subject']); ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-muted btn-sm" href="all_tickets.php"><i class="bi bi-arrow-left"></i> All Tickets</a>
            <?php if (ticket_can_change_status($ticketId)) : ?>
                <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="status">
                    <div class="input-group input-group-sm">
                        <select name="status_id" class="form-select" style="min-width:180px;">
                            <?php foreach ($statuses as $s) : ?>
                                <option value="<?php echo (int) $s['id']; ?>" <?php echo ((int) $s['id'] === (int) $ticket['status_id']) ? 'selected' : ''; ?>><?php echo e($s['status_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-accent" type="submit">Update status</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Details</h2>
                <div class="row g-2 small">
                    <div class="col-md-6"><span class="text-muted">Category</span><div class="fw-semibold"><?php echo e($ticket['category_name']); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Department</span><div class="fw-semibold"><?php echo e($ticket['department_name']); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Account</span><div class="fw-semibold"><?php echo e((string) ($ticket['account_number'] ?? '—')); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Created by</span><div class="fw-semibold"><?php echo e($ticket['created_name']); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Primary assignee</span><div class="fw-semibold"><?php echo e((string) ($ticket['assignee_name'] ?? '—')); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Approver</span><div class="fw-semibold"><?php echo e((string) ($ticket['approver_name'] ?? '—')); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Created</span><div class="fw-semibold"><?php echo e(date('m/d/Y H:i', strtotime((string) $ticket['created_at']))); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Completed</span><div class="fw-semibold"><?php echo !empty($ticket['date_completed']) ? e(date('m/d/Y', strtotime((string) $ticket['date_completed']))) : '—'; ?></div></div>
                </div>
                <hr>
                <div class="fw-semibold mb-2">Description</div>
                <div class="small" style="white-space:pre-wrap;"><?php echo e($ticket['description']); ?></div>
            </div>

            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Assignees & approval chain</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-modern mb-0">
                        <thead><tr><th>Assignee</th><th>Assignment status</th></tr></thead>
                        <tbody>
                        <?php foreach ($assigneeRows as $ar) : ?>
                            <tr>
                                <td><?php echo e($ar['full_name']); ?></td>
                                <td><span class="badge text-bg-light border"><?php echo e($ar['approval_status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$assigneeRows) : ?>
                            <tr><td colspan="2" class="text-muted">No assignee rows.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-light border small mb-2 mb-md-3 py-2">
                    <strong>Approval vs assignment:</strong>
                    <em>Assigned to Me</em> means you work the ticket.
                    <em>Pending My Approval</em> means your approval decision is required (you are on the approval chain, or you have an override role).
                    Approve/Reject appears here only when you are allowed to decide the current pending step.
                </div>
                <div class="small text-muted mb-2">
                    <strong>Current approval status:</strong>
                    <?php
                    $pendNames = [];
                    foreach ($approvalRows as $ar) {
                        if (($ar['approval_status'] ?? '') === 'pending') {
                            $pendNames[] = (string) $ar['full_name'];
                        }
                    }
                    echo $pendNames ? 'Waiting on: ' . e(implode(', ', $pendNames)) : 'No pending approvers in the chain.';
                    ?>
                </div>
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-modern mb-0">
                        <thead><tr><th>Level</th><th>Approver</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($approvalRows as $ar) : ?>
                            <tr>
                                <td><?php echo (int) $ar['approval_level']; ?></td>
                                <td><?php echo e($ar['full_name']); ?></td>
                                <td><span class="badge text-bg-light border"><?php echo e($ar['approval_status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$approvalRows) : ?>
                            <tr><td colspan="3" class="text-muted">No approval records.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($apCtx['info_message'])) : ?>
                    <div class="alert alert-info small py-2 mt-3 mb-0"><?php echo e($apCtx['info_message']); ?></div>
                <?php endif; ?>

                <?php if (!empty($apCtx['can_decide']) && !empty($apCtx['approval_row'])) : ?>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="approval_row_id" value="<?php echo (int) $apCtx['approval_row']['id']; ?>">
                        <label class="form-label small text-muted mb-1">Optional approval comment</label>
                        <textarea name="approval_comment" class="form-control form-control-sm mb-2" rows="2" placeholder="Notes for the requester or audit trail"></textarea>
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-accent btn-sm" name="decision" value="approve" type="submit">Approve</button>
                            <button class="btn btn-outline-danger btn-sm" name="decision" value="reject" type="submit">Reject</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if (ticket_can_assign($ticketId)) : ?>
                    <form method="post" class="row g-2 align-items-end mt-3">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="assign">
                        <div class="col-md-8">
                            <label class="form-label small text-muted mb-1">Reassign primary</label>
                            <select class="form-select form-select-sm" name="assigned_to">
                                <option value="0">Select assignee</option>
                                <?php foreach ($usersList as $uu) : ?>
                                    <option value="<?php echo (int) $uu['id']; ?>" <?php echo ((int) $uu['id'] === (int) ($ticket['assigned_to'] ?? 0)) ? 'selected' : ''; ?>><?php echo e($uu['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-accent btn-sm w-100" type="submit">Save</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Activity</h2>
                <?php if (!$comments) : ?>
                    <div class="text-muted small">No activity yet.</div>
                <?php else : ?>
                    <div class="timeline small">
                        <?php foreach ($comments as $c) : ?>
                            <div class="border-bottom py-2">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong><?php echo e($c['full_name']); ?></strong>
                                    <span class="text-muted"><?php echo e(date('m/d/Y H:i', strtotime((string) $c['created_at']))); ?></span>
                                </div>
                                <div class="text-muted" style="font-size:0.75rem;"><?php echo e($c['activity_type']); ?></div>
                                <div style="white-space:pre-wrap;"><?php echo e($c['comment']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Add comment</h2>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="comment">
                    <textarea name="comment" class="form-control mb-2" rows="3" required placeholder="Write an update..."></textarea>
                    <button class="btn btn-accent btn-sm" type="submit">Post</button>
                </form>
            </div>

            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Attachments</h2>
                <ul class="list-unstyled small mb-3">
                    <?php foreach ($attachmentRows as $a) : ?>
                        <li class="mb-1"><i class="bi bi-paperclip"></i> <?php echo e($a['file_name']); ?> <span class="text-muted">(<?php echo (int) $a['file_size']; ?> bytes)</span></li>
                    <?php endforeach; ?>
                    <?php if (!$attachmentRows) : ?>
                        <li class="text-muted">No attachments.</li>
                    <?php endif; ?>
                </ul>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="attach">
                    <input type="file" name="file" class="form-control form-control-sm mb-2" required>
                    <button class="btn btn-outline-muted btn-sm" type="submit">Upload</button>
                </form>
            </div>

            <div class="card-surface p-3">
                <h2 class="h6 fw-bold mb-3">History</h2>
                <?php if (!$history) : ?>
                    <div class="text-muted small">No history entries.</div>
                <?php else : ?>
                    <?php foreach ($history as $h) : ?>
                        <div class="small border-bottom py-1">
                            <div class="fw-semibold"><?php echo e($h['field_changed']); ?></div>
                            <div class="text-muted"><?php echo e($h['full_name']); ?> · <?php echo e(date('m/d/Y H:i', strtotime((string) $h['created_at']))); ?></div>
                            <div><?php echo e((string) ($h['old_value'] ?? '')); ?> → <?php echo e((string) ($h['new_value'] ?? '')); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
