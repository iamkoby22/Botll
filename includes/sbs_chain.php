<?php
declare(strict_types=1);

/** SBS ticket chain visibility, notifications, and reporting traceability. */

function sbs_ticket_is_completed(array $ticket): bool
{
    return (string) ($ticket['status_name'] ?? '') === 'Completed';
}

function sbs_pillar_role_key_for_route(string $accountRoute): string
{
    return match ($accountRoute) {
        'restricted' => 'restricted_pillar_admin',
        'unrestricted' => 'unrestricted_pillar_admin',
        default => 'general_pillar_admin',
    };
}

function sbs_routed_team_display_name(string $accountRoute): string
{
    return match ($accountRoute) {
        'restricted' => 'Restricted Pillar Admin team',
        'unrestricted' => 'Unrestricted Pillar Admin team',
        default => 'General Pillar Admin team',
    };
}

function sbs_routing_success_flash_message(string $ticketNumber, string $accountRoute): string
{
    return sprintf(
        'Ticket %s was created successfully and routed to the %s.',
        $ticketNumber,
        sbs_routed_team_display_name($accountRoute)
    );
}

function sbs_user_has_pillar_role(int $userId): bool
{
    return in_array(user_role_key($userId), [
        'restricted_pillar_admin',
        'unrestricted_pillar_admin',
        'general_pillar_admin',
    ], true);
}

