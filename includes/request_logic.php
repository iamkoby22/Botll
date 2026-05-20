<?php
declare(strict_types=1);

function request_logic_tables_exist(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $st = db()->query('SHOW TABLES LIKE "request_logic"');
        $ok = (bool) $st->fetch();
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function request_logic_has_deleted_at_column(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $st = db()->query('SHOW COLUMNS FROM request_logic LIKE "deleted_at"');
        $has = (bool) $st->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

function request_logic_active_where_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    $sql = 'COALESCE(' . $p . 'is_active, 1) = 1';
    if (request_logic_has_deleted_at_column()) {
        $sql .= ' AND ' . $p . 'deleted_at IS NULL';
    }
    return $sql;
}

function request_logic_has_request_type_column(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $st = db()->query('SHOW COLUMNS FROM request_logic LIKE "request_type"');
        $has = (bool) $st->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * @param array<string,mixed> $row
 */
function department_display_label(array $row): string
{
    $name = (string) ($row['department_name'] ?? '');
    $num = trim((string) ($row['department_number'] ?? ''));
    $org = trim((string) ($row['organization_code'] ?? ''));
    if ($num !== '' && $org !== '') {
        return $name . ' — ' . $num . ' / ' . $org;
    }
    if ($num !== '') {
        return $name . ' — ' . $num;
    }
    return $name;
}

/**
 * All distinct active request types for intake dropdowns (never filtered by step1/step2).
 *
 * @return list<string>
 */
function request_logic_request_types(): array
{
    if (!request_logic_tables_exist() || !request_logic_has_request_type_column()) {
        return [];
    }
    $st = db()->query(
        'SELECT DISTINCT request_type
         FROM request_logic
         WHERE ' . request_logic_active_where_sql() . '
           AND request_type IS NOT NULL
           AND TRIM(request_type) <> \'\'
         ORDER BY request_type COLLATE utf8mb4_unicode_ci ASC'
    );
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $type = trim((string) $r['request_type']);
        if ($type !== '') {
            $out[] = $type;
        }
    }
    return $out;
}

/**
 * @return list<string>
 */
function request_logic_active_types(): array
{
    return request_logic_request_types();
}

/**
 * Whether the user must pick Step 1 before fields load (any path has a non-empty step1).
 */
function request_logic_type_requires_step1(string $requestType): bool
{
    return request_logic_step1_options($requestType) !== [];
}

/**
 * @return list<string>
 */
function request_logic_step1_options(string $requestType): array
{
    if (!request_logic_tables_exist() || $requestType === '') {
        return [];
    }
    $st = db()->prepare(
        'SELECT step1
         FROM request_logic
         WHERE request_type COLLATE utf8mb4_unicode_ci = ?
           AND ' . request_logic_active_where_sql() . '
           AND step1 IS NOT NULL AND step1 <> \'\'
         GROUP BY step1
         ORDER BY MIN(display_order) ASC, step1 ASC'
    );
    $st->execute([$requestType]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = (string) $r['step1'];
    }
    return $out;
}

/**
 * @return list<string>
 */
function request_logic_step2_options(string $requestType, string $step1): array
{
    if (!request_logic_tables_exist() || $requestType === '') {
        return [];
    }
    $st = db()->prepare(
        'SELECT step2
         FROM request_logic
         WHERE request_type COLLATE utf8mb4_unicode_ci = ?
           AND step1 COLLATE utf8mb4_unicode_ci = ?
           AND step2 IS NOT NULL AND step2 <> \'\'
           AND ' . request_logic_active_where_sql() . '
         GROUP BY step2
         ORDER BY MIN(display_order) ASC, step2 ASC'
    );
    $st->execute([$requestType, $step1]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = (string) $r['step2'];
    }
    return $out;
}

function request_logic_type_has_step2(string $requestType): bool
{
    if (!request_logic_tables_exist() || $requestType === '') {
        return false;
    }
    $st = db()->prepare(
        'SELECT 1 FROM request_logic
         WHERE request_type COLLATE utf8mb4_unicode_ci = ?
           AND step2 IS NOT NULL AND step2 <> \'\'
           AND ' . request_logic_active_where_sql() . '
         LIMIT 1'
    );
    $st->execute([$requestType]);
    return (bool) $st->fetchColumn();
}

/**
 * @return array<string,mixed>|null
 */
function request_logic_resolve_path(string $requestType, string $step1, ?string $step2): ?array
{
    if (!request_logic_tables_exist() || $requestType === '') {
        return null;
    }
    $step2 = $step2 !== null && $step2 !== '' ? $step2 : null;
    $active = request_logic_active_where_sql();
    if ($step2 !== null) {
        $st = db()->prepare(
            'SELECT * FROM request_logic
             WHERE request_type COLLATE utf8mb4_unicode_ci = ?
               AND step1 COLLATE utf8mb4_unicode_ci = ?
               AND step2 COLLATE utf8mb4_unicode_ci = ?
               AND ' . $active . ' LIMIT 1'
        );
        $st->execute([$requestType, $step1, $step2]);
        $row = $st->fetch();
        return $row ?: null;
    }
    $st = db()->prepare(
        'SELECT * FROM request_logic
         WHERE request_type COLLATE utf8mb4_unicode_ci = ?
           AND step1 COLLATE utf8mb4_unicode_ci = ?
           AND (step2 IS NULL OR step2 = \'\')
           AND ' . $active . '
         ORDER BY display_order ASC LIMIT 1'
    );
    $st->execute([$requestType, $step1]);
    $row = $st->fetch();
    if ($row) {
        return $row;
    }
    $st2 = db()->prepare(
        'SELECT * FROM request_logic
         WHERE request_type COLLATE utf8mb4_unicode_ci = ?
           AND (step1 = \'\' OR step1 IS NULL)
           AND (step2 IS NULL OR step2 = \'\')
           AND ' . $active . ' LIMIT 1'
    );
    $st2->execute([$requestType]);
    $row2 = $st2->fetch();
    return $row2 ?: null;
}

/**
 * @return array<string,mixed>|null
 */
function request_logic_fetch_by_id(int $id): ?array
{
    if ($id < 1 || !request_logic_tables_exist()) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM request_logic WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function request_logic_fields_load(int $logicId, bool $activeOnly = true): array
{
    if ($logicId < 1 || !request_logic_tables_exist()) {
        return [];
    }
    try {
        $sql = 'SELECT id, request_logic_id, field_label, field_key, field_key AS field_name, field_type, field_options,
                    is_required, help_text, instruction_text, display_order, display_order AS field_order, is_active
             FROM request_logic_fields
             WHERE request_logic_id = ? AND deleted_at IS NULL';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY display_order ASC, id ASC';
        $st = db()->prepare($sql);
        $st->execute([$logicId]);
        return request_logic_normalize_field_rows($st->fetchAll() ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function request_logic_normalize_field_rows(array $rows): array
{
    foreach ($rows as &$r) {
        $r['placeholder'] = request_logic_field_placeholder_from_row($r);
        $r['help_text_display'] = request_logic_field_help_text_display($r);
    }
    unset($r);
    return $rows;
}

/**
 * @param array<string,mixed> $row
 */
function request_logic_field_placeholder_from_row(array $row): string
{
    if (!empty($row['placeholder'])) {
        return trim((string) $row['placeholder']);
    }
    $help = trim((string) ($row['help_text'] ?? ''));
    if (str_starts_with($help, 'placeholder:')) {
        $rest = substr($help, strlen('placeholder:'));
        $parts = explode("\n", $rest, 2);
        return trim($parts[0]);
    }
    return '';
}

function request_logic_field_help_text_display(array $row): string
{
    $help = trim((string) ($row['help_text'] ?? ''));
    if (str_starts_with($help, 'placeholder:')) {
        $rest = substr($help, strlen('placeholder:'));
        $parts = explode("\n", $rest, 2);
        return isset($parts[1]) ? trim($parts[1]) : '';
    }
    return $help;
}

function request_logic_field_key_from_label(string $label, int $fallback = 1): string
{
    $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
    $key = trim($key, '_');
    if ($key === '') {
        $key = 'field_' . $fallback;
    }
    if (strlen($key) > 72) {
        $key = substr($key, 0, 72);
    }
    return $key;
}

function request_logic_field_has_ticket_values(int $fieldId): bool
{
    if ($fieldId < 1) {
        return false;
    }
    try {
        $st = db()->prepare('SELECT 1 FROM ticket_field_values WHERE request_logic_field_id = ? LIMIT 1');
        $st->execute([$fieldId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<int,int> logic_id => active field count
 */
function request_logic_field_counts_by_logic(): array
{
    if (!request_logic_tables_exist()) {
        return [];
    }
    $st = db()->query(
        'SELECT request_logic_id, COUNT(*) AS c FROM request_logic_fields
         WHERE deleted_at IS NULL AND is_active = 1 GROUP BY request_logic_id'
    );
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['request_logic_id']] = (int) $r['c'];
    }
    return $out;
}

/**
 * Map request logic fields to template-field shape for shared validators/renderers.
 *
 * @return list<array<string,mixed>>
 */
function request_logic_fields_for_form(int $logicId): array
{
    return request_logic_fields_load($logicId);
}

/**
 * @param list<array{request_logic_field_id:int,field_key:string,field_label:string,field_type:string,field_value:string}> $rows
 */
function request_logic_field_values_save(int $ticketId, int $logicId, array $rows): void
{
    if ($ticketId < 1 || $logicId < 1 || !$rows) {
        return;
    }
    try {
        $stmt = db()->prepare(
            'INSERT INTO ticket_field_values (ticket_id, template_id, request_logic_id, request_logic_field_id, field_label, field_key, field_type, field_value)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($rows as $r) {
            $stmt->execute([
                $ticketId,
                null,
                $logicId,
                $r['request_logic_field_id'] > 0 ? $r['request_logic_field_id'] : null,
                $r['field_label'],
                $r['field_key'],
                $r['field_type'],
                $r['field_value'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('request_logic_field_values_save: ' . $e->getMessage());
    }
}

/**
 * Validate posted custom_fields against request logic fields.
 *
 * @param array<string,mixed> $posted
 * @return list<array{request_logic_field_id:int,field_key:string,field_label:string,field_type:string,field_value:string}>
 */
function request_logic_validate_fields(array $fields, array $posted, array &$errors): array
{
    $mapped = [];
    foreach ($fields as $f) {
        $f['id'] = (int) ($f['id'] ?? 0);
        $mapped[] = $f;
    }
    $saved = ticket_custom_fields_validate($mapped, $posted, $errors);
    $out = [];
    foreach ($saved as $row) {
        $out[] = [
            'request_logic_field_id' => (int) ($row['template_field_id'] ?? 0),
            'field_key' => $row['field_key'],
            'field_label' => $row['field_label'],
            'field_type' => $row['field_type'],
            'field_value' => $row['field_value'],
        ];
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
/**
 * Admin list: all non-deleted paths (active and inactive) so custom logic is never hidden.
 *
 * @return list<array<string,mixed>>
 */
function request_logic_admin_list(?string $q = null): array
{
    if (!request_logic_tables_exist()) {
        return [];
    }
    $where = request_logic_has_deleted_at_column() ? 'deleted_at IS NULL' : '1=1';
    $sql = 'SELECT * FROM request_logic WHERE ' . $where;
    $params = [];
    if ($q !== null && trim($q) !== '') {
        $sql .= ' AND (request_type LIKE ? OR step1 LIKE ? OR step2 LIKE ?)';
        $like = '%' . trim($q) . '%';
        $params = [$like, $like, $like];
    }
    $sql .= ' ORDER BY request_type COLLATE utf8mb4_unicode_ci ASC, display_order ASC, step1 ASC, step2 ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
}

/**
 * @return array<string,int>
 */
function request_logic_path_counts_by_type(): array
{
    if (!request_logic_tables_exist()) {
        return [];
    }
    $st = db()->query(
        'SELECT request_type, COUNT(*) AS path_count
         FROM request_logic
         WHERE ' . request_logic_active_where_sql() . '
           AND request_type IS NOT NULL AND TRIM(request_type) <> \'\'
         GROUP BY request_type
         ORDER BY request_type COLLATE utf8mb4_unicode_ci ASC'
    );
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['request_type']] = (int) $r['path_count'];
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $fields
 */
function request_logic_fields_replace(int $logicId, array $fields): void
{
    request_logic_fields_save($logicId, $fields, (int) (current_user()['id'] ?? 0), true);
}

/**
 * Upsert fields for a logic path; soft-delete removed fields that have ticket data.
 *
 * @param list<array<string,mixed>> $fields
 */
function request_logic_fields_save(int $logicId, array $fields, int $byUserId, bool $replaceAll = false): void
{
    if ($logicId < 1 || !request_logic_tables_exist()) {
        return;
    }
    $pdo = db();
    $keptIds = [];
    $ord = 0;
    foreach ($fields as $f) {
        if (!is_array($f)) {
            continue;
        }
        if (array_key_exists('is_active', $f) && !$f['is_active'] && empty($f['field_label'])) {
            continue;
        }
        $row = request_logic_normalize_field_payload($f, $ord);
        if ($row === null) {
            continue;
        }
        $fieldId = (int) ($f['id'] ?? 0);
        if ($fieldId > 0 && !$replaceAll) {
            $pdo->prepare(
                'UPDATE request_logic_fields SET field_label=?, field_key=?, field_type=?, field_options=?, is_required=?,
                 help_text=?, instruction_text=?, display_order=?, is_active=?, updated_at=NOW()
                 WHERE id=? AND request_logic_id=?'
            )->execute([
                $row['field_label'],
                $row['field_key'],
                $row['field_type'],
                $row['field_options'],
                $row['is_required'],
                $row['help_text'],
                $row['instruction_text'],
                $ord,
                $row['is_active'],
                $fieldId,
                $logicId,
            ]);
            $keptIds[] = $fieldId;
        } else {
            $pdo->prepare(
                'INSERT INTO request_logic_fields (request_logic_id, field_label, field_key, field_type, field_options, is_required, help_text, instruction_text, display_order, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $logicId,
                $row['field_label'],
                $row['field_key'],
                $row['field_type'],
                $row['field_options'],
                $row['is_required'],
                $row['help_text'],
                $row['instruction_text'],
                $ord,
                $row['is_active'],
            ]);
            $keptIds[] = (int) $pdo->lastInsertId();
        }
        $ord++;
    }

    if ($replaceAll) {
        $st = $pdo->prepare('SELECT id FROM request_logic_fields WHERE request_logic_id = ?');
        $st->execute([$logicId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $existingId) {
            $existingId = (int) $existingId;
            if (!in_array($existingId, $keptIds, true)) {
                request_logic_field_remove($existingId, $byUserId);
            }
        }
        return;
    }

    $st = $pdo->prepare('SELECT id FROM request_logic_fields WHERE request_logic_id = ? AND deleted_at IS NULL');
    $st->execute([$logicId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $existingId) {
        $existingId = (int) $existingId;
        if (!in_array($existingId, $keptIds, true)) {
            request_logic_field_remove($existingId, $byUserId);
        }
    }
}

/**
 * @param array<string,mixed> $f
 * @return array<string,mixed>|null
 */
function request_logic_normalize_field_payload(array $f, int $ord): ?array
{
    $type = trim((string) ($f['field_type'] ?? 'text'));
    if ($type === 'info') {
        $type = 'instruction';
    }
    if ($type === 'select') {
        $type = 'dropdown';
    }
    if ($type === 'paragraph') {
        $type = 'textarea';
    }
    $label = trim((string) ($f['field_label'] ?? ''));
    if ($label === '' && $type !== 'instruction') {
        return null;
    }
    if ($type === 'instruction') {
        return [
            'field_label' => $label !== '' ? $label : 'Instruction',
            'field_key' => request_logic_field_key_from_label($label !== '' ? $label : 'instruction', $ord + 1),
            'field_type' => 'instruction',
            'field_options' => null,
            'is_required' => 0,
            'help_text' => null,
            'instruction_text' => trim((string) ($f['instruction_text'] ?? $f['field_options'] ?? '')),
            'is_active' => !isset($f['is_active']) || !empty($f['is_active']) ? 1 : 0,
        ];
    }
    if (!ticket_custom_field_is_input_type($type)) {
        return null;
    }
    $key = trim((string) ($f['field_key'] ?? $f['field_name'] ?? ''));
    if ($key === '') {
        $key = request_logic_field_key_from_label($label, $ord + 1);
    } else {
        $key = request_logic_field_key_from_label($key, $ord + 1);
    }
    $placeholder = trim((string) ($f['placeholder'] ?? ''));
    $help = trim((string) ($f['help_text'] ?? ''));
    if ($placeholder !== '' && $help === '') {
        $help = 'placeholder:' . $placeholder;
    } elseif ($placeholder !== '' && !str_starts_with($help, 'placeholder:')) {
        $help = 'placeholder:' . $placeholder . "\n" . $help;
    }
    $opts = trim((string) ($f['field_options'] ?? ''));
    return [
        'field_label' => $label,
        'field_key' => $key,
        'field_type' => $type,
        'field_options' => $opts !== '' ? $opts : null,
        'is_required' => !empty($f['is_required']) ? 1 : 0,
        'help_text' => $help !== '' ? $help : null,
        'instruction_text' => null,
        'is_active' => !isset($f['is_active']) || !empty($f['is_active']) ? 1 : 0,
    ];
}

function request_logic_field_remove(int $fieldId, int $byUserId): void
{
    if ($fieldId < 1) {
        return;
    }
    $pdo = db();
    if (request_logic_field_has_ticket_values($fieldId)) {
        $pdo->prepare(
            'UPDATE request_logic_fields SET is_active = 0, deleted_at = NOW(), deleted_by = ? WHERE id = ?'
        )->execute([$byUserId > 0 ? $byUserId : null, $fieldId]);
        return;
    }
    $pdo->prepare('DELETE FROM request_logic_fields WHERE id = ?')->execute([$fieldId]);
}

function request_logic_soft_delete(int $logicId, int $byUserId): bool
{
    if ($logicId < 1 || !request_logic_tables_exist()) {
        return false;
    }
    $st = db()->prepare(
        'UPDATE request_logic SET is_active = 0, deleted_at = NOW(), deleted_by = ? WHERE id = ?'
    );
    $st->execute([$byUserId, $logicId]);
    return $st->rowCount() > 0;
}

/**
 * Build ticket subject from request path.
 *
 * @param array<string,mixed> $path
 */
function request_logic_ticket_subject(array $path): string
{
    $parts = [(string) $path['request_type']];
    if ((string) ($path['step1'] ?? '') !== '') {
        $parts[] = (string) $path['step1'];
    }
    if ((string) ($path['step2'] ?? '') !== '') {
        $parts[] = (string) $path['step2'];
    }
    return implode(' — ', $parts);
}

/**
 * @param array<string,mixed> $path
 * @param list<array{field_label:string,field_value:string}> $responses
 */
function request_logic_build_description(array $path, array $responses, string $requesterName, string $requesterEmail): string
{
    $lines = [
        'Business Services Request',
        'Requester: ' . $requesterName,
        'Email: ' . $requesterEmail,
        'Request Type: ' . (string) $path['request_type'],
    ];
    if ((string) ($path['step1'] ?? '') !== '') {
        $lines[] = 'Step 1: ' . (string) $path['step1'];
    }
    if ((string) ($path['step2'] ?? '') !== '') {
        $lines[] = 'Step 2: ' . (string) $path['step2'];
    }
    $lines[] = '';
    foreach ($responses as $r) {
        if ((string) ($r['field_type'] ?? '') === 'instruction') {
            continue;
        }
        $lines[] = $r['field_label'] . ': ' . $r['field_value'];
    }
    return implode("\n", $lines);
}

/**
 * @param array<string,mixed> $ticket
 * @return array<string,mixed>|null
 */
function ticket_request_path_display(array $ticket): ?array
{
    if (!empty($ticket['request_type'])) {
        return [
            'requester_name' => (string) ($ticket['requester_name_snapshot'] ?? ''),
            'requester_email' => (string) ($ticket['requester_email_snapshot'] ?? ''),
            'request_type' => (string) $ticket['request_type'],
            'step1' => (string) ($ticket['request_step1'] ?? ''),
            'step2' => (string) ($ticket['request_step2'] ?? ''),
            'request_logic_id' => (int) ($ticket['request_logic_id'] ?? 0),
        ];
    }
    return null;
}
