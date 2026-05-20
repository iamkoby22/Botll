<?php
declare(strict_types=1);

/** SBS access — source of truth is roles.role_key via users.role_id. */

/** @return array<string,string> role_key => human label */
function sbs_required_roles(): array
{
    return [
        'user' => 'User',
        'admin' => 'Admin',
        'super_admin' => 'Super Admin',
        'faculty_staff' => 'Faculty/Staff',
        'restricted_pillar_admin' => 'Restricted Pillar Admin',
        'unrestricted_pillar_admin' => 'Unrestricted Pillar Admin',
        'general_pillar_admin' => 'General Pillar Admin',
        'business_admin' => 'Business Admin',
        'coordinator' => 'Coordinator',
    ];
}

function is_super_admin_role(?string $roleKey = null): bool
{
    $roleKey = strtolower(trim($roleKey ?? current_user_role_key()));
    return in_array($roleKey, ['super_admin', 'superadmin'], true);
}

function is_authenticated_active_user(): bool
{
    return current_user() !== null;
}

function user_role_key(int $userId): string
{
    if ($userId < 1) {
        return 'user';
    }
    $st = db()->prepare(
        'SELECT COALESCE(r.role_key, \'user\') FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1'
    );
    $st->execute([$userId]);
    $rk = trim((string) ($st->fetchColumn() ?: 'user'));
    return $rk !== '' ? $rk : 'user';
}

/**
 * @param array<string,mixed>|null $user
 */
function current_user_role_key(?array $user = null): string
{
    $user = $user ?? current_user();
    if (!$user) {
        return 'user';
    }
    $rk = trim((string) ($user['role_key'] ?? ''));
    if ($rk === '' && !empty($user['id'])) {
        return user_role_key((int) $user['id']);
    }
    return $rk !== '' ? $rk : 'user';
}

function user_has_role(int $userId, string $roleKey): bool
{
    if (is_super_admin_role($roleKey) && is_super_admin_role(user_role_key($userId))) {
        return true;
    }
    return user_role_key($userId) === $roleKey;
}

function current_user_has_role(string $roleKey): bool
{
    return user_has_role((int) (current_user()['id'] ?? 0), $roleKey);
}

/**
 * @param array<int,string> $allowedRoleKeys
 */
function role_matches_any(string $role, array $allowedRoleKeys): bool
{
    if (in_array($role, $allowedRoleKeys, true)) {
        return true;
    }
    if (is_super_admin_role($role)) {
        foreach ($allowedRoleKeys as $allowed) {
            if (is_super_admin_role($allowed)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @param array<string,mixed>|null $user
 */
function user_is_super_admin(?array $user = null): bool
{
    return is_super_admin_role(current_user_role_key($user));
}

/**
 * @param array<string,mixed>|null $user
 */
function user_is_restricted_pillar_admin(?array $user = null): bool
{
    return current_user_role_key($user) === 'restricted_pillar_admin';
}

/**
 * @param array<string,mixed>|null $user
 */
function user_is_unrestricted_pillar_admin(?array $user = null): bool
{
    return current_user_role_key($user) === 'unrestricted_pillar_admin';
}

/**
 * @param array<string,mixed>|null $user
 */
function user_is_general_pillar_admin(?array $user = null): bool
{
    return current_user_role_key($user) === 'general_pillar_admin';
}

/**
 * @param array<string,mixed>|null $user
 */
function user_is_pillar_admin(?array $user = null): bool
{
    return in_array(current_user_role_key($user), [
        'restricted_pillar_admin',
        'unrestricted_pillar_admin',
        'general_pillar_admin',
    ], true);
}

/**
 * @param array<string,mixed>|null $user
 */
function user_is_business_admin(?array $user = null): bool
{
    return current_user_role_key($user) === 'business_admin';
}

/**
 * @param array<string,mixed>|null $user
 */
function user_is_coordinator(?array $user = null): bool
{
    return current_user_role_key($user) === 'coordinator';
}

/**
 * @param array<string,mixed>|null $user
 */
function user_is_faculty_staff(?array $user = null): bool
{
    return in_array(current_user_role_key($user), ['user', 'faculty_staff'], true);
}

function role_id_by_key(string $roleKey): int
{
    static $cache = [];
    if (isset($cache[$roleKey])) {
        return $cache[$roleKey];
    }
    $keys = [$roleKey];
    if (is_super_admin_role($roleKey)) {
        $keys = ['super_admin', 'superadmin'];
    }
    foreach ($keys as $k) {
        $st = db()->prepare('SELECT id FROM roles WHERE role_key = ? LIMIT 1');
        $st->execute([$k]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            $cache[$roleKey] = $id;
            return $id;
        }
    }
    $cache[$roleKey] = 0;
    return 0;
}

/** @return list<array<string,mixed>> */
function sbs_users_with_role(string $roleKey): array
{
    $st = db()->prepare(
        'SELECT u.id, u.full_name, u.email, u.username, COALESCE(r.role_key, \'user\') AS role_key
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.status = "active" AND COALESCE(r.role_key, \'user\') = ?
         ORDER BY u.full_name'
    );
    $st->execute([$roleKey]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