/** @return list<int> */
function sbs_subscribed_user_ids(int $ticketId): array
{
    if (!sbs_table_exists('ticket_notification_subscriptions')) {
        return [];
    }
    $st = db()->prepare('SELECT user_id FROM ticket_notification_subscriptions WHERE ticket_id = ?');
    $st->execute([$ticketId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Chain users for routine updates — excludes pillar admins unless they subscribed.
 *
 * @return list<int>
 */
function sbs_routine_notification_user_ids(int $ticketId): array
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return [];
    }
    $ids = [];
    $add = static function (int $id) use (&$ids): void {
        if ($id > 0) {
            $ids[$id] = $id;
        }
    };
    $add((int) ($ticket['created_by'] ?? 0));
    $add((int) ($ticket['assigned_to'] ?? 0));
    if (sbs_column_exists('tickets', 'work_done_by')) {
        $add((int) ($ticket['work_done_by'] ?? 0));
    }
    if (sbs_table_exists('ticket_assignment_history')) {
        $st = db()->prepare(
            'SELECT DISTINCT h.user_id, h.assigned_by FROM ticket_assignment_history h WHERE h.ticket_id = ?'
        );
        $st->execute([$ticketId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach (['user_id', 'assigned_by'] as $col) {
                $uid = (int) ($row[$col] ?? 0);
                if ($uid > 0 && !sbs_user_has_pillar_role($uid)) {
                    $add($uid);
                }
            }
        }
    }
    foreach (sbs_subscribed_user_ids($ticketId) as $uid) {
        $add($uid);
    }
    if (function_exists('ticket_mention_access_table_exists') && ticket_mention_access_table_exists()) {
        $st = db()->prepare('SELECT user_id FROM ticket_mention_access WHERE ticket_id = ?');
        $st->execute([$ticketId]);
        while ($uid = $st->fetchColumn()) {
            $add((int) $uid);
        }
    } elseif (function_exists('comment_mentions_table_exists') && comment_mentions_table_exists()) {
        $st = db()->prepare('SELECT DISTINCT mentioned_user_id FROM comment_mentions WHERE ticket_id = ?');
        $st->execute([$ticketId]);
        while ($uid = $st->fetchColumn()) {
            $add((int) $uid);
        }
    }
    return array_values($ids);
}

/** @return list<int> */
function sbs_active_user_ids_by_role(string $roleKey): array
{
    $st = db()->prepare(
        'SELECT u.id FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.status = "active" AND r.role_key = ?'
    );
    $st->execute([$roleKey]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return list<int>
 */
function sbs_ticket_chain_user_ids(int $ticketId): array
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return [];
    }
    $ids = [];
    $add = static function (int $id) use (&$ids): void {
        if ($id > 0) {
            $ids[$id] = $id;
        }
    };
    $add((int) ($ticket['created_by'] ?? 0));
    $add((int) ($ticket['assigned_to'] ?? 0));
    if (sbs_column_exists('tickets', 'work_done_by')) {
        $add((int) ($ticket['work_done_by'] ?? 0));
    }
    if (sbs_column_exists('tickets', 'final_completed_by')) {
        $add((int) ($ticket['final_completed_by'] ?? 0));
    }
    if (sbs_table_exists('ticket_assignment_history')) {
        $st = db()->prepare('SELECT DISTINCT user_id, assigned_by FROM ticket_assignment_history WHERE ticket_id = ?');
        $st->execute([$ticketId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $add((int) ($row['user_id'] ?? 0));
            $add((int) ($row['assigned_by'] ?? 0));
        }
    }
    if (sbs_table_exists('ticket_notification_subscriptions')) {
        $st = db()->prepare('SELECT user_id FROM ticket_notification_subscriptions WHERE ticket_id = ?');
        $st->execute([$ticketId]);
        while ($uid = $st->fetchColumn()) {
            $add((int) $uid);
        }
    }
    if (function_exists('ticket_mention_access_table_exists') && ticket_mention_access_table_exists()) {
        $st = db()->prepare('SELECT user_id FROM ticket_mention_access WHERE ticket_id = ?');
        $st->execute([$ticketId]);
        while ($uid = $st->fetchColumn()) {
            $add((int) $uid);
        }
    } elseif (function_exists('comment_mentions_table_exists') && comment_mentions_table_exists()) {
        $st = db()->prepare('SELECT DISTINCT mentioned_user_id FROM comment_mentions WHERE ticket_id = ?');
        $st->execute([$ticketId]);
        while ($uid = $st->fetchColumn()) {
            $add((int) $uid);
        }
    }
    return array_values($ids);
}

function sbs_user_in_ticket_chain(int $ticketId, int $userId): bool
{
    if ($userId < 1) {
        return false;
    }
    return in_array($userId, sbs_ticket_chain_user_ids($ticketId), true);
}

function sbs_was_assigned_with_type(int $ticketId, int $userId, string $assignedType): bool
{
    if (!sbs_table_exists('ticket_assignment_history')) {
        return false;
    }
    $st = db()->prepare(
        'SELECT 1 FROM ticket_assignment_history WHERE ticket_id = ? AND user_id = ? AND assigned_type = ? LIMIT 1'
    );
    $st->execute([$ticketId, $userId, $assignedType]);
    return (bool) $st->fetchColumn();
}

function sbs_reassignment_count(int $ticketId): int
{
    if (!sbs_table_exists('ticket_assignment_history')) {
        return 0;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM ticket_assignment_history WHERE ticket_id = ?');
    $st->execute([$ticketId]);
    return (int) $st->fetchColumn();
}

function sbs_assignment_history_label(int $ticketId, string $assignedType, bool $hadAssignee): string
{
    $role = $assignedType === 'business_admin' ? 'Business Admin' : 'Coordinator';
    $n = sbs_reassignment_count($ticketId) + 1;
    if (!$hadAssignee) {
        return 'Assigned to ' . $role;
    }
    return 'Reassigned ' . $n . ' to ' . $role;
}

function sbs_status_id_by_name(string $name): int
{
    static $cache = [];
    if (isset($cache[$name]) && $cache[$name] > 0) {
        return $cache[$name];
    }
    $st = db()->prepare('SELECT id FROM ticket_statuses WHERE status_name = ? LIMIT 1');
    $st->execute([$name]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if ($id > 0) {
        $cache[$name] = $id;
    }
    return $id;
}

/**
 * @param array<string,mixed> $ticket
 */
function sbs_can_show_notify_me(array $ticket, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || !sbs_ticket_is_routed($ticket)) {
        return false;
    }
    if (user_is_business_admin($user) || user_is_coordinator($user) || user_is_faculty_staff($user)) {
        return false;
    }
    return (user_is_pillar_admin($user) && sbs_pillar_matches_ticket($ticket, $user))
        || user_is_super_admin($user);
}

/**
 * @return list<int>
 */
function sbs_ticket_notification_recipients(int $ticketId, string $eventType, int $excludeUserId = 0): array
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return [];
    }
    $recipients = [];
    $add = static function (int $id) use (&$recipients, $excludeUserId): void {
        if ($id > 0 && $id !== $excludeUserId) {
            $recipients[$id] = $id;
        }
    };

    switch ($eventType) {
        case 'ticket_created_routed':
            $route = (string) ($ticket['account_route'] ?? '');
            foreach (sbs_active_user_ids_by_role(sbs_pillar_role_key_for_route($route)) as $uid) {
                $add($uid);
            }
            break;

        case 'assignment_created':
        case 'reassigned':
            foreach (sbs_routine_notification_user_ids($ticketId) as $uid) {
                $add($uid);
            }
            $add((int) ($ticket['assigned_to'] ?? 0));
            break;

        case 'comment_added':
        case 'attachment_added':
        case 'priority_changed':
            foreach (sbs_routine_notification_user_ids($ticketId) as $uid) {
                $add($uid);
            }
            break;

        case 'done_closed':
            foreach (sbs_routine_notification_user_ids($ticketId) as $uid) {
                $add($uid);
            }
            $route = (string) ($ticket['account_route'] ?? '');
            foreach (sbs_active_user_ids_by_role(sbs_pillar_role_key_for_route($route)) as $uid) {
                $add($uid);
            }
            break;

        case 'completed':
            foreach (sbs_routine_notification_user_ids($ticketId) as $uid) {
                $add($uid);
            }
            break;
    }

    return array_values($recipients);
}

function sbs_send_ticket_notifications(int $ticketId, string $eventType, string $title, string $body, int $excludeUserId = 0): void
{
    $link = 'ticket_detail.php?id=' . $ticketId;
    $type = match ($eventType) {
        'ticket_created_routed', 'assignment_created', 'reassigned' => 'assignment',
        'done_closed' => 'info',
        'completed' => 'success',
        default => 'info',
    };
    foreach (sbs_ticket_notification_recipients($ticketId, $eventType, $excludeUserId) as $uid) {
        notify_user($uid, $title, $body, $type, $link);
    }
}

function sbs_notify_routing_created(int $ticketId): void
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket || !sbs_ticket_is_routed($ticket)) {
        return;
    }
    $route = sbs_route_label((string) $ticket['account_route']);
    $title = match ((string) ($ticket['account_route'] ?? '')) {
        'restricted' => 'New restricted request routed',
        'unrestricted' => 'New unrestricted request routed',
        default => 'New general request routed',
    };
    $reqType = trim((string) ($ticket['request_type'] ?? 'Request'));
    $body = sprintf(
        '%s — %s. Requester: %s. Type: %s. Route: %s.',
        (string) $ticket['ticket_number'],
        (string) $ticket['subject'],
        (string) ($ticket['created_name'] ?? $ticket['requester_name_snapshot'] ?? 'Requester'),
        $reqType !== '' ? $reqType : 'SBS Request',
        $route
    );
    sbs_send_ticket_notifications($ticketId, 'ticket_created_routed', $title, $body, (int) (current_user()['id'] ?? 0));
}

