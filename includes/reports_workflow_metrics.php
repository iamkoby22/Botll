<?php
declare(strict_types=1);

/** @var array{awaiting_level:int,reopened:int,by_level:array<int,int>} $wfRep */
/** @var int $awaitingAudit */
?>
<div class="row g-3 mb-3">
    <div class="col-md-4 col-xl-3">
        <div class="stat-card"><div class="label">Active assignment level</div><div class="value"><?php echo (int) $wfRep['awaiting_level']; ?></div></div>
    </div>
    <div class="col-md-4 col-xl-3">
        <div class="stat-card"><div class="label">Level 1 / 2 / 3</div><div class="value small"><?php echo (int) ($wfRep['by_level'][1] ?? 0); ?> / <?php echo (int) ($wfRep['by_level'][2] ?? 0); ?> / <?php echo (int) ($wfRep['by_level'][3] ?? 0); ?></div></div>
    </div>
    <div class="col-md-4 col-xl-3">
        <div class="stat-card"><div class="label">Closed awaiting audit</div><div class="value"><?php echo (int) $awaitingAudit; ?></div></div>
    </div>
    <div class="col-md-4 col-xl-3">
        <div class="stat-card"><div class="label">Reopened</div><div class="value"><?php echo (int) $wfRep['reopened']; ?></div></div>
    </div>
</div>

