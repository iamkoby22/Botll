<?php
declare(strict_types=1);

/**
 * Multi-level assignment workflow (ticket_assignees), separate from ticket_approvals.
 */

function ticket_assignees_have_assignment_level_column(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $st = db()->query('SHOW COLUMNS FROM ticket_assignees LIKE "assignment_level"');
        $has = (bool) $st->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

function ticket_assignees_have_workflow_columns(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $st = db()->query('SHOW COLUMNS FROM ticket_assignees LIKE "assignment_status"');
        $has = (bool) $st->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * Safe level number for one assignee row (never throws undefined index).
 *
 * @param array<string,mixed> $row
 */
function ticket_assignment_row_level(array $row, int $position = 1): int
{
    if (isset($row['assignment_level']) && (int) $row['assignment_level'] > 0) {
        return (int) $row['assignment_level'];
    }
    if (isset($row['sort_order']) && (int) $row['sort_order'] > 0) {
        return (int) $row['sort_order'];
    }
    return max(1, $position);
}

/**
 * @param array<string,mixed> $row
 */
function ticket_assignment_row_sort_order(array $row, int $position = 1): int
{
    if (isset($row['sort_order']) && (int) $row['sort_order'] > 0) {
        return (int) $row['sort_order'];
    }
    return ticket_assignment_row_level($row, $position);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function ticket_assignment_normalize_rows(array $rows): array
{
    $out = [];
    $pos = 1;
    foreach ($rows as $row) {
        $lvl = ticket_assignment_row_level($row, $pos);
        $sort = ticket_assignment_row_sort_order($row, $pos);
        $row['assignment_level'] = $lvl;
        $row['sort_order'] = $sort;
        if (!isset($row['assignment_status']) || (string) $row['assignment_status'] === '') {
            $row['assignment_status'] = 'pending';
        }
        $out[] = $row;
        $pos++;
    }
    return $out;
}

function ticket_assignment_last_error(): string
{
    return (string) ($GLOBALS['_ticket_assignment_last_error'] ?? '');
}

function ticket_assignment_set_error(string $message): void
{
    $GLOBALS['_ticket_assignment_last_error'] = $message;
    error_log('assignment_error: ' . $message);
}

function ticket_assignment_row_is_completed(string $status): bool
{
    return in_array($status, ['approved', 'done', 'completed'], true);
}

function ticket_assignment_row_is_pending(string $status): bool
{
    return in_array($status, ['pending', 'waiting', ''], true);
}

/**
 * @param list<array<string,mixed>> $rows
 */
function ticket_assignment_rows_status_summary(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = sprintf(
            'id=%d:sort=%d:st=%s',
            (int) $r['id'],
            ticket_assignment_row_sort_order($r),
            (string) ($r['assignment_status'] ?? '')
        );
    }
    return implode(';', $parts);
}

/**
 * Load assignee chain without running repair (safe during final close).
 *
 * @return list<array<string,mixed>>
 */
function ticket_assignment_fetch_chain_rows(int $ticketId): array
{
    $hasLevelCol = ticket_assignees_have_assignment_level_column();
    $levelExpr = $hasLevelCol
        ? 'COALESCE(NULLIF(ta.assignment_level, 0), NULLIF(ta.sort_order, 0), 1) AS assignment_level'
        : 'COALESCE(NULLIF(ta.sort_order, 0), 1) AS assignment_level';

    $sql = 'SELECT ta.*, u.full_name, u.username, ' . $levelExpr . '
            FROM ticket_assignees ta
            JOIN users u ON u.id = ta.user_id
            WHERE ta.ticket_id = ?
            ORDER BY ta.sort_order ASC, ta.id ASC';

    try {
        $st = db()->prepare($sql);
        $st->execute([$ticketId]);
        return ticket_assignment_normalize_rows($st->fetchAll() ?: []);
    } catch (Throwable $e) {
        $st = db()->prepare(
            'SELECT ta.*, u.full_name, u.username, ta.sort_order AS assignment_level
             FROM ticket_assignees ta JOIN users u ON u.id = ta.user_id
             WHERE ta.ticket_id = ? ORDER BY ta.sort_order ASC, ta.id ASC'
        );
        $st->execute([$ticketId]);
        return ticket_assignment_normalize_rows($st->fetchAll() ?: []);
    }
}

/**
 * Repair only open in-progress chains that lost their active row (never reset passed/done levels).
 */
function ticket_assignment_ensure_active_level(int $ticketId): void
{
    if (!ticket_assignees_have_workflow_columns()) {
        return;
    }
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket) {
        return;
    }
    $statusName = (string) ($ticket['status_name'] ?? '');
    if (in_array($statusName, ['Closed', 'Completed', 'Rejected', 'Cancelled'], true)) {
        return;
    }
    if (ticket_work_is_marked_done($ticket) || ticket_conversation_is_closed($ticket)) {
        return;
    }

    $rows = ticket_assignment_fetch_chain_rows($ticketId);
    if (count($rows) < 1) {
        return;
    }

    ticket_assignment_normalize_single_active($ticketId, $rows);
    $rows = ticket_assignment_fetch_chain_rows($ticketId);

    foreach ($rows as $r) {
        if ((string) ($r['assignment_status'] ?? '') === 'active') {
            return;
        }
    }

    $sorted = $rows;
    usort(
        $sorted,
        static fn ($a, $b) => ticket_assignment_row_sort_order($a) <=> ticket_assignment_row_sort_order($b)
    );

    $hasCompleted = false;
    $pendingRows = [];
    foreach ($sorted as $r) {
        $st = (string) ($r['assignment_status'] ?? 'pending');
        if (ticket_assignment_row_is_completed($st)) {
            $hasCompleted = true;
        }
        if (ticket_assignment_row_is_pending($st)) {
            $pendingRows[] = $r;
        }
    }

    if (!$pendingRows) {
        $allTerminal = true;
        foreach ($sorted as $r) {
            $st = (string) ($r['assignment_status'] ?? '');
            if (!ticket_assignment_row_is_completed($st) && $st !== 'done') {
                $allTerminal = false;
                break;
            }
        }
        if ($allTerminal && $hasCompleted) {
            error_log(
                'assignment_repair ticket_id=' . $ticketId
                . ' all levels complete but ticket still open; not reactivating Level 1'
            );
        }
        return;
    }

    $activate = null;
    if ($hasCompleted) {
        foreach ($pendingRows as $candidate) {
            $sort = ticket_assignment_row_sort_order($candidate);
            $ready = true;
            foreach ($sorted as $prior) {
                if (ticket_assignment_row_sort_order($prior) >= $sort) {
                    break;
                }
                if (!ticket_assignment_row_is_completed((string) ($prior['assignment_status'] ?? ''))) {
                    $ready = false;
                    break;
                }
            }
            if ($ready) {
                $activate = $candidate;
                break;
            }
        }
        if (!$activate) {
            return;
        }
    } else {
        $activate = $sorted[0];
        if (!ticket_assignment_row_is_pending((string) ($activate['assignment_status'] ?? 'pending'))) {
            return;
        }
    }

    $pdo = db();
    $activateId = (int) $activate['id'];
    $pdo->prepare(
        'UPDATE ticket_assignees SET assignment_status = ? WHERE id = ? AND ticket_id = ?'
    )->execute(['active', $activateId, $ticketId]);
    $pdo->prepare('UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?')->execute(
        [(int) $activate['user_id'], $ticketId]
    );
    error_log(
        'assignment_repair ticket_id=' . $ticketId . ' activated assignee row id=' . $activateId
        . ' sort=' . ticket_assignment_row_sort_order($activate)
    );
}

/**
 * @return list<array<string,mixed>>
 */
function ticket_assignment_chain_rows(int $ticketId): array
{
    $rows = ticket_assignment_fetch_chain_rows($ticketId);
    if (count($rows) >= 1 && ticket_assignees_have_workflow_columns()) {
        ticket_assignment_ensure_active_level($ticketId);
        return ticket_assignment_fetch_chain_rows($ticketId);
    }
    return $rows;
}

/**
 * True when the ticket has one or more structured assignment rows in ticket_assignees.
 */
function ticket_assignment_has_chain(int $ticketId): bool
{
    return count(ticket_assignment_fetch_chain_rows($ticketId)) >= 1;
}

/**
 * True when multiple assignment levels exist (pass-to-next workflow).
 */
function ticket_assignment_is_multi_level_chain(int $ticketId): bool
{
    return count(ticket_assignment_fetch_chain_rows($ticketId)) > 1;
}

function ticket_user_is_active_assignee(int $ticketId, int $userId): bool
{
    if (!ticket_assignment_has_chain($ticketId)) {
        $st = db()->prepare('SELECT assigned_to FROM tickets WHERE id = ? LIMIT 1');
        $st->execute([$ticketId]);
        $t = $st->fetch();
        return $t && (int) ($t['assigned_to'] ?? 0) === $userId;
    }
    $active = ticket_assignment_active_row($ticketId);
    return $active && (int) $active['user_id'] === $userId;
}

/**
 * @return array{awaiting_level:int,stale_level:int,reopened:int,by_level:array<int,int>}
 */
function ticket_assignment_workflow_metrics(string $scopeSql): array
{
    $pdo = db();
    $awaiting = 0;
    $stale = 0;
    $reopened = 0;
    $byLevel = [1 => 0, 2 => 0, 3 => 0];
    try {
        $awaiting = (int) $pdo->query(
            'SELECT COUNT(DISTINCT t.id) c FROM tickets t
             JOIN ticket_assignees ta ON ta.ticket_id = t.id AND ta.assignment_status = "active"
             WHERE ' . $scopeSql
        )->fetch()['c'];
        $stale = (int) $pdo->query(
            'SELECT COUNT(DISTINCT t.id) c FROM tickets t
             JOIN ticket_assignees ta ON ta.ticket_id = t.id AND ta.assignment_status = "active"
             WHERE ' . $scopeSql . ' AND ta.acted_at IS NULL AND t.updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY)'
        )->fetch()['c'];
        $reopened = (int) $pdo->query(
            'SELECT COUNT(*) c FROM tickets t WHERE ' . $scopeSql . ' AND t.reopened_at IS NOT NULL'
        )->fetch()['c'];
        $lv = $pdo->query(
            'SELECT COALESCE(NULLIF(ta.assignment_level, 0), NULLIF(ta.sort_order, 0), 1) lvl, COUNT(DISTINCT t.id) c
             FROM tickets t
             JOIN ticket_assignees ta ON ta.ticket_id = t.id AND ta.assignment_status = "active"
             WHERE ' . $scopeSql . ' GROUP BY lvl'
        )->fetchAll();
        foreach ($lv as $r) {
            $byLevel[(int) $r['lvl']] = (int) $r['c'];
        }
    } catch (Throwable $e) {
        /* pre-migration */
    }
    return ['awaiting_level' => $awaiting, 'stale_level' => $stale, 'reopened' => $reopened, 'by_level' => $byLevel];
}

