<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_review_metrics.php';

/**
 * @param array<string,mixed> $metrics
 * @param array<string,mixed> $filters
 */
function ai_review_build_prompt(array $metrics, array $filters): string
{
    $metricsJson = json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($metricsJson === false) {
        $metricsJson = '{}';
    }
    $period = ($filters['date_from'] ?? '') . ' to ' . ($filters['date_to'] ?? '');
    return <<<PROMPT
Generate an executive SBS Support Requests platform review for period: {$period}.

RULES (mandatory):
- Use ONLY the metrics JSON below. Do not invent counts, dates, names, or percentages.
- If data is missing, write "Data not available."
- Professional executive tone. Clear business language. No casual slang.
- Do not include API keys or secrets.

Respond with valid JSON only (no markdown fences), keys:
executive_summary (string, 2-4 sentences)
key_findings (array of strings, 3-6 items)
overall_interpretation (string, 2-3 paragraphs plain text)
bottleneck_analysis (string)
bottleneck_reduction_plan (string, delay prevention)
recommendations (array of strings)
monitoring_plan (string)
final_conclusion (string)

METRICS JSON:
{$metricsJson}
PROMPT;
}

/**
 * @return array{ok:bool, sections?:array<string,mixed>, raw?:string, error?:string}
 */
function ai_review_generate_with_openai(string $prompt): array
{
    $key = OPENAI_API_KEY;
    if ($key === '') {
        return ['ok' => false, 'error' => 'no_key'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_missing'];
    }

    $system = 'You are Tilia, executive report writer for SBS Support Requests. Output strict JSON only as instructed. Never fabricate metrics.';

    $payload = [
        'model' => OPENAI_MODEL,
        'temperature' => 0.25,
        'max_tokens' => 2200,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'api_error'];
    }
    $data = json_decode((string) $body, true);
    $raw = (string) ($data['choices'][0]['message']['content'] ?? '');
    $sections = json_decode($raw, true);
    if (!is_array($sections)) {
        $sections = [
            'executive_summary' => $raw,
            'overall_interpretation' => '',
            'bottleneck_analysis' => '',
            'bottleneck_reduction_plan' => '',
            'recommendations' => [],
            'monitoring_plan' => '',
            'final_conclusion' => '',
            'key_findings' => [],
        ];
    }
    return ['ok' => true, 'sections' => $sections, 'raw' => $raw];
}

function ai_review_sanitize_html(string $html): string
{
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
    $html = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html) ?? $html;
    return $html;
}

