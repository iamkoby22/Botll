<?php
declare(strict_types=1);

function tilia_refusal_message(): string
{
    return "I'm Tilia, the SBS Support Requests assistant. I can help with tickets, requests, routing, assignments, comments, mentions, dashboards, reports, and platform navigation.";
}

/**
 * @return array{role_key:string,role_name:string,full_name:string,is_super_admin:bool}
 */
function tilia_actor_context(): array
{
    $u = current_user();
    if (!$u) {
        return [
            'role_key' => '',
            'role_name' => '',
            'full_name' => '',
            'is_super_admin' => false,
        ];
    }

    return [
        'role_key' => (string) ($u['role_key'] ?? ''),
        'role_name' => (string) ($u['role_name'] ?? ''),
        'full_name' => (string) ($u['full_name'] ?? ''),
        'is_super_admin' => is_super_admin_role((string) ($u['role_key'] ?? '')),
    ];
}

function tilia_is_super_admin(): bool
{
    return is_super_admin_role();
}

function tilia_question_asks_request_logic(string $q): bool
{
    if (preg_match('/\b(request\s+logic|ticket\s+logic|ticket\s+template|request\s+template|new\s+ticket\s+logic)\b/i', $q)) {
        return true;
    }
    if (preg_match('/\btemplate\b/i', $q) && preg_match('/\b(create|build|configure|set\s*up|setup|edit|manage|add|design)\b/i', $q)) {
        return true;
    }
    if (preg_match('/\b(create|build|configure)\b/i', $q) && preg_match('/\b(request\s+type|logic\s+path|intake\s+form)\b/i', $q)) {
        return true;
    }
    return false;
}

function tilia_question_asks_new_request(string $q): bool
{
    if (tilia_question_asks_request_logic($q)) {
        return false;
    }
    if (preg_match('/\b(how\s+(do|can|to)\s+i\s+)?(create|submit|raise|open|start)\b/i', $q)
        && preg_match('/\b(request|ticket)\b/i', $q)) {
        return true;
    }
    if (preg_match('/\bnew\s+request\b/i', $q)) {
        return true;
    }
    if (preg_match('/\bbusiness\s+services\b/i', $q)) {
        return true;
    }
    return false;
}

function tilia_answer_who_is_tilia(): string
{
    return "I'm Tilia, the Botll assistant. I help you navigate requests, tickets, approvals, assignments, comments, mentions, reports, dashboards, and settings. Ask me anything about how Botll works.";
}

function tilia_answer_request_logic(?array $actor = null): string
{
    $actor = $actor ?? tilia_actor_context();
    if (!empty($actor['is_super_admin'])) {
        return 'Sure. In Botll, request templates are managed as Request Logic (we moved away from static ticket templates). Open Request Logic in the sidebar, then choose Create New Ticket Logic. Define the Request Type, Step 1, and Step 2 if needed. Add the fields users should complete—text, long text, dropdowns, radio buttons, checkboxes, dates, numbers, email fields, or instruction blocks. Keep Active for new requests checked if you want it on the New Request form.';
    }

    return 'Based on your access level, you can submit requests but you cannot create or edit Request Logic. Request Logic is managed by Super Admins. You can still open New Request, choose a Department and Request Type, complete Step 1 and Step 2 when shown, then fill in the fields that appear.';
}

function tilia_answer_create_request(): string
{
    return 'Sure. To create a request, open New Request from the sidebar. Your name and email are filled in automatically. Choose the Department, then the Request Type. Botll shows Step 1, Step 2, and the request-specific fields based on that selection. Complete the fields, add assignment and approval routing if needed, then submit.';
}

function tilia_answer_approvals(): string
{
    return 'Approvals happen after assigned work is finished. Assigned users mark their work Done in level order. When every assignment level is complete, the approval chain unlocks. Approvers then approve in level order. Comments stay open until final approval. The final approval completes the ticket.';
}

function tilia_answer_assignments(): string
{
    return 'Assigned users work the ticket in level order. Only the active level sees Mark Done. Earlier levels pass work forward; the final level marks work complete. Everyone on the chain can usually comment and upload files, but only the active assignee can Mark Done for their level.';
}

function tilia_answer_mentions(): string
{
    return 'When you mention someone with @ in a comment, they get notified and can view and comment on the ticket. A mention does not make them an assignee or approver, so they cannot Mark Done or Approve unless they were added to the workflow.';
}

/**
 * Obvious off-topic patterns → refusal without calling OpenAI.
 */