function assignment_status_badge(string $status): string
{
    return match ($status) {
        'active' => 'primary',
        'approved' => 'success',
        'done' => 'success',
        'pending' => 'secondary',
        default => 'light',
    };
}

function assignment_status_label(string $status): string
{
    return match ($status) {
        'active' => 'Active',
        'approved' => 'Passed',
        'done' => 'Done',
        'pending' => 'Waiting',
        default => ucfirst($status),
    };
}

/**
 * @return array<string,mixed>|null
 */
function ticket_assignment_active_row(int $ticketId): ?array
{
    $rows = ticket_assignment_chain_rows($ticketId);
    if (!$rows) {
        return null;
    }
    if (!ticket_assignees_have_workflow_columns()) {
        return $rows[0];
    }
    foreach ($rows as $row) {
        if ((string) ($row['assignment_status'] ?? '') === 'active') {
            return $row;
        }
    }
    return null;
}

/**
 * @param list<array<string,mixed>> $rows
 */
function ticket_assignment_max_sort_order(array $rows): int
{
    $max = 0;
    foreach ($rows as $i => $r) {
        $max = max($max, ticket_assignment_row_sort_order($r, $i + 1));
    }
    return $max > 0 ? $max : count($rows);
}

/**
 * Next chain step after the active row (by sort_order), if any step is not completed.
 *
 * @param array<string,mixed>|null $active
 * @param list<array<string,mixed>> $rows
 */
