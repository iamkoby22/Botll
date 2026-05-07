<?php

declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $activeNav */
/** @var bool $includeCharts */

$includeCharts = $includeCharts ?? false;
require __DIR__ . '/layout_start.php';
require __DIR__ . '/sidebar.php';
?>
<div class="app-main flex-grow-1 d-flex flex-column min-vh-100">
<?php require __DIR__ . '/topbar.php'; ?>
<main class="app-content flex-grow-1">
