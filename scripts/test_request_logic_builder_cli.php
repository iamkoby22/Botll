<?php
declare(strict_types=1);

/**
 * CLI: create request logic path + fields, verify load/deactivate.
 * Usage: php scripts/test_request_logic_builder_cli.php
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

if (!request_logic_tables_exist()) {
    fwrite(STDERR, "FAIL: request_logic tables missing\n");
    exit(1);
}

$pdo = db();
$cat = (int) ($pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn() ?: 1);
$pri = (int) ($pdo->query('SELECT id FROM ticket_priorities LIMIT 1')->fetchColumn() ?: 1);

$type = 'Testing CLI ' . date('His');
$pdo->prepare(
    'INSERT INTO request_logic (request_type, step1, step2, display_order, default_category_id, default_priority_id, is_active)
     VALUES (?,?,?,?,?,?,1)'
)->execute([$type, 'Test Step', 'Test Substep', 500, $cat, $pri]);
$logicId = (int) $pdo->lastInsertId();
cli_assert($logicId > 0, 'Created logic path');

$fields = [
    ['field_label' => 'Short text', 'field_type' => 'text', 'is_required' => true],
    ['field_label' => 'Notes', 'field_type' => 'textarea', 'is_required' => false],
    ['field_label' => 'Pick one', 'field_type' => 'dropdown', 'is_required' => true, 'field_options' => "A\nB\nC"],
    ['field_label' => 'Info', 'field_type' => 'instruction', 'instruction_text' => 'Read this before submitting.'],
];
request_logic_fields_save($logicId, $fields, 0, true);

$loaded = request_logic_fields_load($logicId);
cli_assert(count($loaded) === 4, 'Four active fields saved');

$resolved = request_logic_resolve_path($type, 'Test Step', 'Test Substep');
cli_assert($resolved && (int) $resolved['id'] === $logicId, 'Path resolves');

$counts = request_logic_field_counts_by_logic();
cli_assert(($counts[$logicId] ?? 0) === 4, 'Field count for list');

$firstId = (int) $loaded[0]['id'];
request_logic_field_remove($firstId, 0);
$loaded2 = request_logic_fields_load($logicId);
cli_assert(count($loaded2) === 3, 'Field removed/deactivated');

$types = request_logic_request_types();
cli_assert(in_array($type, $types, true), 'Custom type appears in request types list');

echo "\nRequest logic builder CLI checks passed (logic_id={$logicId}).\n";
