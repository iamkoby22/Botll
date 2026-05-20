<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_login();
require_roles(['super_admin']);

$pdo = db();
$actor = current_user();
$errors = [];
$roles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

$vals = [
    'full_name' => trim((string) ($_POST['full_name'] ?? '')),
    'username' => trim((string) ($_POST['username'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'role_id' => (string) ($_POST['role_id'] ?? ''),
    'department_id' => (string) ($_POST['department_id'] ?? ''),
    'status' => (string) ($_POST['status'] ?? 'active'),
    'password' => (string) ($_POST['password'] ?? ''),
];

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } else {
        $rid = (int) $vals['role_id'];
        $roleRow = $pdo->prepare('SELECT role_key FROM roles WHERE id = ? LIMIT 1');
        $roleRow->execute([$rid]);
        $rk = (string) ($roleRow->fetch()['role_key'] ?? '');
        if (!can_actor_assign_role_key($rk)) {
            $errors[] = 'You cannot assign that role.';
        }
        if ($vals['full_name'] === '' || $vals['username'] === '' || $vals['email'] === '' || $rid < 1) {
            $errors[] = 'Name, username, email, and role are required.';
        }
        $dept = (int) $vals['department_id'];
        if (in_array($rk, ['hod', 'director'], true) && $dept < 1) {
            $errors[] = 'Department is required for HOD and Director roles.';
        }
        if (strlen($vals['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        $adminPass = (string) ($_POST['admin_password_confirm'] ?? '');
        if ($adminPass === '') {
            $errors[] = 'Enter your admin password to confirm creating a user.';
        } else {
            $ph = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $ph->execute([(int) $actor['id']]);
            $hashRow = $ph->fetch();
            if (!$hashRow || !password_verify($adminPass, (string) $hashRow['password_hash'])) {
                $errors[] = 'Admin password confirmation does not match your account.';
            }
        }
        if (!$errors) {
            $deptVal = $dept > 0 ? $dept : null;
            $hash = password_hash($vals['password'], PASSWORD_DEFAULT);
            $statusVal = $vals['status'] === 'disabled' ? 'disabled' : 'active';
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, username, password_hash, role_id, department_id, status, must_change_password) VALUES (?,?,?,?,?,?,?,1)'
                );
                $stmt->execute([
                    $vals['full_name'], $vals['email'], $vals['username'], $hash, $rid, $deptVal, $statusVal,
                ]);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, username, password_hash, role_id, department_id, status) VALUES (?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $vals['full_name'], $vals['email'], $vals['username'], $hash, $rid, $deptVal, $statusVal,
                ]);
            }
            $newId = (int) $pdo->lastInsertId();
            if ($newId > 0 && users_has_must_change_column()) {
                $pdo->prepare('UPDATE users SET must_change_password = 1 WHERE id = ?')->execute([$newId]);
            }
            flash_set('success', 'User created.');
            redirect('users.php');
        }
    }
}

$pageTitle = 'Add User';
$activeNav = 'users';
$includeCharts = false;

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div class="page-title-block mb-0">
            <h1>Add User</h1>
            <div class="subtitle">Create a new account</div>
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
                <input class="form-control" name="full_name" required value="<?php echo e($vals['full_name']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Username</label>
                <input class="form-control" name="username" required value="<?php echo e($vals['username']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Email</label>
                <input class="form-control" type="email" name="email" required value="<?php echo e($vals['email']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Temporary password</label>
                <input class="form-control" type="password" name="password" required>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold small">Your admin password (confirmation)</label>
                <input class="form-control" type="password" name="admin_password_confirm" required autocomplete="current-password" placeholder="Re-enter your password to authorize this action">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Access Role</label>
                <select class="form-select" name="role_id" required>
                    <option value="">Select</option>
                    <?php foreach ($roles as $r) :
                        if (!can_actor_assign_role_key((string) $r['role_key'])) {
                            continue;
                        }
                        ?>
                        <option value="<?php echo (int) $r['id']; ?>"><?php echo e($r['role_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Department</label>
                <select class="form-select" name="department_id">
                    <option value="0">None</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>"><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Status</label>
                <select class="form-select" name="status">
                    <option value="active">Active</option>
                    <option value="disabled">Disabled</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2 justify-content-end">
                <a class="btn btn-outline-muted" href="users.php">Cancel</a>
                <button class="btn btn-accent" type="submit">Save user</button>
            </div>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
