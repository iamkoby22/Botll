<?php

declare(strict_types=1);

/** @var string $activeNav */

$u = current_user();
$nav = [
    'create_ticket' => ['href' => 'create_ticket.php', 'label' => 'Create Ticket', 'icon' => 'bi-plus-square'],
    'dashboard' => ['href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'bi-speedometer2'],
    'all_tickets' => ['href' => 'all_tickets.php', 'label' => 'All Tickets', 'icon' => 'bi-ticket-detailed'],
    'my_tickets' => ['href' => 'my_tickets.php', 'label' => 'My Tickets', 'icon' => 'bi-person-lines-fill'],
    'ticket_templates' => ['href' => 'ticket_templates.php', 'label' => 'Ticket Templates', 'icon' => 'bi-journal-text'],
    'requests' => ['href' => 'requests.php', 'label' => 'Requests', 'icon' => 'bi-inboxes'],
    'users' => ['href' => 'users.php', 'label' => 'User/Access', 'icon' => 'bi-people'],
    'reports' => ['href' => 'reports.php', 'label' => 'Reports', 'icon' => 'bi-graph-up-arrow'],
    'faq' => ['href' => 'faq.php', 'label' => 'FAQ', 'icon' => 'bi-question-circle'],
    'settings' => ['href' => 'settings.php', 'label' => 'Settings', 'icon' => 'bi-gear'],
];
?>
<button class="btn btn-sm tilia-menu-toggle d-lg-none mb-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas">
    <i class="bi bi-list"></i> Menu
</button>

<div class="offcanvas-lg offcanvas-start app-sidebar text-white" tabindex="-1" id="sidebarOffcanvas">
<div class="offcanvas-header d-lg-none border-bottom border-opacity-25 border-light">
    <span class="offcanvas-title fw-bold">Navigation</span>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebarOffcanvas" aria-label="Close"></button>
</div>
<div class="offcanvas-body d-flex flex-column p-0">
<aside class="app-sidebar-inner d-flex flex-column h-100">
    <div class="px-3 py-4 border-bottom border-opacity-25 border-light">
        <div class="d-flex align-items-center gap-2">
            <span class="brand-badge">B</span>
            <div>
                <div class="fw-semibold">Botll</div>
                <div class="small text-white-50">Support Requests</div>
            </div>
        </div>
    </div>
    <nav class="nav flex-column px-2 py-3 gap-1 flex-grow-1">
        <?php foreach ($nav as $key => $item) :
            if ($key === 'users' && !can_access('users')) {
                continue;
            }
            if ($key === 'reports' && !can_access('reports')) {
                continue;
            }
            if ($key === 'settings' && !can_access('settings')) {
                continue;
            }
            if ($key === 'faq' && !can_access('faq')) {
                continue;
            }
            if ($key === 'ticket_templates' && !can_access('ticket_templates')) {
                continue;
            }
            $isActive = $activeNav === $key;
        ?>
            <a class="nav-link sidebar-link <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo e($item['href']); ?>">
                <i class="bi <?php echo e($item['icon']); ?> me-2"></i>
                <span><?php echo e($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <button type="button" class="tilia-sidebar-trigger border-0 text-start" id="tiliaOpenSidebar" data-tilia-open="1" aria-label="Open Tilia assistant">
        <div class="d-flex align-items-start gap-3 tilia-card">
            <div class="tilia-avatar" aria-hidden="true">T</div>
            <div class="small">
                <div class="text-white-50 mb-1">For supports and report talk to</div>
                <div class="fw-semibold">Tilia</div>
                <div class="text-white-50">AI assistant</div>
            </div>
        </div>
    </button>
</aside>
</div>
</div>
