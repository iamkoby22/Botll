<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

$failed = 0;

$types = request_logic_request_types();
echo 'request_types count: ' . count($types) . "\n";
foreach ($types as $t) {
    echo "  - $t\n";
}
if (count($types) !== 6) {
    echo "FAIL: expected 6 request types\n";
    $failed++;
}

$expectedCounts = [
    'Purchasing and Financial Support' => 5,
    'Non-Travel Reimbursement' => 1,
    'Travel & Related Reimbursements' => 4,
    'Grant Support' => 2,
    'Human Resources Functions' => 6,
    'Other Financial Support' => 3,
];
$counts = request_logic_path_counts_by_type();
foreach ($expectedCounts as $type => $exp) {
    $got = $counts[$type] ?? 0;
    if ($got !== $exp) {
        echo "FAIL count $type: got $got expected $exp\n";
        $failed++;
    } else {
        echo "OK count $type: $got\n";
    }
}

$step1Tests = [
    ['Purchasing and Financial Support', 5],
    ['Non-Travel Reimbursement', 0],
    ['Travel & Related Reimbursements', 1],
    ['Grant Support', 2],
    ['Human Resources Functions', 1],
    ['Other Financial Support', 3],
];
foreach ($step1Tests as [$type, $exp]) {
    $opts = request_logic_step1_options($type);
    if (count($opts) !== $exp) {
        echo "FAIL step1 $type: " . count($opts) . " (expected $exp)\n";
        var_export($opts);
        echo "\n";
        $failed++;
    } else {
        echo "OK step1 $type: " . count($opts) . "\n";
    }
}

$path = request_logic_resolve_path('Non-Travel Reimbursement', '', null);
echo ($path ? 'OK' : 'FAIL') . " path Non-Travel (no step1)\n";
if (!$path) {
    $failed++;
}

$path2 = request_logic_resolve_path('Grant Support', 'Pre-Award', null);
echo ($path2 ? 'OK' : 'FAIL') . " path Grant Pre-Award\n";
if (!$path2) {
    $failed++;
}

$path3 = request_logic_resolve_path('Other Financial Support', 'Contract Signature Request', null);
echo ($path3 ? 'OK' : 'FAIL') . " path Other Contract\n";
if (!$path3) {
    $failed++;
}

$s2 = request_logic_step2_options('Travel & Related Reimbursements', 'Travel');
echo (count($s2) === 4 ? 'OK' : 'FAIL') . " step2 Travel: " . count($s2) . "\n";
if (count($s2) !== 4) {
    $failed++;
}

exit($failed > 0 ? 1 : 0);
