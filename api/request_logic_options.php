<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * @param array<string,mixed> $payload
 */
function request_logic_api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!current_user()) {
    request_logic_api_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$action = (string) ($_GET['action'] ?? 'types');

try {
    if ($action === 'types' || $action === 'request_types') {
        $types = request_logic_request_types();
        request_logic_api_json([
            'ok' => true,
            'options' => $types,
            'types' => $types,
            'count' => count($types),
        ]);
    }

    if ($action === 'step1') {
        $type = trim((string) ($_GET['request_type'] ?? ''));
        if ($type === '') {
            request_logic_api_json(['ok' => false, 'error' => 'request_type is required'], 400);
        }
        $allTypes = request_logic_request_types();
        if (!in_array($type, $allTypes, true)) {
            request_logic_api_json(['ok' => false, 'error' => 'Unknown or inactive request type'], 404);
        }
        $options = request_logic_step1_options($type);
        $noStep1 = $options === [] && request_logic_resolve_path($type, '', null) !== null;
        request_logic_api_json([
            'ok' => true,
            'options' => $options,
            'step1' => $options,
            'has_step2' => request_logic_type_has_step2($type),
            'no_step1' => $noStep1,
            'load_fields_directly' => $noStep1,
        ]);
    }

    if ($action === 'step2') {
        $type = trim((string) ($_GET['request_type'] ?? ''));
        $s1 = trim((string) ($_GET['step1'] ?? ''));
        if ($type === '' || $s1 === '') {
            request_logic_api_json(['ok' => false, 'error' => 'request_type and step1 are required'], 400);
        }
        $options = request_logic_step2_options($type, $s1);
        request_logic_api_json([
            'ok' => true,
            'options' => $options,
            'step2' => $options,
        ]);
    }

    if ($action === 'path') {
        $type = trim((string) ($_GET['request_type'] ?? ''));
        $s1 = trim((string) ($_GET['step1'] ?? ''));
        $s2 = trim((string) ($_GET['step2'] ?? ''));
        if ($type === '') {
            request_logic_api_json(['ok' => false, 'error' => 'request_type is required'], 400);
        }
        $path = request_logic_resolve_path($type, $s1, $s2 !== '' ? $s2 : null);
        if (!$path) {
            request_logic_api_json(['ok' => false, 'error' => 'Path not found'], 404);
        }
        $logicId = (int) $path['id'];
        $fields = request_logic_fields_for_form($logicId);
        request_logic_api_json([
            'ok' => true,
            'logic_id' => $logicId,
            'path' => [
                'request_type' => (string) $path['request_type'],
                'step1' => (string) $path['step1'],
                'step2' => (string) ($path['step2'] ?? ''),
            ],
            'fields' => array_map(static function (array $f): array {
                return [
                    'id' => (int) $f['id'],
                    'field_label' => (string) $f['field_label'],
                    'field_type' => (string) $f['field_type'],
                    'is_required' => !empty($f['is_required']),
                    'field_options' => (string) ($f['field_options'] ?? ''),
                    'help_text' => (string) ($f['help_text_display'] ?? $f['help_text'] ?? ''),
                    'help_text_display' => (string) ($f['help_text_display'] ?? ''),
                    'placeholder' => (string) ($f['placeholder'] ?? ''),
                    'instruction_text' => (string) ($f['instruction_text'] ?? ''),
                ];
            }, $fields),
        ]);
    }

    request_logic_api_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('request_logic_options.php [' . $action . ']: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    request_logic_api_json([
        'ok' => false,
        'error' => 'Could not load request options. Please try again or contact admin.',
    ], 500);
}
