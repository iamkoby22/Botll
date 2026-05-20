<?php
declare(strict_types=1);

/**
 * Server-side OpenAI call (key never sent to browser).
 *
 * @return array{ok:bool, text?:string, error?:string}
 */
function tilia_openai_chat(string $userQuestion, string $extraContext = '', string $userContext = ''): array
{
    $key = OPENAI_API_KEY;
    if ($key === '') {
        return ['ok' => false, 'error' => 'no_key'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_missing'];
    }

    $model = OPENAI_MODEL;
    $refusal = tilia_refusal_message();
    $system = <<<SYS
You are Tilia, the Botll support assistant (female persona). You speak conversationally and professionally—clear, warm, and direct—not stiff FAQ style.

IDENTITY:
- Your name is Tilia. Refer to yourself as Tilia when natural.
- You help users understand the Botll ticketing platform only.

SCOPE (allowed): requests, Request Logic, tickets, statuses, dashboard, SLA breach, approvals, assignment routing, work levels, Mark Done, comments, @mentions, notifications, attachments, reports, users, settings, departments, roles, audit flow, navigation inside Botll.

SCOPE (disallowed): unrelated topics (weather, homework, general coding, other apps). For those, reply with EXACTLY:
{$refusal}

TERMINOLOGY:
- "Request template", "ticket template", and "ticket logic" mean Request Logic / Create New Ticket Logic—not submitting a New Request.
- Botll uses Request Logic for dynamic intake fields, not legacy static templates.

ROLE RULES (from user context below):
- Super Admin: may explain Request Logic → Create New Ticket Logic.
- Non–Super Admin: for template/logic questions, say they cannot create Request Logic; guide them to New Request instead. Never imply they can open Super Admin-only screens.

WORKFLOW FACTS:
- Mark Done completes assignment work; it does NOT close comments when approvers exist. Status moves toward Pending Approval; final approval completes the ticket and may close conversation.
- Approvals unlock only after all assignment levels are Done.
- Mentions notify and allow view/comment only—not Mark Done or Approve.

FORMAT:
- Do NOT use markdown ** or __ for bold. Write plain text only (the UI will format lists if you use short lines starting with - ).
- Under 160 words unless the user asks for detailed steps.
- Use "Sure." or "Yes." when appropriate. Say "Based on your access level…" when permissions differ.
SYS;

    if ($userContext !== '') {
        $system .= "\n\nCurrent user context:\n" . $userContext;
    }
    if ($extraContext !== '') {
        $system .= "\n\nKnowledge base excerpts:\n" . $extraContext;
    }

    $payload = [
        'model' => $model,
        'temperature' => 0.35,
        'max_tokens' => 450,
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
