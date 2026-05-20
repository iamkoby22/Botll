<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_login();

$u = current_user();
$errors = [];
$forced = user_must_change_password();

if (!$forced) {
    redirect('account.php');
}

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($current === '' || $new === '' || $confirm === '') {
            $errors[] = 'All password fields are required.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New password and confirmation do not match.';
        } else {
            $st = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $st->execute([(int) $u['id']]);
            $row = $st->fetch();
            if (!$row || !password_verify($current, (string) $row['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                try {
                    db()->prepare(
                        'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?'
                    )->execute([$hash, (int) $u['id']]);
                } catch (Throwable $e) {
                    db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$hash, (int) $u['id']]);
                }
                clear_current_user_cache();
                flash_set('success', 'Password updated. You can now use the helpdesk.');
                redirect('dashboard.php');
            }
        }
    }
}

$pageTitle = 'Change password';
$activeNav = '';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4" style="max-width:520px;">
    <div class="page-title-block mb-3">
        <h1>Change password</h1>
    </div>
    <div class="alert alert-warning">
        You are using a temporary password. Please create a new password to continue.
    </div>
    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>
    <form method="post" class="card-surface p-4">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="mb-3">
            <label class="form-label small fw-semibold">Current (temporary) password</label>
            <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">New password</label>
            <input type="password" class="form-control" name="new_password" required autocomplete="new-password" minlength="8">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Confirm new password</label>
            <input type="password" class="form-control" name="confirm_password" required autocomplete="new-password" minlength="8">
        </div>
        <button type="submit" class="btn btn-accent w-100">Save new password</button>
    </form>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