function ticket_assignment_find_next_incomplete_row(?array $active, array $rows): ?array
{
    if (!$active || !$rows) {
        return null;
    }
    $sorted = $rows;
    usort(
        $sorted,
        static fn ($a, $b) => ticket_assignment_row_sort_order($a) <=> ticket_assignment_row_sort_order($b)
    );
    $activeId = (int) $active['id'];
    $seenActive = false;
    foreach ($sorted as $r) {
        if ((int) $r['id'] === $activeId) {
            $seenActive = true;
            continue;
        }
        if ($seenActive && !ticket_assignment_row_is_completed((string) ($r['assignment_status'] ?? 'pending'))) {
            return $r;
        }
    }
    return null;
}

/**
 * Keep a single active row — lowest sort_order wins when repair/pass races duplicate actives.
 *
 * @param list<array<string,mixed>> $rows
 */
function ticket_assignment_normalize_single_active(int $ticketId, array $rows): void
{
    if (!ticket_assignees_have_workflow_columns()) {
        return;
    }
    $activeRows = [];
    foreach ($rows as $r) {
        if ((string) ($r['assignment_status'] ?? '') === 'active') {
            $activeRows[] = $r;
        }
    }
    if (count($activeRows) <= 1) {
        return;
    }
    usort(
        $activeRows,
        static fn ($a, $b) => ticket_assignment_row_sort_order($a) <=> ticket_assignment_row_sort_order($b)
    );
    $keepId = (int) $activeRows[0]['id'];
    $pdo = db();
    foreach ($activeRows as $i => $r) {
        if ($i === 0) {
            continue;
        }
        $pdo->prepare(
            'UPDATE ticket_assignees SET assignment_status = ? WHERE id = ? AND ticket_id = ? AND assignment_status = ?'
        )->execute(['pending', (int) $r['id'], $ticketId, 'active']);
        error_log(
            'assignment_normalize ticket_id=' . $ticketId . ' deactivated duplicate active row id=' . (int) $r['id']
        );
    }
}

