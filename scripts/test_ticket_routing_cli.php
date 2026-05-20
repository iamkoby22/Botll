<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

echo "ticket_routing.php loaded: " . (function_exists('ticket_approval_save_chain') ? 'yes' : 'no') . "\n";

$users = db()->query('SELECT id FROM users WHERE status="active" LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
if (count($users) < 2) {
    echo "SKIP: need 2+ active users\n";
    exit(0);
}
$u1 = (int) $users[0];
$u2 = (int) $users[1];
$u3 = (int) ($users[2] ?? $users[1]);

$errors = [];
$chain = [
    ['user_id' => $u1, 'level' => 1],
    ['user_id' => $u2, 'level' => 2],
    ['user_id' => $u3, 'level' => 3],
];
$ok = ticket_routing_validate_user_chain($chain, 'approver', $errors, true);
echo ($ok ? 'OK' : 'FAIL') . " validate 3-level approval chain\n";
if ($errors) {
    print_r($errors);
}

$dup = [
    ['user_id' => $u1, 'level' => 1],
    ['user_id' => $u1, 'level' => 2],
];
$errors = [];
$bad = ticket_routing_validate_user_chain($dup, 'approver', $errors, true);
echo (!$bad ? 'OK' : 'FAIL') . " duplicate approver blocked\n";

exit(0);
