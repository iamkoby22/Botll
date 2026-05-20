<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

$failed = 0;

echo 'Tables: comment_mentions=' . (comment_mentions_table_exists() ? 'yes' : 'no');
echo ' ticket_mention_access=' . (ticket_mention_access_table_exists() ? 'yes' : 'no') . "\n";

$ids = comment_parse_mention_user_ids('Hello @admin and @Admin again', []);
echo 'Parse @admin (case): ' . count($ids) . " ids\n";
if (count($ids) < 1) {
    echo "FAIL: expected at least one user from @admin\n";
    $failed++;
}

$dup = comment_parse_mention_user_ids('@admin @admin', []);
if (count($dup) !== count(array_unique($dup))) {
    echo "FAIL: duplicate parse\n";
    $failed++;
} else {
    echo "OK duplicate tokens deduped\n";
}

// Approval lock: find ticket with assignees + approvals
$row = db()->query(
    'SELECT t.id FROM tickets t
     WHERE EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id)
       AND EXISTS (SELECT 1 FROM ticket_approvals tap WHERE tap.ticket_id = t.id)
     ORDER BY t.id DESC LIMIT 1'
)->fetch();
if ($row) {
    $tid = (int) $row['id'];
    $complete = ticket_work_is_complete_for_approval($tid);
    echo "Ticket $tid work_complete_for_approval=" . ($complete ? '1' : '0') . "\n";
    $approver = db()->prepare('SELECT approver_id FROM ticket_approvals WHERE ticket_id = ? AND approval_status="pending" ORDER BY approval_level LIMIT 1');
    $approver->execute([$tid]);
    $aid = (int) ($approver->fetchColumn() ?: 0);
    if ($aid > 0) {
        $ctx = ticket_approval_actor_context($tid, $aid);
        echo 'Approver can_decide=' . (!empty($ctx['can_decide']) ? '1' : '0');
        echo ' locked=' . (!empty($ctx['approval_locked']) ? '1' : '0') . "\n";
        if (!$complete && !empty($ctx['can_decide'])) {
            echo "FAIL: approver can_decide before work complete\n";
            $failed++;
        }
        if (!$complete && empty($ctx['approval_locked'])) {
            echo "FAIL: approval_locked should be true\n";
            $failed++;
        }
        if ($complete || empty($ctx['can_decide'])) {
            echo "OK approval lock enforced for incomplete work\n";
        }
    }
} else {
    echo "SKIP: no ticket with both assignees and approvals\n";
}

// Mention access helper
$ma = db()->query('SELECT ticket_id, user_id FROM ticket_mention_access LIMIT 1')->fetch();
if ($ma) {
    $tid = (int) $ma['ticket_id'];
    $mid = (int) $ma['user_id'];
    $view = ticket_can_view($tid) ? 'yes' : 'no';
    $only = ticket_user_is_mention_only($tid) ? 'yes' : 'no';
    echo "Mention user $mid on ticket $tid: can_view=$view mention_only=$only\n";
} else {
    echo "SKIP: no ticket_mention_access rows\n";
}

exit($failed > 0 ? 1 : 0);
