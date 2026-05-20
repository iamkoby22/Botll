<?php
declare(strict_types=1);

/**
 * @param array<string,mixed>|null $u
 */
function user_is_privileged(?array $u): bool
{
    if (!$u) {
        return false;
    }
    return in_array((string) $u['role_key'], ['super_admin', 'admin'], true);
}

/**
 * @param array<string,mixed>|null $u
 */
function user_is_manager(?array $u): bool
{
    if (!$u) {
        return false;
    }
    return in_array((string) $u['role_key'], ['super_admin', 'admin', 'director', 'hod'], true);
}

/**
 * @return array<string,mixed>|null
 */
function ticket_fetch_by_id(int $ticketId): ?array
{
    $sql = 'SELECT t.*, s.status_name, p.priority_name, p.priority_level, c.category_name,
                d.department_name, d.department_number, d.organization_code,
                cu.full_name AS created_name, cu.email AS created_email,
                au.full_name AS assignee_name, ap.full_name AS approver_name
         FROM tickets t
         JOIN ticket_statuses s ON s.id = t.status_id
         JOIN ticket_priorities p ON p.id = t.priority_id
         JOIN ticket_categories c ON c.id = t.category_id
         JOIN departments d ON d.id = t.department_id
         JOIN users cu ON cu.id = t.created_by
         LEFT JOIN users au ON au.id = t.assigned_to
         LEFT JOIN users ap ON ap.id = t.approved_by
         WHERE t.id = ? LIMIT 1';
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        $stmt = db()->prepare(
            'SELECT t.*, s.status_name, p.priority_name, p.priority_level, c.category_name, d.department_name,
                    cu.full_name AS created_name, cu.email AS created_email,
                    au.full_name AS assignee_name, ap.full_name AS approver_name
             FROM tickets t
             JOIN ticket_statuses s ON s.id = t.status_id
             JOIN ticket_priorities p ON p.id = t.priority_id
             JOIN ticket_categories c ON c.id = t.category_id
             JOIN departments d ON d.id = t.department_id
             JOIN users cu ON cu.id = t.created_by
             LEFT JOIN users au ON au.id = t.assigned_to
             LEFT JOIN users ap ON ap.id = t.approved_by
             WHERE t.id = ? LIMIT 1'
        );
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

function ticket_user_involved(int $userId, int $ticketId): bool
{
    $pdo = db();
    $st = $pdo->prepare('SELECT created_by, assigned_to FROM tickets WHERE id = ? LIMIT 1');
    $st->execute([$ticketId]);
    $t = $st->fetch();
    if (!$t) {
        return false;
    }
    if ((int) $t['created_by'] === $userId || (int) ($t['assigned_to'] ?? 0) === $userId) {
        return true;
    }
    $q1 = $pdo->prepare('SELECT 1 FROM ticket_assignees WHERE ticket_id = ? AND user_id = ? LIMIT 1');
    $q1->execute([$ticketId, $userId]);
    if ($q1->fetchColumn()) {
        return true;
    }
    $q2 = $pdo->prepare('SELECT 1 FROM ticket_approvals WHERE ticket_id = ? AND approver_id = ? LIMIT 1');
    $q2->execute([$ticketId, $userId]);
    return (bool) $q2->fetchColumn();
}

function ticket_can_view(int $ticketId): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    if (function_exists('sbs_workflow_enabled') && sbs_workflow_enabled()) {
        $st = db()->prepare('SELECT 1 FROM tickets t WHERE t.id = ? AND ' . tickets_scope_sql('t') . ' LIMIT 1');
        $st->execute([$ticketId]);
        if ($st->fetchColumn()) {
            return true;
        }
        if (!empty($ticket['archived_at'])) {
            return sbs_user_has_archive_access($ticket, $u);
        }
        return false;
    }
    if (user_is_privileged($u)) {
        return true;
    }
    $uid = (int) $u['id'];
    if (ticket_user_involved($uid, $ticketId)) {
        return true;
    }
    if (ticket_user_has_mention_access($ticketId, $uid)) {
        return true;
    }
    $role = (string) $u['role_key'];
    if (in_array($role, ['director', 'hod'], true)) {
        $uid = (int) $u['id'];
        $st = db()->prepare('SELECT department_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $deptId = (int) ($st->fetch()['department_id'] ?? 0);
        return $deptId > 0 && (int) $ticket['department_id'] === $deptId;
    }
    return false;
}

function ticket_can_change_status(int $ticketId): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    $role = (string) $u['role_key'];
    if ($role === 'super_admin' || $role === 'admin') {
        return true;
    }
    if ($role !== 'hod') {
        return false;
    }
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    $deptId = (int) ($u['department_id'] ?? 0);
    return $deptId > 0 && (int) $ticket['department_id'] === $deptId;
}

function ticket_can_assign(int $ticketId): bool
{
    return ticket_can_reassign($ticketId);
}

