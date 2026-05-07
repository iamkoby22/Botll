<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    redirect('dashboard.php');
}

$uid = (int) current_user()['id'];
$row = null;
try {
    $stmt = db()->prepare('SELECT id, action_url, is_read, related_ticket_id, message FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    try {
        $stmt = db()->prepare('SELECT id, action_url, is_read, message FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $uid]);
        $row = $stmt->fetch();
        if ($row) {
            $row['related_ticket_id'] = null;
        }
    } catch (Throwable $e2) {
        $stmt = db()->prepare('SELECT id, is_read FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $uid]);
        $row = $stmt->fetch();
        if ($row) {
            $row['action_url'] = null;
            $row['related_ticket_id'] = null;
            $row['message'] = '';
        }
    }
}
if (!$row) {
    redirect('dashboard.php');
}

if (empty($row['is_read'])) {
    mark_notification_read($id, $uid);
}

$url = trim((string) ($row['action_url'] ?? ''));
if ($url !== '' && !str_contains($url, '://')) {
    redirect($url);
}

$tid = (int) ($row['related_ticket_id'] ?? 0);
if ($tid > 0 && ticket_can_view($tid)) {
    redirect('ticket_detail.php?id=' . $tid);
}

$msg = (string) ($row['message'] ?? '');
if (preg_match('/TKT-\d{4}-\d+/i', $msg, $m)) {
    $st = db()->prepare('SELECT id FROM tickets WHERE ticket_number = ? LIMIT 1');
    $st->execute([$m[0]]);
    $found = $st->fetch();
    if ($found && ticket_can_view((int) $found['id'])) {
        redirect('ticket_detail.php?id=' . (int) $found['id']);
    }
}

redirect('dashboard.php');