function ai_review_esc(string $text): string
{
    return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

/**
 * @param array<string,mixed> $sections
 * @param array<string,mixed> $metrics
 * @param array<string,mixed> $filters
 */
function ai_review_render_report_html(array $sections, array $metrics, array $filters, string $title, ?array $preparedBy = null): string
{
    $counts = $metrics['counts'] ?? [];
    $routes = $metrics['routes'] ?? [];
    $prepared = htmlspecialchars((string) ($preparedBy['full_name'] ?? $preparedBy['username'] ?? 'Super Admin'), ENT_QUOTES, 'UTF-8');
    $period = htmlspecialchars(($filters['date_from'] ?? '') . ' – ' . ($filters['date_to'] ?? ''), ENT_QUOTES, 'UTF-8');
    $generated = htmlspecialchars((string) ($metrics['generated_on'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8');
    $sysName = htmlspecialchars((string) ($metrics['system_name'] ?? 'SBS Support Requests'), ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    $exec = ai_review_esc((string) ($sections['executive_summary'] ?? ''));
    $interp = ai_review_esc((string) ($sections['overall_interpretation'] ?? ''));
    $bn = ai_review_esc((string) ($sections['bottleneck_analysis'] ?? ''));
    $plan = ai_review_esc((string) ($sections['bottleneck_reduction_plan'] ?? ''));
    $mon = ai_review_esc((string) ($sections['monitoring_plan'] ?? ''));
    $concl = ai_review_esc((string) ($sections['final_conclusion'] ?? ''));

    $findings = '';
    foreach ((array) ($sections['key_findings'] ?? []) as $f) {
        $findings .= '<li>' . htmlspecialchars((string) $f, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $recs = '';
    foreach ((array) ($sections['recommendations'] ?? []) as $r) {
        $recs .= '<li>' . htmlspecialchars((string) $r, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    $statusRows = '';
    foreach ((array) ($metrics['by_status'] ?? []) as $sr) {
        $statusRows .= '<tr><td>' . htmlspecialchars((string) ($sr['status_name'] ?? ''), ENT_QUOTES, 'UTF-8')
            . '</td><td class="text-end">' . (int) ($sr['c'] ?? 0) . '</td></tr>';
    }

    $routeTotal = max(1, array_sum($routes));
    $routeCards = '';
    foreach ($routes as $rk => $rv) {
        $pct = round(((int) $rv / $routeTotal) * 100, 1);
        $routeCards .= '<div class="report-metric-card"><div class="label">' . htmlspecialchars(ucfirst($rk), ENT_QUOTES, 'UTF-8')
            . '</div><div class="value">' . (int) $rv . '</div><div class="sub">' . $pct . '%</div></div>';
    }

    $noTickets = ((int) ($counts['total'] ?? 0)) === 0;
    $notice = $noTickets
        ? '<div class="report-callout report-callout-warn">No tickets matched the selected filters for this reporting period.</div>'
        : '';

    $chartRoute = htmlspecialchars(json_encode($metrics['charts']['account_route'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $chartRt = htmlspecialchars(json_encode($metrics['charts']['request_type'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $chartTrend = htmlspecialchars(json_encode($metrics['charts']['trend'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<article class="ai-report-document" id="aiReportPrint">
  <header class="report-cover">
    <p class="report-eyebrow">{$sysName}</p>
    <h1 class="report-title">{$safeTitle}</h1>
    <div class="report-meta-grid">
      <div><span class="meta-label">Reporting period</span><span class="meta-value">{$period}</span></div>
      <div><span class="meta-label">Prepared by</span><span class="meta-value">{$prepared}</span></div>
      <div><span class="meta-label">Generated on</span><span class="meta-value">{$generated}</span></div>
    </div>
  </header>
  {$notice}
  <section class="report-section">
    <h2>Executive Summary</h2>
    <div class="report-card report-card-accent"><p>{$exec}</p></div>
  </section>
  <section class="report-section">
    <h2>Metric Summary</h2>
    <div class="report-metrics-row">
      <div class="report-metric-card"><div class="label">Total tickets</div><div class="value">{$counts['total']}</div></div>
      <div class="report-metric-card"><div class="label">Open</div><div class="value">{$counts['open']}</div></div>
      <div class="report-metric-card"><div class="label">Pending</div><div class="value">{$counts['pending']}</div></div>
      <div class="report-metric-card"><div class="label">Closed / Done</div><div class="value">{$counts['closed']}</div></div>
      <div class="report-metric-card"><div class="label">Completed</div><div class="value">{$counts['completed']}</div></div>
      <div class="report-metric-card report-metric-warn"><div class="label">Stuck</div><div class="value">{$counts['stuck']}</div></div>
      <div class="report-metric-card report-metric-warn"><div class="label">Overdue</div><div class="value">{$counts['overdue']}</div></div>
    </div>
  </section>
  <section class="report-section">
    <h2>Account Route Breakdown</h2>
    <div class="report-metrics-row">{$routeCards}</div>
  </section>
  <section class="report-section">
    <h2>Status Breakdown</h2>
    <table class="table table-sm report-table"><thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead><tbody>{$statusRows}</tbody></table>
  </section>
  <section class="report-section report-charts no-print-break">
    <h2>Dashboard Charts</h2>
    <div class="row g-3">
      <div class="col-md-6"><canvas id="chartRoute" height="200" data-chart="{$chartRoute}"></canvas></div>
      <div class="col-md-6"><canvas id="chartRequestType" height="200" data-chart="{$chartRt}"></canvas></div>
      <div class="col-12"><canvas id="chartTrend" height="120" data-chart="{$chartTrend}"></canvas></div>
    </div>
  </section>
  <section class="report-section">
    <h2>Key Findings</h2>
    <ul class="report-list">{$findings}</ul>
  </section>
  <section class="report-section">
    <h2>Interpretation</h2>
    <div class="report-prose">{$interp}</div>
  </section>
  <section class="report-section">
    <h2>Bottleneck Analysis</h2>
    <div class="report-callout report-callout-warn"><div class="report-prose">{$bn}</div></div>
  </section>
  <section class="report-section">
    <h2>Delay Prevention Plan</h2>
    <div class="report-prose">{$plan}</div>
  </section>
  <section class="report-section">
    <h2>Recommendations</h2>
    <ul class="report-list report-list-check">{$recs}</ul>
  </section>
  <section class="report-section">
    <h2>Monitoring Plan</h2>
    <div class="report-prose">{$mon}</div>
  </section>
  <section class="report-section">
    <h2>Final Conclusion</h2>
    <div class="report-card"><div class="report-prose">{$concl}</div></div>
  </section>
</article>
HTML;

    return ai_review_sanitize_html($html);
}

/**
 * @param array<string,mixed> $data
 * @return array{ok:bool, id?:int, docx_path?:?string, error?:string}
 */
function ai_review_save_report(array $data): array
{
    ai_review_ensure_table();
    if (!ai_review_table_exists()) {
        return ['ok' => false, 'error' => 'no_table'];
    }
    $uid = (int) (current_user()['id'] ?? 0);
    $st = db()->prepare(
        'INSERT INTO ai_review_reports (title, report_type, filters_json, metrics_json, ai_output, html_output, docx_path, created_by)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        (string) ($data['title'] ?? 'SBS Review'),
        (string) ($data['report_type'] ?? 'custom'),
        json_encode($data['filters'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($data['metrics'] ?? [], JSON_UNESCAPED_UNICODE),
        (string) ($data['ai_output'] ?? ''),
        (string) ($data['html_output'] ?? ''),
        $data['docx_path'] ?? null,
        $uid,
    ]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId(), 'docx_path' => $data['docx_path'] ?? null];
}

/**
 * @param array<string,mixed> $sections
 * @param array<string,mixed> $metrics
 */
function ai_review_try_docx_export(array $sections, array $metrics, array $filters, string $title): ?string
{
    if (!class_exists(\PhpOffice\PhpWord\TemplateProcessor::class)) {
        return null;
    }
    $template = ai_review_template_path();
    if ($template === null) {
        return null;
    }
    try {
        $tp = new \PhpOffice\PhpWord\TemplateProcessor($template);
        $user = current_user();
        $tp->setValue('reporting_period', ($filters['date_from'] ?? '') . ' – ' . ($filters['date_to'] ?? ''));
        $tp->setValue('prepared_by', (string) ($user['full_name'] ?? $user['username'] ?? 'Super Admin'));
        $tp->setValue('prepared_for', 'SBS Leadership');
        $tp->setValue('generated_on', date('Y-m-d'));
        $tp->setValue('system_name', (string) ($metrics['system_name'] ?? 'SBS Support Requests'));
        $tp->setValue('executive_summary', (string) ($sections['executive_summary'] ?? ''));
        $tp->setValue('overall_interpretation', (string) ($sections['overall_interpretation'] ?? ''));
        $tp->setValue('bottleneck_analysis', (string) ($sections['bottleneck_analysis'] ?? ''));
        $tp->setValue('bottleneck_reduction_plan', (string) ($sections['bottleneck_reduction_plan'] ?? ''));
        $tp->setValue('recommendations', implode("\n", (array) ($sections['recommendations'] ?? [])));
        $tp->setValue('monitoring_plan', (string) ($sections['monitoring_plan'] ?? ''));
        $tp->setValue('final_conclusion', (string) ($sections['final_conclusion'] ?? ''));
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $title) ?? 'report';
        $out = APP_BASE_PATH . '/storage/reports/generated/' . $safe . '_' . date('Ymd_His') . '.docx';
        $tp->saveAs($out);
        return $out;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Run full review pipeline: metrics, AI, HTML, optional DOCX, save.
 *
 * @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function ai_review_execute_generation(array $filters): array
{
    $metrics = ai_review_collect_metrics($filters);
    $title = ai_review_build_title($filters);
    $sections = ai_review_fallback_sections($metrics);
    $openAiNotice = '';
    if (OPENAI_API_KEY === '') {
        $openAiNotice = 'OpenAI API key is not configured. Please add the key in the environment configuration to generate AI reviews. Showing metrics-only summary.';
    } else {
        $prompt = ai_review_build_prompt($metrics, $filters);
        $gen = ai_review_generate_with_openai($prompt);
        if ($gen['ok'] ?? false) {
            $sections = (array) ($gen['sections'] ?? $sections);
        } else {
            $openAiNotice = 'Tilia could not generate the report at this time. Please try again. Showing metrics-only summary.';
        }
    }
    $html = ai_review_render_report_html($sections, $metrics, $filters, $title, current_user());
    $docxPath = ai_review_try_docx_export($sections, $metrics, $filters, $title);
    $docxNotice = '';
    $docxAvailable = false;
    if ($docxPath !== null) {
        $docxAvailable = true;
    } elseif (ai_review_template_path() === null) {
        $docxNotice = 'The Word template SBS Support Requests 2.docx was not found.';
    } elseif (!class_exists(\PhpOffice\PhpWord\TemplateProcessor::class)) {
        $docxNotice = 'Word export is not available yet. The report can still be viewed and printed.';
    }
    $savedId = 0;
    $save = ai_review_save_report([
        'title' => $title,
        'report_type' => (string) ($filters['review_type'] ?? 'custom'),
        'filters' => $filters,
        'metrics' => $metrics,
        'ai_output' => json_encode($sections, JSON_UNESCAPED_UNICODE),
        'html_output' => $html,
        'docx_path' => $docxPath,
    ]);
    if ($save['ok'] ?? false) {
        $savedId = (int) ($save['id'] ?? 0);
    }
    return [
        'html' => $html,
        'title' => $title,
        'saved_id' => $savedId,
        'docx_available' => $docxAvailable,
        'docx_notice' => $docxNotice,
        'openai_notice' => $openAiNotice,
        'metrics' => $metrics,
        'sections' => $sections,
    ];
}

/** @return array<string,mixed> */
function ai_review_fallback_sections(array $metrics): array
{
    $c = $metrics['counts'] ?? [];
    $total = (int) ($c['total'] ?? 0);
    if ($total === 0) {
        return [
            'executive_summary' => 'No tickets matched the selected filters for this reporting period.',
            'key_findings' => ['No ticket volume in scope.'],
            'overall_interpretation' => 'Data not available for interpretation.',
            'bottleneck_analysis' => 'Data not available.',
            'bottleneck_reduction_plan' => 'Data not available.',
            'recommendations' => ['Review filter criteria or expand the date range.'],
            'monitoring_plan' => 'Data not available.',
            'final_conclusion' => 'No actionable conclusions without ticket data in period.',
        ];
    }
    $summary = sprintf(
        'During this period the platform recorded %d tickets: %d open, %d pending, %d completed, %d stuck, %d overdue.',
        $total,
        (int) ($c['open'] ?? 0),
        (int) ($c['pending'] ?? 0),
        (int) ($c['completed'] ?? 0),
        (int) ($c['stuck'] ?? 0),
        (int) ($c['overdue'] ?? 0)
    );
    return [
        'executive_summary' => $summary,
        'key_findings' => ['Metrics collected from live platform data. Enable OpenAI for full narrative analysis.'],
        'overall_interpretation' => 'Data not available (AI generation required for narrative).',
        'bottleneck_analysis' => 'Data not available.',
        'bottleneck_reduction_plan' => 'Data not available.',
        'recommendations' => [],
        'monitoring_plan' => 'Data not available.',
        'final_conclusion' => 'See metric tables for operational snapshot.',
    ];
}

/** @return array<string,mixed>|null */
function ai_review_load_saved(int $id): ?array
{
    if (!ai_review_table_exists()) {
        return null;
    }
    $st = db()->prepare(
        'SELECT r.*, u.full_name, u.username FROM ai_review_reports r
         LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
