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
<?php
$__hodWarn = hod_department_warning();
if ($__hodWarn) :
    ?>
    <div class="container-fluid px-3 px-lg-4 pt-3 pb-0">
        <div class="alert alert-warning mb-0 small"><?php echo e($__hodWarn); ?></div>
    </div>
<?php endif; ?>
<main class="app-content flex-grow-1">
