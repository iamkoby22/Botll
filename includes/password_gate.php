<?php
declare(strict_types=1);

function users_has_must_change_column(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $st = db()->query('SHOW COLUMNS FROM users LIKE "must_change_password"');
        $has = (bool) $st->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

function user_must_change_password(?array $u = null): bool
{
    $u = $u ?? current_user();
    if (!$u || !users_has_must_change_column()) {
        return false;
    }
    return !empty($u['must_change_password']);
}

/**
 * Redirect new users to change password before using the app.
 */
function require_password_changed(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    if (!users_has_must_change_column()) {
        return;
    }
    $st = db()->prepare('SELECT must_change_password FROM users WHERE id = ? AND status = "active" LIMIT 1');
    $st->execute([(int) $_SESSION['user_id']]);
    $row = $st->fetch();
    if (!$row || !(int) ($row['must_change_password'] ?? 0)) {
        return;
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = ['change_password.php', 'logout.php'];
    if (in_array($script, $allowed, true)) {
        return;
    }
    redirect('change_password.php');
}
