<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$sql = file_get_contents(dirname(__DIR__) . '/database/migration_017_access_role_compat.sql');
if ($sql === false) {
    fwrite(STDERR, "Cannot read migration file.\n");
    exit(1);
}
$pdo = db();
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    try {
        $pdo->exec($stmt);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
    }
}
echo "Migration 017 applied.\n";