function tilia_obvious_off_topic(string $question): bool
{
    $q = strtolower($question);
    if ($q === '') {
        return true;
    }
    $bad = [
        '#\\bweather\\b#iu',
        '#\\bwho won\\b#iu',
        '#\\bnba\\b#iu',
        '#\\brecipe\\b#iu',
        '#\\b(python|javascript|java|typescript)\\b.*\\b(homework|assignment|debug|code)\\b#iu',
        '#\\bwrite me (a )?code\\b#iu',
        '#\\bstock price\\b#iu',
        '#\\btranslate\\b.*\\b(chinese|spanish|french)\\b#iu',
        '#\\bcapital of\\b#iu',
        '#\\bwhat\\s+is\\s+the\\s+capital\\b#iu',
        '#\\bhomework\\b#iu',
        '#\\bmedical advice\\b#iu',
    ];
    foreach ($bad as $pat) {
        if (@preg_match($pat, $q) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * High-confidence answers (order matters — specific intents before broad ones).
 */
function tilia_curated_answer(string $question, ?array $actor = null): ?string
{
    $actor = $actor ?? tilia_actor_context();
    $q = strtolower($question);

    if (preg_match('/\b(who are you|what is your name|your name|who is tilia)\b/', $q)) {
        return tilia_answer_who_is_tilia();
    }

    if (tilia_question_asks_request_logic($question)) {
        return tilia_answer_request_logic($actor);
    }

    if (preg_match('/\bwhat is request logic\b|\brequest logic\b.*\bmean\b/', $q)) {
        return 'Request Logic controls the dynamic fields on New Request. It links Request Type, Step 1, Step 2, and the fields users complete. Super Admins manage it under Request Logic in the sidebar.';
    }

    if (preg_match('/\bwho can manage request logic\b|\bwho manages request logic\b/', $q)) {
        return tilia_is_super_admin()
            ? 'You can manage Request Logic as Super Admin under Request Logic in the sidebar.'
            : 'Request Logic is managed by Super Admins only.';
    }

    if (tilia_question_asks_new_request($question)) {
        return tilia_answer_create_request();
    }

    if (preg_match('/\bmention\b|\b@\b/', $q)) {
        return tilia_answer_mentions();
    }

    if (preg_match('/\bhow do approvals work\b|\bapproval (chain|workflow|process)\b/', $q)) {
        return tilia_answer_approvals();
    }

    if (preg_match('/\bapprove\b|\bapproval\b|\breject\b|\bpending my approval\b/', $q)) {
        return 'Open the ticket from Requests or the bell notification. If you are the current approver and work is fully Done, use Approve or Reject on Ticket detail. Pending My Approval lists tickets waiting on you. Assigned to Me is for doing the work—not the same as approving.';
    }

    if (preg_match('/\bassignment level\b|\bmultiple assignee\b|\bpass to next\b|\bmark done\b.*\blevel\b/', $q)) {
        return tilia_answer_assignments();
    }

    if (preg_match('/\bcan all assignees comment\b|\ball assignees comment\b/', $q)) {
        return 'Yes. Assignees on the chain can usually comment and upload while the ticket is open. Only the active level can Mark Done for their step.';
    }

    if (preg_match('/\bmark.*done\b|\bmark work\b|\bassigned work\b/', $q)) {
        return 'If you are the active assignee on the final level, open the ticket and use Mark Done. Intermediate levels pass to the next assignee. Mark Done completes the work stage; it does not close the ticket when approvers are still required. Comments stay open until final approval.';
    }

    if (preg_match('/\bwho can change\b.*\bstatus\b|\bchange ticket status\b/', $q)) {
        return 'Super Admin, Admin, and HOD (for their department) can use the status dropdown on Ticket detail. Assignees use Mark Done for their level. Final approval completes the ticket when an approval chain exists.';
    }

    if (preg_match('/\bassigned to me\b|\bmy assigned\b/', $q)) {
        return 'Tickets assigned to you appear under Requests → Assigned to Me. Open the ticket to comment, upload files, and Mark Done when your level is active. Approval controls appear only if you are on the approval chain.';
    }

    if (preg_match('/\bhow do i create (a )?ticket\b/', $q) && !preg_match('/\btemplate\b|\blogic\b/', $q)) {
        return 'Most users submit work through New Request (Request Type workflow). Admins may use Create Ticket for operational tickets with assignees and categories.';
    }

    if (preg_match('/\bstep 1\b|\bstep 2\b/', $q) && preg_match('/\brequest\b/', $q)) {
        return 'Step 1 and Step 2 narrow your request within a Request Type. Options come from Request Logic configured by Super Admin. When Step 2 does not apply, fields appear after Step 1.';
    }

    if (preg_match('/\bsla breach\b|\bwhat does sla\b/', $q)) {
        return 'SLA Breach means the ticket passed the expected response or resolution time for its priority or service type. It highlights items that may need attention on the dashboard or ticket lists.';
    }

    if (preg_match('/\bdelete\b.*\b(user|department|request logic)\b|\bremove\b.*\b(user|department)\b/', $q)) {
        return tilia_is_super_admin()
            ? 'As Super Admin, you can deactivate or remove reference data (users, departments, request logic, and similar) from Settings or the relevant admin screens. Destructive actions require your Super Admin password.'
            : 'Removing users, departments, or request logic is limited to Super Admin. Contact your Super Admin if reference data needs to change.';
    }

    if (preg_match('/\breports?\b/', $q) && preg_match('/\b(chart|export|filter|kpi)\b/', $q)) {
        return 'Open Reports from the sidebar. Pick a date range and filters, review KPI cards and charts, and export or print when available. Data reflects tickets in your visibility scope.';
    }

    if (preg_match('/\buser management\b|\bcreate user\b|\badd user\b/', $q)) {
        return tilia_is_super_admin() || actor_role_key() === 'admin'
            ? 'Open User Management, choose Create User, complete the form, and confirm with your admin password. Admins cannot create or edit Super Admin accounts.'
            : 'User Management is available to Super Admin and Admin roles. Based on your access, ask an administrator if you need a new account.';
    }

    return null;
}

/**
 * @return array<int, array{q:string,a:string,tags:string}>
 */
function tilia_local_rules(): array
{
    return [
        [
            'q' => 'Who is Tilia?',
            'a' => 'Tilia is the Botll assistant. She helps users navigate requests, tickets, approvals, assignments, comments, mentions, reports, dashboards, and settings.',
            'tags' => 'tilia assistant who identity',
        ],
        [
            'q' => 'How do I create a request?',
            'a' => 'Open New Request, choose Department and Request Type, complete Step 1 and Step 2 when shown, fill dynamic fields, then submit.',
            'tags' => 'create request new request intake submit',
        ],
        [
            'q' => 'How do I create Request Logic?',
            'a' => 'Super Admin: Request Logic → Create New Ticket Logic. Others: use New Request to submit; they cannot edit Request Logic.',
            'tags' => 'request logic template ticket logic builder create template',
        ],
        [
            'q' => 'How do approvals work?',
            'a' => 'Assignees mark Done in order; then approval unlocks; approvers act in level order; final approval completes the ticket.',
            'tags' => 'approval approve chain workflow pending',
        ],
        [
            'q' => 'How do assignments work?',
            'a' => 'Work proceeds by assignment level; only the active level can Mark Done; others may comment.',
            'tags' => 'assignment assign level mark done routing',
        ],
        [
            'q' => 'What happens when I mention someone?',
            'a' => 'Mentioned users are notified and can view and comment. Mentions do not grant Mark Done or Approve unless they are on the workflow.',
            'tags' => 'mention at notify comment',
        ],
        [
            'q' => 'How do I check my ticket status?',
            'a' => 'Open All Tickets and find your ticket by number or search. Status shows Open, Pending Approval, Completed, and others.',
            'tags' => 'status all tickets search',
        ],
        [
            'q' => 'What does SLA Breach mean?',
            'a' => 'SLA Breach means a ticket has passed the expected response or resolution time for its priority or service type.',
            'tags' => 'sla breach overdue',
        ],
        [
            'q' => 'What is Request Logic?',
            'a' => 'Request Logic defines Request Type, Step 1, Step 2, and the fields shown on New Request. Super Admin manages it.',
            'tags' => 'request logic intake fields step1 step2',
        ],
        [
            'q' => 'How do I filter tickets?',
            'a' => 'On All Tickets use search plus filters for status, priority, category, assignee, department, SLA breach, and date range.',
            'tags' => 'filters all tickets duration assignee department',
        ],
        [
            'q' => 'What is the dashboard for?',
            'a' => 'Dashboard summarizes totals, late tickets, response times, CSAT, and charts for workload and trends.',
            'tags' => 'dashboard charts metrics kpi',
        ],
        [
            'q' => 'How do notifications work?',
            'a' => 'Click the bell for recent items. Choosing a row marks it read and opens the related ticket when configured.',
            'tags' => 'notifications bell topbar read',
        ],
        [
            'q' => 'How do attachments work?',
            'a' => 'New Request and ticket detail support uploads stored under uploads/tickets.',
            'tags' => 'attachments upload files',
        ],
        [
            'q' => 'How do I log out?',
            'a' => 'Use the profile menu on the top-right and choose Logout.',
            'tags' => 'logout account menu',
        ],
    ];
}

function tilia_token_matches_question(string $token, string $questionLower): bool
{
    $token = strtolower(trim($token));
    if ($token === '' || strlen($token) < 3) {
        return false;
    }
    if (preg_match('/^[a-z]+$/i', $token)) {
        return (bool) preg_match('/\b' . preg_quote($token, '/') . '\b/i', $questionLower);
    }
    return str_contains($questionLower, $token);
}

/**
 * @param array<int, array<string,mixed>> $faqRows
 */
function tilia_faq_snippet_for_openai(array $faqRows, string $question, int $maxChars = 1800): string
{
    $q = strtolower($question);
    $chunks = [];
    $n = 0;
    foreach ($faqRows as $row) {
        $fq = strtolower((string) ($row['question'] ?? ''));
        $fa = trim((string) ($row['answer'] ?? ''));
        if ($fa === '') {
            continue;
        }
        $pct = 0.0;
        $sim = similar_text($q, $fq, $pct);
        $hit = $fq !== '' && (str_contains($q, $fq) || str_contains($fq, $q) || ($sim > 18 && $pct > 55.0));
        if ($hit || $n < 4) {
            $chunks[] = 'Q: ' . ($row['question'] ?? '') . "\nA: " . $fa;
            $n++;
        }
        if (strlen(implode("\n\n", $chunks)) > $maxChars) {
            break;
        }
    }
    return implode("\n\n", array_slice($chunks, 0, 8));
}

/**
 * Context string for OpenAI (role + KB snippet).
 */
function tilia_openai_user_context(?array $actor = null): string
{
    $actor = $actor ?? tilia_actor_context();
    $lines = [];
    if ($actor['full_name'] !== '') {
        $lines[] = 'User name: ' . $actor['full_name'];
    }
    if ($actor['role_name'] !== '' || $actor['role_key'] !== '') {
        $lines[] = 'Role: ' . ($actor['role_name'] !== '' ? $actor['role_name'] : $actor['role_key'])
            . ' (' . $actor['role_key'] . ')';
    }
    $lines[] = $actor['is_super_admin']
        ? 'This user IS Super Admin and CAN create or edit Request Logic (Create New Ticket Logic).'
        : 'This user is NOT Super Admin and CANNOT create or edit Request Logic. For template/logic questions, explain what they can do on New Request instead.';
    $lines[] = 'Terminology: Botll uses Request Logic (not legacy ticket templates) for dynamic New Request fields.';
    return implode("\n", $lines);
}

/**
 * @param array<int, array<string,mixed>> $faqRows
 */
function tilia_answer_local(string $question, array $faqRows = [], ?array $actor = null): ?string
{
    $actor = $actor ?? tilia_actor_context();
    $cur = tilia_curated_answer($question, $actor);
    if ($cur) {
        return $cur;
    }

    $q = strtolower(trim($question));
    if ($q === '') {
        return null;
    }

    foreach ($faqRows as $row) {
        $fq = strtolower((string) ($row['question'] ?? ''));
        $fa = trim((string) ($row['answer'] ?? ''));
        if ($fq === '' || $fa === '') {
            continue;
        }
        if (strlen($fq) >= 12 && (str_contains($q, $fq) || str_contains($fq, $q))) {
            if (tilia_question_asks_request_logic($question) && str_contains($fq, 'new request') && !str_contains($fq, 'logic')) {
                continue;
            }
            return $fa;
        }
    }

    $best = null;
    $bestScore = 0;
    foreach (tilia_local_rules() as $rule) {
        if (tilia_question_asks_request_logic($question) && str_contains(strtolower($rule['tags']), 'create request')
            && !str_contains(strtolower($rule['tags']), 'template')) {
            continue;
        }
        $tokens = preg_split('/\s+/', strtolower($rule['tags'] . ' ' . $rule['q']));
        $tokens = array_unique(array_filter($tokens, static fn ($t) => strlen((string) $t) > 2));
        $score = 0;
        foreach ($tokens as $t) {
            if (tilia_token_matches_question((string) $t, $q)) {
                $score += 2;
            }
        }
        if (tilia_question_asks_request_logic($question) && str_contains(strtolower($rule['tags']), 'logic')) {
            $score += 6;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $rule['a'];
        }
    }

    if ($bestScore >= 4 && $best) {
        if (tilia_question_asks_request_logic($question) && !tilia_is_super_admin()
            && str_contains(strtolower($best), 'request logic') && str_contains(strtolower($best), 'super admin')) {
            return tilia_answer_request_logic($actor);
        }
        return $best;
    }

    return null;
}
