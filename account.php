<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('account');

$u = current_user();
$pdo = db();
$errors = [];
$success = '';

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'profile') {
            $full = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($full === '' || $email === '') {
                $errors[] = 'Name and email are required.';
            } else {
                $pdo->prepare('UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ? LIMIT 1')
                    ->execute([$full, $email, (int) $u['id']]);
                $success = 'Profile updated.';
                // bust current_user cache by redirect
                redirect('account.php?saved=1');
            }
        } elseif ($action === 'password') {
            $cur = (string) ($_POST['current_password'] ?? '');
            $np = (string) ($_POST['new_password'] ?? '');
            $cp = (string) ($_POST['new_password_confirm'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $u['id']]);
            $hash = (string) ($stmt->fetch()['password_hash'] ?? '');
            if (!password_verify($cur, $hash)) {
                $errors[] = 'Current password is incorrect.';
            } elseif (strlen($np) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } elseif ($np !== $cp) {
                $errors[] = 'New passwords do not match.';
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ? LIMIT 1')
                    ->execute([password_hash($np, PASSWORD_DEFAULT), (int) $u['id']]);
                $success = 'Password changed.';
                redirect('account.php?pw=1');
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'Profile updated.';
}
if (isset($_GET['pw'])) {
    $success = 'Password changed.';
}

// refresh user row
$stmt = $pdo->prepare(
    'SELECT u.*, r.role_name, d.department_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=? LIMIT 1'
);
$stmt->execute([(int) $u['id']]);
$profile = $stmt->fetch() ?: $u;

$pageTitle = 'Account';
$activeNav = 'account';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1>Account</h1>
        <div class="subtitle">Your profile and security</div>
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
        <div class="col-lg-6">
            <div class="card-surface p-3 p-lg-4 mb-3">
                <h2 class="h6 fw-bold mb-3">Profile</h2>
                <div class="small text-muted mb-3">Username: <strong><?php echo e((string) $profile['username']); ?></strong></div>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="profile">
                    <div class="mb-3">
                        <label class="form-label small">Full name</label>
                        <input class="form-control" name="full_name" required value="<?php echo e((string) $profile['full_name']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Email</label>
                        <input class="form-control" type="email" name="email" required value="<?php echo e((string) $profile['email']); ?>">
                    </div>
                    <div class="mb-3 small text-muted">
                        Role: <strong><?php echo e((string) $profile['role_name']); ?></strong><br>
                        Department: <strong><?php echo e((string) ($profile['department_name'] ?? '—')); ?></strong><br>
                        Status: <strong><?php echo e((string) $profile['status']); ?></strong>
                    </div>
                    <button class="btn btn-accent" type="submit">Save profile</button>
                </form>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card-surface p-3 p-lg-4 mb-3">
                <h2 class="h6 fw-bold mb-3">Change password</h2>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="password">
                    <div class="mb-3">
                        <label class="form-label small">Current password</label>
                        <input class="form-control" type="password" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">New password</label>
                        <input class="form-control" type="password" name="new_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Confirm new password</label>
                        <input class="form-control" type="password" name="new_password_confirm" required>
                    </div>
                    <button class="btn btn-accent" type="submit">Update password</button>
                </form>
            </div>
            <div class="card-surface p-3">
                <h2 class="h6 fw-bold mb-2">Session</h2>
                <p class="small text-muted mb-0">For security, sign out when finished on a shared computer.</p>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