/**
 * True when the active row is the last step in the ordered assignment chain.
 *
 * @param array<string,mixed>|null $active
 * @param list<array<string,mixed>> $rows
 */
function ticket_assignment_is_final_row(?array $active, array $rows): bool
{
    if (!$active || !$rows) {
        return false;
    }
    if (count($rows) === 1) {
        return (int) $active['id'] === (int) $rows[0]['id'];
    }
    $sorted = $rows;
    usort(
        $sorted,
        static fn ($a, $b) => ticket_assignment_row_sort_order($a) <=> ticket_assignment_row_sort_order($b)
    );
    $last = $sorted[count($sorted) - 1];

    return (int) $active['id'] === (int) $last['id'];
}

/**
 * @param array<string,mixed> $ctx
 */
function ticket_assignment_log_done_action(int $ticketId, int $userId, array $ctx): void
{
    $active = $ctx['active_row'] ?? null;
    $rows = ticket_assignment_fetch_chain_rows($ticketId);
    $isFinalRow = $active ? ticket_assignment_is_final_row($active, $rows) : false;
    $ticket = ticket_fetch_by_id($ticketId);
    $line = sprintf(
        'assignment_done ticket_id=%d user_id=%d ticket_status=%s rows_before=[%s] active_id=%s active_user=%s active_sort=%s active_level=%s max_sort=%s status=%s is_active=%s is_final=%s is_final_row=%s can_pass=%s can_mark_done=%s can_level_done=%s',
        $ticketId,
        $userId,
        $ticket ? (string) ($ticket['status_name'] ?? '') : 'null',
        ticket_assignment_rows_status_summary($rows),
        $active ? (string) (int) $active['id'] : 'null',
        $active ? (string) (int) ($active['user_id'] ?? 0) : 'null',
        $active ? (string) ticket_assignment_row_sort_order($active) : 'null',
        $active ? (string) ticket_assignment_row_level($active) : 'null',
        (string) ticket_assignment_max_sort_order($rows),
        $active ? (string) ($active['assignment_status'] ?? '') : 'null',
        !empty($ctx['is_active_assignee']) ? '1' : '0',
        !empty($ctx['is_final_level']) ? '1' : '0',
        $isFinalRow ? '1' : '0',
        !empty($ctx['can_pass']) ? '1' : '0',
        !empty($ctx['can_mark_done']) ? '1' : '0',
        !empty($ctx['can_level_done']) ? '1' : '0'
    );
    error_log($line);
}

