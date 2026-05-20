<?php
declare(strict_types=1);

/**
 * Renders custom fields for request logic or legacy templates.
 *
 * Expects: $templateFields (list), $customFieldValues (map id=>value), $usersList (list)
 * Optional: $customFieldsSectionTitle (string)
 */

if (!function_exists('e')) {
    return;
}

/**
 * @param array<string,mixed> $field
 */
function _cf_posted_value(array $field, array $customFieldValues): string
{
    $fid = (string) (int) ($field['id'] ?? 0);
    if ($fid === '0') {
        return '';
    }
    $v = $customFieldValues[$fid] ?? $customFieldValues[(int) $fid] ?? '';
    if (is_array($v)) {
        return implode(', ', array_map('strval', $v));
    }
    return (string) $v;
}

/**
 * @param array<string,mixed> $field
 * @param list<array<string,mixed>> $usersList
 */
function _cf_render_field(array $field, array $customFieldValues, array $usersList): void
{
    $type = (string) ($field['field_type'] ?? 'text');
    $label = (string) ($field['field_label'] ?? 'Field');
    $fid = (int) ($field['id'] ?? 0);
    $required = !empty($field['is_required']);
    $placeholder = trim((string) ($field['placeholder'] ?? ''));
    $help = trim((string) ($field['help_text_display'] ?? $field['help_text'] ?? ''));
    if (str_starts_with($help, 'placeholder:')) {
        $parts = explode("\n", substr($help, strlen('placeholder:')), 2);
        $help = isset($parts[1]) ? trim($parts[1]) : '';
    }
    $value = _cf_posted_value($field, $customFieldValues);
    $name = 'custom_fields[' . $fid . ']';
    $reqAttr = $required ? ' required' : '';
    $opts = ticket_custom_field_options_list(isset($field['field_options']) ? (string) $field['field_options'] : null);

    if ($type === 'divider') {
        echo '<hr class="my-3">';
        return;
    }
    if ($type === 'section') {
        echo '<div class="col-12"><div class="fw-bold small text-uppercase text-muted mb-1">' . e($label) . '</div></div>';
        return;
    }
    if ($type === 'instruction') {
        $instr = trim((string) ($field['instruction_text'] ?? $help));
        echo '<div class="col-12"><div class="alert alert-info border small mb-0">';
        if ($label !== '') {
            echo '<div class="fw-semibold mb-1">' . e($label) . '</div>';
        }
        echo '<div style="white-space:pre-wrap;">' . nl2br(e($instr)) . '</div></div></div></div>';
        return;
    }
    if ($type === 'file') {
        echo '<div class="col-12"><div class="alert alert-light border small py-2 mb-0">';
        echo '<strong>' . e($label) . '</strong> — use the Attachments section below.';
        echo '</div></div>';
        return;
    }

    echo '<div class="col-12">';
    echo '<label class="form-label fw-semibold small" for="cf_' . $fid . '">' . e($label);
    if ($required) {
        echo ' <span class="text-danger">*</span>';
    }
    echo '</label>';

    if ($type === 'paragraph' || $type === 'textarea') {
        echo '<textarea class="form-control" id="cf_' . $fid . '" name="' . e($name) . '" rows="4"' . $reqAttr;
        if ($placeholder !== '') {
            echo ' placeholder="' . e($placeholder) . '"';
        }
        echo '>' . e($value) . '</textarea>';
    } elseif ($type === 'dropdown' || $type === 'select') {
        echo '<select class="form-select" id="cf_' . $fid . '" name="' . e($name) . '"' . $reqAttr . '>';
        echo '<option value="">Select…</option>';
        foreach ($opts as $opt) {
            $sel = $value === $opt ? ' selected' : '';
            echo '<option value="' . e($opt) . '"' . $sel . '>' . e($opt) . '</option>';
        }
        echo '</select>';
    } elseif ($type === 'radio') {
        if (!$opts) {
            $opts = ['Yes', 'No'];
        }
        echo '<div class="d-flex flex-wrap gap-3 pt-1">';
        foreach ($opts as $i => $opt) {
            $rid = 'cf_' . $fid . '_' . $i;
            $chk = $value === $opt ? ' checked' : '';
            echo '<div class="form-check">';
            echo '<input class="form-check-input" type="radio" id="' . e($rid) . '" name="' . e($name) . '" value="' . e($opt) . '"' . $chk;
            if ($required) {
                echo ' required';
            }
            echo '>';
            echo '<label class="form-check-label small" for="' . e($rid) . '">' . e($opt) . '</label>';
            echo '</div>';
        }
        echo '</div>';
    } elseif ($type === 'checkbox') {
        $checked = $value !== '' && $value !== '0' && strtolower($value) !== 'no';
        echo '<div class="form-check pt-1">';
        echo '<input class="form-check-input" type="checkbox" id="cf_' . $fid . '" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '');
        if ($required) {
            echo ' required';
        }
        echo '>';
        echo '<label class="form-check-label small" for="cf_' . $fid . '">' . e($placeholder !== '' ? $placeholder : 'Yes') . '</label>';
        echo '</div>';
    } elseif ($type === 'user_selector') {
        echo '<select class="form-select" id="cf_' . $fid . '" name="' . e($name) . '"' . $reqAttr . '>';
        echo '<option value="">Select user…</option>';
        foreach ($usersList as $uu) {
            $uid = (int) $uu['id'];
            $sel = ((string) $uid === $value || (string) $uid === (string) (int) $value) ? ' selected' : '';
            echo '<option value="' . $uid . '"' . $sel . '>' . e((string) $uu['full_name']) . '</option>';
        }
        echo '</select>';
    } else {
        $inputType = 'text';
        if ($type === 'email') {
            $inputType = 'email';
        } elseif ($type === 'number') {
            $inputType = 'number';
        } elseif ($type === 'date') {
            $inputType = 'date';
        }
        echo '<input class="form-control" type="' . e($inputType) . '" id="cf_' . $fid . '" name="' . e($name) . '" value="' . e($value) . '"' . $reqAttr;
        if ($placeholder !== '') {
            echo ' placeholder="' . e($placeholder) . '"';
        }
        echo '>';
    }

    if ($help !== '') {
        echo '<div class="form-text text-muted small">' . e($help) . '</div>';
    }
    echo '</div>';
}

$sectionTitle = $customFieldsSectionTitle ?? 'Request-specific information';

$hasRenderable = false;
foreach ($templateFields as $_cf) {
    $t = (string) ($_cf['field_type'] ?? 'text');
    if (ticket_custom_field_is_input_type($t) || in_array($t, ['divider', 'section', 'file', 'instruction'], true)) {
        $hasRenderable = true;
        break;
    }
}

if (!$hasRenderable) {
    return;
}
?>
<div class="col-12">
    <div class="card border-0 bg-light-subtle mt-1 mb-2">
        <div class="card-body p-3 p-lg-4">
            <h2 class="h6 fw-bold mb-1"><?php echo e($sectionTitle); ?></h2>
            <div class="row g-3">
                <?php foreach ($templateFields as $field) :
                    _cf_render_field($field, $customFieldValues, $usersList);
                endforeach; ?>
            </div>
        </div>
    </div>
</div>
