<?php
declare(strict_types=1);

// Never emit HTML/notices into the JSON body for this endpoint.
ini_set('display_errors', '0');

ob_start();

try {
    require_once dirname(__DIR__) . '/includes/init.php';
    require_once dirname(__DIR__) . '/includes/tilia_kb_core.php';
    require_once dirname(__DIR__) . '/includes/tilia_openai.php';
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(
        [
            'ok' => false,
            'answer' => 'Could not load the assistant. Check server configuration.',
            'source' => 'bootstrap_error',
        ],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/**
 * @param array<string, mixed> $payload
 */
function tilia_emit_json(int $status, array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

try {
    if (!current_user()) {
        tilia_emit_json(401, ['ok' => false, 'answer' => 'Please sign in to use Tilia.', 'source' => 'auth']);
        exit;
    }

    if (request_method() !== 'POST') {
        tilia_emit_json(405, ['ok' => false, 'answer' => 'Method not allowed.', 'source' => 'method']);
        exit;
    }

    try {
        [$ok, $msg] = tilia_rate_limit_ok();
    } catch (Throwable $e) {
        [$ok, $msg] = [true, ''];
    }

    if (!$ok) {
        tilia_emit_json(429, ['ok' => false, 'answer' => $msg, 'source' => 'rate_limit']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $raw = is_string($raw) ? $raw : '';
    $json = json_decode($raw, true);

    $question = '';
    if (is_array($json) && isset($json['question'])) {
        $question = (string) $json['question'];
    }
    if ($question === '' && isset($_POST['question'])) {
        $question = (string) $_POST['question'];
    }

    $token = '';
    if (is_array($json) && array_key_exists('csrf', $json)) {
        $token = is_string($json['csrf']) ? $json['csrf'] : '';
    }
    if ($token === '') {
        $token = isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : '';
        if ($token === '' && isset($_POST['csrf'])) {
            $token = (string) $_POST['csrf'];
        }
    }

    if (!csrf_verify($token !== '' ? $token : null)) {
        tilia_emit_json(400, [
            'ok' => false,
            'answer' => 'Security check failed. Refresh the page and try again.',
            'source' => 'csrf',
        ]);
        exit;
    }

    $question = trim(strip_tags($question));
    if ($question === '') {
        tilia_emit_json(400, ['ok' => false, 'answer' => 'Please enter a question.', 'source' => 'validation']);
        exit;
    }
    if (strlen($question) > 2000) {
        tilia_emit_json(400, ['ok' => false, 'answer' => 'Please shorten your question.', 'source' => 'validation']);
        exit;
    }

    if (tilia_obvious_off_topic($question)) {
        tilia_emit_json(200, [
            'ok' => true,
            'answer' => tilia_refusal_message(),
            'source' => 'off_topic',
        ]);
        exit;
    }

    $faqRows = [];
    try {
        $faqRows = db()->query('SELECT question, answer FROM faqs WHERE is_active = 1 ORDER BY id DESC LIMIT 100')->fetchAll();
        if (!is_array($faqRows)) {
            $faqRows = [];
        }
    } catch (Throwable $e) {
        $faqRows = [];
    }

    $assistantRows = [];
    try {
        $assistantRows = db()->query('SELECT question, answer FROM assistant_faqs ORDER BY id DESC LIMIT 50')->fetchAll();
        if (!is_array($assistantRows)) {
            $assistantRows = [];
        }
    } catch (Throwable $e) {
        $assistantRows = [];
    }

    $merged = array_merge($faqRows, $assistantRows);
    $actor = tilia_actor_context();

    $local = tilia_answer_local($question, $merged, $actor);
    if (is_string($local) && $local !== '') {
        tilia_emit_json(200, ['ok' => true, 'answer' => $local, 'source' => 'local']);
        exit;
    }

    if (OPENAI_API_KEY !== '') {
        try {
            $snippet = tilia_faq_snippet_for_openai($merged, $question);
            $userCtx = tilia_openai_user_context($actor);
            $ai = tilia_openai_chat($question, $snippet, $userCtx);
            if (!empty($ai['ok']) && !empty($ai['text'])) {
                $text = trim((string) $ai['text']);
                if (stripos($text, 'this ticketing platform') !== false && strlen($text) < 420) {
                    tilia_emit_json(200, [
                        'ok' => true,
                        'answer' => tilia_refusal_message(),
                        'source' => 'openai_refusal',
                    ]);
                    exit;
                }
                tilia_emit_json(200, ['ok' => true, 'answer' => $text, 'source' => 'openai']);
                exit;
            }
        } catch (Throwable $e) {
            // Fall back to deterministic response (same as local-only refusal).
        }

        tilia_emit_json(200, [
            'ok' => true,
            'answer' => tilia_refusal_message(),
            'source' => 'openai_fallback',
        ]);
        exit;
    }

    tilia_emit_json(200, [
        'ok' => true,
        'answer' => tilia_refusal_message(),
        'source' => 'refusal',
    ]);
    exit;
} catch (Throwable $e) {
    tilia_emit_json(500, [
        'ok' => false,
        'answer' => 'Something went wrong. Please refresh and try again.',
        'source' => 'exception',
    ]);
    exit;
}
