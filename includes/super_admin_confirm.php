<?php
declare(strict_types=1);

/**
 * Verify current Super Admin password for destructive actions.
 */
function super_admin_confirm_password(?string $password): bool
{
    $u = current_user();
    if (!$u || !is_super_admin_role((string) ($u['role_key'] ?? ''))) {
        return false;
    }
    if ($password === null || trim($password) === '') {
        return false;
    }
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int) $u['id']]);
    $hash = $st->fetchColumn();
    if (!$hash) {
        return false;
    }
    return password_verify(trim($password), (string) $hash);
}

/**
 * @param array<int,string> $errors
 */
function super_admin_require_password(?string $password, array &$errors, string $actionLabel = 'this action'): bool
{
    if (!is_super_admin_role()) {
        $errors[] = 'Only Super Admin may perform ' . $actionLabel . '.';
        return false;
    }
    if (!super_admin_confirm_password($password)) {
        $errors[] = 'Super Admin password confirmation failed.';
        return false;
    }
    return true;
}
