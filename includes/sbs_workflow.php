<?php
declare(strict_types=1);

/** SBS Support Requests workflow — routing, assignment, visibility, SLA, archive. */

function sbs_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $st = db()->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $column]);
        $cache[$key] = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function sbs_workflow_enabled(): bool
{
    return sbs_column_exists('tickets', 'account_route');
}

/** @deprecated Use current_user_role_key() — roles.role_key is the SBS authority. */
function sbs_user_level(?array $user = null): string
{
    return current_user_role_key($user);
}

function sbs_is_super_admin(?array $user = null): bool
{
    return user_is_super_admin($user);
}

function sbs_is_pillar_admin(?array $user = null): bool
{
    return user_is_pillar_admin($user);
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_ticket_is_routed(array $ticket): bool
{
    return sbs_workflow_enabled() && trim((string) ($ticket['account_route'] ?? '')) !== '';
}

function sbs_grant_funds_labels(): array
{
    return [
        'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, Foundation, or restricted account(s)?',
        'Is any part of this request utilizing funds from Grant, Sponsored, TRIF, or Foundation account(s)?',
    ];
}

/**
 * @param array<int,array<string,mixed>> $collected from request_logic_validate_fields
 */
function sbs_parse_account_route_from_collected(array $collected): array
{
    $answer = '';
    foreach ($collected as $row) {
        $key = strtolower(trim((string) ($row['field_key'] ?? '')));
        $label = trim((string) ($row['field_label'] ?? ''));
        if ($key === 'grant_funds' || in_array($label, sbs_grant_funds_labels(), true)) {
            $answer = trim((string) ($row['field_value'] ?? ''));
            break;
        }
    }
    return sbs_route_from_grant_answer($answer);
}

function sbs_route_from_grant_answer(string $answer): array
{
    $norm = strtolower(trim($answer));
    if ($norm === 'yes' || $norm === 'y') {
        return ['account_route' => 'restricted', 'routed_pillar' => 'restricted_pillar_admin'];
    }
    if ($norm === 'no' || $norm === 'n') {
        return ['account_route' => 'unrestricted', 'routed_pillar' => 'unrestricted_pillar_admin'];
    }
    if (in_array($norm, ['not sure', 'i am not sure', 'unsure', 'unknown', ''], true)
        || str_contains($norm, 'not sure')) {
        return ['account_route' => 'general', 'routed_pillar' => 'general_pillar_admin'];
    }
    return ['account_route' => 'general', 'routed_pillar' => 'general_pillar_admin'];
}

function sbs_can_bulk_archive_user(?array $user = null): bool
{
    return user_is_super_admin($user) || user_is_pillar_admin($user);
}

/**
 * @param list<int> $ticketIds
 * @return array{ok:int, fail:int}
 */
function ticket_bulk_archive(array $ticketIds, int $userId, string $reason = 'bulk'): array
{
    $ok = 0;
    $fail = 0;
    foreach ($ticketIds as $tid) {
        $tid = (int) $tid;
        if ($tid < 1) {
            $fail++;
            continue;
        }
        $ticket = ticket_fetch_by_id($tid);
        if ($ticket && ticket_can_archive($ticket)) {
            db()->prepare(
                'UPDATE tickets SET archived_at = NOW(), archived_by = ?, archive_reason = ? WHERE id = ?'
            )->execute([$userId, $reason, $tid]);
            ticket_log_history($tid, $userId, 'archived', '', $reason);
            $ok++;
        } else {
            $fail++;
        }
    }
    return ['ok' => $ok, 'fail' => $fail];
}

function sbs_can_bulk_complete_user(?array $user = null): bool
{
    return user_is_super_admin($user);
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_ticket_ready_for_bulk_complete(array $ticket): bool
{
    if (!empty($ticket['archived_at'])) {
        return false;
    }
    if (sbs_ticket_is_completed($ticket)) {
        return false;
    }
    $status = (string) ($ticket['status_name'] ?? '');
    if (in_array($status, ['Cancelled', 'Rejected'], true)) {
        return false;
    }
    if ($status === 'Closed') {
        return true;
    }
    return !empty($ticket['work_done_at']);
}

/**
 * @param list<int> $ticketIds
 * @return array{ok:int, fail:int, error:?string}
 */
function ticket_bulk_complete(array $ticketIds, int $userId): array
{
    if (!sbs_can_bulk_complete_user()) {
        return ['ok' => 0, 'fail' => count($ticketIds), 'error' => 'permission'];
    }
    if ((int) (current_user()['id'] ?? 0) !== $userId) {
        return ['ok' => 0, 'fail' => count($ticketIds), 'error' => 'permission'];
    }
    if ($ticketIds === []) {
        return ['ok' => 0, 'fail' => 0, 'error' => 'empty'];
    }

    $toComplete = [];
    foreach ($ticketIds as $tid) {
        $tid = (int) $tid;
        if ($tid < 1) {
            return ['ok' => 0, 'fail' => count($ticketIds), 'error' => 'ineligible'];
        }
        $ticket = ticket_fetch_by_id($tid);
        if (!$ticket || !sbs_ticket_ready_for_bulk_complete($ticket)) {
            return ['ok' => 0, 'fail' => count($ticketIds), 'error' => 'ineligible'];
        }
        $toComplete[] = $ticket;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($toComplete as $ticket) {
            if (!sbs_complete_ticket((int) $ticket['id'], $userId, true)) {
                $pdo->rollBack();
                return ['ok' => 0, 'fail' => count($ticketIds), 'error' => 'failed'];
            }
        }
        $pdo->commit();
        return ['ok' => count($toComplete), 'fail' => 0, 'error' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => 0, 'fail' => count($ticketIds), 'error' => 'failed'];
    }
}


function sbs_route_label(string $route): string
{
    return match ($route) {
        'restricted' => 'Restricted',
        'unrestricted' => 'Unrestricted',
        default => 'General',
    };
}

function sbs_pillar_label(string $pillar): string
{
    return match ($pillar) {
        'restricted_pillar_admin' => 'Restricted Pillar Admin',
        'unrestricted_pillar_admin' => 'Unrestricted Pillar Admin',
        default => 'General Pillar Admin',
    };
}

/**
 * Apply routing after ticket + field values saved.
 */
function sbs_apply_routing_on_create(int $ticketId, array $collected): void
{
    if (!sbs_workflow_enabled()) {
        return;
    }
    $route = sbs_parse_account_route_from_collected($collected);
    if ($route['account_route'] === '' && $ticketId > 0) {
        $route = sbs_load_grant_answer_from_ticket($ticketId);
    }
    $pdo = db();
    $pdo->prepare(
        'UPDATE tickets SET account_route = ?, routed_pillar = ?, routed_at = NOW(),
         response_target_hours = COALESCE(response_target_hours, 168),
         resolution_target_hours = COALESCE(resolution_target_hours, 336),
         last_activity_at = NOW(), assigned_to = NULL, assigned_type = NULL
         WHERE id = ?'
    )->execute([$route['account_route'], $route['routed_pillar'], $ticketId]);
    ticket_log_history($ticketId, (int) (current_user()['id'] ?? 0), 'account_route', '', $route['account_route']);
    sbs_touch_activity($ticketId);
    if (function_exists('sbs_notify_routing_created')) {
        sbs_notify_routing_created($ticketId);
    }
}

function sbs_load_grant_answer_from_ticket(int $ticketId): array
{
    $labels = sbs_grant_funds_labels();
    $placeholders = implode(',', array_fill(0, count($labels), '?'));
    $params = array_merge([$ticketId], $labels);
    $st = db()->prepare(
        "SELECT field_value FROM ticket_field_values
         WHERE ticket_id = ? AND (field_key = 'grant_funds' OR field_label IN ($placeholders))
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute($params);
    $val = (string) ($st->fetchColumn() ?: '');
    return sbs_route_from_grant_answer($val);
}

function sbs_touch_activity(int $ticketId): void
{
    if (!sbs_column_exists('tickets', 'last_activity_at')) {
        return;
    }
    db()->prepare('UPDATE tickets SET last_activity_at = NOW() WHERE id = ?')->execute([$ticketId]);
}

function ticket_last_activity_at(int $ticketId): ?string
{
    if (sbs_column_exists('tickets', 'last_activity_at')) {
        $st = db()->prepare('SELECT last_activity_at FROM tickets WHERE id = ?');
        $st->execute([$ticketId]);
        $la = $st->fetchColumn();
        if ($la) {
            return (string) $la;
        }
    }
    $events = [];
    foreach (['ticket_comments' => 'created_at', 'ticket_history' => 'changed_at', 'ticket_attachments' => 'uploaded_at'] as $tbl => $col) {
        try {
            $st = db()->prepare("SELECT MAX($col) FROM $tbl WHERE ticket_id = ?");
            $st->execute([$ticketId]);
            $m = $st->fetchColumn();
            if ($m) {
                $events[] = strtotime((string) $m);
            }
        } catch (Throwable $e) {
        }
    }
    $st = db()->prepare('SELECT created_at, updated_at FROM tickets WHERE id = ?');
    $st->execute([$ticketId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if ($t) {
        foreach (['created_at', 'updated_at'] as $c) {
            if (!empty($t[$c])) {
                $events[] = strtotime((string) $t[$c]);
            }
        }
    }
    if (!$events) {
        return null;
    }
    return date('Y-m-d H:i:s', max($events));
}

/**
 * @param array<string,mixed> $ticket
 */
function ticket_should_be_stuck(array $ticket): bool
{
    $status = (string) ($ticket['status_name'] ?? '');
    if (in_array($status, ['Closed', 'Completed', 'Archived', 'Cancelled', 'Rejected', 'Stuck'], true)) {
        return false;
    }
    $hours = (int) ($ticket['response_target_hours'] ?? 168);
    $last = ticket_last_activity_at((int) $ticket['id']);
    if (!$last) {
        $last = (string) ($ticket['created_at'] ?? '');
    }
    if ($last === '') {
        return false;
    }
    return (time() - strtotime($last)) > ($hours * 3600);
}

/**
 * @param array<string,mixed> $ticket
 */
function ticket_should_be_overdue(array $ticket): bool
{
    $status = (string) ($ticket['status_name'] ?? '');
    if (in_array($status, ['Completed', 'Archived', 'Cancelled', 'Rejected'], true)) {
        return false;
    }
    $hours = (int) ($ticket['resolution_target_hours'] ?? 336);
    $created = (string) ($ticket['created_at'] ?? '');
    if ($created === '') {
        return false;
    }
    return (time() - strtotime($created)) > ($hours * 3600);
}

function ticket_apply_sla_status(int $ticketId): void
{
    if (!sbs_workflow_enabled()) {
        return;
    }
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket || !sbs_ticket_is_routed($ticket)) {
        return;
    }
    if (!empty($ticket['archived_at'])) {
        return;
    }
    $pdo = db();
    $status = (string) ($ticket['status_name'] ?? '');
    $stuckId = (int) ($pdo->query('SELECT id FROM ticket_statuses WHERE status_name="Stuck" LIMIT 1')->fetchColumn() ?: 0);
    $overdueId = (int) ($pdo->query('SELECT id FROM ticket_statuses WHERE status_name="Overdue" LIMIT 1')->fetchColumn() ?: 0);

    if (ticket_should_be_overdue($ticket) && $overdueId > 0 && !in_array($status, ['Completed', 'Closed'], true)) {
        $pdo->prepare('UPDATE tickets SET status_id = ?, sla_risk = 1 WHERE id = ?')->execute([$overdueId, $ticketId]);
        return;
    }
    if (ticket_should_be_stuck($ticket) && $stuckId > 0) {
        $pdo->prepare('UPDATE tickets SET status_id = ? WHERE id = ?')->execute([$stuckId, $ticketId]);
    }
}

function ticket_apply_sla_status_batch(?array $user = null): void
{
    if (!sbs_workflow_enabled()) {
        return;
    }
    $scope = tickets_scope_sql('t');
    $arch = sbs_column_exists('tickets', 'archived_at') ? ' AND t.archived_at IS NULL' : '';
    $st = db()->query(
        "SELECT t.id FROM tickets t WHERE $scope AND t.account_route IS NOT NULL AND t.account_route != '' $arch LIMIT 200"
    );
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        ticket_apply_sla_status((int) $row['id']);
    }
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_pillar_matches_ticket(array $ticket, ?array $user = null): bool
{
    $role = current_user_role_key($user);
    return $role === (string) ($ticket['routed_pillar'] ?? '')
        || ($role === 'restricted_pillar_admin' && ($ticket['account_route'] ?? '') === 'restricted')
        || ($role === 'unrestricted_pillar_admin' && ($ticket['account_route'] ?? '') === 'unrestricted')
        || ($role === 'general_pillar_admin' && ($ticket['account_route'] ?? '') === 'general');
}

/** Pillar Admin / Super Admin may assign or reassign until Completed. */
function sbs_can_assign(array $ticket, ?array $user = null): bool
{
    return sbs_can_reassign($ticket, $user);
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_can_reassign(array $ticket, ?array $user = null): bool
{
    if (!sbs_ticket_is_routed($ticket) || sbs_ticket_is_completed($ticket)) {
        return false;
    }
    if (sbs_is_super_admin($user)) {
        return true;
    }
    return sbs_is_pillar_admin($user) && sbs_pillar_matches_ticket($ticket, $user);
}

/** Active users with roles.role_key = $roleKey (not username). */
function sbs_users_by_level(string $roleKey): array
{
    return sbs_users_with_role($roleKey);
}

function sbs_assign_ticket(int $ticketId, int $targetUserId, string $assignedType, int $actorId): bool
{
    $assignedType = in_array($assignedType, ['business_admin', 'coordinator'], true) ? $assignedType : '';
    if ($assignedType === '' || $targetUserId < 1) {
        return false;
    }
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    $baReassign = $assignedType === 'coordinator' && sbs_can_reassign_to_coordinator($ticket);
    if (!$baReassign && !sbs_can_reassign($ticket)) {
        return false;
    }
    $targetRole = $assignedType === 'business_admin' ? 'business_admin' : 'coordinator';
    if (user_role_key($targetUserId) !== $targetRole) {
        return false;
    }
    $hadAssignee = !empty($ticket['assigned_to']);
    $pdo = db();
    $pendingId = sbs_status_id_by_name('Pending');
    if ($pendingId < 1) {
        $pdo->exec("INSERT IGNORE INTO ticket_statuses (status_name) VALUES ('Pending')");
        $pendingId = sbs_status_id_by_name('Pending');
    }
    $pdo->prepare(
        'UPDATE tickets SET assigned_to = ?, assigned_type = ?, status_id = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$targetUserId, $assignedType, $pendingId > 0 ? $pendingId : (int) $ticket['status_id'], $ticketId]);
    if (sbs_table_exists('ticket_assignment_history')) {
        $pdo->prepare(
            'INSERT INTO ticket_assignment_history (ticket_id, user_id, assigned_type, assigned_by) VALUES (?,?,?,?)'
        )->execute([$ticketId, $targetUserId, $assignedType, $actorId]);
    }
    $nameSt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
    $nameSt->execute([$targetUserId]);
    $newName = (string) ($nameSt->fetchColumn() ?: 'User');
    $histLabel = sbs_assignment_history_label($ticketId, $assignedType, $hadAssignee);
    ticket_log_history($ticketId, $actorId, 'assigned_to', (string) ($ticket['assignee_name'] ?? ''), $histLabel . ': ' . $newName);
    $event = $hadAssignee ? 'reassigned' : 'assignment_created';
    sbs_send_ticket_notifications(
        $ticketId,
        $event,
        $hadAssignee ? 'Ticket reassigned' : 'Ticket assigned',
        $histLabel . ' on ' . $ticket['ticket_number'],
        $actorId
    );
    sbs_touch_activity($ticketId);
    return true;
}

function sbs_table_exists(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $st = db()->query('SHOW TABLES LIKE ' . db()->quote($table));
        $cache[$table] = (bool) $st->fetch();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_is_assigned_worker(array $ticket, int $userId): bool
{
    return (int) ($ticket['assigned_to'] ?? 0) === $userId;
}

function sbs_was_assigned(int $ticketId, int $userId): bool
{
    if (sbs_is_assigned_worker(ticket_fetch_by_id($ticketId) ?: [], $userId)) {
        return true;
    }
    if (!sbs_table_exists('ticket_assignment_history')) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM ticket_assignment_history WHERE ticket_id = ? AND user_id = ? LIMIT 1');
    $st->execute([$ticketId, $userId]);
    return (bool) $st->fetchColumn();
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_can_reassign_to_coordinator(array $ticket, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || !sbs_ticket_is_routed($ticket) || sbs_ticket_is_completed($ticket)) {
        return false;
    }
    $uid = (int) $user['id'];
    $tid = (int) $ticket['id'];
    if (!user_is_business_admin($user)) {
        return false;
    }
    if (sbs_is_assigned_worker($ticket, $uid) && ($ticket['assigned_type'] ?? '') === 'business_admin') {
        return true;
    }
    return sbs_was_assigned_with_type($tid, $uid, 'business_admin');
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_work_is_closed(array $ticket): bool
{
    $status = (string) ($ticket['status_name'] ?? '');
    return in_array($status, ['Closed', 'Completed'], true) || !empty($ticket['work_done_at']);
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_can_mark_done(array $ticket, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || sbs_work_is_closed($ticket) || sbs_ticket_is_completed($ticket)) {
        return false;
    }
    $uid = (int) $user['id'];
    $type = (string) ($ticket['assigned_type'] ?? '');
    if (user_is_business_admin($user) && $type === 'business_admin' && sbs_is_assigned_worker($ticket, $uid)) {
        return true;
    }
    if (user_is_coordinator($user) && $type === 'coordinator' && sbs_is_assigned_worker($ticket, $uid)) {
        return true;
    }
    return false;
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_can_complete(array $ticket, ?array $user = null): bool
{
    if (!sbs_work_is_closed($ticket) && empty($ticket['work_done_at'])) {
        return false;
    }
    if ((string) ($ticket['status_name'] ?? '') === 'Completed') {
        return false;
    }
    if (sbs_is_super_admin($user)) {
        return true;
    }
    return sbs_is_pillar_admin($user) && sbs_pillar_matches_ticket($ticket, $user);
}

function sbs_mark_work_done(int $ticketId, int $userId): bool
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket || !sbs_can_mark_done($ticket, current_user())) {
        return false;
    }
    $pdo = db();
    $closedId = sbs_status_id_by_name('Closed');
    $pdo->prepare(
        'UPDATE tickets SET status_id = COALESCE(?, status_id), work_done_at = NOW(), work_done_by = ?,
         conversation_closed_at = COALESCE(conversation_closed_at, NOW()), updated_at = NOW() WHERE id = ?'
    )->execute([$closedId > 0 ? $closedId : null, $userId, $ticketId]);
    ticket_log_history($ticketId, $userId, 'status', (string) $ticket['status_name'], 'Closed');
    sbs_send_ticket_notifications(
        $ticketId,
        'done_closed',
        'Ticket work marked Done',
        'Ticket ' . $ticket['ticket_number'] . ' is Closed and awaiting completion.',
        $userId
    );
    sbs_touch_activity($ticketId);
    return true;
}

function sbs_complete_ticket(int $ticketId, int $userId, bool $bulkBySuperAdmin = false): bool
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    if ($bulkBySuperAdmin) {
        $actorSt = db()->prepare(
            'SELECT u.id, COALESCE(r.role_key, \'user\') AS role_key FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
        );
        $actorSt->execute([$userId]);
        $actor = $actorSt->fetch(PDO::FETCH_ASSOC);
        if (!$actor || !user_is_super_admin($actor) || !sbs_ticket_ready_for_bulk_complete($ticket)) {
            return false;
        }
    } elseif (!sbs_can_complete($ticket)) {
        return false;
    }
    $pdo = db();
    $completedId = (int) ($pdo->query('SELECT id FROM ticket_statuses WHERE status_name="Completed" LIMIT 1')->fetchColumn() ?: 0);
    $sets = ['status_id = COALESCE(?, status_id)', 'date_completed = CURDATE()', 'updated_at = NOW()'];
    $params = [$completedId ?: null];
    if (sbs_column_exists('tickets', 'final_completed_at')) {
        $sets[] = 'final_completed_at = NOW()';
        $sets[] = 'final_completed_by = ?';
        $params[] = $userId;
    }
    $params[] = $ticketId;
    $pdo->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    ticket_log_history($ticketId, $userId, 'status', (string) $ticket['status_name'], 'Completed');
    if ($bulkBySuperAdmin) {
        ticket_log_history($ticketId, $userId, 'completion', '', 'Ticket completed in bulk by Super Admin.');
    }
    sbs_send_ticket_notifications(
        $ticketId,
        'completed',
        'Ticket completed',
        'Ticket ' . $ticket['ticket_number'] . ' has been marked Completed.',
        $userId
    );
    sbs_touch_activity($ticketId);
    return true;
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_can_change_priority(array $ticket, ?array $user = null): bool
{
    if (!sbs_ticket_is_routed($ticket) || sbs_ticket_is_completed($ticket)) {
        return false;
    }
    if (sbs_is_super_admin($user)) {
        return true;
    }
    if (user_is_pillar_admin($user) && sbs_pillar_matches_ticket($ticket, $user)) {
        return true;
    }
    $uid = (int) (($user ?? current_user())['id'] ?? 0);
    if (!user_is_business_admin($user)) {
        return false;
    }
    return sbs_user_in_ticket_chain((int) $ticket['id'], $uid);
}

function sbs_notify_subscribed(int $ticketId, string $title, string $body, string $type = 'info'): void
{
    if (!sbs_table_exists('ticket_notification_subscriptions')) {
        return;
    }
    $st = db()->prepare('SELECT user_id FROM ticket_notification_subscriptions WHERE ticket_id = ?');
    $st->execute([$ticketId]);
    $link = 'ticket_detail.php?id=' . $ticketId;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        notify_user((int) $row['user_id'], $title, $body, $type, $link);
    }
}

function sbs_toggle_notify(int $ticketId, int $userId): bool
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    $actorSt = db()->prepare('SELECT u.*, COALESCE(r.role_key, \'user\') AS role_key FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $actorSt->execute([$userId]);
    $actorRow = $actorSt->fetch(PDO::FETCH_ASSOC);
    if (!$actorRow || !sbs_can_show_notify_me($ticket, $actorRow)) {
        return false;
    }
    if (!sbs_table_exists('ticket_notification_subscriptions')) {
        return false;
    }
    $st = db()->prepare('SELECT id FROM ticket_notification_subscriptions WHERE ticket_id = ? AND user_id = ?');
    $st->execute([$ticketId, $userId]);
    if ($st->fetch()) {
        db()->prepare('DELETE FROM ticket_notification_subscriptions WHERE ticket_id = ? AND user_id = ?')->execute([$ticketId, $userId]);
        return false;
    }
    db()->prepare('INSERT IGNORE INTO ticket_notification_subscriptions (ticket_id, user_id) VALUES (?,?)')->execute([$ticketId, $userId]);
    return true;
}

function sbs_is_subscribed(int $ticketId, int $userId): bool
{
    if (!sbs_table_exists('ticket_notification_subscriptions')) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM ticket_notification_subscriptions WHERE ticket_id = ? AND user_id = ?');
    $st->execute([$ticketId, $userId]);
    return (bool) $st->fetchColumn();
}

/**
 * @param array<string,mixed> $ticket
 */
function ticket_can_archive(array $ticket, ?array $user = null): bool
{
    if (!sbs_column_exists('tickets', 'archived_at')) {
        return false;
    }
    if (!empty($ticket['archived_at'])) {
        return false;
    }
    if (sbs_is_super_admin($user)) {
        return true;
    }
    return sbs_is_pillar_admin($user) && sbs_pillar_matches_ticket($ticket, $user);
}

function ticket_archive(int $ticketId, int $userId, string $reason = 'manual'): bool
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket || !ticket_can_archive($ticket)) {
        return false;
    }
    db()->prepare(
        'UPDATE tickets SET archived_at = NOW(), archived_by = ?, archive_reason = ? WHERE id = ?'
    )->execute([$userId, $reason, $ticketId]);
    ticket_log_history($ticketId, $userId, 'archived', '', $reason);
    return true;
}

function sbs_archive_not_archived_sql(string $alias = 't'): string
{
    if (sbs_column_exists('tickets', 'archived_at')) {
        return $alias . '.archived_at IS NULL';
    }
    return '1=1';
}

function ticket_apply_archive_thresholds(?array $user = null): void
{
    if (!sbs_column_exists('tickets', 'archived_at')) {
        return;
    }
    $user = $user ?? current_user();
    if (!$user) {
        return;
    }
    $limit = sbs_is_super_admin($user) ? 100 : 50;
    $scope = tickets_scope_sql('t');
    $arch = sbs_archive_not_archived_sql('t');
    $st = db()->query(
        "SELECT t.id FROM tickets t WHERE $scope AND $arch ORDER BY t.id DESC"
    );
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) <= $limit) {
        return;
    }
    $toArchive = array_slice($ids, $limit);
    $uid = (int) $user['id'];
    foreach ($toArchive as $tid) {
        ticket_archive((int) $tid, $uid, 'threshold');
    }
}

function sbs_org_code_filter_sql(string $alias, string $orgCode): array
{
    $orgCode = trim($orgCode);
    if ($orgCode === '') {
        return ['', []];
    }
    return [
        "EXISTS (SELECT 1 FROM departments d WHERE d.id = {$alias}.department_id AND d.organization_code = ?)",
        [$orgCode],
    ];
}

/**
 * SBS visibility — uses roles.role_key when SBS workflow columns exist.
 */
function tickets_scope_sql(string $alias = 't'): string
{
    if (!sbs_workflow_enabled()) {
        return tickets_scope_sql_legacy($alias);
    }
    $u = current_user();
    if (!$u) {
        return '1=0';
    }
    $role = current_user_role_key($u);
    $uid = (int) $u['id'];
    $mentionSql = sbs_mention_scope_sql($alias, $uid);

    if (is_super_admin_role($role)) {
        return '1=1';
    }

    if (in_array($role, ['user', 'faculty_staff'], true)) {
        return "({$alias}.created_by = $uid $mentionSql)";
    }

    if ($role === 'restricted_pillar_admin') {
        $sub = sbs_subscription_scope_sql($alias, $uid);
        return "({$alias}.account_route = 'restricted' $sub $mentionSql)";
    }
    if ($role === 'unrestricted_pillar_admin') {
        return "({$alias}.account_route = 'unrestricted' $mentionSql)";
    }
    if ($role === 'general_pillar_admin') {
        return "({$alias}.account_route = 'general' $mentionSql)";
    }

    if (in_array($role, ['business_admin', 'coordinator'], true)) {
        $hist = sbs_table_exists('ticket_assignment_history')
            ? " OR EXISTS (SELECT 1 FROM ticket_assignment_history h WHERE h.ticket_id = {$alias}.id AND h.user_id = $uid)"
            : '';
        $workDone = sbs_column_exists('tickets', 'work_done_by')
            ? " OR {$alias}.work_done_by = $uid"
            : '';
        return "({$alias}.assigned_to = $uid $hist $workDone $mentionSql)";
    }

    if ($role === 'admin') {
        return tickets_scope_sql_legacy_involved($alias, $uid);
    }

    return tickets_scope_sql_legacy($alias);
}

function tickets_scope_sql_legacy_involved(string $alias, int $uid): string
{
    $mentionSql = sbs_mention_scope_sql($alias, $uid);
    return '(' . $alias . '.created_by = ' . $uid
        . ' OR ' . $alias . '.assigned_to = ' . $uid
        . ' OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = ' . $alias . '.id AND ta.user_id = ' . $uid . ')'
        . ' OR EXISTS (SELECT 1 FROM ticket_approvals tap WHERE tap.ticket_id = ' . $alias . '.id AND tap.approver_id = ' . $uid . ')'
        . $mentionSql
        . ')';
}

/** Visibility for active (non-archived) ticket lists. */
function tickets_active_scope_sql(string $alias = 't'): string
{
    $scope = tickets_scope_sql($alias);
    if (sbs_column_exists('tickets', 'archived_at')) {
        return "($scope AND " . sbs_archive_not_archived_sql($alias) . ')';
    }
    return $scope;
}

function tickets_scope_sql_legacy(string $alias = 't'): string
{
    $u = current_user();
    if (!$u) {
        return '1=0';
    }
    $role = (string) $u['role_key'];
    if (is_super_admin_role($role) || $role === 'admin') {
        return '1=1';
    }
    if (in_array($role, ['hod', 'director'], true)) {
        $deptId = (int) ($u['department_id'] ?? 0);
        if ($deptId > 0) {
            return $alias . '.department_id = ' . $deptId;
        }
        return '1=0';
    }
    $uid = (int) $u['id'];
    $mentionSql = sbs_mention_scope_sql($alias, $uid);
    return '(' . $alias . '.created_by = ' . $uid
        . ' OR ' . $alias . '.assigned_to = ' . $uid
        . ' OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = ' . $alias . '.id AND ta.user_id = ' . $uid . ')'
        . ' OR EXISTS (SELECT 1 FROM ticket_approvals tap WHERE tap.ticket_id = ' . $alias . '.id AND tap.approver_id = ' . $uid . ')'
        . $mentionSql
        . ')';
}

function sbs_mention_scope_sql(string $alias, int $uid): string
{
    if (function_exists('ticket_mention_access_table_exists') && ticket_mention_access_table_exists()) {
        return " OR EXISTS (SELECT 1 FROM ticket_mention_access tma WHERE tma.ticket_id = {$alias}.id AND tma.user_id = $uid)";
    }
    if (function_exists('comment_mentions_table_exists') && comment_mentions_table_exists()) {
        return " OR EXISTS (SELECT 1 FROM comment_mentions cm WHERE cm.ticket_id = {$alias}.id AND cm.mentioned_user_id = $uid)";
    }
    return '';
}

function sbs_subscription_scope_sql(string $alias, int $uid): string
{
    if (!sbs_table_exists('ticket_notification_subscriptions')) {
        return '';
    }
    return " OR EXISTS (SELECT 1 FROM ticket_notification_subscriptions s WHERE s.ticket_id = {$alias}.id AND s.user_id = $uid)";
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_can_view_ticket(array $ticket, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    $tid = (int) $ticket['id'];
    $uid = (int) $user['id'];
    $st = db()->prepare("SELECT 1 FROM tickets t WHERE t.id = ? AND " . tickets_scope_sql('t') . ' LIMIT 1');
    $st->execute([$tid]);
    if ($st->fetch()) {
        return true;
    }
    if (!empty($ticket['archived_at']) && sbs_user_has_archive_access($ticket, $user)) {
        return true;
    }
    return false;
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_user_has_archive_access(array $ticket, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    if (sbs_is_super_admin($user)) {
        return true;
    }
    $uid = (int) $user['id'];
    if ((int) ($ticket['created_by'] ?? 0) === $uid) {
        return true;
    }
    if (sbs_was_assigned($tid = (int) $ticket['id'], $uid)) {
        return true;
    }
    return sbs_pillar_matches_ticket($ticket, $user);
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_can_post_conversation(array $ticket, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    if (sbs_ticket_is_completed($ticket)) {
        return sbs_is_super_admin($user) || (sbs_is_pillar_admin($user) && sbs_pillar_matches_ticket($ticket, $user));
    }
    if (sbs_work_is_closed($ticket)) {
        return sbs_is_super_admin($user) || (sbs_is_pillar_admin($user) && sbs_pillar_matches_ticket($ticket, $user));
    }
    if (ticket_user_is_mention_only((int) $ticket['id'], $user)) {
        return true;
    }
    $uid = (int) $user['id'];
    $tid = (int) $ticket['id'];
    if ((int) ($ticket['created_by'] ?? 0) === $uid) {
        return true;
    }
    if (sbs_is_pillar_admin($user) && sbs_pillar_matches_ticket($ticket, $user)) {
        return true;
    }
    if (sbs_is_super_admin($user)) {
        return true;
    }
    return sbs_user_in_ticket_chain($tid, $uid);
}

function sbs_handle_ticket_detail_post(int $ticketId, array &$errors): bool
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket || !sbs_ticket_is_routed($ticket)) {
        return false;
    }
    $uid = (int) current_user()['id'];
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'sbs_assign' && sbs_can_reassign($ticket)) {
        $target = (int) ($_POST['sbs_assign_user_id'] ?? 0);
        $type = (string) ($_POST['sbs_assign_type'] ?? '');
        if (!sbs_assign_ticket($ticketId, $target, $type, $uid)) {
            $errors[] = 'Could not assign ticket.';
        } else {
            flash_set('success', empty($ticket['assigned_to']) ? 'Ticket assigned.' : 'Ticket reassigned.');
        }
        return true;
    }
    if ($action === 'sbs_reassign_coordinator' && sbs_can_reassign_to_coordinator($ticket)) {
        $target = (int) ($_POST['sbs_assign_user_id'] ?? 0);
        if (!sbs_assign_ticket($ticketId, $target, 'coordinator', $uid)) {
            $errors[] = 'Could not reassign to coordinator.';
        } else {
            flash_set('success', 'Reassigned to coordinator.');
        }
        return true;
    }
    if ($action === 'sbs_mark_done' && sbs_can_mark_done($ticket)) {
        if (!sbs_mark_work_done($ticketId, $uid)) {
            $errors[] = 'Could not mark work done.';
        } else {
            flash_set('success', 'Work marked done — ticket is Closed pending completion.');
        }
        return true;
    }
    if ($action === 'sbs_complete' && sbs_can_complete($ticket)) {
        if (!sbs_complete_ticket($ticketId, $uid)) {
            $errors[] = 'Could not complete ticket.';
        } else {
            flash_set('success', 'Ticket completed.');
        }
        return true;
    }
    if ($action === 'sbs_priority' && sbs_can_change_priority($ticket)) {
        $pri = (int) ($_POST['priority_id'] ?? 0);
        if ($pri > 0) {
            db()->prepare('UPDATE tickets SET priority_id = ? WHERE id = ?')->execute([$pri, $ticketId]);
            sbs_touch_activity($ticketId);
            sbs_send_ticket_notifications(
                $ticketId,
                'priority_changed',
                'Priority updated',
                'Priority was updated on ticket ' . $ticket['ticket_number'],
                $uid
            );
            flash_set('success', 'Priority updated.');
        }
        return true;
    }
    if ($action === 'sbs_notify') {
        sbs_toggle_notify($ticketId, $uid);
        flash_set('success', 'Notification preference updated.');
        return true;
    }
    if ($action === 'sbs_archive' && ticket_can_archive($ticket)) {
        ticket_archive($ticketId, $uid, 'manual');
        flash_set('success', 'Ticket archived.');
        return true;
    }
    return false;
}
