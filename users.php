<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_login();
require_roles(['super_admin', 'admin']);

$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$roleId = (int) ($_GET['role_id'] ?? 0);
$deptId = (int) ($_GET['department_id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));

$wheres = ['1=1'];
$params = [];
if ($q !== '') {
    $wheres[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($roleId > 0) {
    $wheres[] = 'u.role_id = ?';
    $params[] = $roleId;
}
if ($deptId > 0) {
    $wheres[] = 'u.department_id = ?';
    $params[] = $deptId;
}
if ($status === 'active' || $status === 'disabled') {
    $wheres[] = 'u.status = ?';
    $params[] = $status;
}

$whereSql = implode(' AND ', $wheres);
$sql = 'SELECT u.*, r.role_name, r.role_key, d.department_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE ' . $whereSql . '
        ORDER BY u.id ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$roles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

$pageTitle = 'User Access';
$activeNav = 'users';
$includeCharts = false;
$topbarSearchQuery = $q;

require __DIR__ . '/includes/shell_begin.php';
$f = flash_get();
if ($f) :
    ?>
    <div class="container-fluid px-3 px-lg-4"><div class="alert alert-<?php echo e($f['type'] === 'success' ? 'success' : 'danger'); ?>"><?php echo e($f['message']); ?></div></div>
<?php endif; ?>

<div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div class="page-title-block mb-0">
            <h1 class="mb-0">User Management</h1>
            <div class="subtitle">Manage helpdesk users and their access levels</div>
        </div>
        <button type="button" class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalCreateUser"><i class="bi bi-person-plus"></i> Create User</button>
    </div>

    <form class="card-surface p-3 mb-3" method="get" action="users.php">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search</label>
                <input class="form-control form-control-sm" name="q" value="<?php echo e($q); ?>" placeholder="Name, username, email">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Role</label>
                <select class="form-select form-select-sm" name="role_id">
                    <option value="0">All</option>
                    <?php foreach ($roles as $r) : ?>
                        <option value="<?php echo (int) $r['id']; ?>" <?php echo $roleId === (int) $r['id'] ? 'selected' : ''; ?>><?php echo e($r['role_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Department</label>
                <select class="form-select form-select-sm" name="department_id">
                    <option value="0">All</option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo $deptId === (int) $d['id'] ? 'selected' : ''; ?>><?php echo e($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="">All</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="disabled" <?php echo $status === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-accent btn-sm w-100" type="submit">Apply</button>
                <a class="btn btn-outline-muted btn-sm w-100" href="users.php">Reset</a>
            </div>
        </div>
    </form>

    <div class="card-surface p-0 overflow-auto">
        <table class="table table-modern table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) :
                $canEditThis = can_actor_assign_role_key((string) $row['role_key']);
                ?>
                <tr>
                    <td><?php echo e($row['full_name']); ?></td>
                    <td><?php echo e($row['username']); ?></td>
                    <td><?php echo e($row['email']); ?></td>
                    <td><span class="badge text-bg-light border"><?php echo e($row['role_name']); ?></span></td>
                    <td><?php echo e((string) ($row['department_name'] ?? '—')); ?></td>
                    <td><span class="badge <?php echo $row['status'] === 'active' ? 'text-bg-success-subtle text-success' : 'text-bg-secondary'; ?>"><?php echo e($row['status']); ?></span></td>
                    <td>
                        <?php if ($canEditThis) : ?>
                            <a class="btn btn-sm btn-outline-muted" href="edit_user.php?id=<?php echo (int) $row['id']; ?>">Edit</a>
                        <?php else : ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="small text-muted mt-2"><?php echo count($rows); ?> user(s) shown</div>
</div>

<div class="modal fade" id="modalCreateUser" tabindex="-1" aria-labelledby="modalCreateUserLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalCreateUserLabel">Create User</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="create_user.php">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="status" value="active">
                    <p class="small text-muted">After filling user details, enter <strong>your</strong> admin password to authorize creation.</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Name</label>
                            <input class="form-control" name="full_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Username</label>
                            <input class="form-control" name="username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email</label>
                            <input class="form-control" type="email" name="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="0">None</option>
                                <?php foreach ($departments as $d) : ?>
                                    <option value="<?php echo (int) $d['id']; ?>"><?php echo e($d['department_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Role</label>
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
                            <label class="form-label small fw-semibold">Temporary password</label>
                            <input class="form-control" type="password" name="password" required minlength="8">
                        </div>
                        <div class="col-12 border-top pt-3 mt-1">
                            <div class="fw-semibold small mb-2">Authentication Required</div>
                            <p class="small text-muted mb-2">Enter your admin password to create a new user.</p>
                            <label class="form-label small fw-semibold">Your password</label>
                            <input class="form-control" type="password" name="admin_password_confirm" required autocomplete="current-password">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-muted" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-accent">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