/**
 * @return array<string,mixed>
 */
function sbs_ticket_chain_report(int $ticketId): array
{
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return [];
    }
    $chain = [];
    $st = db()->prepare(
        'SELECT h.*, u.full_name, u.username, r.role_key
         FROM ticket_assignment_history h
         JOIN users u ON u.id = h.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE h.ticket_id = ?
         ORDER BY h.assigned_at ASC, h.id ASC'
    );
    if (sbs_table_exists('ticket_assignment_history')) {
        $st->execute([$ticketId]);
        $chain = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $workDoneName = '';
    if (!empty($ticket['work_done_by'])) {
        $ws = db()->prepare('SELECT full_name FROM users WHERE id = ?');
        $ws->execute([(int) $ticket['work_done_by']]);
        $workDoneName = (string) ($ws->fetchColumn() ?: '');
    }
    $completedByName = '';
    if (!empty($ticket['final_completed_by'])) {
        $cs = db()->prepare('SELECT full_name FROM users WHERE id = ?');
        $cs->execute([(int) $ticket['final_completed_by']]);
        $completedByName = (string) ($cs->fetchColumn() ?: '');
    }
    return [
        'ticket_id' => $ticketId,
        'ticket_number' => (string) $ticket['ticket_number'],
        'requester' => (string) ($ticket['created_name'] ?? ''),
        'account_route' => (string) ($ticket['account_route'] ?? ''),
        'routed_pillar' => (string) ($ticket['routed_pillar'] ?? ''),
        'routed_pillar_label' => sbs_pillar_label((string) ($ticket['routed_pillar'] ?? '')),
        'current_assignee' => (string) ($ticket['assignee_name'] ?? ''),
        'assigned_type' => (string) ($ticket['assigned_type'] ?? ''),
        'status' => (string) ($ticket['status_name'] ?? ''),
        'priority' => (string) ($ticket['priority_name'] ?? ''),
        'created_at' => (string) ($ticket['created_at'] ?? ''),
        'routed_at' => (string) ($ticket['routed_at'] ?? ''),
        'work_done_at' => (string) ($ticket['work_done_at'] ?? ''),
        'work_done_by' => $workDoneName,
        'completed_at' => (string) ($ticket['final_completed_at'] ?? $ticket['date_completed'] ?? ''),
        'completed_by' => $completedByName,
        'assignment_chain' => $chain,
        'chain_user_ids' => sbs_ticket_chain_user_ids($ticketId),
    ];
}