/**
 * @param list<array<string,mixed>> $rows
 */
function ticket_assignment_log_final_close(
    int $ticketId,
    int $userId,
    string $phase,
    array $rows,
    bool $markWorkResult,
    ?string $exception = null
): void {
    $ticket = ticket_fetch_by_id($ticketId);
    error_log(sprintf(
        'assignment_final_%s ticket_id=%d user_id=%d ticket_status=%s work_done_at=%s rows=[%s] mark_work_done=%s exception=%s',
        $phase,
        $ticketId,
        $userId,
        $ticket ? (string) ($ticket['status_name'] ?? '') : 'null',
        $ticket && !empty($ticket['work_done_at']) ? '1' : '0',
        ticket_assignment_rows_status_summary($rows),
        $markWorkResult ? '1' : '0',
        $exception ?? 'none'
    ));
}

/**
 * @param list<array{user_id:int,level:int}> $ordered
 */
function ticket_assignment_save_chain(int $ticketId, array $ordered): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM ticket_assignees WHERE ticket_id = ?')->execute([$ticketId]);
    if (!$ordered) {
        return;
    }
    usort($ordered, static fn ($a, $b) => $a['level'] <=> $b['level']);
    $primary = null;
    $levelNum = 1;
    foreach ($ordered as $item) {
        $uid = (int) $item['user_id'];
        if ($uid < 1) {
            continue;
        }
        $lvl = (int) ($item['level'] ?? $levelNum);
        if ($lvl < 1) {
            $lvl = $levelNum;
        }
        $status = $levelNum === 1 ? 'active' : 'pending';
        $inserted = false;
        if (ticket_assignees_have_workflow_columns() && ticket_assignees_have_assignment_level_column()) {
            try {
                $pdo->prepare(
                    'INSERT INTO ticket_assignees (ticket_id, user_id, approval_status, sort_order, assignment_level, assignment_status)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$ticketId, $uid, 'pending', $levelNum, $lvl, $status]);
                $inserted = true;
            } catch (Throwable $e) {
                $inserted = false;
            }
        }
        if (!$inserted) {
            $pdo->prepare(
                'INSERT INTO ticket_assignees (ticket_id, user_id, approval_status, sort_order) VALUES (?,?,?,?)'
            )->execute([$ticketId, $uid, 'pending', $levelNum]);
            if (ticket_assignees_have_workflow_columns()) {
                try {
                    $pdo->prepare(
                        'UPDATE ticket_assignees SET assignment_status = ?, assignment_level = ? WHERE ticket_id = ? AND user_id = ?'
                    )->execute([$status, $lvl, $ticketId, $uid]);
                } catch (Throwable $e) {
                    /* ignore */
                }
            }
        }
        if ($primary === null) {
            $primary = $uid;
        }
        $levelNum++;
    }
    if ($primary !== null) {
        $pdo->prepare('UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?')->execute([$primary, $ticketId]);
    }
}

/**
 * @return array<string,mixed>
 */
