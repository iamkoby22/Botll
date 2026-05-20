<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

$tests = [
    ['step1', 'Purchasing and Financial Support', null, null, 5],
    ['step1', 'Travel & Related Reimbursements', null, null, 1],
    ['step2', 'Travel & Related Reimbursements', 'Travel', null, 4],
    ['path', 'Purchasing and Financial Support', 'Pcard', null, null],
    ['path', 'Grant Support', 'Pre-Award', null, null],
    ['path', 'Human Resources Functions', 'HR Functions', 'Recruitment and New Hires', null],
];

$failed = 0;
foreach ($tests as [$action, $type, $s1, $s2, $minCount]) {
    try {
        if ($action === 'step1') {
            $opts = request_logic_step1_options($type);
            $ok = count($opts) === $minCount;
            echo ($ok ? 'OK' : 'FAIL') . " step1 {$type}: " . count($opts) . " options\n";
            if (!$ok) {
                var_export($opts);
                echo "\n";
                $failed++;
            }
        } elseif ($action === 'step2') {
            $opts = request_logic_step2_options($type, (string) $s1);
            $ok = count($opts) === $minCount;
            echo ($ok ? 'OK' : 'FAIL') . " step2 {$type}/{$s1}: " . count($opts) . " options\n";
            if (!$ok) {
                var_export($opts);
                echo "\n";
                $failed++;
            }
        } else {
            $path = request_logic_resolve_path($type, (string) $s1, $s2 !== null ? (string) $s2 : null);
            $ok = $path !== null;
            echo ($ok ? 'OK' : 'FAIL') . " path {$type}/{$s1}/{$s2}: " . ($path ? 'id=' . $path['id'] : 'null') . "\n";
            if (!$ok) {
                $failed++;
            }
        }
    } catch (Throwable $e) {
        echo "ERR {$action} {$type}: " . $e->getMessage() . "\n";
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);
