<?php

declare(strict_types=1);

/**
 * @return array<string,mixed>|null
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = db()->prepare(
        'SELECT u.id, u.full_name, u.email, u.username, u.role_id, u.department_id, u.status,
                r.role_key, r.role_name, d.department_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = ? AND u.status = "active" LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();
    $cache = $row ?: null;
    return $cache;
}

function require_login(): void
{
    if (!current_user()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('login.php');
    }
}

/**
 * @param array<int,string> $allowedRoleKeys
 */
function require_roles(array $allowedRoleKeys): void
{
    require_login();
    $u = current_user();
    if (!$u || !in_array((string) $u['role_key'], $allowedRoleKeys, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body style="font-family:system-ui;padding:2rem;"><h1>403 Forbidden</h1><p>You do not have access to this page.</p><p><a href="dashboard.php">Back to dashboard</a></p></body></html>';
        exit;
    }
}

/**
 * Check if current user can access a route key used in RBAC map.
 */
function can_access(string $pageKey): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    $role = (string) $u['role_key'];

    /** @var array<string,array<int,string>> $map */
    $map = [
        'dashboard' => ['super_admin', 'admin', 'director', 'hod', 'user'],
        'all_tickets' => ['super_admin', 'admin', 'director', 'hod', 'user'],
        'my_tickets' => ['super_admin', 'admin', 'director', 'hod', 'user'],
        'create_ticket' => ['super_admin', 'admin', 'director', 'hod', 'user'],
        'ticket_templates' => ['super_admin', 'admin', 'director', 'hod', 'user'],
        'create_template' => ['super_admin', 'admin'],
        'edit_template' => ['super_admin', 'admin'],
        'requests' => ['super_admin', 'admin', 'director', 'hod', 'user'],
        'new_request' => ['super_admin', 'admin', 'director', 'hod', 'user'],
        'users' => ['super_admin', 'admin'],
        'reports' => ['super_admin', 'admin', 'director', 'hod'],
        'settings' => ['super_admin', 'admin'],
        'account' => ['super_admin', 'admin', 'director', 'hod', 'user'],
        'faq' => ['super_admin', 'admin', 'director', 'hod', 'user'],
    ];

    if (!isset($map[$pageKey])) {
        return false;
    }
    return in_array($role, $map[$pageKey], true);
}

function require_page(string $pageKey): void
{
    require_login();
    if (!can_access($pageKey)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body style="font-family:system-ui;padding:2rem;"><h1>403 Forbidden</h1><p><a href="dashboard.php">Dashboard</a></p></body></html>';
        exit;
    }
}

function login_user(string $username, string $password): bool
{
    $stmt = db()->prepare(
        'SELECT u.id, u.password_hash, u.status FROM users u WHERE u.username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || ($row['status'] ?? '') !== 'active') {
        return false;
    }
    if (!password_verify($password, (string) $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    return true;
}

function actor_role_key(): string
{
    $u = current_user();
    return $u ? (string) $u['role_key'] : '';
}

/**
 * Whether the current actor may assign the given role key to another user.
 */
function can_actor_assign_role_key(string $targetRoleKey): bool
{
    $a = actor_role_key();
    if ($a === 'super_admin') {
        return true;
    }
    if ($a === 'admin' && $targetRoleKey !== 'super_admin') {
        return true;
    }
    return false;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Restricted ticket listing: non-privileged users see only their tickets.
 */
function tickets_scope_sql(string $alias = 't'): string
{
    $u = current_user();
    if (!$u) {
        return '1=0';
    }
    if (in_array((string) $u['role_key'], ['super_admin', 'admin', 'director', 'hod'], true)) {
        return '1=1';
    }
    $uid = (int) $u['id'];
    return '(' . $alias . '.created_by = ' . $uid
        . ' OR ' . $alias . '.assigned_to = ' . $uid
        . ' OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = ' . $alias . '.id AND ta.user_id = ' . $uid . ')'
        . ' OR EXISTS (SELECT 1 FROM ticket_approvals tap WHERE tap.ticket_id = ' . $alias . '.id AND tap.approver_id = ' . $uid . ')'
        . ')';
}