/**
 * Reassign primary assignee / assignment chain — creator, HOD (same dept), admin, super admin only.
 *
 * @param array<string,mixed>|null $ticket
 * @param array<string,mixed>|null $user
 */
function ticket_can_reassign(int $ticketId, ?array $ticket = null, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    $ticket = $ticket ?? ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    $status = (string) ($ticket['status_name'] ?? '');
    if (in_array($status, ['Completed', 'Rejected'], true)) {
        return false;
    }
    $role = (string) $user['role_key'];
    $uid = (int) $user['id'];
    if ($role === 'super_admin' || $role === 'admin') {
        return true;
    }
    if (ticket_user_is_creator($ticketId, $uid)) {
        return true;
    }
    if ($role === 'hod') {
        $deptId = (int) ($user['department_id'] ?? 0);

        return $deptId > 0 && (int) $ticket['department_id'] === $deptId;
    }

    return false;
}

/**
 * @return array{id:int,approval_status:string}|null
 */
function ticket_pending_approval_for_user(int $ticketId, int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, approval_status FROM ticket_approvals WHERE ticket_id = ? AND approver_id = ? AND approval_status = "pending" LIMIT 1'
    );
    $stmt->execute([$ticketId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ticket_user_is_assigned_worker(int $ticketId, int $userId): bool
{
    $pdo = db();
    $st = $pdo->prepare('SELECT assigned_to FROM tickets WHERE id = ? LIMIT 1');
    $st->execute([$ticketId]);
    $t = $st->fetch();
    if (!$t) {
        return false;
    }
    if ((int) ($t['assigned_to'] ?? 0) === $userId) {
        return true;
    }
    $q = $pdo->prepare('SELECT 1 FROM ticket_assignees WHERE ticket_id = ? AND user_id = ? LIMIT 1');
    $q->execute([$ticketId, $userId]);
    return (bool) $q->fetchColumn();
}

/**
 * First pending approval row in chain order.
 *
 * @return array<string,mixed>|null
 */
function ticket_first_pending_approval_row(int $ticketId): ?array
{
    $stmt = db()->prepare(
        'SELECT ta.*, u.full_name AS approver_name
         FROM ticket_approvals ta
         JOIN users u ON u.id = ta.approver_id
         WHERE ta.ticket_id = ? AND ta.approval_status = "pending"
         ORDER BY ta.approval_level ASC, ta.id ASC
         LIMIT 1'
    );
    $stmt->execute([$ticketId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Whether current actor may approve/reject a pending step (explicit approver or role override).
 *
 * @return array{
 *   can_decide: bool,
 *   approval_row: array<string,mixed>|null,
 *   mode: string,
 *   waiting_label: string,
 *   assignee_only: bool,
 *   info_message: string,
 *   approval_locked: bool,
 *   work_complete_for_approval: bool,
 *   mention_only: bool
 * }
 */
function ticket_approval_actor_context(int $ticketId, int $userId): array
{
    $empty = static fn (array $extra = []): array => array_merge([
        'can_decide' => false,
        'approval_row' => null,
        'mode' => 'none',
        'waiting_label' => '',
        'assignee_only' => false,
        'info_message' => '',
        'approval_locked' => false,
        'work_complete_for_approval' => true,
        'mention_only' => false,
    ], $extra);

    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return $empty();
    }

    $workComplete = ticket_work_is_complete_for_approval($ticketId);
    $hasApproval = ticket_has_approval_chain($ticketId);
    $approvalLocked = $hasApproval && !$workComplete;
    $lockMsg = 'Approval is locked until all assigned work levels are marked Done.';
    $mentionOnly = ticket_user_is_mention_only($ticketId);
    $mentionMsg = $mentionOnly
        ? 'You were mentioned on this ticket. You can view and comment, but you cannot mark work Done or approve unless you are assigned or listed as an approver.'
        : '';

    $first = ticket_first_pending_approval_row($ticketId);
    $waiting = $first ? (string) $first['approver_name'] . ' (level ' . (int) $first['approval_level'] . ')' : '—';

    $explicit = ticket_pending_approval_for_user($ticketId, $userId);
    $isCurrentApprover = $explicit && $first
        && (int) ($first['approver_id'] ?? 0) === $userId
        && (int) ($first['id'] ?? 0) === (int) $explicit['id'];

    if ($isCurrentApprover) {
        if ($approvalLocked) {
            return $empty([
                'waiting_label' => $waiting,
                'approval_locked' => true,
                'work_complete_for_approval' => false,
                'mention_only' => $mentionOnly,
                'info_message' => $lockMsg . ($mentionMsg !== '' ? ' ' . $mentionMsg : ''),
            ]);
        }

        return $empty([
            'can_decide' => true,
            'approval_row' => $first,
            'mode' => 'explicit',
            'waiting_label' => $waiting,
            'work_complete_for_approval' => true,
            'mention_only' => $mentionOnly,
            'info_message' => $mentionMsg,
        ]);
    }

    if (!$first) {
        $assignedOnly = ticket_user_is_assigned_worker($ticketId, $userId);
        return $empty([
            'waiting_label' => $waiting,
            'assignee_only' => $assignedOnly,
            'mention_only' => $mentionOnly,
            'work_complete_for_approval' => $workComplete,
            'info_message' => $mentionMsg !== '' ? $mentionMsg : ($assignedOnly
                ? 'You are assigned to work this ticket. Use Conversation to message the creator, upload evidence, then Mark as Done when finished.'
                : ''),
        ]);
    }

    $assignedOnly = ticket_user_is_assigned_worker($ticketId, $userId);
    $pendingApprover = (int) ($first['approver_id'] ?? 0) === $userId;

    $info = $mentionMsg;
    if ($approvalLocked && ($pendingApprover || $assignedOnly)) {
        $info = trim($lockMsg . ($info !== '' ? ' ' . $info : ''));
    } elseif ($assignedOnly) {
        $info = $info !== '' ? $info : 'You are assigned to work this ticket. Use Conversation and Mark as Done — you are not the current approver.';
    }

    return $empty([
        'waiting_label' => $waiting,
        'assignee_only' => $assignedOnly,
        'approval_locked' => $approvalLocked,
        'work_complete_for_approval' => $workComplete,
        'mention_only' => $mentionOnly,
        'info_message' => $info,
    ]);
}

function status_id_by_name(string $name, bool $allowCreate = true): ?int
{
    static $cache = [];
    if (isset($cache[$name]) && $cache[$name] !== null) {
        return $cache[$name];
    }
    $st = db()->prepare('SELECT id FROM ticket_statuses WHERE status_name = ? LIMIT 1');
    $st->execute([$name]);
    $row = $st->fetch();
    if ($row) {
        $cache[$name] = (int) $row['id'];
        return $cache[$name];
    }
    if ($allowCreate && $name === 'Closed') {
        try {
            db()->prepare('INSERT IGNORE INTO ticket_statuses (status_name) VALUES (?)')->execute(['Closed']);
            $st->execute([$name]);
            $row = $st->fetch();
            if ($row) {
                $cache[$name] = (int) $row['id'];
                error_log('status_id_by_name: created missing Closed status id=' . $cache[$name]);
                return $cache[$name];
            }
        } catch (Throwable $e) {
            error_log('status_id_by_name: could not create Closed status: ' . $e->getMessage());
        }
    }
    error_log('status_id_by_name: status not found: ' . $name);
    return null;
}

/**
 * @param array<string,mixed> $ticket
 */
function ticket_conversation_is_closed(array $ticket): bool
{
    if (!empty($ticket['conversation_closed_at'])) {
        return true;
    }
    $status = (string) ($ticket['status_name'] ?? '');
    return in_array($status, ['Completed', 'Cancelled', 'Rejected'], true);
}

function ticket_user_is_approver(int $ticketId, int $userId): bool
{
    $st = db()->prepare('SELECT 1 FROM ticket_approvals WHERE ticket_id = ? AND approver_id = ? LIMIT 1');
    $st->execute([$ticketId, $userId]);
    return (bool) $st->fetchColumn();
}

/**
 * @param array<string,mixed> $ticket
 */
function ticket_work_is_marked_done(array $ticket): bool
{
    return !empty($ticket['work_done_at']);
}

/**
 * Assigned work must be finished before formal approval can proceed.
 */
function ticket_work_is_complete_for_approval(int $ticketId): bool
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }

    $rows = ticket_assignment_fetch_chain_rows($ticketId);
    $hasAssignment = $rows !== [] || (int) ($ticket['assigned_to'] ?? 0) > 0;

    if (!$hasAssignment) {
        return true;
    }

    if (!ticket_work_is_marked_done($ticket)) {
        return false;
    }

    foreach ($rows as $row) {
        $st = (string) ($row['assignment_status'] ?? 'pending');
        if ($st === 'active') {
            return false;
        }
        if (!ticket_assignment_row_is_completed($st)) {
            return false;
        }
    }

    return true;
}

function ticket_has_approval_chain(int $ticketId): bool
{
    $st = db()->prepare('SELECT 1 FROM ticket_approvals WHERE ticket_id = ? LIMIT 1');
    $st->execute([$ticketId]);
    return (bool) $st->fetchColumn();
}

/**
 * Status name used when the full approval chain is approved (prefer Completed).
 */
function ticket_status_name_after_final_approval(): string
{
    if (status_id_by_name('Completed', false)) {
        return 'Completed';
    }
    if (status_id_by_name('Closed', false)) {
        return 'Closed';
    }
    return 'Completed';
}

/**
 * After the last approver approves, mark the ticket finished for reporting/dashboards.
 *
 * @param array<string,mixed>|null $ticket
 */
function ticket_finalize_after_final_approval(int $ticketId, int $approverUserId, ?array $ticket = null): bool
{
    $ticket = $ticket ?? ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    $statusName = ticket_status_name_after_final_approval();
    $statusId = status_id_by_name($statusName);
    if (!$statusId) {
        error_log('ticket_finalize_after_final_approval: missing status ' . $statusName);
        return false;
    }

    $oldStatus = (string) ($ticket['status_name'] ?? '');
    $pdo = db();
    try {
        if ($statusName === 'Completed') {
            $pdo->prepare(
                'UPDATE tickets SET status_id = ?, approved_by = ?, date_completed = CURDATE(),
                 conversation_closed_at = NOW(), updated_at = NOW() WHERE id = ?'
            )->execute([$statusId, $approverUserId, $ticketId]);
        } else {
            $pdo->prepare(
                'UPDATE tickets SET status_id = ?, approved_by = ?, conversation_closed_at = NOW(), updated_at = NOW() WHERE id = ?'
            )->execute([$statusId, $approverUserId, $ticketId]);
        }
    } catch (Throwable $e) {
        error_log('ticket_finalize_after_final_approval: ' . $e->getMessage());
        try {
            $pdo->prepare(
                'UPDATE tickets SET status_id = ?, approved_by = ?, date_completed = CURDATE(), updated_at = NOW() WHERE id = ?'
            )->execute([$statusId, $approverUserId, $ticketId]);
        } catch (Throwable $e2) {
            $pdo->prepare('UPDATE tickets SET status_id = ?, approved_by = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$statusId, $approverUserId, $ticketId]);
        }
    }

    ticket_log_history($ticketId, $approverUserId, 'status', $oldStatus, $statusName);
    ticket_log_history($ticketId, $approverUserId, 'approval_complete', 'pending', 'Final approval completed; ticket closed.');
    try {
        ticket_log_comment(
            $ticketId,
            $approverUserId,
            'Final approval completed. Ticket is now ' . $statusName . '.',
            'approval'
        );
    } catch (Throwable $e) {
        /* optional */
    }

    $num = (string) ($ticket['ticket_number'] ?? '');
    notify_user(
        (int) $ticket['created_by'],
        'Request approved — ' . $num,
        'All required approvals are complete. This ticket is now ' . $statusName . '.',
        'success',
        'ticket_detail.php?id=' . $ticketId
    );

    return true;
}

/**
 * While approvals remain pending, keep ticket in approval stage when that status exists.
 */
function ticket_set_pending_approval_status(int $ticketId, int $actorUserId, ?array $ticket = null): void
{
    $pendingId = status_id_by_name('Pending Approval', false);
    if (!$pendingId) {
        return;
    }
    $ticket = $ticket ?? ticket_fetch_by_id($ticketId);
    if (!$ticket || (string) ($ticket['status_name'] ?? '') === 'Pending Approval') {
        return;
    }
    if (in_array((string) ($ticket['status_name'] ?? ''), ['Completed', 'Closed', 'Cancelled'], true)) {
        return;
    }
    db()->prepare('UPDATE tickets SET status_id = ?, updated_at = NOW() WHERE id = ?')->execute([$pendingId, $ticketId]);
    ticket_log_history($ticketId, $actorUserId, 'status', (string) $ticket['status_name'], 'Pending Approval');
}

function ticket_user_is_creator(int $ticketId, int $userId): bool
{
    $st = db()->prepare('SELECT created_by FROM tickets WHERE id = ? LIMIT 1');
    $st->execute([$ticketId]);
    $row = $st->fetch();
    return $row && (int) $row['created_by'] === $userId;
}

function ticket_actor_is_status_manager(?array $u = null): bool
{
    $u = $u ?? current_user();
    if (!$u) {
        return false;
    }
    return in_array((string) $u['role_key'], ['super_admin', 'admin', 'hod'], true);
}

function ticket_actor_is_admin_auditor(?array $u = null): bool
{
    $u = $u ?? current_user();
    if (!$u) {
        return false;
    }
    return in_array((string) $u['role_key'], ['super_admin', 'admin', 'hod'], true);
}

function ticket_activity_type_label(string $type): string
{
    return match ($type) {
        'conversation' => 'Message',
        'completion_remarks' => 'Completion remarks',
        'admin_comment' => 'Admin note',
        'approval' => 'Approval',
        'attachment' => 'Attachment',
        'system' => 'System',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
}

/**
 * @param array<string,mixed> $ticket
 */
function ticket_can_mark_work_done(int $ticketId, ?array $ticket = null): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    $ticket = $ticket ?? ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    if (ticket_work_is_marked_done($ticket) || ticket_conversation_is_closed($ticket)) {
        return false;
    }
    if (!in_array((string) $u['role_key'], ['user', 'hod', 'director'], true)) {
        return false;
    }
    $ctx = ticket_assignment_actor_context($ticketId, (int) $u['id'], $ticket);
    if (!empty($ctx['can_mark_done'])) {
        return true;
    }
    if (!empty($ctx['is_active_assignee']) && ticket_assignment_has_chain($ticketId)) {
        $rows = ticket_assignment_fetch_chain_rows($ticketId);
        $active = $ctx['active_row'] ?? ticket_assignment_active_row($ticketId);
        if ($active) {
            return ticket_assignment_is_final_row($active, $rows);
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $ticket
 */
function ticket_is_dynamic_request(array $ticket): bool
{
    if (!empty($ticket['request_logic_id'])) {
        return true;
    }
    $rt = trim((string) ($ticket['request_type'] ?? ''));
    return $rt !== '';
}

/**
 * Hide auto-generated intake summary stored in tickets.description for request-logic tickets.
 *
 * @param array<string,mixed> $ticket
 */
function ticket_description_is_auto_generated(array $ticket): bool
{
    if (!ticket_is_dynamic_request($ticket)) {
        return false;
    }
    $desc = trim((string) ($ticket['description'] ?? ''));
    if ($desc === '') {
        return true;
    }
    return str_starts_with($desc, 'Business Services Request')
        || str_contains($desc, 'Request Type:')
        || str_contains($desc, 'Requester:');
}

/**
 * @param array<string,mixed> $ticket
 */
function ticket_can_post_conversation(int $ticketId, string $activityType, ?array $ticket = null): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    $ticket = $ticket ?? ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    if (!ticket_can_view($ticketId)) {
        return false;
    }
    if (function_exists('sbs_ticket_is_routed') && sbs_ticket_is_routed($ticket)) {
        if ($activityType === 'admin_comment') {
            return sbs_is_super_admin($u);
        }
        return sbs_can_post_conversation($ticket, $u);
    }
    $uid = (int) $u['id'];
    $closed = ticket_conversation_is_closed($ticket);
    $isCreator = ticket_user_is_creator($ticketId, $uid);
    $isAssignee = ticket_user_is_assigned_worker($ticketId, $uid);
    $isAuditor = ticket_actor_is_admin_auditor($u);

    if ($activityType === 'admin_comment') {
        return $isAuditor;
    }
    if ($activityType === 'completion_remarks') {
        return $closed && $isAssignee
            && in_array((string) $u['role_key'], ['user', 'hod', 'director'], true);
    }
    if ($closed) {
        return false;
    }
    if ($activityType === 'conversation') {
        if ($isAuditor) {
            return true;
        }
        if ($isCreator || $isAssignee) {
            return true;
        }
        if (ticket_user_is_approver($ticketId, $uid)) {
            return true;
        }
        if (ticket_user_has_mention_access($ticketId, $uid)) {
            return true;
        }
        $pending = ticket_pending_approval_for_user($ticketId, $uid);
        return $pending !== null;
    }
    return false;
}

function ticket_can_upload_attachment(int $ticketId, ?array $ticket = null): bool
{
    return ticket_can_post_conversation($ticketId, 'conversation', $ticket)
        || ticket_can_post_conversation($ticketId, 'admin_comment', $ticket);
}

/**
 * Browser URL for a stored attachment path (relative to app root).
 */
function ticket_attachment_web_url(?string $filePath): string
{
    if ($filePath === null || $filePath === '') {
        return '#';
    }
    $path = str_replace('\\', '/', $filePath);
    $path = ltrim($path, '/');
    $base = defined('APP_WEB_BASE') ? (string) APP_WEB_BASE : '';
    if ($base !== '' && $base !== '/') {
        return rtrim($base, '/') . '/' . $path;
    }
    return $path;
}

function ticket_attachment_is_image(string $fileName): bool
{
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function ticket_attachment_allowed(string $fileName): bool
{
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowed = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg',
        'mp3', 'wav', 'ogg', 'm4a', 'aac',
        'mp4', 'mov', 'avi', 'webm', 'mkv',
    ];
    return $ext !== '' && in_array($ext, $allowed, true);
}

function ticket_notify_operations_staff(int $ticketId, string $title, string $message, string $type): void
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return;
    }
    $deptId = (int) $ticket['department_id'];
    $st = db()->query(
        'SELECT u.id, r.role_key, u.department_id FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.status = "active" AND r.role_key IN ("super_admin","admin","hod")'
    );
    $url = 'ticket_detail.php?id=' . $ticketId;
    foreach ($st->fetchAll() as $row) {
        $rk = (string) $row['role_key'];
        if ($rk === 'hod' && (int) ($row['department_id'] ?? 0) !== $deptId) {
            continue;
        }
        notify_user((int) $row['id'], $title, $message, $type, $url);
    }
}

function ticket_mark_work_done(int $ticketId, int $userId, string $note = '', bool $fromFinalAssignment = false): bool
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        error_log("ticket_mark_work_done: ticket not found id={$ticketId}");
        return false;
    }
    if (ticket_work_is_marked_done($ticket)) {
        return true;
    }
    if (ticket_conversation_is_closed($ticket)) {
        return true;
    }
    if (!$fromFinalAssignment && !ticket_can_mark_work_done($ticketId, $ticket)) {
        error_log("ticket_mark_work_done: permission denied ticket_id={$ticketId} user_id={$userId}");
        return false;
    }
    $pdo = db();
    $note = trim($note);
    $hasApproval = ticket_has_approval_chain($ticketId);
    $oldStatus = (string) ($ticket['status_name'] ?? '');

    try {
        if ($hasApproval) {
            $pdo->prepare(
                'UPDATE tickets SET work_done_at = NOW(), work_done_by = ?, completion_note = ?, updated_at = NOW() WHERE id = ?'
            )->execute([$userId, $note !== '' ? $note : null, $ticketId]);
            ticket_set_pending_approval_status($ticketId, $userId, $ticket);
        } else {
            $closedId = status_id_by_name('Closed');
            if ($closedId) {
                $pdo->prepare(
                    'UPDATE tickets SET status_id = ?, work_done_at = NOW(), work_done_by = ?, completion_note = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$closedId, $userId, $note !== '' ? $note : null, $ticketId]);
                if ($oldStatus !== 'Closed') {
                    ticket_log_history($ticketId, $userId, 'status', $oldStatus, 'Closed');
                }
            } else {
                $pdo->prepare(
                    'UPDATE tickets SET work_done_at = NOW(), work_done_by = ?, completion_note = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$userId, $note !== '' ? $note : null, $ticketId]);
            }
        }
    } catch (Throwable $e) {
        error_log('ticket_mark_work_done: update failed ticket_id=' . $ticketId . ' ' . $e->getMessage());
        try {
            $pdo->prepare(
                'UPDATE tickets SET work_done_at = NOW(), work_done_by = ?, updated_at = NOW() WHERE id = ?'
            )->execute([$userId, $ticketId]);
        } catch (Throwable $e2) {
            error_log('ticket_mark_work_done: minimal update failed ticket_id=' . $ticketId . ' ' . $e2->getMessage());
            return false;
        }
    }

    ticket_log_history($ticketId, $userId, 'work_done', '—', 'Assigned work marked Done');
    if ($note !== '') {
        ticket_log_comment($ticketId, $userId, 'Work marked Done. ' . $note, 'system');
    } else {
        $systemMsg = $hasApproval
            ? 'Work marked Done. Awaiting approval; conversation remains open.'
            : 'Work marked Done. Conversation remains open for follow-up.';
        ticket_log_comment($ticketId, $userId, $systemMsg, 'system');
    }

    $creatorId = (int) $ticket['created_by'];
    $num = (string) $ticket['ticket_number'];
    if ($hasApproval) {
        notify_user(
            $creatorId,
            'Work completed on ' . $num,
            'Assigned work is Done. Formal approval is now required. You may continue commenting until approval is complete.',
            'info',
            'ticket_detail.php?id=' . $ticketId
        );
        $pendingAp = ticket_first_pending_approval_row($ticketId);
        if ($pendingAp) {
            notify_user(
                (int) $pendingAp['approver_id'],
                'Approval needed — ' . $num,
                'All assignment levels are Done. This ticket is ready for your approval.',
                'approval',
                'ticket_detail.php?id=' . $ticketId
            );
        }
    } else {
        notify_user(
            $creatorId,
            'Work completed on ' . $num,
            'The assigned user marked work as Done. The conversation remains open.',
            'info',
            'ticket_detail.php?id=' . $ticketId
        );
        ticket_notify_operations_staff(
            $ticketId,
            'Ticket awaiting audit — ' . $num,
            'Assigned work was marked Done and needs admin/HOD review for final completion.',
            'warning'
        );
    }
    return true;
}

/**
 * Notify creator and assignee after admin final status change.
 */
function ticket_notify_status_outcome(int $ticketId, string $newStatusName): void
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return;
    }
    $url = 'ticket_detail.php?id=' . $ticketId;
    $num = (string) $ticket['ticket_number'];
    $msg = 'Ticket ' . $num . ' status is now ' . $newStatusName . '.';
    notify_user((int) $ticket['created_by'], 'Ticket status updated', $msg, 'info', $url);
    $assignId = (int) ($ticket['assigned_to'] ?? 0);
    if ($assignId > 0 && $assignId !== (int) $ticket['created_by']) {
        notify_user($assignId, 'Ticket status updated', $msg, 'info', $url);
    }
}

/**
 * Dashboard/report helper: tickets marked done awaiting admin completion.
 */
function tickets_awaiting_audit_sql(string $alias = 't'): string
{
    $closedId = status_id_by_name('Closed');
    if (!$closedId) {
        return '1=0';
    }
    $parts = [$alias . '.status_id = ' . (int) $closedId];
    try {
        $parts[] = $alias . '.work_done_at IS NOT NULL';
    } catch (Throwable $e) {
        /* column may be missing until migration */
    }
    return '(' . implode(' AND ', $parts) . ')';
}

/**
 * Replace structured fields for a template (builder).
 *
 * @param list<array<string,mixed>> $fields
 */
function ticket_template_fields_replace(int $templateId, array $fields, int $editorId): void
{
    $pdo = db();
    try {
        $pdo->prepare('DELETE FROM template_fields WHERE template_id = ?')->execute([$templateId]);
    } catch (Throwable $e) {
        return;
    }
    $ord = 0;
    foreach ($fields as $f) {
        if (!is_array($f)) {
            continue;
        }
        $label = trim((string) ($f['field_label'] ?? 'Untitled field'));
        $type = trim((string) ($f['field_type'] ?? 'text'));
        if ($type === '') {
            $type = 'text';
        }
        $name = trim((string) ($f['field_name'] ?? ''));
        if ($name === '') {
            $name = 'field_' . $ord;
        }
        $name = preg_replace('/[^a-z0-9_]+/i', '_', $name) ?: 'field_' . $ord;
        $req = !empty($f['is_required']) ? 1 : 0;
        $opts = trim((string) ($f['field_options'] ?? ''));
        $ph = trim((string) ($f['placeholder'] ?? ''));
        $help = trim((string) ($f['help_text'] ?? ''));
        $def = trim((string) ($f['default_value'] ?? ''));
        try {
            $pdo->prepare(
                'INSERT INTO template_fields (template_id, field_name, field_label, field_type, is_required, field_order, field_options, placeholder, help_text, default_value)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([$templateId, $name, $label, $type, $req, $ord, $opts !== '' ? $opts : null, $ph !== '' ? $ph : null, $help !== '' ? $help : null, $def !== '' ? $def : null]);
        } catch (Throwable $e) {
            try {
                $pdo->prepare(
                    'INSERT INTO template_fields (template_id, field_name, field_label, field_type, is_required, field_order) VALUES (?,?,?,?,?,?)'
                )->execute([$templateId, $name, $label, $type, $req, $ord]);
            } catch (Throwable $e2) {
                /* ignore single field */
            }
        }
        $ord++;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ticket_template_fields_load(int $templateId): array
{
    if ($templateId < 1) {
        return [];
    }
    try {
        $stmt = db()->prepare(
            'SELECT id, template_id, field_name, field_label, field_type, is_required, field_order,
                    field_options, placeholder, help_text, default_value
             FROM template_fields
             WHERE template_id = ?
             ORDER BY field_order ASC, id ASC'
        );
        $stmt->execute([$templateId]);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<string>
 */
function ticket_custom_field_options_list(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    $trim = trim($raw);
    $decoded = json_decode($trim, true);
    if (is_array($decoded)) {
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item) && isset($item['label'])) {
                $out[] = trim((string) $item['label']);
            }
        }
        return $out;
    }
    $lines = preg_split('/\r\n|\r|\n/', $trim) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

function ticket_custom_field_is_input_type(string $fieldType): bool
{
    return !in_array($fieldType, ['divider', 'section', 'file', 'instruction'], true);
}

/**
 * @param array<string,mixed> $posted custom_fields[id => value]
 * @return list<array{template_field_id:int,field_key:string,field_label:string,field_type:string,field_value:string}>
 */
function ticket_custom_fields_validate(array $fields, array $posted, array &$errors): array
{
    $saved = [];
    foreach ($fields as $f) {
        $type = (string) ($f['field_type'] ?? 'text');
        if (!ticket_custom_field_is_input_type($type)) {
            continue;
        }
        $fieldId = (int) ($f['id'] ?? 0);
        $label = trim((string) ($f['field_label'] ?? 'Field'));
        $key = trim((string) ($f['field_name'] ?? ('field_' . $fieldId)));
        $required = !empty($f['is_required']);

        $raw = $posted[(string) $fieldId] ?? $posted[$fieldId] ?? '';
        if (is_array($raw)) {
            $raw = implode(', ', array_map('strval', $raw));
        }
        $value = trim((string) $raw);

        if ($type === 'checkbox') {
            $checked = $value !== '' && $value !== '0' && strtolower($value) !== 'off';
            $value = $checked ? 'Yes' : 'No';
            if ($required && !$checked) {
                $errors[] = $label . ' is required.';
                continue;
            }
        } elseif ($required && $value === '') {
            $errors[] = $label . ' is required.';
            continue;
        }

        if ($value !== '' && in_array($type, ['email'], true) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $label . ' must be a valid email address.';
            continue;
        }
        if ($value !== '' && in_array($type, ['number'], true) && !is_numeric($value)) {
            $errors[] = $label . ' must be a number.';
            continue;
        }

        if ($value !== '' && in_array($type, ['dropdown', 'select', 'radio'], true)) {
            $opts = ticket_custom_field_options_list(isset($f['field_options']) ? (string) $f['field_options'] : null);
            if ($opts && !in_array($value, $opts, true)) {
                $errors[] = $label . ' has an invalid selection.';
                continue;
            }
        }

        if ($type === 'user_selector' && $value !== '') {
            $uid = (int) $value;
            if ($uid < 1) {
                $errors[] = $label . ' requires a valid user selection.';
                continue;
            }
            $st = db()->prepare('SELECT full_name FROM users WHERE id = ? AND status = "active" LIMIT 1');
            $st->execute([$uid]);
            $uname = $st->fetch()['full_name'] ?? null;
            if (!$uname) {
                $errors[] = $label . ' requires a valid user selection.';
                continue;
            }
            $value = (string) $uname . ' (#' . $uid . ')';
        }

        if ($value === '' && !$required) {
            continue;
        }

        $saved[] = [
            'template_field_id' => $fieldId,
            'field_key' => $key,
            'field_label' => $label,
            'field_type' => $type,
            'field_value' => $value,
        ];
    }

    return $saved;
}

/**
 * @param list<array{template_field_id:int,field_key:string,field_label:string,field_type:string,field_value:string}> $rows
 */
function ticket_custom_fields_save(int $ticketId, int $templateId, array $rows): void
{
    if ($ticketId < 1 || $templateId < 1 || !$rows) {
        return;
    }
    try {
        $stmt = db()->prepare(
            'INSERT INTO ticket_field_values (ticket_id, template_id, template_field_id, field_label, field_key, field_type, field_value)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($rows as $r) {
            $stmt->execute([
                $ticketId,
                $templateId,
                $r['template_field_id'] > 0 ? $r['template_field_id'] : null,
                $r['field_label'],
                $r['field_key'],
                $r['field_type'],
                $r['field_value'],
            ]);
        }
    } catch (Throwable $e) {
        // ticket_field_values missing until migration_005
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ticket_custom_field_values_load(int $ticketId): array
{
    if ($ticketId < 1) {
        return [];
    }
    try {
        $stmt = db()->prepare(
            'SELECT field_label, field_key, field_type, field_value, created_at
             FROM ticket_field_values
             WHERE ticket_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$ticketId]);
        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $type = (string) ($r['field_type'] ?? 'text');
            if ($type === 'instruction') {
                continue;
            }
            $label = trim((string) ($r['field_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<array<string,mixed>> $usersList
 */
function ticket_custom_fields_apply_defaults(array $fields, array &$values): void
{
    foreach ($fields as $f) {
        if (!ticket_custom_field_is_input_type((string) ($f['field_type'] ?? 'text'))) {
            continue;
        }
        $fid = (string) (int) ($f['id'] ?? 0);
        if ($fid === '0') {
            continue;
        }
        if (isset($values[$fid]) && trim((string) $values[$fid]) !== '') {
            continue;
        }
        $def = trim((string) ($f['default_value'] ?? ''));
        if ($def !== '') {
            $values[$fid] = $def;
        }
    }
}

function ticket_log_comment(int $ticketId, int $userId, string $comment, string $activityType = 'comment'): int
{
    $stmt = db()->prepare(
        'INSERT INTO ticket_comments (ticket_id, user_id, comment, activity_type) VALUES (?,?,?,?)'
    );
    $stmt->execute([$ticketId, $userId, $comment, $activityType]);
    return (int) db()->lastInsertId();
}

function ticket_log_history(int $ticketId, int $userId, string $field, ?string $old, ?string $new): void
{
    $stmt = db()->prepare(
        'INSERT INTO ticket_history (ticket_id, changed_by, field_changed, old_value, new_value) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$ticketId, $userId, $field, $old, $new]);
}

function notify_user(int $userId, string $title, string $message, string $type, ?string $actionUrl): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO notifications (user_id, title, message, notification_type, is_read, action_url) VALUES (?,?,?,?,0,?)'
        );
        $stmt->execute([$userId, $title, $message, $type, $actionUrl]);
    } catch (Throwable $e) {
        $stmt = db()->prepare(
            'INSERT INTO notifications (user_id, title, message, notification_type, is_read) VALUES (?,?,?,?,0)'
        );
        $stmt->execute([$userId, $title, $message, $type]);
    }
}

function mark_notification_read(int $notificationId, int $userId): void
{
    try {
        db()->prepare(
            'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? LIMIT 1'
        )->execute([$notificationId, $userId]);
    } catch (Throwable $e) {
        db()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? LIMIT 1'
        )->execute([$notificationId, $userId]);
    }
}

function mark_all_notifications_read(int $userId): void
{
    try {
        db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0')->execute([$userId]);
    } catch (Throwable $e) {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$userId]);
    }
}

function setting_get(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function setting_set(string $key, string $value, string $group = 'general'): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)'
        );
        $stmt->execute([$key, $value, $group]);
    } catch (Throwable $e) {
        // system_settings missing until migration
    }
}
