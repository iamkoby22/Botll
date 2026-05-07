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
            $error = 'Invalid credentials.';
        } else {
            $next = $_SESSION['after_login'] ?? 'dashboard.php';
            unset($_SESSION['after_login']);
            if (!is_string($next) || $next === '' || str_contains($next, '://')) {
                $next = 'dashboard.php';
            }
            redirect($next);
        }
    }
}

$pageTitle = 'Login';
$heroPath = __DIR__ . '/assets/images/login-hero.jpg';
$heroHasImage = is_file($heroPath);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> · Botll</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container-fluid px-0 login-page">
    <div class="row g-0">
        <div class="col-lg-6 d-none d-lg-block">
            <div class="login-hero <?php echo $heroHasImage ? '' : 'login-hero-fallback'; ?>" <?php if ($heroHasImage) : ?>style="background-image:url('assets/images/login-hero.jpg')"<?php endif; ?>>
                <div>
                    <div class="display-6 fw-bold mb-2">One Place for</div>
                    <div class="display-5 fw-bold">Every Request.</div>
                </div>
            </div>
        </div>
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
                            <a class="link-soft small" href="#">Forgot Password?</a>
                        </div>
                    </form>
                    <div class="small text-white-50">Use demo credentials from README (e.g. user / password123).</div>
                </div>
            </div>
        </div>
        <div class="col-12 d-lg-none">
            <div class="p-4 px-3 text-center bg-white border-top">
                <div class="fw-bold" style="color:var(--color-text-main)">One Place for Every Request.</div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
