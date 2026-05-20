<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_login();

if (!is_post() || !csrf_verify($_POST['_csrf'] ?? null)) {
    flash_set('danger', 'Invalid request.');
    redirect('dashboard.php');
}

mark_all_notifications_read((int) current_user()['id']);
flash_set('success', 'All notifications marked as read.');
redirect($_POST['return'] ?? 'dashboard.php');