function ticket_assignment_actor_context(int $ticketId, int $userId, ?array $ticket = null): array
{
    $rows = ticket_assignment_chain_rows($ticketId);
    $ticket = $ticket ?? ticket_fetch_by_id($ticketId);
    $closed = $ticket && ticket_conversation_is_closed($ticket);
    $workerRoles = in_array(actor_role_key(), ['user', 'hod', 'director'], true);
    $isWorker = ticket_user_is_assigned_worker($ticketId, $userId);

    if (!$rows || !ticket_assignment_has_chain($ticketId)) {
        return [
            'has_chain' => false,
            'is_multi_level' => false,
            'is_active_assignee' => $isWorker && !$closed,
            'is_final_level' => true,
            'can_pass' => false,
            'can_mark_done' => $isWorker && !$closed && $workerRoles,
            'can_level_done' => $isWorker && !$closed && $workerRoles,
            'active_row' => null,
            'total_levels' => $isWorker ? 1 : 0,
            'is_chain_member' => $isWorker,
        ];
    }

    $active = ticket_assignment_active_row($ticketId);
    $activeUid = $active ? (int) $active['user_id'] : 0;
    $isActive = $activeUid === $userId;
    $activeLevel = $active ? ticket_assignment_row_level($active) : 0;
    $isFinalRow = $isActive && $active && ticket_assignment_is_final_row($active, $rows);
    $isFinal = $isFinalRow;
    $canAct = $isActive && !$closed && $workerRoles;
    $canPass = $canAct && !$isFinal && ticket_assignment_find_next_incomplete_row($active, $rows) !== null;
    $canMarkDone = $canAct && $isFinal;

    return [
        'has_chain' => true,
        'is_multi_level' => ticket_assignment_is_multi_level_chain($ticketId),
        'is_active_assignee' => $isActive,
        'is_final_level' => $isFinal,
        'is_final_row' => $isFinalRow,
        'can_pass' => $canPass,
        'can_mark_done' => $canMarkDone,
        'can_level_done' => $canPass || $canMarkDone,
        'active_row' => $active,
        'total_levels' => ticket_assignment_max_sort_order($rows),
        'current_level' => $activeLevel,
        'is_chain_member' => $isWorker,
    ];
}

