<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/ai_review_generator.php';

ai_review_require_super_admin();

$pdo = db();
$errors = [];
$reportHtml = '';
$reportTitle = '';
$savedId = 0;
$docxAvailable = false;
$docxNotice = '';
$openAiNotice = '';

$viewId = (int) ($_GET['view'] ?? 0);
if ($viewId > 0) {
    $saved = ai_review_load_saved($viewId);
    if ($saved) {
        $reportHtml = (string) ($saved['html_output'] ?? '');
        $reportTitle = (string) ($saved['title'] ?? 'Saved Review');
        $savedId = $viewId;
        $docxAvailable = !empty($saved['docx_path']) && is_file((string) $saved['docx_path']);
    } else {
        $errors[] = 'Saved report not found.';
    }
}

$input = array_merge($_GET, $_POST);
$filters = ai_review_parse_filters($input);

$shouldGenerate = false;
if ($viewId === 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_review'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid session token. Please try again.';
    } else {
        $shouldGenerate = true;
        $action = (string) ($_POST['action'] ?? 'generate');
        if ($action === 'weekly') {
            $filters = ai_review_parse_filters(['period' => 'weekly']);
        } elseif ($action === 'uptodate') {
            $filters = ai_review_parse_filters(['period' => 'uptodate']);
        } else {
            $filters = ai_review_parse_filters($_POST);
        }
    }
} elseif ($viewId === 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $gp = (string) ($_GET['period'] ?? '');
    if ($gp === 'weekly' || $gp === 'uptodate' || $gp === 'up_to_date') {
        $shouldGenerate = true;
        $filters = ai_review_parse_filters(['period' => $gp === 'up_to_date' ? 'uptodate' : $gp]);
    }
}

if ($shouldGenerate) {
    $result = ai_review_execute_generation($filters);
    $reportHtml = (string) ($result['html'] ?? '');
    $reportTitle = (string) ($result['title'] ?? '');
    $savedId = (int) ($result['saved_id'] ?? 0);
    $docxAvailable = !empty($result['docx_available']);
    $docxNotice = (string) ($result['docx_notice'] ?? '');
    $openAiNotice = (string) ($result['openai_notice'] ?? '');
}

$orgCodes = [];
if (analytics_column_exists('departments', 'organization_code')) {
    $orgCodes = $pdo->query(
        'SELECT DISTINCT organization_code FROM departments WHERE organization_code IS NOT NULL AND TRIM(organization_code) <> "" ORDER BY organization_code'
    )->fetchAll(PDO::FETCH_COLUMN);
}

