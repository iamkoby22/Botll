<?php

declare(strict_types=1);

if (!current_user()) {
    return;
}
?>
<div class="tilia-overlay" id="tiliaOverlay" hidden></div>
<div class="tilia-panel" id="tiliaPanel" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="tiliaTitle">
    <div class="tilia-header">
        <div class="d-flex align-items-center gap-2">
            <div class="tilia-avatar" style="width:34px;height:34px;font-size:0.85rem;">T</div>
            <div>
                <div class="fw-bold" id="tiliaTitle">Tilia</div>
                <div class="small text-white-50">Platform assistant</div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-light" id="tiliaClose" aria-label="Close">&times;</button>
    </div>
    <div class="tilia-body" id="tiliaMessages">
        <div class="small text-muted mb-2">Ask about Botll navigation, tickets, templates, dashboard metrics, and approvals.</div>
        <div class="tilia-starters mb-2" id="tiliaStarters">
            <button type="button" data-q="How do I create a ticket?">How do I create a ticket?</button>
            <button type="button" data-q="How do I check my ticket status?">How do I check my ticket status?</button>
            <button type="button" data-q="What does SLA Breach mean?">What does SLA Breach mean?</button>
            <button type="button" data-q="How do I approve a ticket that is sent to me for approval?">How do I approve a ticket?</button>
            <button type="button" data-q="How do approvals work?">How do approvals work?</button>
            <button type="button" data-q="How do I use ticket templates?">How do I use ticket templates?</button>
        </div>
    </div>
    <div class="p-2 border-top bg-white">
        <div class="input-group tilia-input mb-2">
            <input type="text" class="form-control" id="tiliaInput" placeholder="Ask about this platform..." autocomplete="off" maxlength="2000">
            <button class="btn btn-accent" type="button" id="tiliaSend">Send</button>
        </div>
        <div class="small text-muted">Tilia cannot access external topics or your private data.</div>
    </div>
</div>
<?php
$tiliaEndpoint = APP_WEB_BASE . '/api/tilia_assistant.php';
?>
<script type="application/json" id="tiliaMeta"><?php echo json_encode(['csrf' => csrf_token(), 'endpoint' => $tiliaEndpoint, 'webBase' => APP_WEB_BASE], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
