<?php
declare(strict_types=1);

function comment_mentions_table_exists(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $st = db()->query('SHOW TABLES LIKE "comment_mentions"');
        $ok = (bool) $st->fetch();
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function ticket_mention_access_table_exists(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $st = db()->query('SHOW TABLES LIKE "ticket_mention_access"');
        $ok = (bool) $st->fetch();
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * Parse @mentions and notify users. Returns HTML-safe highlighted body.
 */
function comment_format_display(string $text): string
{
    $escaped = e($text);
    return (string) preg_replace(
        '/@([A-Za-z0-9_.-]+)/',
        '<span class="badge text-bg-light border mention-tag">@$1</span>',
        $escaped
    );
}

/**
 * @param list<int> $extraIds from hidden form fields (selected suggestions)
 * @return list<int>
 */
function comment_parse_mention_user_ids(string $body, array $extraIds = []): array
{
    $ids = [];
    foreach ($extraIds as $raw) {
        $id = (int) $raw;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    if (preg_match_all('/@([A-Za-z0-9_.-]+)/u', $body, $m)) {
        $pdo = db();
        foreach (array_unique($m[1]) as $token) {
            $token = (string) $token;
            if ($token === '') {
                continue;
            }
            $st = $pdo->prepare(
                'SELECT id FROM users
                 WHERE status = "active"
                   AND (
                     LOWER(username) = LOWER(?)
                     OR LOWER(full_name) = LOWER(?)
                     OR full_name LIKE ?
                   )
                 LIMIT 1'
            );
            $st->execute([$token, $token, $token]);
            $row = $st->fetch();
            if ($row) {
                $ids[(int) $row['id']] = (int) $row['id'];
            }
        }
    }

    return array_values($ids);
}

function ticket_user_has_mention_access(int $ticketId, int $userId): bool
{
    if ($ticketId < 1 || $userId < 1) {
        return false;
    }
    try {
        if (ticket_mention_access_table_exists()) {
            $st = db()->prepare(
                'SELECT 1 FROM ticket_mention_access WHERE ticket_id = ? AND user_id = ? LIMIT 1'
            );
            $st->execute([$ticketId, $userId]);
            if ($st->fetchColumn()) {
                return true;
            }
        }
        if (comment_mentions_table_exists()) {
            $st2 = db()->prepare(
                'SELECT 1 FROM comment_mentions WHERE ticket_id = ? AND mentioned_user_id = ? LIMIT 1'
            );
            $st2->execute([$ticketId, $userId]);
            return (bool) $st2->fetchColumn();
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

/**
 * Mention-only access (view/comment) without workflow roles on this ticket.
 *
 * @param array<string,mixed>|null $user
 */
function ticket_user_is_mention_only(int $ticketId, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    if (user_is_privileged($user)) {
        return false;
    }
    $uid = (int) $user['id'];
    if (ticket_user_involved($uid, $ticketId)) {
        return false;
    }
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return false;
    }
    $role = (string) ($user['role_key'] ?? '');
    if (in_array($role, ['hod', 'director'], true)) {
        $dept = (int) ($user['department_id'] ?? 0);
        if ($dept > 0 && (int) $ticket['department_id'] === $dept) {
            return false;
        }
    }
    if ($role === 'hod' || $role === 'director') {
        return false;
    }

    return ticket_user_has_mention_access($ticketId, $uid);
}

function mention_grant_ticket_access(int $ticketId, int $mentionedUserId, int $commentId, int $authorId): void
{
    if ($ticketId < 1 || $mentionedUserId < 1) {
        return;
    }
    if (!ticket_mention_access_table_exists()) {
        return;
    }
    try {
        db()->prepare(
            'INSERT IGNORE INTO ticket_mention_access (ticket_id, user_id, first_comment_id, mentioned_by_user_id)
             VALUES (?,?,?,?)'
        )->execute([$ticketId, $mentionedUserId, $commentId > 0 ? $commentId : null, $authorId > 0 ? $authorId : null]);
    } catch (Throwable $e) {
        error_log('mention_grant_ticket_access: ' . $e->getMessage());
    }
}

/**
 * @param list<int>|null $extraIds
 */
function comment_save_mentions(int $commentId, int $ticketId, string $body, int $authorId, ?array $extraIds = null): void
{
    $extraIds = $extraIds ?? [];
    if (isset($_POST['mention_user_ids']) && is_array($_POST['mention_user_ids'])) {
        foreach ($_POST['mention_user_ids'] as $raw) {
            $extraIds[] = (int) $raw;
        }
    }

    $ids = comment_parse_mention_user_ids($body, $extraIds);
    if (!$ids) {
        return;
    }

    $pdo = db();
    $ticket = ticket_fetch_by_id($ticketId);
    $num = $ticket ? (string) $ticket['ticket_number'] : '#' . $ticketId;
    $subject = $ticket ? (string) $ticket['subject'] : '';
    $author = current_user();
    $authorName = $author ? (string) ($author['full_name'] ?? 'Someone') : 'Someone';
    $detailUrl = 'ticket_detail.php?id=' . $ticketId;

    foreach ($ids as $mid) {
        if ($mid === $authorId) {
            continue;
        }
        try {
            if (comment_mentions_table_exists()) {
                $pdo->prepare(
                    'INSERT IGNORE INTO comment_mentions (comment_id, ticket_id, mentioned_user_id, mentioned_by_user_id)
                     VALUES (?,?,?,?)'
                )->execute([$commentId, $ticketId, $mid, $authorId]);
            }
            mention_grant_ticket_access($ticketId, $mid, $commentId, $authorId);
        } catch (Throwable $e) {
            error_log('comment_save_mentions insert: ' . $e->getMessage());
        }

        $title = 'You were mentioned on ' . $num;
        $message = $authorName . ' mentioned you in a comment on ' . $num;
        if ($subject !== '') {
            $message .= ': ' . $subject;
        }
        notify_user($mid, $title, $message, 'mention', $detailUrl);
    }
}
