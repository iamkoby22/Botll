<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('new_request');

if (!request_logic_tables_exist()) {
    flash_set('danger', 'Request Logic is not installed. Run database/migration_011_request_logic_refactor.sql');
    redirect('dashboard.php');
}

$pdo = db();
$u = current_user();
$uid = (int) $u['id'];
$departments = ref_active_departments();
$requestTypes = request_logic_request_types();
$expectedRequestTypeCount = 6;

$errors = [];
$defaultDept = (int) ($u['department_id'] ?? 0);
$vals = [
    'department_id' => (string) ($_POST['department_id'] ?? ($defaultDept > 0 ? (string) $defaultDept : '')),
    'request_type' => trim((string) ($_POST['request_type'] ?? '')),
    'step1' => trim((string) ($_POST['step1'] ?? '')),
    'step2' => trim((string) ($_POST['step2'] ?? '')),
    'request_logic_id' => (string) ($_POST['request_logic_id'] ?? ''),
];

$logicFields = [];
$customFieldValues = [];
$activeLogic = null;

if (is_post()) {
    $postedCustom = $_POST['custom_fields'] ?? [];
    $customFieldValues = is_array($postedCustom) ? $postedCustom : [];
}

$logicId = (int) $vals['request_logic_id'];
if ($logicId < 1 && $vals['request_type'] !== '') {
    $activeLogic = request_logic_resolve_path(
        $vals['request_type'],
        $vals['step1'],
        $vals['step2'] !== '' ? $vals['step2'] : null
    );
    $logicId = $activeLogic ? (int) $activeLogic['id'] : 0;
}
if ($logicId > 0) {
    $activeLogic = request_logic_fetch_by_id($logicId);
    $logicFields = request_logic_fields_for_form($logicId);
}

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $dept = (int) $vals['department_id'];
        if ($dept < 1) {
            $errors[] = 'Department is required.';
        }
        if ($vals['request_type'] === '') {
            $errors[] = 'Request Type is required.';
        }
        if (!$activeLogic) {
            $errors[] = 'Select a valid request path (Request Type, Step 1, and Step 2 if shown).';
        } else {
            if (request_logic_type_requires_step1($vals['request_type']) && $vals['step1'] === '') {
                $errors[] = 'Step 1 is required for this Request Type.';
            }
            $s2opts = $vals['step1'] !== '' ? request_logic_step2_options($vals['request_type'], $vals['step1']) : [];
            if ($s2opts && $vals['step2'] === '') {
                $errors[] = 'Step 2 is required for this path.';
            }
        }

        $collected = [];
        if ($activeLogic && $logicFields) {
            $collected = request_logic_validate_fields($logicFields, $customFieldValues, $errors);
        }

        if (!$errors && $activeLogic) {
            $path = $activeLogic;
            $logicId = (int) $path['id'];
            $cat = (int) ($path['default_category_id'] ?? 0);
            $pri = (int) ($path['default_priority_id'] ?? 0);
            if ($cat < 1) {
                $cat = (int) ($pdo->query('SELECT id FROM ticket_categories ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
            }
            if ($pri < 1) {
                $pri = (int) ($pdo->query('SELECT id FROM ticket_priorities ORDER BY priority_level LIMIT 1')->fetchColumn() ?: 1);
            }
            $statusId = (int) ($pdo->query('SELECT id FROM ticket_statuses WHERE status_name="Open" LIMIT 1')->fetchColumn() ?: 1);

            $requesterName = (string) $u['full_name'];
            $requesterEmail = (string) $u['email'];
            $subject = request_logic_ticket_subject($path);
            $desc = 'SBS Support Request';
            $routePreview = sbs_parse_account_route_from_collected($collected);

            $pdo->beginTransaction();
            try {
                $max = (int) $pdo->query('SELECT IFNULL(MAX(id),0) m FROM tickets')->fetch()['m'];
                $ticketNumber = sprintf('TKT-%s-%04d', date('Y'), $max + 1);

                $routeCols = '';
                $routeVals = '';
                $routeParams = [];
                if (sbs_workflow_enabled()) {
                    $routeCols = ', account_route, routed_pillar, routed_at, response_target_hours, resolution_target_hours, last_activity_at';
                    $routeVals = ',?,?,NOW(),168,336,NOW()';
                    $routeParams = [$routePreview['account_route'], $routePreview['routed_pillar']];
                }
                $insSql = 'INSERT INTO tickets (ticket_number, subject, description, category_id, priority_id, status_id, department_id,
                    created_by, request_logic_id, requester_name_snapshot, requester_email_snapshot, request_type, request_step1, request_step2, created_at'
                    . $routeCols . ')
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()' . $routeVals . ')';
                $pdo->prepare($insSql)->execute(array_merge([
                    $ticketNumber,
                    $subject,
                    $desc,
                    $cat,
                    $pri,
                    $statusId,
                    $dept,
                    $uid,
                    $logicId,
                    $requesterName,
                    $requesterEmail,
                    (string) $path['request_type'],
                    (string) ($path['step1'] ?? ''),
                    (string) ($path['step2'] ?? ''),
                ], $routeParams));
                $tid = (int) $pdo->lastInsertId();

                if ($collected) {
                    request_logic_field_values_save($tid, $logicId, $collected);
                    sbs_apply_routing_on_create($tid, $collected);
                } elseif (sbs_workflow_enabled()) {
                    sbs_apply_routing_on_create($tid, []);
                }

                $dir = UPLOAD_PATH . '/' . $tid;
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $atts = 0;
                if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
                    $names = $_FILES['attachments']['name'];
                    $tmp = $_FILES['attachments']['tmp_name'];
                    $sizes = $_FILES['attachments']['size'];
                    $types = $_FILES['attachments']['type'];
                    for ($i = 0, $c = count($names); $i < $c; $i++) {
                        if (empty($tmp[$i]) || !is_uploaded_file($tmp[$i])) {
                            continue;
                        }
                        $orig = (string) $names[$i];
                        if (!ticket_attachment_allowed($orig)) {
                            continue;
                        }
                        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
                        if (move_uploaded_file($tmp[$i], $dir . '/' . $safe)) {
                            $atts++;
                            $pdo->prepare(
                                'INSERT INTO ticket_attachments (ticket_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?)'
                            )->execute([$tid, $orig, 'uploads/tickets/' . $tid . '/' . $safe, (string) ($types[$i] ?? ''), (int) ($sizes[$i] ?? 0), $uid]);
                        }
                    }
                    if ($atts > 0) {
                        $pdo->prepare('UPDATE tickets SET attachments_count = ? WHERE id = ?')->execute([$atts, $tid]);
                    }
                }

                try {
                    ticket_log_comment($tid, $uid, 'SBS support request submitted.', 'system');
                } catch (Throwable $e) {
                }

                $pdo->commit();
                $routedTicket = ticket_fetch_by_id($tid);
                $routeKey = (string) ($routedTicket['account_route'] ?? $routePreview['account_route'] ?? 'general');
                if (function_exists('sbs_routing_success_flash_message') && sbs_ticket_is_routed($routedTicket ?: [])) {
                    flash_set('success', sbs_routing_success_flash_message($ticketNumber, $routeKey));
                } else {
                    flash_set('success', 'Request ' . $ticketNumber . ' submitted.');
                }
                redirect('ticket_detail.php?id=' . $tid);
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('new_request submit: ' . $e->getMessage());
                $errors[] = 'Could not save request. If migration 011 was not applied, run it and try again.';
            }
        }
    }
}

