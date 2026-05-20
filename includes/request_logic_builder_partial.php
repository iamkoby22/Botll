<?php
declare(strict_types=1);

/** @var list<array<string,mixed>> $builderFields */
$builderFields = $builderFields ?? [];
?>
<div class="card-surface p-3 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
            <h2 class="h6 fw-bold mb-1">Request Fields</h2>
            <p class="small text-muted mb-0">Fields created here appear on <strong>New Request</strong> when this Request Type / Step path is selected.</p>
        </div>
        <button type="button" class="btn btn-outline-accent btn-sm" id="rlAddFieldBtn"><i class="bi bi-plus-lg"></i> Add Field</button>
    </div>
    <div id="rlFieldList"></div>
</div>
