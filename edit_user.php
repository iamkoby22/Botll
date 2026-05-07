<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_login();
require_roles(['super_admin', 'admin']);

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    redirect('users.php');
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT u.*, r.role_key FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) {
    redirect('users.php');
}

$actor = current_user();
if ((string) $user['role_key'] === 'super_admin' && (string) $actor['role_key'] !== 'super_admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
if (!can_actor_assign_role_key((string) $user['role_key']) && (int) $user['id'] !== (int) $actor['id']) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$errors = [];
$roles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $full = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $rid = (int) ($_POST['role_id'] ?? 0);
        $dept = (int) ($_POST['department_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'active');
        $newPass = (string) ($_POST['new_password'] ?? '');

        $roleRow = $pdo->prepare('SELECT role_key FROM roles WHERE id = ? LIMIT 1');
        $roleRow->execute([$rid]);
        $rk = (string) ($roleRow->fetch()['role_key'] ?? '');
        if (!can_actor_assign_role_key($rk)) {
            $errors[] = 'You cannot assign that role.';
        }
        if ($full === '' || $email === '' || $username === '' || $rid < 1) {
            $errors[] = 'Name, email, username, and role are required.';
        }
        if ($newPass !== '' && strlen($newPass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!$errors) {
            $deptVal = $dept > 0 ? $dept : null;
            if ($newPass !== '') {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $pdo->prepare(
                    'UPDATE users SET full_name=?, email=?, username=?, role_id=?, department_id=?, status=?, password_hash=?, updated_at=NOW() WHERE id=?'
                )->execute([$full, $email, $username, $rid, $deptVal, $status, $hash, $id]);
            } else {
                $pdo->prepare(
                    'UPDATE users SET full_name=?, email=?, username=?, role_id=?, department_id=?, status=?, updated_at=NOW() WHERE id=?'
                )->execute([$full, $email, $username, $rid, $deptVal, $status, $id]);
            }
            flash_set('success', 'User updated.');
            redirect('users.php');
        }
    }
}

$pageTitle = 'Edit User';
$activeNav = 'users';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div class="page-title-block mb-0">
            <h1>Edit User</h1>
            <div class="subtitle"><?php echo e($user['username']); ?></div>
        </div>
        <a class="btn btn-outline-muted btn-sm" href="users.php">Back</a>
    </div>

    <?php if ($errors) : ?>
        <div class="alert alert-danger"><?php foreach ($errors as $er) {
            echo '<div>' . e($er) . '</div>';
        } ?></div>
    <?php endif; ?>

    <form method="post" class="card-surface p-3 p-lg-4">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Full name</label>
                <input class="form-control" name="full_name" required value="<?php echo e((string) $user['full_name']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Username</label>
                <input class="form-control" name="username" required value="<?php echo e((string) $user['username']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Email</label>
                <input class="form-control" type="email" name="email" required value="<?php echo e((string) $user['email']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">New password (optional)</label>
                <input class="form-control" type="password" name="new_password" placeholder="Leave blank to keep current">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Role</label>
                <select class="form-select" name="role_id" required>
                    <?php foreach ($roles as $r) :
                        $rk = (string) $r['role_key'];
                        $selected = (int) $r['id'] === (int) $user['role_id'];
                        if (!can_actor_assign_role_key($rk) && !$selected) {
                            continue;
                        }
                        ?>
                        <option value="<?php echo (int) $r['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>><?php echo e($r['role_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Department</label>
                <select class="form-select" name="department_id">
                    <option value="0">None</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo ((int) ($user['department_id'] ?? 0) === (int) $d['id']) ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Status</label>
                <select class="form-select" name="status">
                    <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="disabled" <?php echo $user['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2 justify-content-end">
                <a class="btn btn-outline-muted" href="users.php">Cancel</a>
                <button class="btn btn-accent" type="submit">Save changes</button>
            </div>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
