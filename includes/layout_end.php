<?php

declare(strict_types=1);

/** @var bool $includeCharts */

$includeCharts = $includeCharts ?? false;
?>
<?php require __DIR__ . '/tilia_assistant.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<?php if (!empty($includeCharts)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="assets/js/app.js" defer></script>
</body>
</html>
