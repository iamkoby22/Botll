<?php

declare(strict_types=1);

function tilia_refusal_message(): string
{
    return 'I can only help with using this ticketing platform. You can ask me about creating tickets, tracking approvals, assigned tickets, filters, templates, dashboard metrics, reports, account settings, or notifications.';
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
        '#\\bpython\\b#iu',
        '#\\bjavascript\\b#iu',
        '#\\bwrite me (a )?code\\b#iu',
        '#\\bstock price\\b#iu',
        '#\\btranslate\\b.*\\b(chinese|spanish|french)\\b#iu',
        '#\\bcapital of\\b#iu',
        '#\\bwhat\\s+is\\s+the\\s+capital\\b#iu',
    ];
    foreach ($bad as $pat) {
        if (@preg_match($pat, $q) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * High-confidence answers (avoid token "assign" matching "assigned" → wrong rule).
 */
function tilia_curated_answer(string $question): ?string
{
    $q = strtolower($question);

    if (preg_match('/\bapprove\b|\bapproval\b|\breject\b|\bpending my approval\b/', $q)) {
        return 'To approve or reject a ticket: open Requests in the sidebar, use the Pending My Approval queue filter (or open the ticket from the bell notification). On Ticket detail, if you are the current approver (or have Admin/Super Admin/HOD/Director override where policy allows), use Approve or Reject and optionally add an approval note. Create Ticket is only for submitting new work — it does not approve existing tickets. Assigned to Me means you are working the item; it does not by itself mean you can approve unless you are also on the approval chain.';
    }

    if (preg_match('/\bassigned to me\b|\bmy assigned\b/', $q)) {
        return 'Tickets assigned to you appear under Requests with the Assigned to Me queue filter and in All Tickets when you are the primary or additional assignee. Open the ticket row to add comments or update status if your role allows.';
    }

    if (preg_match('/\bhow do i create (a )?ticket\b/', $q)) {
        return 'Go to **Create Ticket** in the left sidebar. Choose **category**, **priority**, and **department**, enter **subject** and **description**, add **assignees** if needed, attach files, then click **Create**. You can also start from **Requests → Service catalog → Request** for guided service items.';
    }

    if (preg_match('/\b(template|templates)\b/', $q) && preg_match('/\b(create|builder|publish|draft)\b/', $q)) {
        return 'Open Ticket Templates, then Create Ticket Template. Use Edit / Preview tabs, add fields from the palette, configure labels and options on the right, Save draft, then Publish so the template appears for Use template on Create Ticket.';
    }

    if (preg_match('/\breports?\b/', $q) && preg_match('/\b(chart|export|filter|kpi)\b/', $q)) {
        return 'Open Reports from the sidebar. Pick a date range and filters, review KPI cards and charts, scan the summary table, and use Export CSV / Print as available. Data reflects tickets in your visibility scope.';
    }

    if (preg_match('/\buser management\b|\bcreate user\b|\badd user\b/', $q)) {
        return 'Super Admin and Admin can open User Management, use Create User, complete the form, and confirm with their own admin password. Admins cannot create or edit Super Admin accounts.';
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
            'q' => 'How do I create a ticket?',
            'a' => 'Go to Create Ticket from the left sidebar, choose a category and priority, enter the subject, account, and description, attach supporting files if needed, assign teammates, then click Create.',
            'tags' => 'create ticket form sidebar category priority description upload',
        ],
        [
            'q' => 'How do I check my ticket status?',
            'a' => 'Open All Tickets and find your ticket by number or use the search box. The Status column shows Open, Completed, Pending Approval, Stuck, or Cancelled.',
            'tags' => 'status all tickets search',
        ],
        [
            'q' => 'What does SLA Breach mean?',
            'a' => 'SLA Breach means a ticket has passed the expected response or resolution time for its priority or service type.',
            'tags' => 'sla breach overdue',
        ],
        [
            'q' => 'How do I use ticket templates?',
            'a' => 'Open Ticket Templates. Published templates can be applied on Create Ticket to prefill category, priority, department, and description.',
            'tags' => 'templates library apply prefill',
        ],
        [
            'q' => 'How do I filter tickets?',
            'a' => 'On All Tickets use search plus filters for status, priority, category, assignee, department, SLA breach, and date range.',
            'tags' => 'filters all tickets duration assignee department',
        ],
        [
            'q' => 'What is the dashboard for?',
            'a' => 'Dashboard summarizes totals, late tickets, response times, CSAT, and charts for workload by department, trends, and priority mix.',
            'tags' => 'dashboard charts metrics kpi',
        ],
        [
            'q' => 'How do notifications work?',
            'a' => 'Click the bell for recent items. Choosing a row marks it read and follows action_url to the related ticket when configured.',
            'tags' => 'notifications bell topbar read',
        ],
        [
            'q' => 'What are roles and permissions?',
            'a' => 'Super Admin and Admin manage users and global settings. Director and HOD have reporting and approval responsibilities. Users create and track their own tickets and assignments.',
            'tags' => 'roles rbac permissions admin user director hod',
        ],
        [
            'q' => 'How do attachments work?',
            'a' => 'Create Ticket and ticket detail support uploads stored on the server under uploads/tickets. Limits depend on PHP settings.',
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
 * @param array<int, array<string,mixed>> $faqRows
 */
function tilia_answer_local(string $question, array $faqRows = []): ?string
{
    $cur = tilia_curated_answer($question);
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
            return $fa;
        }
    }

    $best = null;
    $bestScore = 0;
    foreach (tilia_local_rules() as $rule) {
        $tokens = preg_split('/\s+/', strtolower($rule['tags'] . ' ' . $rule['q']));
        $tokens = array_unique(array_filter($tokens, static fn ($t) => strlen((string) $t) > 2));
        $score = 0;
        foreach ($tokens as $t) {
            if (tilia_token_matches_question((string) $t, $q)) {
                $score += 2;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $rule['a'];
        }
    }

    if ($bestScore >= 4 && $best) {
        return $best;
    }

    return null;
}
