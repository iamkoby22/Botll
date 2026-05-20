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
                <div class="fw-bold tilia-brand-name" id="tiliaTitle">Tilia</div>
        <div class="small text-white-50">SBS platform assistant</div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-light" id="tiliaClose" aria-label="Close">&times;</button>
    </div>
    <div class="tilia-body" id="tiliaMessages">
        <div class="small text-muted mb-2 tilia-intro">Ask Tilia about navigation, requests, Request Logic, approvals, assignments, and dashboards.</div>
        <?php if (user_is_super_admin()) : ?>
        <a href="ai_reviews.php?period=weekly" class="btn btn-sm btn-outline-secondary w-100 mb-2"><i class="bi bi-file-earmark-text me-1"></i>Run Weekly Review</a>
        <?php endif; ?>
        <div class="tilia-starters mb-2" id="tiliaStarters">
            <button type="button" data-q="Who are you?">Who are you?</button>
            <button type="button" data-q="How do I create a request?">How do I create a request?</button>
            <button type="button" data-q="How do I create a request template?">How do I create a request template?</button>
            <button type="button" data-q="How do approvals work?">How do approvals work?</button>
            <button type="button" data-q="What happens when I mention someone?">What happens when I mention someone?</button>
            <button type="button" data-q="How do I check my ticket status?">How do I check my ticket status?</button>
        </div>
    </div>
    <div class="p-2 border-top bg-white">
        <div class="input-group tilia-input mb-2">
            <textarea class="form-control" id="tiliaInput" placeholder="Ask Tilia about Botll..." autocomplete="off" maxlength="2000" rows="2"></textarea>
            <button class="btn btn-accent" type="button" id="tiliaSend">Send</button>
        </div>
        <div class="small text-muted">Tilia only answers Botll questions; she cannot access private data or external sites.</div>
    </div>
</div>
<?php
$tiliaEndpoint = APP_WEB_BASE . '/api/tilia_assistant.php';
?>
<script type="application/json" id="tiliaMeta"><?php echo json_encode(['csrf' => csrf_token(), 'endpoint' => $tiliaEndpoint, 'webBase' => APP_WEB_BASE], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
