<?php
declare(strict_types=1);

/**
 * Verify custom request logic appears in admin list and New Request types after save.
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
$type = 'Testing ' . date('His');
$cat = (int) ($pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn() ?: 1);
$pri = (int) ($pdo->query('SELECT id FROM ticket_priorities LIMIT 1')->fetchColumn() ?: 1);

$pdo->prepare(
    'INSERT INTO request_logic (request_type, step1, step2, display_order, default_category_id, default_priority_id, is_active)
     VALUES (?,?,?,?,?,?,1)'
)->execute([$type, 'Test Step', 'Test Substep', 100, $cat, $pri]);
$id = (int) $pdo->lastInsertId();

request_logic_fields_save($id, [
    ['field_label' => 'Name', 'field_type' => 'text', 'is_required' => true],
], 0, true);

$row = $pdo->query('SELECT is_active, deleted_at FROM request_logic WHERE id=' . $id)->fetch();
cli_assert((int) $row['is_active'] === 1 && empty($row['deleted_at']), 'DB row active and not deleted');

$inList = false;
foreach (request_logic_admin_list() as $r) {
    if ((int) $r['id'] === $id) {
        $inList = true;
        break;
    }
}
cli_assert($inList, 'Appears in request_logic_admin_list');

$types = request_logic_request_types();
cli_assert(in_array($type, $types, true), 'Appears in request_logic_request_types (New Request)');

$resolved = request_logic_resolve_path($type, 'Test Step', 'Test Substep');
cli_assert($resolved && (int) $resolved['id'] === $id, 'Path resolves for cascade');

// Inactive path hidden from dropdown but visible in admin list
$pdo->prepare('UPDATE request_logic SET is_active=0 WHERE id=?')->execute([$id]);
cli_assert(!in_array($type, request_logic_request_types(), true), 'Inactive hidden from New Request types');
$inListInactive = false;
foreach (request_logic_admin_list() as $r) {
    if ((int) $r['id'] === $id) {
        $inListInactive = true;
        break;
    }
}
cli_assert($inListInactive, 'Inactive still visible in admin list');

echo "\nRequest logic visibility CLI passed (logic_id={$id}, type={$type}).\n";
