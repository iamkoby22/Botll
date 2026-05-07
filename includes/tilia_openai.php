<?php

declare(strict_types=1);

/**
 * Server-side OpenAI call (key never sent to browser).
 *
 * @return array{ok:bool, text?:string, error?:string}
 */
function tilia_openai_chat(string $userQuestion, string $extraContext = ''): array
{
    $key = OPENAI_API_KEY;
    if ($key === '') {
        return ['ok' => false, 'error' => 'no_key'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_missing'];
    }

    $model = OPENAI_MODEL;
    $system = <<<SYS
You are Tilia, the in-product assistant for the Botll web ticketing platform (PHP/MySQL helpdesk app).
You ONLY help with using this application: login, dashboard, All Tickets, My Tickets, Requests (service catalog, queues), Assigned to Me, Pending My Approval, ticket detail, comments, status, approve/reject workflow, ticket templates and template builder, create ticket, new service request, reports, settings, user management, notifications, account, FAQ, roles (Super Admin, Admin, Director, HOD, User), and Tilia itself.

CRITICAL RULES:
- Never tell a user to use "Create Ticket" to approve a request. Approving is done on the ticket detail page (Approve/Reject) when they are an approver, or from Requests → queue "Pending My Approval" / notifications.
- "Assigned to Me" is for working the ticket; "Pending My Approval" is where approval decisions live. Do not conflate them.
- If the question is unrelated (weather, politics, coding homework, other websites), reply with EXACTLY this single sentence and nothing else:
I can only help with using this ticketing platform. You can ask me about creating tickets, tracking approvals, assigned tickets, filters, templates, dashboard metrics, reports, account settings, or notifications.
- Keep answers under 140 words. Use short bullet steps when helpful.
SYS;

    if ($extraContext !== '') {
        $system .= "\n\nContext from the knowledge base:\n" . $extraContext;
    }

    $payload = [
        'model' => $model,
        'temperature' => 0.25,
        'max_tokens' => 400,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userQuestion],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'curl_exec'];
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'bad_json'];
    }
    if ($code >= 400) {
        $msg = is_array($json['error'] ?? null) ? (string) ($json['error']['message'] ?? 'api_error') : 'api_error';
        return ['ok' => false, 'error' => $msg];
    }
    $text = (string) ($json['choices'][0]['message']['content'] ?? '');
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'empty'];
    }
    return ['ok' => true, 'text' => $text];
}
