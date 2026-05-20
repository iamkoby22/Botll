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
        if (sbs_handle_ticket_detail_post($ticketId, $errors)) {
            redirect('ticket_detail.php?id=' . $ticketId);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'conversation' || $action === 'comment') {
            $body = trim((string) ($_POST['comment'] ?? ''));
            $msgType = $action === 'comment' ? 'conversation' : 'conversation';
            if ($body === '') {
                $errors[] = 'Message cannot be empty.';
            } elseif (!ticket_can_post_conversation($ticketId, $msgType, $ticket)) {
                $errors[] = 'You cannot add messages on this ticket at this stage.';
            } else {
                $commentId = ticket_log_comment($ticketId, $uid, $body, 'conversation');
                comment_save_mentions($commentId, $ticketId, $body, $uid);
                if (sbs_ticket_is_routed($ticket)) {
                    sbs_send_ticket_notifications(
                        $ticketId,
                        'comment_added',
                        'New message on ' . $ticket['ticket_number'],
                        trim($body) !== '' ? mb_substr(trim($body), 0, 200) : 'A new comment was posted.',
                        $uid
                    );
                } else {
                    $creatorId = (int) $ticket['created_by'];
                    $assignId = (int) ($ticket['assigned_to'] ?? 0);
                    $num = (string) $ticket['ticket_number'];
                    if ($uid === $creatorId && $assignId > 0 && $assignId !== $creatorId) {
                        notify_user($assignId, 'New message on ' . $num, 'The requester replied on an assigned ticket.', 'info', 'ticket_detail.php?id=' . $ticketId);
                    } elseif ($uid === $assignId && $creatorId !== $uid) {
                        notify_user($creatorId, 'Assignee replied on ' . $num, 'Your assigned user posted a message.', 'info', 'ticket_detail.php?id=' . $ticketId);
                    }
                }
                flash_set('success', 'Message posted.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'completion_remarks') {
            $body = trim((string) ($_POST['comment'] ?? ''));
            if ($body === '') {
                $errors[] = 'Completion remarks cannot be empty.';
            } elseif (!ticket_can_post_conversation($ticketId, 'completion_remarks', $ticket)) {
                $errors[] = 'You cannot add completion remarks on this ticket.';
            } else {
                ticket_log_comment($ticketId, $uid, $body, 'completion_remarks');
                flash_set('success', 'Completion remarks saved.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'admin_comment') {
            $body = trim((string) ($_POST['comment'] ?? ''));
            if ($body === '') {
                $errors[] = 'Admin note cannot be empty.';
            } elseif (!ticket_can_post_conversation($ticketId, 'admin_comment', $ticket)) {
                $errors[] = 'You cannot add admin notes on this ticket.';
            } else {
                ticket_log_comment($ticketId, $uid, $body, 'admin_comment');
                flash_set('success', 'Admin note posted.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'mark_done' || $action === 'pass_level') {
            $note = trim((string) ($_POST['completion_note'] ?? $_POST['pass_note'] ?? ''));
            $ctxBefore = ticket_assignment_actor_context($ticketId, $uid, $ticket);
            if (!ticket_assignment_level_done($ticketId, $uid, $note)) {
                $errors[] = ticket_assignment_last_error() !== ''
                    ? ticket_assignment_last_error()
                    : 'You cannot complete this assignment level right now.';
            } else {
                $hasAp = ticket_has_approval_chain($ticketId);
                $msg = (!empty($ctxBefore['can_mark_done']) || !empty($ctxBefore['is_final_level']))
                    ? ($hasAp
                        ? 'Work marked Done. Awaiting approval; comments remain open.'
                        : 'Work marked Done. Comments remain open.')
                    : 'Done — passed to the next assignment level.';
                flash_set('success', $msg);
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'reopen' && is_super_admin_role()) {
            if (!ticket_super_admin_reopen($ticketId, $uid)) {
                $errors[] = 'Could not reopen this ticket.';
            } else {
                flash_set('success', 'Ticket reopened for further work.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'status' && ticket_can_change_status($ticketId)) {
            $newStatusId = (int) ($_POST['status_id'] ?? 0);
            $st = $pdo->prepare('SELECT id, status_name FROM ticket_statuses WHERE id = ? LIMIT 1');
            $st->execute([$newStatusId]);
            $ns = $st->fetch();
            if ($ns) {
                $oldName = (string) $ticket['status_name'];
                $newName = (string) $ns['status_name'];
                $pdo->prepare('UPDATE tickets SET status_id = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatusId, $ticketId]);
                if ($newName === 'Completed') {
                    $pdo->prepare('UPDATE tickets SET date_completed = CURDATE() WHERE id = ?')->execute([$ticketId]);
                }
                if ($newName === 'Open' && $oldName === 'Closed') {
                    try {
                        $pdo->prepare(
                            'UPDATE tickets SET conversation_closed_at = NULL, work_done_at = NULL, work_done_by = NULL, completion_note = NULL WHERE id = ?'
                        )->execute([$ticketId]);
                    } catch (Throwable $e) {
                        /* migration columns optional */
                    }
                }
                ticket_log_history($ticketId, $uid, 'status', $oldName, $newName);
                ticket_log_comment($ticketId, $uid, 'Status changed to ' . $newName . ' by ' . (string) $u['full_name'], 'admin_comment');
                ticket_notify_status_outcome($ticketId, $newName);
                flash_set('success', 'Status updated.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'assign' && ticket_can_reassign($ticketId, $ticket)) {
            $assignee = (int) ($_POST['assigned_to'] ?? 0);
            if ($assignee > 0) {
                $oldName = (string) ($ticket['assignee_name'] ?? 'Unassigned');
                $nameSt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
                $nameSt->execute([$assignee]);
                $newName = (string) ($nameSt->fetchColumn() ?: 'User #' . $assignee);
                $pdo->prepare('UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?')->execute([$assignee, $ticketId]);
                $pdo->prepare(
                    'INSERT INTO ticket_assignees (ticket_id, user_id, approval_status, sort_order) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)'
                )->execute([$ticketId, $assignee, 'pending', 1]);
                ticket_log_history($ticketId, $uid, 'assigned_to', $oldName, $newName);
                notify_user(
                    $assignee,
                    'Ticket assigned',
                    'You were assigned to ticket ' . $ticket['ticket_number'],
                    'assignment',
                    'ticket_detail.php?id=' . $ticketId
                );
                $creatorId = (int) $ticket['created_by'];
                if ($creatorId > 0 && $creatorId !== $uid) {
                    notify_user(
                        $creatorId,
                        'Ticket ' . $ticket['ticket_number'] . ' — assignee changed',
                        (string) $u['full_name'] . ' reassigned this ticket to ' . $newName . '.',
                        'assignment',
                        'ticket_detail.php?id=' . $ticketId
                    );
                }
                flash_set('success', 'Assignee updated.');
                redirect('ticket_detail.php?id=' . $ticketId);
            }
        } elseif ($action === 'approve') {
            $ctx = ticket_approval_actor_context($ticketId, $uid);
            $apRow = $ctx['approval_row'] ?? null;
            $postedRow = (int) ($_POST['approval_row_id'] ?? 0);
            if (!ticket_work_is_complete_for_approval($ticketId) && ticket_has_approval_chain($ticketId)) {
                $errors[] = 'The ticket cannot be approved yet because assigned work is not fully completed.';
            } elseif ($ctx['can_decide'] && $apRow && $postedRow === (int) $apRow['id']) {
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

                $cancelId = status_id_by_name('Cancelled');

                $pc = $pdo->prepare('SELECT COUNT(*) FROM ticket_approvals WHERE ticket_id = ? AND approval_status = "pending"');
                $pc->execute([$ticketId]);
                $pendingCnt = (int) $pc->fetchColumn();

                if ($new === 'rejected' && $cancelId) {
                    $pdo->prepare('UPDATE tickets SET status_id = ?, updated_at = NOW() WHERE id = ?')->execute([$cancelId, $ticketId]);
                    ticket_log_history($ticketId, $uid, 'status', (string) $ticket['status_name'], 'Cancelled');
                } elseif ($new === 'approved' && $pendingCnt === 0) {
                    ticket_finalize_after_final_approval($ticketId, $uid, $ticket);
                } elseif ($new === 'approved' && $pendingCnt > 0) {
                    ticket_set_pending_approval_status($ticketId, $uid, $ticket);
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

                if ($new === 'approved' && $pendingCnt === 0) {
                    flash_set('success', 'Final approval recorded. Ticket is now ' . ticket_status_name_after_final_approval() . '.');
                } else {
                    flash_set('success', 'Approval updated.');
                }
                redirect('ticket_detail.php?id=' . $ticketId);
            }
            $errors[] = 'You cannot approve or reject this ticket with your current role.';
        } elseif ($action === 'attach') {
            if (!ticket_can_upload_attachment($ticketId, $ticket)) {
                $errors[] = 'You cannot upload files on this ticket at this stage.';
            } elseif (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $orig = (string) ($_FILES['file']['name'] ?? 'file');
                if (!ticket_attachment_allowed($orig)) {
                    $errors[] = 'File type not allowed. Use documents, images, audio, or video formats.';
                } else {
                $dir = UPLOAD_PATH . '/' . $ticketId;
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
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
                    if (sbs_ticket_is_routed($ticket)) {
                        sbs_send_ticket_notifications(
                            $ticketId,
                            'attachment_added',
                            'Attachment on ' . $ticket['ticket_number'],
                            'New file uploaded: ' . $orig,
                            $uid
                        );
                    } else {
                        $creatorId = (int) $ticket['created_by'];
                        $assignId = (int) ($ticket['assigned_to'] ?? 0);
                        if ($uid === $creatorId && $assignId > 0) {
                            notify_user($assignId, 'Attachment on ' . $ticket['ticket_number'], 'New file uploaded by requester.', 'info', 'ticket_detail.php?id=' . $ticketId);
                        } elseif ($uid === $assignId && $creatorId !== $uid) {
                            notify_user($creatorId, 'Attachment on ' . $ticket['ticket_number'], 'Assignee uploaded evidence.', 'info', 'ticket_detail.php?id=' . $ticketId);
                        }
                    }
                    flash_set('success', 'Attachment uploaded.');
                    redirect('ticket_detail.php?id=' . $ticketId);
                }
                }
            }
            if (!$errors) {
                $errors[] = 'Upload failed.';
            }
        }
    }
}

$ticket = ticket_fetch_by_id($ticketId);
$isSbsRouted = sbs_ticket_is_routed($ticket);
if ($isSbsRouted) {
    ticket_apply_sla_status($ticketId);
    $ticket = ticket_fetch_by_id($ticketId);
}

$assigneeRows = ticket_assignment_chain_rows($ticketId);

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

$templateFieldResponses = ticket_custom_field_values_load($ticketId);

$apCtx = ticket_approval_actor_context($ticketId, $uid);

$conversationClosed = ticket_conversation_is_closed($ticket);
$workDone = ticket_work_is_marked_done($ticket);
$hasApprovalChain = ticket_has_approval_chain($ticketId);
$awaitingFinalApproval = $workDone && $hasApprovalChain && !in_array(
    (string) ($ticket['status_name'] ?? ''),
    ['Completed', 'Cancelled', 'Rejected'],
    true
);
$assignCtx = ticket_assignment_actor_context($ticketId, $uid, $ticket);
$canMarkDone = $assignCtx['can_mark_done'];
$canPassLevel = $assignCtx['can_pass'];
$canLevelDone = $assignCtx['can_level_done'] ?? ($canPassLevel || $canMarkDone);
$assigneeWaitMsg = !empty($assignCtx['is_chain_member']) && empty($assignCtx['is_active_assignee']) && !$conversationClosed;
$canConverse = ticket_can_post_conversation($ticketId, 'conversation', $ticket);
$canUpload = ticket_can_upload_attachment($ticketId, $ticket);
$canReopen = is_super_admin_role() && $conversationClosed;
$canCompletionRemarks = ticket_can_post_conversation($ticketId, 'completion_remarks', $ticket);
$canAdminComment = ticket_can_post_conversation($ticketId, 'admin_comment', $ticket);
$isCreator = ticket_user_is_creator($ticketId, $uid);
$isAssignee = ticket_user_is_assigned_worker($ticketId, $uid);
$mentionOnly = ticket_user_is_mention_only($ticketId, $u);
$approvalLocked = !empty($apCtx['approval_locked']);
$workCompleteForApproval = !empty($apCtx['work_complete_for_approval']);

$sbsCanDone = false;
$sbsCanComplete = false;
$sbsCanArchive = false;
if ($isSbsRouted) {
    $conversationClosed = sbs_work_is_closed($ticket) || (string) ($ticket['status_name'] ?? '') === 'Completed';
    $canConverse = sbs_can_post_conversation($ticket);
    $canUpload = $canConverse;
    $canMarkDone = false;
    $canPassLevel = false;
    $canLevelDone = false;
    $sbsCanDone = sbs_can_mark_done($ticket);
    $sbsCanComplete = sbs_can_complete($ticket);
    $sbsCanArchive = ticket_can_archive($ticket);
}

$showArchiveBtn = ticket_can_archive($ticket) && empty($ticket['archived_at']);

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

    <?php if ($mentionOnly) : ?>
        <div class="alert alert-info border small mb-3">
            You were mentioned on this ticket. You can view and comment, but you cannot mark work Done or approve unless you are assigned or listed as an approver.
        </div>
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
                <?php if (!empty($ticket['archived_at'])) : ?>
                    <span class="badge bg-secondary">Archived</span>
                <?php endif; ?>
            </div>
            <div class="subtitle"><?php echo e($ticket['subject']); ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-muted btn-sm" href="all_tickets.php"><i class="bi bi-arrow-left"></i> All Tickets</a>
            <?php if ($showArchiveBtn) : ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Archive this ticket?');">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="sbs_archive">
                    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-archive"></i> Archive</button>
                </form>
            <?php endif; ?>
            <?php if ($canReopen) : ?>
                <form method="post" class="d-inline" onsubmit="return confirm('This will reopen the conversation and undo the Done/Closed state. Continue?');">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="reopen">
                    <button class="btn btn-outline-warning btn-sm" type="submit">Reopen Ticket</button>
                </form>
            <?php endif; ?>
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
                    <div class="col-md-6"><span class="text-muted">Department</span><div class="fw-semibold"><?php
                        $deptLabel = (string) $ticket['department_name'];
                        if (!empty($ticket['department_number']) || !empty($ticket['organization_code'])) {
                            $deptLabel = department_display_label([
                                'department_name' => $ticket['department_name'],
                                'department_number' => $ticket['department_number'] ?? '',
                                'organization_code' => $ticket['organization_code'] ?? '',
                            ]);
                        }
                        echo e($deptLabel);
                        ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Account</span><div class="fw-semibold"><?php echo e((string) ($ticket['account_number'] ?? '—')); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Created by</span><div class="fw-semibold"><?php echo e($ticket['created_name']); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Primary assignee</span><div class="fw-semibold"><?php echo e((string) ($ticket['assignee_name'] ?? '—')); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Approver</span><div class="fw-semibold"><?php echo e((string) ($ticket['approver_name'] ?? '—')); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Created</span><div class="fw-semibold"><?php echo e(date('m/d/Y H:i', strtotime((string) $ticket['created_at']))); ?></div></div>
                    <div class="col-md-6"><span class="text-muted">Completed</span><div class="fw-semibold"><?php echo !empty($ticket['date_completed']) ? e(date('m/d/Y', strtotime((string) $ticket['date_completed']))) : '—'; ?></div></div>
                </div>
                <?php
                $showDescription = trim((string) ($ticket['description'] ?? '')) !== ''
                    && !ticket_description_is_auto_generated($ticket);
                if ($showDescription) : ?>
                <hr>
                <div class="fw-semibold mb-2">Description</div>
                <div class="small" style="white-space:pre-wrap;"><?php echo e($ticket['description']); ?></div>
                <?php endif; ?>
            </div>

            <?php
            $requestPath = ticket_request_path_display($ticket);
            if ($requestPath) : ?>
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Business Request Information</h2>
                <dl class="row g-2 mb-0 small">
                    <dt class="col-sm-4 text-muted">Requester Name</dt>
                    <dd class="col-sm-8 fw-semibold"><?php echo e($requestPath['requester_name'] !== '' ? $requestPath['requester_name'] : (string) $ticket['created_name']); ?></dd>
                    <dt class="col-sm-4 text-muted">Requester Email</dt>
                    <dd class="col-sm-8 fw-semibold"><?php echo e($requestPath['requester_email'] !== '' ? $requestPath['requester_email'] : (string) ($ticket['created_email'] ?? '')); ?></dd>
                    <dt class="col-sm-4 text-muted">Department</dt>
                    <dd class="col-sm-8 fw-semibold"><?php
                    echo e(department_display_label([
                        'department_name' => $ticket['department_name'],
                        'department_number' => $ticket['department_number'] ?? '',
                        'organization_code' => $ticket['organization_code'] ?? '',
                    ]));
                    ?></dd>
                    <dt class="col-sm-4 text-muted">Request Type</dt>
                    <dd class="col-sm-8 fw-semibold"><?php echo e($requestPath['request_type']); ?></dd>
                    <?php if ($requestPath['step1'] !== '') : ?>
                    <dt class="col-sm-4 text-muted">Step 1</dt>
                    <dd class="col-sm-8 fw-semibold"><?php echo e($requestPath['step1']); ?></dd>
                    <?php endif; ?>
                    <?php if ($requestPath['step2'] !== '') : ?>
                    <dt class="col-sm-4 text-muted">Step 2</dt>
                    <dd class="col-sm-8 fw-semibold"><?php echo e($requestPath['step2']); ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
            <?php endif; ?>

            <?php if ($templateFieldResponses) : ?>
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Request Responses</h2>
                <dl class="row g-2 mb-0 small">
                    <?php foreach ($templateFieldResponses as $fr) :
                        $ftype = (string) ($fr['field_type'] ?? 'text');
                        $fval = (string) ($fr['field_value'] ?? '');
                        ?>
                        <dt class="col-sm-4 text-muted"><?php echo e((string) ($fr['field_label'] ?? '')); ?></dt>
                        <dd class="col-sm-8 fw-semibold mb-0">
                            <?php if ($ftype === 'paragraph' || $ftype === 'textarea') : ?>
                                <span style="white-space:pre-wrap;"><?php echo e($fval); ?></span>
                            <?php else : ?>
                                <?php echo e($fval !== '' ? $fval : '—'); ?>
                            <?php endif; ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>
            </div>
            <?php endif; ?>

            <?php require __DIR__ . '/includes/ticket_detail_sbs.php'; ?>

            <?php if (!$isSbsRouted) : ?>
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Work Assignment Routing</h2>
                <p class="small text-muted mb-2">Assigned users work the ticket in level order. This is separate from formal approval below.</p>
                <?php if (!empty($assignCtx['active_row'])) : ?>
                    <div class="alert alert-info small py-2 mb-2">
                        Current active: <strong><?php echo e($assignCtx['active_row']['full_name']); ?></strong>
                        (Level <?php echo ticket_assignment_row_level($assignCtx['active_row']); ?>)
                    </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm table-modern mb-0">
                        <thead><tr><th>Level</th><th>Assignee</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($assigneeRows as $i => $ar) :
                            $ast = (string) ($ar['assignment_status'] ?? 'pending');
                            $lvl = ticket_assignment_row_level($ar, $i + 1);
                            ?>
                            <tr class="<?php echo $ast === 'active' ? 'table-primary' : ''; ?>">
                                <td>Level <?php echo $lvl; ?></td>
                                <td><?php echo e($ar['full_name']); ?></td>
                                <td><span class="badge text-bg-<?php echo e(assignment_status_badge($ast)); ?>"><?php echo e(assignment_status_label($ast)); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$assigneeRows) : ?>
                            <tr><td colspan="3" class="text-muted">No assignment chain.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (ticket_can_reassign($ticketId, $ticket)) : ?>
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
                <h2 class="h6 fw-bold mb-3">Approval Routing</h2>
                <p class="small text-muted mb-2">Formal approvers act in level order. Only the current pending level may approve or reject.</p>
                <div class="small text-muted mb-2">
                    <strong>Current approval status:</strong>
                    <?php
                    $firstPending = ticket_first_pending_approval_row($ticketId);
                    if ($firstPending) {
                        echo 'Waiting on: ' . e((string) $firstPending['approver_name']) . ' (level ' . (int) $firstPending['approval_level'] . ')';
                    } else {
                        $anyPending = false;
                        foreach ($approvalRows as $ar) {
                            if (($ar['approval_status'] ?? '') === 'pending') {
                                $anyPending = true;
                                break;
                            }
                        }
                        echo $anyPending ? 'Approval in progress.' : 'No pending approvers in the chain.';
                    }
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

                <?php if ($approvalLocked) : ?>
                    <div class="alert alert-warning small py-2 mt-3 mb-0">Approval is locked until all assigned work levels are marked Done.</div>
                <?php endif; ?>

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
            </div>
            <?php endif; ?>

            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Conversation / Work Log</h2>
                <p class="small text-muted">Creator and assignee collaborate while the ticket is active. All messages and files remain for audit.</p>
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
                                <div class="text-muted" style="font-size:0.75rem;"><?php echo e(ticket_activity_type_label((string) ($c['activity_type'] ?? 'comment'))); ?></div>
                                <div style="white-space:pre-wrap;"><?php echo comment_format_display((string) $c['comment']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="small fw-semibold mt-3 mb-2">Files on ticket</div>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($attachmentRows as $a) :
                        $fileUrl = ticket_attachment_web_url((string) $a['file_path']);
                        ?>
                        <li class="mb-1">
                            <i class="bi bi-paperclip"></i>
                            <a href="<?php echo e($fileUrl); ?>" target="_blank" rel="noopener"><?php echo e($a['file_name']); ?></a>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$attachmentRows) : ?>
                        <li class="text-muted">No files yet.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if ($conversationClosed) : ?>
                <div class="alert alert-light border small mb-3">This ticket is finalized. The conversation is closed.</div>
            <?php elseif ($awaitingFinalApproval) : ?>
                <div class="alert alert-info border small mb-3">
                    Assigned work is complete. Formal approval is required before this ticket is finalized.
                    Comments remain open until final approval.
                </div>
            <?php elseif ($workDone && !$hasApprovalChain) : ?>
                <div class="alert alert-info border small mb-3">
                    Work has been marked Done. Comments remain open.
                </div>
            <?php elseif ($assigneeWaitMsg) : ?>
                <div class="alert alert-info border small mb-3">
                    You are assigned to this ticket. You may comment and upload files now.
                    The <strong>Done</strong> action will become available when your assignment level is active.
                </div>
            <?php endif; ?>

            <?php if ($canConverse) : ?>
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Send message</h2>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="conversation">
                    <textarea name="comment" class="form-control mention-input mb-2" rows="3" required placeholder="Write a message..."></textarea>
                    <p class="small text-muted mb-2">Type @ to mention a user. Mentioned users will be notified and can view/comment on this ticket, but they will not receive approval or assignment permissions unless added to the workflow.</p>
                    <button class="btn btn-accent btn-sm" type="submit">Post message</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($canCompletionRemarks) : ?>
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Completion remarks</h2>
                <p class="small text-muted">Assigned user final remarks after work is Done.</p>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="completion_remarks">
                    <textarea name="comment" class="form-control mb-2" rows="3" required placeholder="Final notes for audit..."></textarea>
                    <button class="btn btn-outline-muted btn-sm" type="submit">Save remarks</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($canAdminComment) : ?>
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Admin / audit note</h2>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="admin_comment">
                    <textarea name="comment" class="form-control mb-2" rows="3" required placeholder="Internal audit note..."></textarea>
                    <button class="btn btn-outline-muted btn-sm" type="submit">Post admin note</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($canUpload) : ?>
            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Upload evidence</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="attach">
                    <input type="file" name="file" class="form-control form-control-sm mb-2" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.png,.jpg,.jpeg,.gif,.webp,.mp3,.wav,.mp4,.mov,.avi,.zip" required>
                    <button class="btn btn-outline-muted btn-sm" type="submit">Upload file</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($sbsCanDone) : ?>
            <div class="card-surface p-3 mb-3 border border-accent">
                <h2 class="h6 fw-bold mb-2">Done</h2>
                <p class="small text-muted">Marks work complete and sets ticket to Closed. A Pillar Admin must Complete the ticket for final closure.</p>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="sbs_mark_done">
                    <button class="btn btn-accent btn-sm" type="submit" onclick="return confirm('Mark work as Done? Conversation will close.');">Done</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($sbsCanComplete) : ?>
            <div class="card-surface p-3 mb-3 border border-success">
                <h2 class="h6 fw-bold mb-2">Complete</h2>
                <p class="small text-muted">Final administrative completion.</p>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="sbs_complete">
                    <button class="btn btn-success btn-sm" type="submit" onclick="return confirm('Mark this ticket as Completed?');">Complete</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($sbsCanArchive) : ?>
            <div class="card-surface p-3 mb-3">
                <form method="post" onsubmit="return confirm('Archive this ticket?');">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="sbs_archive">
                    <button class="btn btn-outline-muted btn-sm" type="submit">Archive</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($canLevelDone) : ?>
            <div class="card-surface p-3 mb-3 border border-accent">
                <h2 class="h6 fw-bold mb-2">Done</h2>
                <?php if ($canPassLevel) : ?>
                    <p class="small text-muted">Complete your level and pass work to the next assignee. The ticket stays open.</p>
                <?php else : ?>
                    <p class="small text-muted">Final assignment level — closes the conversation and moves the ticket to Closed for admin audit.</p>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="mark_done">
                    <textarea name="completion_note" class="form-control form-control-sm mb-2" rows="2" placeholder="Optional note"></textarea>
                    <button class="btn btn-accent btn-sm" type="submit" onclick="return confirm(<?php echo $canPassLevel ? "'Mark this level Done and pass to the next assignee?'" : "'Mark work as Done? The creator will no longer be able to message.'"; ?>);">Done</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="card-surface p-3 mb-3">
                <h2 class="h6 fw-bold mb-3">Attachments (audit)</h2>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($attachmentRows as $a) :
                        $fileUrl = ticket_attachment_web_url((string) $a['file_path']);
                        ?>
                        <li class="mb-1">
                            <i class="bi bi-paperclip"></i>
                            <a href="<?php echo e($fileUrl); ?>" target="_blank" rel="noopener"><?php echo e($a['file_name']); ?></a>
                            <span class="text-muted">(<?php echo (int) $a['file_size']; ?> bytes)</span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$attachmentRows) : ?>
                        <li class="text-muted">No attachments.</li>
                    <?php endif; ?>
                </ul>
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

<script src="<?php echo e((defined('APP_WEB_BASE') && APP_WEB_BASE !== '') ? rtrim((string) APP_WEB_BASE, '/') . '/' : ''); ?>assets/js/mentions.js"></script>
<?php require __DIR__ . '/includes/shell_end.php'; ?>