$pageTitle = 'New Request';
$activeNav = 'create_ticket';
$includeCharts = false;
$customFieldsSectionTitle = 'Request-specific information';
$templateFields = $logicFields;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1>New Request</h1>
        <div class="subtitle">Submit request information only — routing and assignment happen after submission.</div>
    </div>

    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>

    <?php if (!$requestTypes) : ?>
        <div class="alert alert-warning">No Request Logic paths are active. A Super Admin must configure Request Logic first.</div>
    <?php elseif (count($requestTypes) < $expectedRequestTypeCount) : ?>
        <div class="alert alert-warning">Only <?php echo count($requestTypes); ?> of <?php echo (int) $expectedRequestTypeCount; ?> expected request types are active. A Super Admin should review Request Logic or run database/migration_012_request_logic_repair.sql.</div>
    <?php endif; ?>

    <form class="card-surface p-3 p-lg-4" method="post" enctype="multipart/form-data" id="intakeForm">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="request_logic_id" id="request_logic_id" value="<?php echo e($vals['request_logic_id']); ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Requester Name</label>
                <input class="form-control" type="text" value="<?php echo e((string) $u['full_name']); ?>" readonly>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Requester Email Address</label>
                <input class="form-control" type="email" value="<?php echo e((string) $u['email']); ?>" readonly>
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold small">Department</label>
                <select name="department_id" class="form-select" required>
                    <option value="">Select department</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo ((string) $d['id'] === $vals['department_id']) ? 'selected' : ''; ?>><?php echo e(department_display_label($d)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold small">Request Type</label>
                <select name="request_type" id="request_type" class="form-select" required>
                    <option value="">Select request type</option>
                    <?php foreach ($requestTypes as $rt) : ?>
                        <option value="<?php echo e($rt); ?>" <?php echo $vals['request_type'] === $rt ? 'selected' : ''; ?>><?php echo e($rt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="apiLoadError" class="col-12 alert alert-danger d-none mb-0" role="alert"></div>
            <div class="col-md-6 d-none" id="wrap_step1">
                <label class="form-label fw-semibold small">Step 1</label>
                <select name="step1" id="step1" class="form-select"></select>
            </div>
            <div class="col-md-6 d-none" id="wrap_step2">
                <label class="form-label fw-semibold small">Step 2</label>
                <select name="step2" id="step2" class="form-select"></select>
            </div>
        </div>

        <div id="dynamicFields" class="row g-3 mt-2">
            <?php
            if ($logicFields) {
                echo '<div class="col-12"><hr class="my-2"></div>';
                $customFieldsSectionTitle = 'Request-specific information';
                $templateFields = $logicFields;
                require __DIR__ . '/includes/custom_fields_form.php';
            }
            ?>
        </div>

        <div class="row g-3 mt-2">
        <div class="col-12 mt-1">
            <label class="form-label fw-semibold small">Attachments</label>
            <input class="form-control" type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,image/*">
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 pt-3">
            <a class="btn btn-outline-muted" href="requests.php">Cancel</a>
            <button type="submit" class="btn btn-accent" id="btnSubmit" <?php echo $requestTypes ? '' : 'disabled'; ?>>Submit request</button>
        </div>
        </div>
    </form>
</div>

<script src="assets/js/request_logic_intake_fields.js?v=<?php echo (int) @filemtime(__DIR__ . '/assets/js/request_logic_intake_fields.js'); ?>"></script>
<script>
(function () {
  const api = 'api/request_logic_options.php';
  const typeEl = document.getElementById('request_type');
  const s1El = document.getElementById('step1');
  const s2El = document.getElementById('step2');
  const wrap1 = document.getElementById('wrap_step1');
  const wrap2 = document.getElementById('wrap_step2');
  const logicIdEl = document.getElementById('request_logic_id');
  const dyn = document.getElementById('dynamicFields');
  const apiErr = document.getElementById('apiLoadError');
  const initType = <?php echo json_encode($vals['request_type']); ?>;
  const initS1 = <?php echo json_encode($vals['step1']); ?>;
  const initS2 = <?php echo json_encode($vals['step2']); ?>;
  const API_FAIL_MSG = 'Could not load request options. Please try again or contact admin.';
  const TYPE_FAIL_MSG = 'Could not load request logic for this request type.';

  function pickOptions(data, legacyKey) {
    if (Array.isArray(data.options)) return data.options;
    if (legacyKey && Array.isArray(data[legacyKey])) return data[legacyKey];
    return [];
  }

  function showApiError(msg) {
    if (!apiErr) return;
    apiErr.textContent = msg || API_FAIL_MSG;
    apiErr.classList.remove('d-none');
  }

  function clearApiError() {
    if (!apiErr) return;
    apiErr.textContent = '';
    apiErr.classList.add('d-none');
  }

  function fetchJson(url) {
    return fetch(url, { credentials: 'same-origin' })
      .then(r => r.json().then(body => ({ okHttp: r.ok, body })))
      .then(({ okHttp, body }) => {
        if (!body || typeof body !== 'object') throw new Error(API_FAIL_MSG);
        if (!okHttp || body.ok === false) throw new Error(body.error || API_FAIL_MSG);
        return body;
      });
  }

  function resetSteps() {
    s1El.value = '';
    s2El.value = '';
    wrap1.classList.add('d-none');
    wrap2.classList.add('d-none');
    s1El.removeAttribute('required');
    s2El.removeAttribute('required');
    logicIdEl.value = '';
    dyn.innerHTML = '';
  }

  function fillSelect(sel, items, selected) {
    sel.innerHTML = '<option value="">Select…</option>';
    items.forEach(v => {
      const o = document.createElement('option');
      o.value = v;
      o.textContent = v;
      if (v === selected) o.selected = true;
      sel.appendChild(o);
    });
  }

  function loadStep1(type) {
    return fetchJson(api + '?action=step1&request_type=' + encodeURIComponent(type));
  }

  function loadStep2(type, s1) {
    return fetchJson(api + '?action=step2&request_type=' + encodeURIComponent(type) + '&step1=' + encodeURIComponent(s1));
  }

  function loadPath() {
    const type = typeEl.value;
    const s1 = s1El.value || '';
    const s2 = s2El.value || '';
    if (!type) {
      logicIdEl.value = '';
      dyn.innerHTML = '';
      return Promise.resolve();
    }
    if (!wrap1.classList.contains('d-none') && !s1) {
      logicIdEl.value = '';
      dyn.innerHTML = '';
      return Promise.resolve();
    }
    if (!wrap2.classList.contains('d-none') && !s2) {
      logicIdEl.value = '';
      dyn.innerHTML = '';
      return Promise.resolve();
    }
    let url = api + '?action=path&request_type=' + encodeURIComponent(type) + '&step1=' + encodeURIComponent(s1);
    if (s2) url += '&step2=' + encodeURIComponent(s2);
    return fetchJson(url).then(data => {
      clearApiError();
      logicIdEl.value = data.logic_id || '';
      renderFields(data.fields || []);
    }).catch(err => {
      logicIdEl.value = '';
      dyn.innerHTML = '';
      showApiError(err.message || TYPE_FAIL_MSG);
    });
  }

  function renderFields(fields) {
    if (window.RequestLogicIntakeFields) {
      window.RequestLogicIntakeFields.renderFields(dyn, fields || []);
      return;
    }
    dyn.innerHTML = '';
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  typeEl.addEventListener('change', () => {
    clearApiError();
    resetSteps();
    const type = typeEl.value;
    if (!type) return;
    loadStep1(type).then(data => {
      clearApiError();
      const items = pickOptions(data, 'step1');
      const direct = data.load_fields_directly || data.no_step1 || (!items.length && data.ok);
      if (!items.length) {
        wrap1.classList.add('d-none');
        s1El.removeAttribute('required');
        if (direct) return loadPath();
        showApiError(TYPE_FAIL_MSG);
        return;
      }
      wrap1.classList.remove('d-none');
      s1El.setAttribute('required', 'required');
      fillSelect(s1El, items, '');
      if (items.length === 1) {
        s1El.value = items[0];
        s1El.dispatchEvent(new Event('change'));
      }
    }).catch(err => showApiError(err.message || TYPE_FAIL_MSG));
  });

  s1El.addEventListener('change', () => {
    clearApiError();
    s2El.value = '';
    wrap2.classList.add('d-none');
    s2El.removeAttribute('required');
    logicIdEl.value = '';
    dyn.innerHTML = '';
    const type = typeEl.value;
    const s1 = s1El.value;
    if (!type || !s1) return;
    loadStep2(type, s1).then(data => {
      clearApiError();
      const items = pickOptions(data, 'step2');
      if (!items.length) return loadPath();
      wrap2.classList.remove('d-none');
      s2El.setAttribute('required', 'required');
      fillSelect(s2El, items, '');
    }).catch(err => showApiError(err.message || TYPE_FAIL_MSG));
  });

  s2El.addEventListener('change', () => {
    clearApiError();
    loadPath();
  });

  if (initType) {
    loadStep1(initType).then(data => {
      const items = pickOptions(data, 'step1');
      const direct = data.load_fields_directly || data.no_step1 || (!items.length && data.ok);
      if (!items.length) {
        if (direct) return loadPath();
        showApiError(TYPE_FAIL_MSG);
        return;
      }
      wrap1.classList.remove('d-none');
      s1El.setAttribute('required', 'required');
      fillSelect(s1El, items, initS1);
      if (!initS1) return;
      return loadStep2(initType, initS1).then(d2 => {
        const s2items = pickOptions(d2, 'step2');
        if (s2items.length) {
          wrap2.classList.remove('d-none');
          s2El.setAttribute('required', 'required');
          fillSelect(s2El, s2items, initS2);
        }
        return loadPath();
      });
    }).catch(err => showApiError(err.message || TYPE_FAIL_MSG));
  }
})();
</script>

<?php require __DIR__ . '/includes/shell_end.php'; ?>

