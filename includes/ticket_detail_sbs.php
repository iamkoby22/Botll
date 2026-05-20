<?php
declare(strict_types=1);

/** SBS workflow panels for ticket_detail.php — expects $ticket, $ticketId, $uid */

$sbsTicket = sbs_ticket_is_routed($ticket);
if (!$sbsTicket) {
    return;
}

$priorities = $priorities ?? db()->query('SELECT * FROM ticket_priorities ORDER BY priority_level')->fetchAll();
$baUsers = sbs_users_by_level('business_admin');
$coordUsers = sbs_users_by_level('coordinator');
$canReassign = sbs_can_reassign($ticket);
$canDone = sbs_can_mark_done($ticket);
$canComplete = sbs_can_complete($ticket);
$canReassignCoord = sbs_can_reassign_to_coordinator($ticket);
$canPriority = sbs_can_change_priority($ticket);
$canArchive = ticket_can_archive($ticket);
$canNotify = sbs_can_show_notify_me($ticket);
$subscribed = sbs_is_subscribed($ticketId, $uid);
$route = (string) ($ticket['account_route'] ?? '');
$pillar = (string) ($ticket['routed_pillar'] ?? '');
$assignedType = (string) ($ticket['assigned_type'] ?? '');
$isArchived = !empty($ticket['archived_at']);
$priorityName = (string) ($ticket['priority_name'] ?? '—');
?>
<?php if ($isArchived) : ?>
<div class="alert alert-secondary small mb-3">This ticket is <strong>Archived</strong><?php echo !empty($ticket['archive_reason']) ? ' (' . e((string) $ticket['archive_reason']) . ')' : ''; ?>.
</div>
<?php endif; ?>

<div class="card-surface p-3 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 class="h6 fw-bold mb-0">Routing Information</h2>
        <?php if ($canArchive) : ?>
        <form method="post" class="mb-0" onsubmit="return confirm('Archive this ticket?');">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="sbs_archive">
            <button type="submit" class="btn btn-outline-muted btn-sm">Archive</button>
        </form>
        <?php endif; ?>
    </div>
    <dl class="row g-2 mb-0 small">
        <dt class="col-sm-4 text-muted">Account Route</dt>
        <dd class="col-sm-8 fw-semibold"><?php echo e(sbs_route_label($route)); ?></dd>
        <dt class="col-sm-4 text-muted">Routed To</dt>
        <dd class="col-sm-8 fw-semibold"><?php echo e(sbs_pillar_label($pillar)); ?></dd>
        <?php if (!empty($ticket['assigned_to'])) : ?>
        <dt class="col-sm-4 text-muted">Assigned</dt>
        <dd class="col-sm-8 fw-semibold"><?php echo e((string) ($ticket['assignee_name'] ?? '')); ?>
            <span class="text-muted">(<?php echo e(str_replace('_', ' ', $assignedType)); ?>)</span></dd>
        <?php endif; ?>
    </dl>
</div>

<div class="card-surface p-3 mb-3">
    <h2 class="h6 fw-bold mb-2">Priority</h2>
    <p class="small mb-2">
        <span class="badge text-bg-light border"><?php echo e($priorityName); ?></span>
        <?php if (!$canPriority) : ?>
            <span class="text-muted ms-1">(view only)</span>
        <?php endif; ?>
    </p>
    <?php if ($canPriority) : ?>
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="sbs_priority">
        <div class="col-md-8">
            <select name="priority_id" class="form-select form-select-sm" aria-label="Change priority">
                <?php foreach ($priorities as $pr) : ?>
                    <option value="<?php echo (int) $pr['id']; ?>" <?php echo ((int) $pr['id'] === (int) $ticket['priority_id']) ? 'selected' : ''; ?>><?php echo e($pr['priority_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button class="btn btn-accent btn-sm w-100" type="submit">Update priority</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php if ($canReassign) : ?>
<div class="card-surface p-3 mb-3">
    <h2 class="h6 fw-bold mb-3"><?php echo empty($ticket['assigned_to']) ? 'Assignment' : 'Reassign'; ?></h2>
    <form method="post" class="row g-2" id="sbsAssignForm">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="sbs_assign">
        <div class="col-12">
            <label class="form-label small text-muted">Assignment Target Type</label>
            <select name="sbs_assign_type" id="sbs_assign_type" class="form-select form-select-sm" required>
                <option value="">Select type</option>
                <option value="business_admin">Business Admin</option>
                <option value="coordinator">Coordinator</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label small text-muted">User</label>
            <select name="sbs_assign_user_id" id="sbs_assign_user_id" class="form-select form-select-sm" required>
                <option value="">Select user</option>
            </select>
        </div>
        <div class="col-12">
            <button class="btn btn-accent btn-sm" type="submit"><?php echo empty($ticket['assigned_to']) ? 'Assign' : 'Reassign'; ?></button>
        </div>
    </form>
    <script>
    (function () {
      const ba = <?php echo json_encode(array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['full_name']], $baUsers)); ?>;
      const co = <?php echo json_encode(array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['full_name']], $coordUsers)); ?>;
      const typeEl = document.getElementById('sbs_assign_type');
      const userEl = document.getElementById('sbs_assign_user_id');
      function fill() {
        const list = typeEl.value === 'business_admin' ? ba : (typeEl.value === 'coordinator' ? co : []);
        userEl.innerHTML = '<option value="">Select user</option>';
        list.forEach(function (u) {
          const o = document.createElement('option');
          o.value = u.id;
          o.textContent = u.name;
          userEl.appendChild(o);
        });
      }
      typeEl.addEventListener('change', fill);
    })();
    </script>
</div>
<?php endif; ?>

<?php if ($canReassignCoord) : ?>
<div class="card-surface p-3 mb-3">
    <h2 class="h6 fw-bold mb-3">Reassign to Coordinator</h2>
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="sbs_reassign_coordinator">
        <input type="hidden" name="sbs_assign_type" value="coordinator">
        <div class="col-md-8">
            <select name="sbs_assign_user_id" class="form-select form-select-sm" required>
                <option value="">Select coordinator</option>
                <?php foreach ($coordUsers as $cu) : ?>
                    <option value="<?php echo (int) $cu['id']; ?>"><?php echo e($cu['full_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button class="btn btn-accent btn-sm w-100" type="submit">Reassign</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($canNotify) : ?>
<div class="card-surface p-3 mb-3">
    <p class="small text-muted mb-2">You received the initial routing notice. Use Notify Me to receive all future updates on this ticket.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="sbs_notify">
        <button class="btn btn-outline-muted btn-sm" type="submit"><?php echo $subscribed ? 'Stop Notifications' : 'Notify Me'; ?></button>
    </form>
</div>
<?php endif; ?>
