<?php
declare(strict_types=1);

/** @var array<string,mixed> $analyticsPayload */
if (empty($analyticsPayload['can_view'])) {
    $personal = $analyticsPayload['personal'] ?? [];
    if (!$personal) {
        return;
    }
    ?>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <h2 class="h6 fw-bold mb-0">My work</h2>
            <p class="small text-muted mb-2">Your requests and assignments</p>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <div class="label">My Open Requests</div>
                <div class="value"><?php echo (int) ($personal['my_open'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <div class="label">My Active Work</div>
                <div class="value"><?php echo (int) ($personal['my_active_work'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <div class="label">My Closed Tickets</div>
                <div class="value"><?php echo (int) ($personal['my_closed'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <div class="label">My Completed Tickets</div>
                <div class="value"><?php echo (int) ($personal['my_completed'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <div class="label">My Stuck Tickets</div>
                <div class="value"><?php echo (int) ($personal['my_stuck'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stat-card">
                <div class="label">My Overdue Tickets</div>
                <div class="value"><?php echo (int) ($personal['my_overdue'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <?php
    return;
}

$perf = $analyticsPayload['performance'] ?? [];
?>
<div class="mb-3 d-flex flex-wrap justify-content-between align-items-end gap-2">
    <div>
        <h2 class="h5 fw-bold mb-1">Analytics &amp; Performance</h2>
        <p class="small text-muted mb-0"><?php echo e((string) ($analyticsPayload['scope_label'] ?? '')); ?></p>
    </div>
    <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
        <?php foreach ($_GET as $k => $v) :
            if ($k === 'analytics_days' || $k === 'org_code') {
                continue;
            }
            if (is_array($v)) {
                continue;
            } ?>
            <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e((string) $v); ?>">
        <?php endforeach; ?>
        <div>
            <label class="form-label small text-muted mb-0">Period</label>
            <select name="analytics_days" class="form-select form-select-sm">
                <option value="7" <?php echo (int) ($analyticsPayload['days'] ?? 30) === 7 ? 'selected' : ''; ?>>Last 7 days</option>
                <option value="30" <?php echo (int) ($analyticsPayload['days'] ?? 30) === 30 ? 'selected' : ''; ?>>Last 30 days</option>
                <option value="90" <?php echo (int) ($analyticsPayload['days'] ?? 30) === 90 ? 'selected' : ''; ?>>Last 90 days</option>
                <option value="0" <?php echo (int) ($analyticsPayload['days'] ?? 30) === 0 ? 'selected' : ''; ?>>All time</option>
            </select>
        </div>
        <button type="submit" class="btn btn-outline-muted btn-sm">Apply</button>
    </form>
</div>

<div class="card-surface p-3 mb-3">
    <h3 class="h6 fw-bold mb-2">Performance Summary</h3>
    <ul class="small mb-0 ps-3">
        <li><?php echo (int) ($perf['received'] ?? 0); ?> tickets received in the selected period.</li>
        <li><?php echo (int) ($perf['done'] ?? 0); ?> tickets were marked Done.</li>
        <li><?php echo (int) ($perf['completed'] ?? 0); ?> tickets were completed.</li>
        <li>Average time to Done: <?php echo e((string) ($perf['avg_days_done'] ?? 0)); ?> days.</li>
        <li>Average time to Completion: <?php echo e((string) ($perf['avg_days_completion'] ?? 0)); ?> days.</li>
        <li><?php echo (int) ($perf['stuck'] ?? 0); ?> tickets are currently Stuck.</li>
        <li><?php echo (int) ($perf['overdue'] ?? 0); ?> tickets are Overdue / SLA risk.</li>
    </ul>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card-surface p-3 h-100 chart-card chart-card-tall">
            <div class="fw-bold mb-2">Tickets by Account Route</div>
            <div class="chart-container"><canvas id="chartAccountRoute"></canvas></div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card-surface p-3 h-100 chart-card chart-card-tall">
            <div class="fw-bold mb-2">Tickets by Request Type</div>
            <div class="chart-container"><canvas id="chartRequestType"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="card-surface p-3 chart-card chart-card-tall">
            <div class="fw-bold mb-2">Ticket Count by Assigned User</div>
            <div class="chart-container"><canvas id="chartAssignedUser"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card-surface p-3 chart-card chart-card-tall">
            <div class="fw-bold mb-2">Average Time to Done by User (hours)</div>
            <div class="chart-container"><canvas id="chartDoneByUser"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-surface p-3 chart-card chart-card-tall">
            <div class="fw-bold mb-2">Tickets Done by User</div>
            <div class="chart-container"><canvas id="chartDoneCountUser"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card-surface p-3 chart-card chart-card-tall">
            <div class="fw-bold mb-2">Received vs Completed Trend</div>
            <div class="chart-container"><canvas id="chartAnalyticsTrend"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-surface p-3 chart-card chart-card-tall">
            <div class="fw-bold mb-2">SLA Risk / Stuck / Open</div>
            <div class="chart-container"><canvas id="chartSlaSummary"></canvas></div>
        </div>
    </div>
</div>
