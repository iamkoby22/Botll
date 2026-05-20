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
    if (!empty($_SESSION['_user_cache_cleared'])) {
        $cache = null;
        unset($_SESSION['_user_cache_cleared']);
    }
    if ($cache !== null) {
        return $cache;
    }
    $mustCol = users_has_must_change_column() ? ', u.must_change_password' : '';
    $apprCol = users_have_approval_status_column() ? ', u.approval_status' : '';
    $stmt = db()->prepare(
        'SELECT u.id, u.full_name, u.email, u.username, u.role_id, u.department_id, u.status' . $mustCol . $apprCol . ',
                COALESCE(r.role_key, \'user\') AS role_key, COALESCE(r.role_name, \'User\') AS role_name,
                d.department_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = ? AND u.status = "active"' . (users_have_approval_status_column() ? ' AND u.approval_status = "approved"' : '') . ' LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();
    $cache = $row ?: null;
    return $cache;
}

function clear_current_user_cache(): void
{
    $_SESSION['_user_cache_cleared'] = true;
}

function require_login(): void
{
    if (!current_user()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('login.php');
    }
    require_password_changed();
}

/**
 * @param array<int,string> $allowedRoleKeys
 */
function require_roles(array $allowedRoleKeys): void
{
    require_login();
    if (role_matches_any(current_user_role_key(), $allowedRoleKeys)) {
        return;
    }
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body style="font-family:system-ui;padding:2rem;"><h1>403 Forbidden</h1><p>You do not have access to this page.</p><p><a href="dashboard.php">Back to dashboard</a></p></body></html>';
    exit;
}

/**
 * Core pages: any authenticated active user (ticket rows scoped inside the page).
 *
 * @return list<string>
 */
function access_login_only_page_keys(): array
{
    return [
        'dashboard',
        'my_tickets',
        'requests',
        'new_request',
        'archive',
        'create_ticket',
        'account',
        'faq',
        'reports',
    ];
}

/**
 * Check if current user can access a route key used in RBAC map.
 */
function can_access(string $pageKey): bool
{
    if (!is_authenticated_active_user()) {
        return false;
    }
    if (in_array($pageKey, access_login_only_page_keys(), true)) {
        return true;
    }

    $role = current_user_role_key();

    /** @var array<string,array<int,string>> $restricted */
    $restricted = [
        'all_tickets' => [
            'super_admin', 'superadmin', 'admin',
            'restricted_pillar_admin', 'unrestricted_pillar_admin', 'general_pillar_admin',
        ],
        'ticket_templates' => ['super_admin', 'superadmin'],
        'create_template' => ['super_admin', 'superadmin'],
        'edit_template' => ['super_admin', 'superadmin'],
        'users' => ['super_admin', 'superadmin'],
        'settings' => ['super_admin', 'superadmin'],
        'auditing' => ['super_admin', 'superadmin'],
        'ai_reviews' => ['super_admin', 'superadmin'],
    ];

    if (!isset($restricted[$pageKey])) {
        return true;
    }
    return role_matches_any($role, $restricted[$pageKey]);
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
    $apprCol = users_have_approval_status_column() ? ', u.approval_status' : '';
    $mustCol = users_has_must_change_column() ? ', u.must_change_password' : '';
    $stmt = db()->prepare(
        'SELECT u.id, u.password_hash, u.status' . $apprCol . $mustCol . ' FROM users u WHERE u.username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        return false;
    }
    if (($row['status'] ?? '') !== 'active') {
        return false;
    }
    if (users_have_approval_status_column() && (string) ($row['approval_status'] ?? 'approved') !== 'approved') {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    clear_current_user_cache();
    return true;
}

function users_have_approval_status_column(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $st = db()->query('SHOW COLUMNS FROM users LIKE "approval_status"');
        $has = (bool) $st->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

function login_user_pending_message(string $username): ?string
{
    if (!users_have_approval_status_column()) {
        return null;
    }
    $stmt = db()->prepare('SELECT status, approval_status FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ((string) ($row['approval_status'] ?? '') === 'pending') {
        return 'Your account is pending Super Admin approval.';
    }
    if ((string) ($row['approval_status'] ?? '') === 'rejected') {
        return 'Your account registration was not approved.';
    }
    if (($row['status'] ?? '') === 'disabled') {
        return 'Your account is disabled.';
    }
    return null;
}

function actor_role_key(): string
{
    return current_user_role_key();
}

/**
 * Whether the current actor may assign the given role key to another user.
 */
function can_actor_assign_role_key(string $targetRoleKey): bool
{
    $a = actor_role_key();
    if (is_super_admin_role($a)) {
        return true;
    }
    if ($a === 'admin' && !is_super_admin_role($targetRoleKey)) {
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

/* tickets_scope_sql() — defined in includes/sbs_workflow.php */

/**
 * Whether a sidebar nav item should render for the current role.
 */
function sidebar_show_nav(string $key): bool
{
    if (!is_authenticated_active_user()) {
        return false;
    }
    $role = actor_role_key();

    return match ($key) {
        'dashboard', 'my_tickets', 'requests', 'new_request', 'reports', 'faq', 'archive' => true,
        'all_tickets' => role_matches_any($role, [
            'super_admin', 'superadmin', 'admin',
            'restricted_pillar_admin', 'unrestricted_pillar_admin', 'general_pillar_admin',
        ]),
        'ticket_templates' => is_super_admin_role($role) || $role === 'admin',
        'create_ticket' => true,
        'users', 'settings', 'auditing', 'ai_reviews' => is_super_admin_role($role),
        default => can_access($key),
    };
}
