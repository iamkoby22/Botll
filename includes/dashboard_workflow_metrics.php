<?php
declare(strict_types=1);

/** @var array{awaiting_level:int,stale_level:int,reopened:int,by_level:array<int,int>} $wf */
if (!isset($wf)) {
    return;
}
?>
<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3">
        <div class="stat-card">
            <div class="label">Awaiting next level</div>
            <div class="value"><?php echo (int) $wf['awaiting_level']; ?></div>
            <div class="meta">Active assignment level</div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="stat-card">
            <div class="label">Level 1 / 2 / 3 active</div>
            <div class="value small"><?php echo (int) ($wf['by_level'][1] ?? 0); ?> / <?php echo (int) ($wf['by_level'][2] ?? 0); ?> / <?php echo (int) ($wf['by_level'][3] ?? 0); ?></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="stat-card">
            <div class="label">Stale at level (3+ days)</div>
            <div class="value"><?php echo (int) $wf['stale_level']; ?></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="stat-card">
            <div class="label">Reopened tickets</div>
            <div class="value"><?php echo (int) $wf['reopened']; ?></div>
        </div>
    </div>
</div>
