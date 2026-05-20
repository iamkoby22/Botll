<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $error = 'Invalid session token. Please try again.';
    } else {
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        if ($u === '' || $p === '') {
            $error = 'Enter username and password.';
        } elseif (!login_user($u, $p)) {
            $pending = login_user_pending_message($u);
            $error = $pending ?? 'Invalid credentials.';
        } else {
            if (users_has_must_change_column()) {
                $st = db()->prepare('SELECT must_change_password FROM users WHERE id = ? LIMIT 1');
                $st->execute([(int) $_SESSION['user_id']]);
                $row = $st->fetch();
                if ($row && (int) ($row['must_change_password'] ?? 0) === 1) {
                    redirect('change_password.php');
                }
            }
            $cu = current_user();
            if ($cu && user_must_change_password($cu)) {
                redirect('change_password.php');
            }
            $next = 'dashboard.php';
            unset($_SESSION['after_login']);
            if (!is_string($next) || $next === '' || str_contains($next, '://')) {
                $next = 'dashboard.php';
            }
            redirect($next);
        }
    }
}

$pageTitle = 'Login';
$heroRel = 'assets/img/abstract-futuristic-school-classroom.jpg';
$heroPath = __DIR__ . '/' . $heroRel;
$heroHasImage = is_file($heroPath);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> · <?php echo e(defined('APP_DISPLAY_NAME') ? APP_DISPLAY_NAME : 'SBS Support Requests'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container-fluid px-0 login-page">
    <div class="row g-0">
        <div class="col-lg-6 d-none d-lg-block">
            <div class="login-hero <?php echo $heroHasImage ? '' : 'login-hero-fallback'; ?>" <?php if ($heroHasImage) : ?>style="background-image:url('<?php echo e($heroRel); ?>')"<?php endif; ?>>
                <div class="login-hero-caption">
                    <div class="display-6 fw-bold mb-2">One Place for</div>
                    <div class="display-5 fw-bold">Every Request.</div>
                </div>
            </div>
        </div>
        <?php if ($heroHasImage) : ?>
        <div class="col-12 d-lg-none">
            <div class="login-hero-mobile" style="background-image:url('<?php echo e($heroRel); ?>')" role="img" aria-label="Botll login"></div>
        </div>
        <?php endif; ?>
        <div class="col-lg-6">
            <div class="login-panel">
                <div class="login-card w-100">
                    <div class="d-flex align-items-center gap-2 mb-4">
                        <a href="login.php" class="link-soft small d-inline-flex align-items-center gap-1"><i class="bi bi-arrow-left"></i> Back</a>
                    </div>
                    <h1 class="h3 fw-bold mb-1">Login</h1>
                    <p class="text-white-50 mb-4">Sign in with School Credentials</p>
                    <?php if ($error) : ?>
                        <div class="alert alert-danger small"><?php echo e($error); ?></div>
                    <?php endif; ?>
                    <form method="post" novalidate>
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="username">Username</label>
                            <input class="form-control" id="username" name="username" autocomplete="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <button type="submit" class="btn btn-accent px-4">Log in</button>
                            <a class="link-soft small" href="register.php">Create an account</a>
                        </div>
                    </form>
                    <div class="small text-white-50">Use demo credentials from README (e.g. user / password123).</div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
