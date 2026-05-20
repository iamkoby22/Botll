<?php
declare(strict_types=1);

/** @var list<array<string,mixed>> $usersList */
$usersList = $usersList ?? [];
$maxRoutingLevels = 5;
?>
<div class="col-12"><hr class="my-2"></div>
<div class="col-12">
    <h2 class="h6 fw-bold mb-1">Work Assignment Routing</h2>
    <p class="small text-muted mb-2">Assigned users perform the work in level order. Level 1 is active first; the final level marks Done when work is complete. This is separate from formal approval below.</p>
    <div id="assigneeRows" class="d-flex flex-column gap-2">
        <div class="d-flex gap-2 align-items-center flex-wrap assignee-row routing-row">
            <select class="form-select flex-grow-1" name="assignee_id[]" aria-label="Assigned user">
                <option value="">Select assigned user</option>
                <?php foreach ($usersList as $uu) : ?>
                    <option value="<?php echo (int) $uu['id']; ?>"><?php echo e((string) $uu['full_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select routing-level-select" name="assignee_level[]" style="max-width:130px;" aria-label="Assignment level">
                <?php for ($lv = 1; $lv <= $maxRoutingLevels; $lv++) : ?>
                    <option value="<?php echo $lv; ?>" <?php echo $lv === 1 ? 'selected' : ''; ?>>Level <?php echo $lv; ?></option>
                <?php endfor; ?>
            </select>
            <button type="button" class="btn btn-outline-danger btn-sm routing-remove d-none" title="Remove row" aria-label="Remove assignment row"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
    <button type="button" class="btn btn-link btn-sm px-0 mt-2" id="addAssigneeRow"><i class="bi bi-plus-circle"></i> Add Assigned User</button>
</div>

<div class="col-12 mt-2">
    <h2 class="h6 fw-bold mb-1">Approval Routing</h2>
    <p class="small text-muted mb-2">Approvers formally approve or reject the request in level order. One approver at level 1 is the final approver; with multiple levels, the highest level is final after prior levels approve.</p>
    <div id="approverRows" class="d-flex flex-column gap-2">
        <div class="d-flex gap-2 align-items-center flex-wrap approver-row routing-row">
            <select class="form-select flex-grow-1" name="approver_id[]" aria-label="Approver">
                <option value="">Select approver</option>
                <?php foreach ($usersList as $uu) : ?>
                    <option value="<?php echo (int) $uu['id']; ?>"><?php echo e((string) $uu['full_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select routing-level-select" name="approval_level[]" style="max-width:130px;" aria-label="Approval level">
                <?php for ($lv = 1; $lv <= $maxRoutingLevels; $lv++) : ?>
                    <option value="<?php echo $lv; ?>" <?php echo $lv === 1 ? 'selected' : ''; ?>>Level <?php echo $lv; ?></option>
                <?php endfor; ?>
            </select>
            <button type="button" class="btn btn-outline-danger btn-sm routing-remove d-none" title="Remove row" aria-label="Remove approver row"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
    <button type="button" class="btn btn-link btn-sm px-0 mt-2" id="addApproverRow"><i class="bi bi-plus-circle"></i> Add Approver</button>
</div>

<script>
(function () {
  function nextLevel(wrap) {
    return wrap.querySelectorAll('.routing-row').length + 1;
  }

  function syncRemoveButtons(wrap) {
    const rows = wrap.querySelectorAll('.routing-row');
    rows.forEach((row) => {
      const btn = row.querySelector('.routing-remove');
      if (btn) btn.classList.toggle('d-none', rows.length <= 1);
    });
  }

  function addRow(wrap, rowClass) {
    const tpl = wrap.querySelector('.' + rowClass);
    if (!tpl) return;
    const clone = tpl.cloneNode(true);
    clone.querySelectorAll('select').forEach((sel) => {
      if (sel.name && sel.name.indexOf('level') !== -1) {
        const lvl = Math.min(5, nextLevel(wrap));
        sel.value = String(lvl);
      } else {
        sel.selectedIndex = 0;
      }
    });
    wrap.appendChild(clone);
    syncRemoveButtons(wrap);
  }

  function bindRouting(wrapId, addId, rowClass) {
    const wrap = document.getElementById(wrapId);
    const addBtn = document.getElementById(addId);
    if (!wrap || !addBtn) return;
    syncRemoveButtons(wrap);
    addBtn.addEventListener('click', () => addRow(wrap, rowClass));
    wrap.addEventListener('click', (e) => {
      const btn = e.target.closest('.routing-remove');
      if (!btn || !wrap.contains(btn)) return;
      const row = btn.closest('.routing-row');
      if (!row || wrap.querySelectorAll('.routing-row').length <= 1) return;
      row.remove();
      syncRemoveButtons(wrap);
    });
  }

  bindRouting('assigneeRows', 'addAssigneeRow', 'assignee-row');
  bindRouting('approverRows', 'addApproverRow', 'approver-row');
})();
</script>
