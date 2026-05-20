<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect('dashboard.php');
}

$pdo = db();
$errors = [];
$success = '';
$departments = [];
try {
    $departments = ref_active_departments();
} catch (Throwable $e) {
    $departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
}

$vals = [
    'full_name' => trim((string) ($_POST['full_name'] ?? '')),
    'username' => trim((string) ($_POST['username'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'department_id' => (string) ($_POST['department_id'] ?? ''),
];

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Invalid session token.';
    } elseif (!users_have_approval_status_column()) {
        $errors[] = 'Self-registration is not available until database migration 008 is applied.';
    } else {
        $pass = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($vals['full_name'] === '' || $vals['username'] === '' || $vals['email'] === '') {
            $errors[] = 'Name, username, and email are required.';
        }
        if (strlen($pass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($pass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
        if (!$errors) {
            $roleId = (int) ($pdo->query('SELECT id FROM roles WHERE role_key = "user" LIMIT 1')->fetch()['id'] ?? 0);
            if ($roleId < 1) {
                $errors[] = 'Default user role is not configured.';
            } else {
                $dept = (int) $vals['department_id'];
                $deptVal = $dept > 0 ? $dept : null;
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                try {
                    $sql = users_has_must_change_column()
                        ? 'INSERT INTO users (full_name, email, username, password_hash, role_id, department_id, status, approval_status, must_change_password) VALUES (?,?,?,?,?,?,"disabled","pending",0)'
                        : 'INSERT INTO users (full_name, email, username, password_hash, role_id, department_id, status, approval_status) VALUES (?,?,?,?,?,?,"disabled","pending")';
                    $pdo->prepare($sql)->execute([
                        $vals['full_name'],
                        $vals['email'],
                        $vals['username'],
                        $hash,
                        $roleId,
                        $deptVal,
                    ]);
                    $success = 'Your account has been submitted and is pending Super Admin approval.';
                    $vals = ['full_name' => '', 'username' => '', 'email' => '', 'department_id' => ''];
                } catch (Throwable $e) {
                    $errors[] = 'Could not register. Username or email may already be in use.';
                }
            }
        }
    }
}

$pageTitle = 'Create account';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> · Botll</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container py-5" style="max-width:520px;">
    <h1 class="h3 fw-bold mb-3">Create an account</h1>
    <?php if ($success) : ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
        <a class="btn btn-accent" href="login.php">Back to login</a>
    <?php else : ?>
        <?php if ($errors) : ?>
            <div class="alert alert-danger"><?php foreach ($errors as $er) {
                echo '<div>' . e($er) . '</div>';
            } ?></div>
        <?php endif; ?>
        <form method="post" class="card-surface p-4">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <div class="mb-3">
                <label class="form-label">Full name</label>
                <input class="form-control" name="full_name" required value="<?php echo e($vals['full_name']); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input class="form-control" name="username" required value="<?php echo e($vals['username']); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" required value="<?php echo e($vals['email']); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Department (optional)</label>
                <select class="form-select" name="department_id">
                    <option value="0">—</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo $vals['department_id'] === (string) $d['id'] ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input class="form-control" type="password" name="password" required minlength="8">
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm password</label>
                <input class="form-control" type="password" name="confirm_password" required minlength="8">
            </div>
            <button type="submit" class="btn btn-accent w-100">Submit for approval</button>
        </form>
        <p class="small text-muted mt-3 mb-0"><a href="login.php">Already have an account? Log in</a></p>
    <?php endif; ?>
</div>
</body>
</html>

