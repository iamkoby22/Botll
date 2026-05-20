<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

$sql = file_get_contents(dirname(__DIR__) . '/database/migration_013_ticket_mentions_notifications.sql');
if ($sql === false) {
    fwrite(STDERR, "Could not read migration file\n");
    exit(1);
}

$pdo = db();
$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
foreach ($statements as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--') || str_starts_with($stmt, 'SET @')) {
        continue;
    }
    if (str_starts_with($stmt, 'PREPARE') || str_starts_with($stmt, 'EXECUTE') || str_starts_with($stmt, 'DEALLOCATE')) {
        continue;
    }
    try {
        $pdo->exec($stmt);
        echo "OK: " . substr(str_replace("\n", ' ', $stmt), 0, 80) . "...\n";
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'already exists')) {
            echo "SKIP: " . $e->getMessage() . "\n";
        } else {
            echo "ERR: " . $e->getMessage() . "\n";
        }
    }
}

// Run dynamic alters manually
$alters = [
    'ALTER TABLE comment_mentions ADD COLUMN mentioned_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER mentioned_user_id',
    'ALTER TABLE comment_mentions ADD UNIQUE KEY uniq_cm_comment_user (comment_id, mentioned_user_id)',
];
foreach ($alters as $a) {
    try {
        $pdo->exec($a);
        echo "OK alter\n";
    } catch (Throwable $e) {
        echo 'SKIP alter: ' . $e->getMessage() . "\n";
    }
}

echo 'comment_mentions=' . (comment_mentions_table_exists() ? 'yes' : 'no') . "\n";
echo 'ticket_mention_access=' . (ticket_mention_access_table_exists() ? 'yes' : 'no') . "\n";
