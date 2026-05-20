<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$path = dirname(__DIR__) . '/database/migration_015_sbs_workflow_completion.sql';
if (!is_file($path)) {
    fwrite(STDERR, "Missing migration file.\n");
    exit(1);
}

$sql = file_get_contents($path);
$pdo = db();
$chunks = preg_split('/;\s*\n/', $sql);
$ok = 0;
$err = 0;
foreach ($chunks as $chunk) {
    $chunk = trim($chunk);
    if ($chunk === '' || str_starts_with($chunk, '--') || str_starts_with($chunk, 'SET @')) {
        continue;
    }
    if (preg_match('/^(PREPARE|EXECUTE|DEALLOCATE)/i', $chunk)) {
        continue;
    }
    try {
        $pdo->exec($chunk);
        $ok++;
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'already exists')) {
            continue;
        }
        fwrite(STDERR, $e->getMessage() . "\n-- chunk: " . substr($chunk, 0, 80) . "...\n");
        $err++;
    }
}

// Run dynamic PREPARE blocks via multi pass on raw file
$lines = file($path);
$buf = '';
foreach ($lines as $line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '--')) {
        continue;
    }
    $buf .= $line;
    if (str_ends_with(trim($line), ';')) {
        if (str_contains($buf, 'PREPARE') && str_contains($buf, 'EXECUTE')) {
            foreach (['PREPARE s FROM @sql', 'EXECUTE s', 'DEALLOCATE PREPARE s'] as $part) {
                if (preg_match('/' . preg_quote($part, '/') . '/i', $buf)) {
                    try {
                        $pdo->exec($part);
                    } catch (Throwable $e) {
                        if (!str_contains($e->getMessage(), 'Duplicate')) {
                            fwrite(STDERR, $e->getMessage() . "\n");
                            $err++;
                        }
                    }
                }
            }
        }
        $buf = '';
    }
}

echo "Migration 015 finished. statements_ok=$ok errors=$err\n";
exit($err > 0 ? 1 : 0);