$pastReports = [];
if (ai_review_table_exists()) {
    $pastReports = $pdo->query(
        'SELECT r.id, r.title, r.report_type, r.filters_json, r.created_at, u.full_name, u.username
         FROM ai_review_reports r LEFT JOIN users u ON u.id = r.created_by
         ORDER BY r.created_at DESC LIMIT 25'
    )->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'AI Reviews';
$activeNav = 'ai_reviews';
$includeCharts = !empty($reportHtml);
$includeReportCss = true;

require __DIR__ . '/includes/shell_begin.php';
?>
<link rel="stylesheet" href="assets/css/report.css">

<div class="container-fluid px-3 px-lg-4 pb-4">
    <div class="page-title-block mb-3 d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h1>AI Reviews</h1>
            <div class="subtitle">Executive platform reviews powered by Tilia and live SBS metrics</div>
        </div>
        <?php if ($reportHtml !== '') : ?>
        <div class="report-export-toolbar no-print">
            <a class="btn btn-outline-secondary btn-sm" href="ai_reviews.php"><i class="bi bi-sliders me-1"></i>Back to Filters</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print();"><i class="bi bi-printer me-1"></i>Print Report</button>
            <?php if ($savedId > 0 && $docxAvailable) : ?>
                <a class="btn btn-primary btn-sm" href="ai_review_download.php?id=<?php echo $savedId; ?>&type=docx"><i class="bi bi-file-earmark-word me-1"></i>Download Word</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php foreach ($errors as $err) : ?>
        <div class="alert alert-danger"><?php echo e($err); ?></div>
    <?php endforeach; ?>
    <?php if ($openAiNotice !== '') : ?>
        <div class="alert alert-warning"><?php echo e($openAiNotice); ?></div>
    <?php endif; ?>
    <?php if ($docxNotice !== '') : ?>
        <div class="alert alert-info"><?php echo e($docxNotice); ?></div>
    <?php endif; ?>

    <?php if ($reportHtml === '') : ?>
    <form class="card-surface p-3 mb-4 ai-review-filters" method="post" action="ai_reviews.php">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="generate_review" value="1">
        <h2 class="h6 mb-3">Report filters</h2>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label small">Review type</label>
                <select class="form-select form-select-sm" name="review_type" id="reviewType">
                    <option value="weekly" <?php echo ($filters['review_type'] ?? '') === 'weekly' ? 'selected' : ''; ?>>Weekly Review</option>
                    <option value="monthly" <?php echo ($filters['review_type'] ?? '') === 'monthly' ? 'selected' : ''; ?>>Monthly Review</option>
                    <option value="custom" <?php echo ($filters['review_type'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom Date Range</option>
                    <option value="uptodate" <?php echo ($filters['review_type'] ?? '') === 'uptodate' ? 'selected' : ''; ?>>Up-to-Date Review</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Start date</label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo e((string) ($filters['date_from'] ?? '')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">End date</label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo e((string) ($filters['date_to'] ?? '')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Account route</label>
                <select class="form-select form-select-sm" name="account_route">
                    <option value="">All</option>
                    <option value="restricted" <?php echo ($filters['account_route'] ?? '') === 'restricted' ? 'selected' : ''; ?>>Restricted</option>
                    <option value="unrestricted" <?php echo ($filters['account_route'] ?? '') === 'unrestricted' ? 'selected' : ''; ?>>Unrestricted</option>
                    <option value="general" <?php echo ($filters['account_route'] ?? '') === 'general' ? 'selected' : ''; ?>>General / Not Sure</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Request type</label>
                <select class="form-select form-select-sm" name="request_type">
                    <option value="">All</option>
                    <?php foreach (ai_review_request_type_options() as $rt) : ?>
                        <option value="<?php echo e($rt); ?>" <?php echo ($filters['request_type'] ?? '') === $rt ? 'selected' : ''; ?>><?php echo e($rt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Status</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="">All</option>
                    <?php foreach (['Open', 'Pending', 'Closed', 'Completed', 'Stuck', 'Overdue', 'Archived'] as $st) : ?>
                        <option value="<?php echo e($st); ?>" <?php echo ($filters['status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo e($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Org code</label>
                <select class="form-select form-select-sm" name="org_code">
                    <option value="">All</option>
                    <?php foreach ($orgCodes as $oc) : ?>
                        <option value="<?php echo e((string) $oc); ?>" <?php echo ($filters['org_code'] ?? '') === (string) $oc ? 'selected' : ''; ?>><?php echo e((string) $oc); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Include archived</label>
                <select class="form-select form-select-sm" name="include_archived">
                    <option value="0" <?php echo empty($filters['include_archived']) ? 'selected' : ''; ?>>No</option>
                    <option value="1" <?php echo !empty($filters['include_archived']) ? 'selected' : ''; ?>>Yes</option>
                </select>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3 report-export-toolbar">
            <button type="submit" class="btn btn-accent btn-sm" name="action" value="generate"><i class="bi bi-stars me-1"></i>Generate Review</button>
            <button type="submit" class="btn btn-outline-primary btn-sm" name="action" value="weekly"><i class="bi bi-calendar-week me-1"></i>Run Weekly Review</button>
            <button type="submit" class="btn btn-outline-primary btn-sm" name="action" value="uptodate"><i class="bi bi-calendar-range me-1"></i>Review Up to Date</button>
        </div>
    </form>
    <?php else : ?>
    <div class="ai-report-canvas mb-4">
        <?php echo $reportHtml; ?>
    </div>
    <?php endif; ?>

    <?php if ($pastReports !== []) : ?>
    <section class="card-surface p-3 no-print">
        <h2 class="h6 mb-3">Previous AI Reviews</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Date range</th>
                        <th>Generated by</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pastReports as $pr) :
                    $fj = json_decode((string) ($pr['filters_json'] ?? '{}'), true);
                    $fj = is_array($fj) ? $fj : [];
                    $range = ($fj['date_from'] ?? '') . ' – ' . ($fj['date_to'] ?? '');
                    $by = (string) ($pr['full_name'] ?? $pr['username'] ?? '');
                ?>
                    <tr>
                        <td><?php echo e((string) $pr['title']); ?></td>
                        <td><span class="badge text-bg-light border"><?php echo e((string) $pr['report_type']); ?></span></td>
                        <td class="small"><?php echo e($range); ?></td>
                        <td class="small"><?php echo e($by); ?></td>
                        <td class="small"><?php echo e((string) $pr['created_at']); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="ai_reviews.php?view=<?php echo (int) $pr['id']; ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>

<?php if ($includeCharts && $reportHtml !== '') : ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    function parseChart(el) {
        try { return JSON.parse(el.getAttribute('data-chart') || '{}'); } catch (e) { return {}; }
    }
    function doughnut(id, data, labelKey) {
        var el = document.getElementById(id);
        if (!el || !window.Chart) return;
        var d = parseChart(el);
        var labels = (d.labels || []);
        var values = (d.values || d.counts || []);
        new Chart(el, {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: values, backgroundColor: ['#2563eb','#7c3aed','#059669','#d97706','#dc2626','#64748b'] }] },
            options: { plugins: { legend: { position: 'bottom' } }, maintainAspectRatio: true }
        });
    }
    function lineTrend(id) {
        var el = document.getElementById(id);
        if (!el || !window.Chart) return;
        var d = parseChart(el);
        new Chart(el, {
            type: 'line',
            data: {
                labels: d.labels || d.months || [],
                datasets: [
                    { label: 'Received', data: d.received || d.created || [], borderColor: '#2563eb', tension: 0.3 },
                    { label: 'Completed', data: d.completed || d.resolved || [], borderColor: '#059669', tension: 0.3 }
                ]
            },
            options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
        });
    }
    doughnut('chartRoute');
    doughnut('chartRequestType');
    lineTrend('chartTrend');
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
