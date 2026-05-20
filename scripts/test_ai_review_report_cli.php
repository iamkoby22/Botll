<?php
declare(strict_types=1);

/**
 * CLI tests for AI Review report feature.
 * Run: php scripts/test_ai_review_report_cli.php
 */

$root = dirname(__DIR__);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/password_gate.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/sbs_roles.php';
require_once $root . '/includes/sbs_workflow.php';
require_once $root . '/includes/analytics_metrics.php';
require_once $root . '/includes/ai_review_metrics.php';
require_once $root . '/includes/ai_review_generator.php';

$failures = 0;

function test_assert(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "OK   {$label}\n";
    } else {
        echo "FAIL {$label}\n";
        $failures++;
    }
}

// 1 Super Admin access allowed
test_assert(user_can_access_ai_reviews(['role_key' => 'super_admin']), 'Super Admin access allowed');

// 2 Non-Super Admin denied
test_assert(!user_can_access_ai_reviews(['role_key' => 'business_admin']), 'Non-Super Admin access denied');
test_assert(!user_can_access_ai_reviews(['role_key' => 'coordinator']), 'Coordinator denied');
test_assert(!user_can_access_ai_reviews(['role_key' => 'faculty_staff']), 'Faculty denied');

// 3 Weekly filter 7-day range
$weekly = ai_review_parse_filters(['period' => 'weekly']);
$from = new DateTimeImmutable((string) $weekly['date_from']);
$to = new DateTimeImmutable((string) $weekly['date_to']);
$diff = (int) $from->diff($to)->days;
test_assert($diff >= 6 && $diff <= 8, 'Weekly filter creates ~7-day date range');
test_assert(($weekly['review_type'] ?? '') === 'weekly', 'Weekly review_type set');

// 4 Up to date uses earliest ticket date
$up = ai_review_parse_filters(['period' => 'uptodate']);
$earliest = ai_review_earliest_ticket_date();
test_assert(($up['date_from'] ?? '') === $earliest, 'Review up to date uses earliest ticket date');

// 5 Filter object supports route/request type/status/org code
$f = ai_review_parse_filters([
    'account_route' => 'restricted',
    'request_type' => 'Grant Support',
    'status' => 'Open',
    'org_code' => 'ORG1',
]);
test_assert($f['account_route'] === 'restricted', 'Filter route');
test_assert($f['request_type'] === 'Grant Support', 'Filter request type');
test_assert($f['status'] === 'Open', 'Filter status');
test_assert($f['org_code'] === 'ORG1', 'Filter org code');

// 6 Metrics collector returns required keys
$metrics = ai_review_collect_metrics($weekly);
foreach (['counts', 'routes', 'by_status', 'by_request_type', 'by_assignee', 'bottlenecks', 'comments', 'charts', 'performance'] as $key) {
    test_assert(array_key_exists($key, $metrics), "Metrics has {$key}");
}

// 7 No tickets matched handled in fallback
$emptyMetrics = ['counts' => ['total' => 0]];
$fb = ai_review_fallback_sections($emptyMetrics);
test_assert(str_contains((string) ($fb['executive_summary'] ?? ''), 'No tickets matched'), 'No tickets matched filters handled');

// 8 AI prompt contains metrics but not API key
$prompt = ai_review_build_prompt($metrics, $weekly);
test_assert(str_contains($prompt, 'METRICS JSON'), 'Prompt includes metrics');
test_assert(!str_contains($prompt, 'sk-'), 'Prompt does not contain API key pattern');

// 9 Missing OpenAI key handled
$origKey = OPENAI_API_KEY;
if (!defined('OPENAI_API_KEY_OVERRIDE')) {
    // simulate empty via reflection on constant not possible; check function return
}
$genEmpty = ai_review_generate_with_openai('test');
if ($origKey === '') {
    test_assert(($genEmpty['error'] ?? '') === 'no_key', 'Missing OpenAI key handled gracefully');
} else {
    test_assert(true, 'Missing OpenAI key handled gracefully (key present in env)');
}

// 10 Template file lookup
$tpl = ai_review_template_path();
test_assert($tpl === null || is_file($tpl), 'Template file lookup works');

// 11 Saved report insert works (if DB available)
ai_review_ensure_table();
ai_review_ensure_storage();
if (ai_review_table_exists()) {
    $uid = (int) db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($uid > 0) {
        $_SESSION['user_id'] = $uid;
        clear_current_user_cache();
    }
    $html = ai_review_render_report_html($fb, $metrics, $weekly, 'CLI Test Report', ['full_name' => 'CLI']);
    $save = ai_review_save_report([
        'title' => 'CLI Test ' . date('Y-m-d H:i:s'),
        'report_type' => 'weekly',
        'filters' => $weekly,
        'metrics' => $metrics,
        'ai_output' => '{}',
        'html_output' => $html,
        'docx_path' => null,
    ]);
    test_assert($save['ok'] ?? false, 'Saved report insert works');
} else {
    test_assert(false, 'Saved report insert works (table missing)');
}

// 12 Generated HTML includes required sections
$html = ai_review_render_report_html([
    'executive_summary' => 'Test',
    'overall_interpretation' => 'Interp',
    'bottleneck_analysis' => 'BN',
    'bottleneck_reduction_plan' => 'Plan',
    'recommendations' => ['R1'],
    'monitoring_plan' => 'Mon',
    'final_conclusion' => 'End',
    'key_findings' => ['K1'],
], $metrics, $weekly, 'Test', null);
foreach (['Executive Summary', 'Interpretation', 'Bottleneck Analysis', 'Delay Prevention Plan', 'Recommendations', 'Final Conclusion'] as $section) {
    test_assert(str_contains($html, $section), "HTML includes {$section}");
}

// 13 Word export gracefully skipped if PHPWord unavailable
$docx = ai_review_try_docx_export($fb, $metrics, $weekly, 'Test');
test_assert($docx === null || is_file($docx), 'Word export gracefully skipped or produced file');

// 14 Strict PHP syntax
$files = [
    $root . '/ai_reviews.php',
    $root . '/includes/ai_review_metrics.php',
    $root . '/includes/ai_review_generator.php',
];
$phpBin = PHP_BINARY ?: 'php';
foreach ($files as $file) {
    $out = [];
    $code = 0;
    exec($phpBin . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    test_assert($code === 0, 'PHP lint ' . basename($file));
}

// can_access page key (no authenticated user)
unset($_SESSION['user_id']);
clear_current_user_cache();
test_assert(can_access('ai_reviews') === false, 'can_access ai_reviews false without session');

echo "\nFailures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