function ticket_assignment_pass_to_next(int $ticketId, int $userId, string $remarks = ''): bool
{
    $rows = ticket_assignment_fetch_chain_rows($ticketId);
    ticket_assignment_normalize_single_active($ticketId, $rows);
    $rows = ticket_assignment_fetch_chain_rows($ticketId);
    $ctx = ticket_assignment_actor_context($ticketId, $userId);
    if (
        !$ctx['can_pass']
        || !$ctx['active_row']
        || ticket_assignment_is_final_row($ctx['active_row'], $rows)
    ) {
        return false;
    }
    $pdo = db();
    $activeRow = $ctx['active_row'];
    $rowId = (int) $activeRow['id'];
    $level = ticket_assignment_row_level($activeRow);
    $next = ticket_assignment_find_next_incomplete_row($activeRow, $rows);
    if (!$next) {
        return false;
    }
    try {
        $pdo->prepare(
            'UPDATE ticket_assignees SET assignment_status = ?, acted_at = NOW(), remarks = ? WHERE id = ? AND ticket_id = ?'
        )->execute(['approved', $remarks !== '' ? $remarks : null, $rowId, $ticketId]);
        $pdo->prepare(
            'UPDATE ticket_assignees SET assignment_status = ? WHERE ticket_id = ? AND assignment_status = ? AND id != ?'
        )->execute(['pending', $ticketId, 'active', (int) $next['id']]);
        $pdo->prepare(
            'UPDATE ticket_assignees SET assignment_status = ? WHERE id = ? AND ticket_id = ?'
        )->execute(['active', (int) $next['id'], $ticketId]);
    } catch (Throwable $e) {
        return false;
    }
    $pdo->prepare('UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $next['user_id'], $ticketId]);
    $nextLevel = ticket_assignment_row_level($next);
    ticket_log_history($ticketId, $userId, 'assignment_level', 'Level ' . $level, 'Level ' . $nextLevel . ' active');
    $msg = 'Level ' . $level . ' approved — your turn on ticket ' . (ticket_fetch_by_id($ticketId)['ticket_number'] ?? '');
    notify_user((int) $next['user_id'], 'Assignment level activated', $msg, 'assignment', 'ticket_detail.php?id=' . $ticketId);
    ticket_log_comment($ticketId, $userId, 'Approved and passed to next level (Level ' . $level . ').', 'system');
    return true;
}

function ticket_assignment_mark_final_done(int $ticketId, int $userId, string $note = ''): bool
{
    ticket_assignment_set_error('');
    $rowsBefore = ticket_assignment_fetch_chain_rows($ticketId);
    $ctx = ticket_assignment_actor_context($ticketId, $userId);
    $activeRow = $ctx['active_row'] ?? null;
    if (!$activeRow && !empty($ctx['has_chain'])) {
        ticket_assignment_ensure_active_level($ticketId);
        $activeRow = ticket_assignment_active_row($ticketId);
    }
    $isFinalRow = $activeRow && ticket_assignment_is_final_row($activeRow, $rowsBefore);
    $mayClose = !empty($ctx['can_mark_done'])
        || (!empty($ctx['is_active_assignee']) && $isFinalRow && in_array(actor_role_key(), ['user', 'hod', 'director'], true));
    if (!$mayClose) {
        ticket_assignment_set_error('You are not the active final assignee for this ticket.');
        return false;
    }

    if (!$activeRow) {
        if (!empty($ctx['has_chain'])) {
            ticket_assignment_set_error('You are not the active final assignee for this ticket.');
            return false;
        }
        ticket_assignment_log_final_close($ticketId, $userId, 'before', $rowsBefore, false, null);
        if (!ticket_mark_work_done($ticketId, $userId, $note, true)) {
            ticket_assignment_set_error('Could not set ticket to Closed. Please contact an administrator.');
            return false;
        }
        ticket_assignment_log_final_close($ticketId, $userId, 'after', $rowsBefore, true, null);
        return true;
    }

    $activeRowId = (int) $activeRow['id'];
    ticket_assignment_log_final_close($ticketId, $userId, 'before', $rowsBefore, false, null);

    $pdo = db();
    $closedOk = false;
    $excMsg = null;
    try {
        $pdo->beginTransaction();
        // Close ticket while final row is still active — do not mark assignee done first.
        $closedOk = ticket_mark_work_done($ticketId, $userId, $note, true);
        if (!$closedOk) {
            throw new RuntimeException('ticket_mark_work_done returned false');
        }
        $pdo->prepare(
            'UPDATE ticket_assignees SET assignment_status = ?, acted_at = NOW() WHERE id = ? AND ticket_id = ?'
        )->execute(['done', $activeRowId, $ticketId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $excMsg = $e->getMessage();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log(
            'assignment_final_close failed ticket_id=' . $ticketId . ' user_id=' . $userId . ' ' . $excMsg
        );
        ticket_assignment_set_error(
            'Could not close this ticket for audit. The assignment chain was not reset. Please contact an administrator.'
        );
        ticket_assignment_log_final_close($ticketId, $userId, 'failed', $rowsBefore, false, $excMsg);
        return false;
    }

    $rowsAfter = ticket_assignment_fetch_chain_rows($ticketId);
    ticket_assignment_log_final_close($ticketId, $userId, 'after', $rowsAfter, $closedOk, null);

    if (!$closedOk) {
        ticket_assignment_set_error('Could not set ticket to Closed. Please contact an administrator.');
        return false;
    }

    return true;
}

function ticket_assignment_level_done(int $ticketId, int $userId, string $note = ''): bool
{
    ticket_assignment_set_error('');
    $rowsBefore = ticket_assignment_fetch_chain_rows($ticketId);
    $ctx = ticket_assignment_actor_context($ticketId, $userId);
    ticket_assignment_log_done_action($ticketId, $userId, $ctx);

    $activeForDone = $ctx['active_row'] ?? ticket_assignment_active_row($ticketId);
    $isFinalRow = $activeForDone && ticket_assignment_is_final_row($activeForDone, $rowsBefore);

    if (!empty($ctx['can_pass']) && !$isFinalRow) {
        return ticket_assignment_pass_to_next($ticketId, $userId, $note);
    }
    if (!empty($ctx['can_mark_done']) || (!empty($ctx['is_active_assignee']) && $isFinalRow)) {
        $ok = ticket_assignment_mark_final_done($ticketId, $userId, $note);
        if (!$ok && ticket_assignment_last_error() === '') {
            ticket_assignment_set_error('Could not complete final assignment level.');
        }
        return $ok;
    }
    ticket_assignment_set_error('You cannot complete this assignment level right now.');
    return false;
}

function ticket_super_admin_reopen(int $ticketId, int $userId): bool
{
    if (!is_super_admin_role()) {
        return false;
    }
    $ticket = ticket_fetch_by_id($ticketId);
    if (!$ticket || !ticket_conversation_is_closed($ticket)) {
        return false;
    }
    $openId = status_id_by_name('Open');
    if (!$openId) {
        return false;
    }
    $pdo = db();
    try {
        $pdo->prepare(
            'UPDATE tickets SET status_id = ?, conversation_closed_at = NULL, work_done_at = NULL, work_done_by = NULL,
             completion_note = NULL, reopened_at = NOW(), reopened_by = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$openId, $userId, $ticketId]);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE tickets SET status_id = ?, updated_at = NOW() WHERE id = ?')->execute([$openId, $ticketId]);
    }
    $rows = ticket_assignment_chain_rows($ticketId);
    if (count($rows) > 1 && ticket_assignees_have_workflow_columns()) {
        $minSort = ticket_assignment_max_sort_order($rows);
        foreach ($rows as $r) {
            $sort = ticket_assignment_row_sort_order($r);
            $st = $sort === $minSort || $sort === 1 ? 'active' : 'pending';
            try {
                $pdo->prepare(
                    'UPDATE ticket_assignees SET assignment_status = ?, acted_at = NULL, remarks = NULL WHERE id = ?'
                )->execute([$st, (int) $r['id']]);
            } catch (Throwable $e) {
                /* ignore */
            }
        }
    }
    $active = ticket_assignment_active_row($ticketId);
    if ($active) {
        $pdo->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ?')->execute([(int) $active['user_id'], $ticketId]);
    }
    ticket_log_history($ticketId, $userId, 'status', (string) $ticket['status_name'], 'Open (reopened)');
    ticket_log_comment($ticketId, $userId, 'Ticket reopened by Super Admin.', 'system');
    $url = 'ticket_detail.php?id=' . $ticketId;
    $num = (string) $ticket['ticket_number'];
    notify_user((int) $ticket['created_by'], 'Ticket reopened — ' . $num, 'A Super Admin reopened this ticket for further work.', 'info', $url);
    foreach (ticket_assignment_chain_rows($ticketId) as $r) {
        notify_user((int) $r['user_id'], 'Ticket reopened — ' . $num, 'This ticket was reopened for further assignment work.', 'info', $url);
    }
    return true;
}

function ref_table_has_is_active(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $st = db()->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE "is_active"');
        $cache[$table] = (bool) $st->fetch();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

/**
 * @return list<array<string,mixed>>
 */
function ref_active_departments(): array
{
    $sql = ref_table_has_is_active('departments')
        ? 'SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name'
        : 'SELECT * FROM departments ORDER BY department_name';
    return db()->query($sql)->fetchAll();
}

/**
 * @return list<array<string,mixed>>
 */
function ref_active_categories(): array
{
    $sql = ref_table_has_is_active('ticket_categories')
        ? 'SELECT * FROM ticket_categories WHERE is_active = 1 ORDER BY category_name'
        : 'SELECT * FROM ticket_categories ORDER BY category_name';
    return db()->query($sql)->fetchAll();
}

/**
 * @return list<array<string,mixed>>
 */
function ref_active_priorities(): array
{
    $sql = ref_table_has_is_active('ticket_priorities')
        ? 'SELECT * FROM ticket_priorities WHERE is_active = 1 ORDER BY priority_level'
        : 'SELECT * FROM ticket_priorities ORDER BY priority_level';
    return db()->query($sql)->fetchAll();
}

function hod_department_warning(): ?string
{
    $u = current_user();
    if (!$u || !in_array((string) $u['role_key'], ['hod', 'director'], true)) {
        return null;
    }
    if ((int) ($u['department_id'] ?? 0) > 0) {
        return null;
    }
    return 'Your account has no department assigned. Dashboard and reports are limited until an administrator sets your department.';
}
