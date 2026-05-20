<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$limit = 15;

try {
    if ($q === '') {
        echo json_encode(['ok' => true, 'users' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $like = '%' . $q . '%';
    $st = db()->prepare(
        'SELECT id, full_name, username, email
         FROM users
         WHERE status = "active"
           AND (
             username LIKE ? COLLATE utf8mb4_unicode_ci
             OR full_name LIKE ? COLLATE utf8mb4_unicode_ci
             OR email LIKE ? COLLATE utf8mb4_unicode_ci
           )
         ORDER BY full_name ASC
         LIMIT ' . (int) $limit
    );
    $st->execute([$like, $like, $like]);
    $users = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $id = (int) $row['id'];
        $name = (string) $row['full_name'];
        $username = trim((string) ($row['username'] ?? ''));
        $email = (string) $row['email'];
        $insert = $username !== '' ? '@' . $username : '@' . preg_replace('/\s+/', '', $name);
        $users[] = [
            'id' => $id,
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'label' => $name . ($username !== '' ? ' (@' . $username . ')' : '') . ' — ' . $email,
            'insert' => $insert,
        ];
    }
    echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('user_mentions.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not search users.']);
}
