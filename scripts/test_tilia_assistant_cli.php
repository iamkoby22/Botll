<?php
declare(strict_types=1);

/**
 * CLI smoke tests for Tilia KB (no HTTP session — simulates roles via tilia_curated_answer actor param).
 * Usage: php scripts/test_tilia_assistant_cli.php
 */

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/tilia_kb_core.php';

function cli_assert(bool $cond, string $label): void
{
    if ($cond) {
        echo "PASS: {$label}\n";
        return;
    }
    fwrite(STDERR, "FAIL: {$label}\n");
    exit(1);
}

$superAdmin = ['role_key' => 'super_admin', 'role_name' => 'Super Admin', 'full_name' => 'Test SA', 'is_super_admin' => true];
$user = ['role_key' => 'user', 'role_name' => 'User', 'full_name' => 'Test User', 'is_super_admin' => false];

// A. Identity
$who = tilia_curated_answer('Who are you?', $superAdmin);
cli_assert($who !== null && stripos($who, 'Tilia') !== false, 'Who are you mentions Tilia');

// B. Off-topic
cli_assert(tilia_obvious_off_topic('What is the weather today?'), 'Weather is off-topic');
$refusal = tilia_refusal_message();
cli_assert(stripos($refusal, 'Tilia') !== false && stripos($refusal, 'Botll') !== false, 'Refusal mentions Tilia and Botll');

// C. Create request
$createReq = tilia_curated_answer('How do I create a request?', $user);
cli_assert($createReq !== null && stripos($createReq, 'New Request') !== false, 'Create request → New Request');
cli_assert(stripos($createReq, 'Request Logic') === false || stripos($createReq, 'Create New Ticket Logic') === false, 'Create request is not Request Logic builder');

// D. Template as Super Admin
$tplSa = tilia_curated_answer('how do i create a request template??', $superAdmin);
cli_assert($tplSa !== null && stripos($tplSa, 'Request Logic') !== false, 'Super Admin template → Request Logic');
cli_assert(stripos($tplSa, 'Create New Ticket Logic') !== false, 'Super Admin template mentions Create New Ticket Logic');
cli_assert(!preg_match('/^Open\s+New\s+Request/i', trim($tplSa)), 'Super Admin template is not only New Request');

// E. Template as normal user
$tplUser = tilia_curated_answer('how do i create a request template??', $user);
cli_assert($tplUser !== null && stripos($tplUser, 'access level') !== false, 'User template mentions access level');
cli_assert(stripos($tplUser, 'cannot') !== false || stripos($tplUser, 'not') !== false, 'User template denies create');
cli_assert(stripos($tplUser, 'Create New Ticket Logic') === false, 'User template does not teach Super Admin screen');

// F. Approvals
$ap = tilia_curated_answer('How do approvals work?', $user);
cli_assert($ap !== null && stripos($ap, 'Done') !== false && stripos($ap, 'approv') !== false, 'Approvals answer');

// G. Mentions
$men = tilia_curated_answer('What happens when I mention someone?', $user);
cli_assert($men !== null && stripos($men, 'mention') !== false, 'Mentions answer');
cli_assert(stripos($men, 'Mark Done') !== false || stripos($men, 'Approve') !== false, 'Mentions explain permissions');

// H. Intent: ticket logic phrase
cli_assert(tilia_question_asks_request_logic('how do i create ticket logic'), 'ticket logic intent detected');
cli_assert(!tilia_question_asks_new_request('how do i create ticket logic'), 'ticket logic is not new request');

echo "\nAll Tilia KB CLI checks passed.\n";
