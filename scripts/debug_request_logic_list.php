<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

echo "=== request_logic (Testing) ===\n";
$st = db()->query(
    "SELECT id, request_type, step1, step2, display_order, is_active, deleted_at
     FROM request_logic
     WHERE request_type LIKE '%Testing%' OR request_type LIKE '%Test%'
     ORDER BY id DESC LIMIT 20"
);
foreach ($st->fetchAll() as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== request_logic_request_types() ===\n";
foreach (request_logic_request_types() as $t) {
    echo $t . "\n";
}

echo "\n=== request_logic_admin_list() count: " . count(request_logic_admin_list()) . " ===\n";
foreach (request_logic_admin_list() as $r) {
    if (stripos((string) $r['request_type'], 'test') !== false) {
        echo json_encode([
            'id' => $r['id'],
            'request_type' => $r['request_type'],
            'is_active' => $r['is_active'],
            'deleted_at' => $r['deleted_at'] ?? null,
        ], JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n=== last 5 rows (any status) ===\n";
foreach (db()->query('SELECT id, request_type, is_active, deleted_at FROM request_logic ORDER BY id DESC LIMIT 5') as $r) {
    echo json_encode($r) . "\n";
}
