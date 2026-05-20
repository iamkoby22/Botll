<?php
declare(strict_types=1);

/**
 * Parse assignee/approver rows from POST arrays.
 *
 * @return list<array{user_id:int,level:int}>
 */
function ticket_routing_parse_chain_from_post(string $userField, string $levelField): array
{
    $ids = isset($_POST[$userField]) && is_array($_POST[$userField]) ? $_POST[$userField] : [];
    $levels = isset($_POST[$levelField]) && is_array($_POST[$levelField]) ? $_POST[$levelField] : [];
    $ordered = [];
    $autoLvl = 1;
    foreach ($ids as $i => $rawId) {
        $uid = (int) $rawId;
        if ($uid < 1) {
            continue;
        }
        $lvl = (int) ($levels[$i] ?? $autoLvl);
        if ($lvl < 1) {
            $lvl = $autoLvl;
        }
        $ordered[] = ['user_id' => $uid, 'level' => $lvl];
        $autoLvl++;
    }
    if ($ordered) {
        usort($ordered, static fn ($a, $b) => $a['level'] <=> $b['level'] ?: $a['user_id'] <=> $b['user_id']);
    }
    return $ordered;
}

/**
 * @return array<int,bool> Active user IDs keyed by id.
 */
function ticket_routing_active_user_id_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    try {
        $st = db()->query('SELECT id FROM users WHERE status = "active"');
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $map[(int) $id] = true;
        }
    } catch (Throwable $e) {
        $map = [];
    }
    return $map;
}

/**
 * @param list<array{user_id:int,level:int}> $ordered
 */
function ticket_routing_validate_user_chain(array $ordered, string $label, array &$errors, bool $required = false): bool
{
    if (!$ordered) {
        if ($required) {
            $errors[] = 'Add at least one ' . $label . '.';
            return false;
        }
        return true;
    }

    $active = ticket_routing_active_user_id_map();
    $seenUser = [];
    $seenLevelUser = [];

    foreach ($ordered as $item) {
        $uid = (int) $item['user_id'];
        $lvl = (int) $item['level'];
        if ($lvl < 1) {
            $errors[] = $label . ' levels must be positive numbers.';
            return false;
        }
        if (empty($active[$uid])) {
            $errors[] = 'Selected ' . strtolower($label) . ' is not a valid active user.';
            return false;
        }
        $key = $lvl . ':' . $uid;
        if (isset($seenLevelUser[$key])) {
            $errors[] = 'Duplicate ' . strtolower($label) . ' at the same level is not allowed.';
            return false;
        }
        $seenLevelUser[$key] = true;
        if (isset($seenUser[$uid])) {
            $errors[] = 'The same person cannot appear more than once in the ' . strtolower($label) . ' chain.';
            return false;
        }
        $seenUser[$uid] = true;
    }

    return true;
}

/**
 * @param list<array{user_id:int,level:int}> $ordered
 */
function ticket_approval_save_chain(int $ticketId, array $ordered): void
{
    if ($ticketId < 1) {
        return;
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM ticket_approvals WHERE ticket_id = ?')->execute([$ticketId]);
    if (!$ordered) {
        return;
    }
    usort($ordered, static fn ($a, $b) => $a['level'] <=> $b['level'] ?: $a['user_id'] <=> $b['user_id']);
    $ins = $pdo->prepare(
        'INSERT INTO ticket_approvals (ticket_id, approver_id, approval_level, approval_status) VALUES (?,?,?,"pending")'
    );
    foreach ($ordered as $item) {
        $uid = (int) $item['user_id'];
        if ($uid < 1) {
            continue;
        }
        $lvl = (int) $item['level'];
        if ($lvl < 1) {
            $lvl = 1;
        }
        $ins->execute([$ticketId, $uid, $lvl]);
    }
}

/**
 * @param list<array{user_id:int,level:int}> $ordered
 */
function ticket_routing_notify_approval_chain(int $ticketId, string $ticketNumber, string $subject, array $ordered): void
{
    if (!$ordered) {
        return;
    }
    usort($ordered, static fn ($a, $b) => $a['level'] <=> $b['level']);
    $detailUrl = 'ticket_detail.php?id=' . $ticketId;
    $minLevel = (int) $ordered[0]['level'];
    foreach ($ordered as $item) {
        $aid = (int) $item['user_id'];
        $lvl = (int) $item['level'];
        if ($aid < 1) {
            continue;
        }
        if ($lvl === $minLevel) {
            notify_user(
                $aid,
                'Approval required',
                $ticketNumber . ' — ' . $subject,
                'approval',
                $detailUrl
            );
        } else {
            notify_user(
                $aid,
                'Approval chain — ' . $ticketNumber,
                'You are Level ' . $lvl . ' approver. You will be notified when prior levels complete.',
                'approval',
                $detailUrl
            );
        }
    }
}

/**
 * @param list<array{user_id:int,level:int}> $ordered
 */
function ticket_routing_notify_assignment_chain(int $ticketId, string $ticketNumber, array $ordered): void
{
    if (!$ordered) {
        return;
    }
    usort($ordered, static fn ($a, $b) => $a['level'] <=> $b['level']);
    $detailUrl = 'ticket_detail.php?id=' . $ticketId;
    $minLevel = (int) $ordered[0]['level'];
    foreach ($ordered as $item) {
        $aid = (int) $item['user_id'];
        $lvl = (int) $item['level'];
        if ($aid < 1) {
            continue;
        }
        $msg = $lvl === $minLevel
            ? 'You are Level ' . $lvl . ' on ' . $ticketNumber . ' — you can start work now.'
            : 'You are Level ' . $lvl . ' on ' . $ticketNumber . ' — you will be notified when it is your turn.';
        notify_user($aid, 'New ticket assigned', $msg, 'assignment', $detailUrl);
    }
}
