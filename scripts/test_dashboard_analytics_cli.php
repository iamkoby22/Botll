<?php
declare(strict_types=1);

/**
 * CLI: dashboard analytics scope and counts.
 * Usage: php scripts/test_dashboard_analytics_cli.php
 */

require_once dirname(__DIR__) . '/includes/init.php';

function cli_assert(bool $cond, string $label): void
{
    if ($cond) {
        echo "PASS: {$label}\n";
        return;
    }
    fwrite(STDERR, "FAIL: {$label}\n");
    exit(1);
}

$pdo = db();

if (!analytics_column_exists('tickets', 'account_route')) {
    try {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN account_route VARCHAR(20) NULL DEFAULT NULL');
    } catch (Throwable $e) {
        /* */
    }
}
if (!analytics_column_exists('tickets', 'archived_at')) {
    try {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL');
    } catch (Throwable $e) {
        /* */
    }
}
if (!analytics_column_exists('users', 'user_level')) {
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN user_level VARCHAR(50) NULL DEFAULT NULL');
    } catch (Throwable $e) {
        /* */
    }
}

cli_assert(user_can_view_full_analytics(['role_key' => 'super_admin']) === true, 'super_admin sees full analytics');
cli_assert(user_can_view_full_analytics(['role_key' => 'user']) === false, 'user does not see full analytics');
cli_assert(user_can_view_full_analytics(['role_key' => 'admin']) === false, 'legacy admin hidden unless user_level set');

$_SESSION['user_id'] = (int) ($pdo->query('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key="super_admin" LIMIT 1')->fetchColumn() ?: 0);
clear_current_user_cache();
if ($_SESSION['user_id'] < 1) {
    fwrite(STDERR, "FAIL: no super_admin user\n");
    exit(1);
}

$scope = analytics_scope_for_user();
$routes = analytics_account_route_counts($scope);
cli_assert(is_array($routes['labels']) && count($routes['labels']) === 3, 'account route chart has 3 labels');

$rt = analytics_tickets_by_request_type($scope);
cli_assert(count($rt['labels']) >= 1, 'request type chart has data');

$assigned = analytics_tickets_by_assigned_user($scope);
cli_assert(is_array($assigned['labels']), 'assigned user chart structure');

$perf = analytics_performance_summary($scope);
cli_assert(array_key_exists('received', $perf), 'performance summary received');

$archFilter = analytics_column_exists('tickets', 'archived_at') ? 'archived_at IS NULL' : '1=1';
if (analytics_column_exists('tickets', 'account_route')) {
    $st = $pdo->query(
        'SELECT account_route, COUNT(*) c FROM tickets WHERE ' . $archFilter . ' GROUP BY account_route'
    );
    $dbRoutes = [];
    foreach ($st->fetchAll() as $row) {
        $key = (string) ($row['account_route'] ?? 'general');
        $dbRoutes[$key] = (int) $row['c'];
    }
    $expectedRestricted = (int) ($dbRoutes['restricted'] ?? 0);
    $idx = array_search('Restricted', $routes['labels'], true);
    if ($idx !== false) {
        cli_assert((int) $routes['values'][$idx] === $expectedRestricted, 'restricted count matches SQL');
    }
} else {
    echo "SKIP: account_route column not present (run migration_014_analytics_support.sql)\n";
}

$st = $pdo->query(
    'SELECT COALESCE(NULLIF(TRIM(request_type), ""), "Unknown Request Type") AS rt, COUNT(*) c
     FROM tickets WHERE ' . $archFilter . ' GROUP BY rt ORDER BY c DESC LIMIT 1'
);
$row = $st ? $st->fetch() : false;
if ($row) {
    $topType = (string) $row['rt'];
    $found = in_array($topType, $rt['labels'], true);
    cli_assert($found, 'top request_type appears in analytics chart');
}

$personal = analytics_personal_work_metrics();
cli_assert(array_key_exists('my_open', $personal), 'personal metrics for scoped user');

echo "\nAll dashboard analytics CLI checks passed.\n";
