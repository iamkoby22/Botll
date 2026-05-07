<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('faq');

$rows = [];
try {
    $rows = db()->query('SELECT * FROM faqs WHERE is_active = 1 ORDER BY category ASC, id ASC')->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

$cats = [];
foreach ($rows as $r) {
    $c = (string) $r['category'];
    if (!isset($cats[$c])) {
        $cats[$c] = [];
    }
    $cats[$c][] = $r;
}

$pageTitle = 'FAQ';
$activeNav = 'faq';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1>FAQ</h1>
        <div class="subtitle">Searchable help articles for Botll</div>
    </div>

    <div class="card-surface p-3 mb-3">
        <label class="form-label small text-muted">Search FAQs</label>
        <input type="search" class="form-control" id="faqSearch" placeholder="Type to filter questions and answers...">
    </div>

    <div id="faqAccordion" class="accordion">
        <?php
        $i = 0;
        foreach ($cats as $cat => $items) :
            ?>
            <h2 class="h6 fw-bold mt-4 mb-2 text-muted"><?php echo e($cat); ?></h2>
            <?php foreach ($items as $row) :
                $i++;
                $headingId = 'fqh' . $i;
                $collapseId = 'fqc' . $i;
                ?>
                <div class="accordion-item faq-item mb-2 border rounded-3 overflow-hidden" data-text="<?php echo e(strtolower($row['question'] . ' ' . $row['answer'])); ?>">
                    <h3 class="accordion-header" id="<?php echo e($headingId); ?>">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo e($collapseId); ?>">
                            <?php echo e($row['question']); ?>
                        </button>
                    </h3>
                    <div id="<?php echo e($collapseId); ?>" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body small" style="white-space:pre-wrap;"><?php echo e($row['answer']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$rows) : ?>
            <div class="text-muted">No FAQs loaded. Run <code>database/migration_002_platform_completion.sql</code> to install FAQ content.</div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
  const input=document.getElementById('faqSearch');
  if(!input) return;
  input.addEventListener('input',()=>{
    const q=input.value.trim().toLowerCase();
    document.querySelectorAll('.faq-item').forEach((el)=>{
      const t=(el.getAttribute('data-text')||'');
      el.style.display = (!q || t.includes(q)) ? '' : 'none';
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
