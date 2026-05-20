<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

$st = db()->prepare(
    'UPDATE request_logic SET is_active = 1, updated_at = NOW()
     WHERE COALESCE(is_active, 0) = 0 AND deleted_at IS NULL'
);
$st->execute();
echo 'Activated ' . $st->rowCount() . " inactive request_logic path(s).\n";
