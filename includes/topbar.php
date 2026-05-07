<?php

declare(strict_types=1);

$u = current_user();
$uid = (int) ($u['id'] ?? 0);
$__topbar_q = isset($topbarSearchQuery) ? (string) $topbarSearchQuery : '';

$notifications = [];
try {
    $notifStmt = db()->prepare(
        'SELECT id, title, message, created_at, is_read, action_url FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10'
    );
    $notifStmt->execute([$uid]);
    $notifications = $notifStmt->fetchAll();
} catch (Throwable $e) {
    try {
        $notifStmt = db()->prepare(
            'SELECT id, title, message, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10'
        );
        $notifStmt->execute([$uid]);
        $notifications = $notifStmt->fetchAll();
    } catch (Throwable $e2) {
        $notifications = [];
    }
}

$unreadStmt = db()->prepare('SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadStmt->execute([$uid]);
$unread = (int) ($unreadStmt->fetch()['c'] ?? 0);

$returnUrl = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
if (!empty($_SERVER['QUERY_STRING'])) {
    $returnUrl .= '?' . (string) $_SERVER['QUERY_STRING'];
}
?>
<header class="app-topbar border-bottom">
    <div class="container-fluid py-2 px-3 px-lg-4 d-flex align-items-center gap-2">
        <form class="flex-grow-1 me-2" method="get" action="all_tickets.php" role="search">
            <div class="input-group input-group-sm topbar-search">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="search" name="q" class="form-control border-start-0" placeholder="Search ..." aria-label="Search tickets" value="<?php echo e($__topbar_q); ?>">
            </div>
        </form>
        <div class="d-flex align-items-center gap-2 ms-auto">
            <div class="dropdown">
                <button class="btn btn-link text-white topbar-icon position-relative" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="notifToggle" aria-label="Notifications">
                    <i class="bi bi-bell fs-5"></i>
                    <?php if ($unread > 0) : ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-accent"><?php echo (string) min($unread, 9); ?></span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow tilia-dropdown p-0 overflow-hidden" style="min-width:320px;max-height:420px;overflow-y:auto;" aria-labelledby="notifToggle">
                    <li class="px-3 py-2 border-bottom d-flex align-items-center justify-content-between gap-2">
                        <span class="small fw-semibold text-muted">Notifications</span>
                        <?php if ($unread > 0) : ?>
                            <form method="post" action="notifications_mark_all.php" class="m-0">
                                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">
                                <button type="submit" class="btn btn-link btn-sm p-0 small">Mark all read</button>
                            </form>
                        <?php endif; ?>
                    </li>
                    <?php if (!$notifications) : ?>
                        <li class="px-3 py-3 text-muted small">No notifications yet.</li>
                    <?php else : ?>
                        <?php foreach ($notifications as $n) :
                            $nid = (int) $n['id'];
                            $isRead = !empty($n['is_read']);
                            ?>
                            <li>
                                <a class="dropdown-item small py-2 <?php echo $isRead ? '' : 'bg-soft-unread'; ?>"
                                   href="notifications_go.php?id=<?php echo $nid; ?>">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="fw-semibold"><?php echo e($n['title']); ?></div>
                                        <?php if (!$isRead) : ?>
                                            <span class="badge rounded-pill bg-accent align-self-start">New</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted"><?php echo e($n['message']); ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;"><?php echo e(date('m/d/Y H:i', strtotime((string) $n['created_at']))); ?></div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="dropdown">
                <button class="btn btn-link text-white topbar-icon" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="accountMenuBtn" aria-label="Account menu">
                    <i class="bi bi-person-circle fs-4"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow tilia-dropdown" aria-labelledby="accountMenuBtn">
                    <li class="px-3 py-2 border-bottom small text-muted">
                        Signed in as<br><strong><?php echo e($u['full_name'] ?? ''); ?></strong>
                    </li>
                    <li><a class="dropdown-item" href="account.php"><i class="bi bi-person-badge me-2"></i>Account</a></li>
                    <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                    <li><a class="dropdown-item" href="faq.php"><i class="bi bi-question-circle me-2"></i>FAQ</a></li>
                    <li><button class="dropdown-item" type="button" data-tilia-open="1"><i class="bi bi-chat-dots me-2"></i>Talk to Tilia</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</header>
