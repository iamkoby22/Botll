<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

$pdo = db();

echo "=== A. All rows ===\n";
$rows = $pdo->query(
    'SELECT id, request_type, step1, step2, is_active, deleted_at, display_order
     FROM request_logic ORDER BY request_type, display_order, step1, step2'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
echo 'Total: ' . count($rows) . "\n\n";

echo "=== B. Distinct types (COALESCE is_active) ===\n";
$types = $pdo->query(
    "SELECT DISTINCT request_type FROM request_logic
     WHERE COALESCE(is_active, 1) = 1 AND deleted_at IS NULL
       AND request_type IS NOT NULL AND request_type <> ''
     ORDER BY request_type"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($types as $t) {
    echo "- $t\n";
}
echo 'Count: ' . count($types) . "\n\n";

echo "=== B2. request_logic_active_types() ===\n";
foreach (request_logic_active_types() as $t) {
    echo "- $t\n";
}
echo 'Count: ' . count(request_logic_active_types()) . "\n\n";

echo "=== C. Count by type ===\n";
$counts = $pdo->query(
    "SELECT request_type, COUNT(*) AS path_count FROM request_logic
     WHERE COALESCE(is_active, 1) = 1 AND deleted_at IS NULL
     GROUP BY request_type ORDER BY request_type"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($counts as $c) {
    echo $c['request_type'] . ': ' . $c['path_count'] . "\n";
}

echo "\n=== is_active values ===\n";
$ia = $pdo->query('SELECT DISTINCT is_active, COUNT(*) c FROM request_logic GROUP BY is_active')->fetchAll();
print_r($ia);

echo "\n=== deleted_at column? ===\n";
echo request_logic_has_deleted_at_column() ? "yes\n" : "no\n";

echo "\n=== step1 Non-Travel ===\n";
print_r(request_logic_step1_options('Non-Travel Reimbursement'));

echo "\n=== resolve Non-Travel empty step1 ===\n";
print_r(request_logic_resolve_path('Non-Travel Reimbursement', '', null));
