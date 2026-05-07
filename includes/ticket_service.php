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
    if (user_is_privileged($u)) {
        return true;
    }
    if (ticket_user_involved((int) $u['id'], $ticketId)) {
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
    if (user_is_privileged($u)) {
        return true;
    }
    $role = (string) $u['role_key'];
    if (!in_array($role, ['director', 'hod'], true)) {
        return false;
    }
    if (!ticket_can_view($ticketId)) {
        return false;
    }
    return true;
}

function ticket_can_assign(int $ticketId): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if (user_is_privileged($u)) {
        return true;
    }
    if ((string) $u['role_key'] === 'hod' && ticket_can_view($ticketId)) {
        return true;
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
 *   info_message: string
 * }
 */
function ticket_approval_actor_context(int $ticketId, int $userId): array
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return [
            'can_decide' => false,
            'approval_row' => null,
            'mode' => 'none',
            'waiting_label' => '',
            'assignee_only' => false,
            'info_message' => '',
        ];
    }

    $first = ticket_first_pending_approval_row($ticketId);
    $waiting = $first ? (string) $first['approver_name'] . ' (level ' . (int) $first['approval_level'] . ')' : '—';

    $explicit = ticket_pending_approval_for_user($ticketId, $userId);
    if ($explicit) {
        $full = db()->prepare('SELECT ta.*, u.full_name AS approver_name FROM ticket_approvals ta JOIN users u ON u.id = ta.approver_id WHERE ta.id = ? LIMIT 1');
        $full->execute([(int) $explicit['id']]);
        $row = $full->fetch() ?: null;

        return [
            'can_decide' => true,
            'approval_row' => $row,
            'mode' => 'explicit',
            'waiting_label' => $waiting,
            'assignee_only' => false,
            'info_message' => '',
        ];
    }

    if (!$first) {
        $assignedOnly = ticket_user_is_assigned_worker($ticketId, $userId);
        return [
            'can_decide' => false,
            'approval_row' => null,
            'mode' => 'none',
            'waiting_label' => $waiting,
            'assignee_only' => $assignedOnly,
            'info_message' => $assignedOnly
                ? 'You are assigned to work on this ticket, but you are not currently listed as an approver. Use Comments and status updates as your role allows. Approvals appear only under Pending My Approval when you are named on the approval chain (or you have an override role).'
                : '',
        ];
    }

    $u = current_user();
    if (!$u) {
        return [
            'can_decide' => false,
            'approval_row' => null,
            'mode' => 'none',
            'waiting_label' => $waiting,
            'assignee_only' => false,
            'info_message' => '',
        ];
    }

    $role = (string) $u['role_key'];
    $actorDept = (int) ($u['department_id'] ?? 0);
    $ticketDept = (int) ($ticket['department_id'] ?? 0);
    $priLevel = (int) ($ticket['priority_level'] ?? 0);

    if (in_array($role, ['super_admin', 'admin'], true)) {
        return [
            'can_decide' => true,
            'approval_row' => $first,
            'mode' => 'override_admin',
            'waiting_label' => $waiting,
            'assignee_only' => false,
            'info_message' => 'You are approving as ' . ($role === 'super_admin' ? 'Super Admin' : 'Admin') . ' (override on the next pending step).',
        ];
    }

    if ($role === 'hod' && $actorDept > 0 && $ticketDept === $actorDept) {
        return [
            'can_decide' => true,
            'approval_row' => $first,
            'mode' => 'override_hod',
            'waiting_label' => $waiting,
            'assignee_only' => false,
            'info_message' => 'You are acting as Head of Department for this department ticket.',
        ];
    }

    if ($role === 'director' && $priLevel >= 3) {
        return [
            'can_decide' => true,
            'approval_row' => $first,
            'mode' => 'override_director',
            'waiting_label' => $waiting,
            'assignee_only' => false,
            'info_message' => 'You are acting as Director on a High or Critical priority ticket.',
        ];
    }

    $assignedOnly = ticket_user_is_assigned_worker($ticketId, $userId);

    return [
        'can_decide' => false,
        'approval_row' => null,
        'mode' => 'none',
        'waiting_label' => $waiting,
        'assignee_only' => $assignedOnly,
        'info_message' => $assignedOnly
            ? 'You are assigned to work on this ticket, but you are not currently listed as an approver.'
            : '',
    ];
}

function status_id_by_name(string $name): ?int
{
    static $cache = [];
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    $st = db()->prepare('SELECT id FROM ticket_statuses WHERE status_name = ? LIMIT 1');
    $st->execute([$name]);
    $row = $st->fetch();
    $cache[$name] = $row ? (int) $row['id'] : null;
    return $cache[$name];
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

function ticket_log_comment(int $ticketId, int $userId, string $comment, string $activityType = 'comment'): void
{
    $stmt = db()->prepare(
        'INSERT INTO ticket_comments (ticket_id, user_id, comment, activity_type) VALUES (?,?,?,?)'
    );
    $stmt->execute([$ticketId, $userId, $comment, $activityType]);
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
