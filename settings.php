<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('settings');

$u = current_user();
$isSuper = (string) $u['role_key'] === 'super_admin';
$pdo = db();
$errors = [];
$success = '';

$categories = $pdo->query('SELECT * FROM ticket_categories ORDER BY category_name')->fetchAll();
$priorities = $pdo->query('SELECT * FROM ticket_priorities ORDER BY priority_level')->fetchAll();
$statuses = $pdo->query('SELECT * FROM ticket_statuses ORDER BY id')->fetchAll();

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'sla' && ($isSuper || (string) $u['role_key'] === 'admin')) {
            setting_set('sla_response_hours', trim((string) ($_POST['sla_response_hours'] ?? '24')), 'sla');
            setting_set('sla_resolution_hours', trim((string) ($_POST['sla_resolution_hours'] ?? '72')), 'sla');
            $success = 'SLA settings saved.';
        } elseif ($action === 'notifications' && $isSuper) {
            setting_set('notify_assignments', isset($_POST['notify_assignments']) ? '1' : '0', 'notifications');
            setting_set('notify_approvals', isset($_POST['notify_approvals']) ? '1' : '0', 'notifications');
            $success = 'Notification settings saved.';
        } elseif ($action === 'assistant' && $isSuper) {
            setting_set('tilia_mode', trim((string) ($_POST['tilia_mode'] ?? 'local')), 'assistant');
            $success = 'Assistant settings saved.';
        } elseif ($action === 'prefs') {
            $pk = 'user_pref_' . (int) $u['id'] . '_density';
            setting_set($pk, trim((string) ($_POST['pref_theme'] ?? 'light')), 'preferences');
            $success = 'Preferences saved.';
        } elseif ($action === 'add_category' && $isSuper) {
            $name = trim((string) ($_POST['category_name'] ?? ''));
            if ($name !== '') {
                try {
                    $pdo->prepare('INSERT INTO ticket_categories (category_name) VALUES (?)')->execute([$name]);
                    $success = 'Category added.';
                } catch (Throwable $e) {
                    $errors[] = 'Could not add category (duplicate?).';
                }
            }
        }
    }
}

$pageTitle = 'Settings';
$activeNav = 'settings';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1>Settings</h1>
        <div class="subtitle">System configuration and personal preferences</div>
    </div>

    <?php if ($success) : ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
    <?php endif; ?>
    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <?php if ($isSuper || (string) $u['role_key'] === 'admin') : ?>
            <div class="col-lg-6">
                <div class="card-surface p-3 p-lg-4 h-100">
                    <h2 class="h6 fw-bold mb-3">SLA targets</h2>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="sla">
                        <div class="mb-3">
                            <label class="form-label small">Response time target (hours)</label>
                            <input class="form-control" name="sla_response_hours" value="<?php echo e(setting_get('sla_response_hours', '24')); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Resolution target (hours)</label>
                            <input class="form-control" name="sla_resolution_hours" value="<?php echo e(setting_get('sla_resolution_hours', '72')); ?>">
                        </div>
                        <button class="btn btn-accent" type="submit">Save SLA settings</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isSuper) : ?>
            <div class="col-lg-6">
                <div class="card-surface p-3 p-lg-4 h-100">
                    <h2 class="h6 fw-bold mb-3">Notifications</h2>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="notifications">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="notify_assignments" id="na" <?php echo setting_get('notify_assignments', '1') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="na">Notify assignees on new assignments</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_approvals" id="nap" <?php echo setting_get('notify_approvals', '1') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="nap">Notify approvers for pending approvals</label>
                        </div>
                        <button class="btn btn-accent" type="submit">Save notification settings</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-surface p-3 p-lg-4 h-100">
                    <h2 class="h6 fw-bold mb-3">Tilia assistant</h2>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="assistant">
                        <label class="form-label small">Mode</label>
                        <select class="form-select mb-3" name="tilia_mode">
                            <option value="local" <?php echo setting_get('tilia_mode', 'local') === 'local' ? 'selected' : ''; ?>>Local knowledge (default)</option>
                            <option value="openai_ready" <?php echo setting_get('tilia_mode', 'local') === 'openai_ready' ? 'selected' : ''; ?>>OpenAI-ready (server-side only)</option>
                        </select>
                        <p class="small text-muted">OpenAI requires a server key in <code>includes/config.php</code> / environment. Keys are never sent to the browser.</p>
                        <button class="btn btn-accent" type="submit">Save assistant settings</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-surface p-3 p-lg-4 h-100">
                    <h2 class="h6 fw-bold mb-3">Ticket categories</h2>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Name</th></tr></thead>
                            <tbody>
                                <?php foreach ($categories as $c) : ?>
                                    <tr><td><?php echo e($c['category_name']); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="add_category">
                        <input class="form-control form-control-sm" name="category_name" placeholder="New category name">
                        <button class="btn btn-outline-muted btn-sm" type="submit">Add</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-lg-6">
            <div class="card-surface p-3 p-lg-4 h-100">
                <h2 class="h6 fw-bold mb-3">Personal preferences</h2>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="prefs">
                    <label class="form-label small">Display density</label>
                    <?php $prefKey = 'user_pref_' . (int) $u['id'] . '_density'; ?>
                    <select class="form-select mb-3" name="pref_theme">
                        <option value="light" <?php echo setting_get($prefKey, 'light') === 'light' ? 'selected' : ''; ?>>Comfortable</option>
                        <option value="compact" <?php echo setting_get($prefKey, 'light') === 'compact' ? 'selected' : ''; ?>>Compact</option>
                    </select>
                    <button class="btn btn-accent" type="submit">Save preferences</button>
                </form>
            </div>
        </div>

        <div class="col-12">
            <div class="card-surface p-3 p-lg-4">
                <h2 class="h6 fw-bold mb-3">Reference tables (read-only)</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small text-muted mb-1">Priorities</div>
                        <ul class="mb-0 small">
                            <?php foreach ($priorities as $p) : ?>
                                <li><?php echo e($p['priority_name']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted mb-1">Statuses</div>
                        <ul class="mb-0 small">
                            <?php foreach ($statuses as $s) : ?>
                                <li><?php echo e($s['status_name']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
