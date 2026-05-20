<?php
declare(strict_types=1);

/**
 * SBS analytics — read-only metrics for dashboard/reports.
 * Does not alter workflow; uses tickets.account_route when present.
 */

function analytics_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $st = db()->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $column]);
        $cache[$key] = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function analytics_normalize_user_level(string $level): string
{
    $level = strtolower(trim($level));
    $map = [
        'super admin' => 'super_admin',
        'superadmin' => 'super_admin',
        'restricted pillar admin' => 'restricted_pillar_admin',
        'restricted_pillar' => 'restricted_pillar_admin',
        'unrestricted pillar admin' => 'unrestricted_pillar_admin',
        'unrestricted_pillar' => 'unrestricted_pillar_admin',
        'general pillar admin' => 'general_pillar_admin',
        'pillar admin' => 'general_pillar_admin',
        'business admin' => 'business_admin',
        'coordinator' => 'coordinator',
        'faculty' => 'faculty_staff',
        'faculty staff' => 'faculty_staff',
        'staff' => 'faculty_staff',
        'user' => 'faculty_staff',
    ];
    return $map[$level] ?? $level;
}

/**
 * @param array<string,mixed>|null $user
 */
function analytics_resolve_user_level(?array $user = null): string
{
    return current_user_role_key($user);
}

/**
 * @param array<string,mixed>|null $user
 */
function user_can_view_full_analytics(?array $user = null): bool
{
    return in_array(analytics_resolve_user_level($user), [
        'super_admin',
        'restricted_pillar_admin',
        'unrestricted_pillar_admin',
        'general_pillar_admin',
    ], true);
}

function analytics_route_filter_for_level(string $level): ?string
{
    return match ($level) {
        'restricted_pillar_admin' => 'restricted',
        'unrestricted_pillar_admin' => 'unrestricted',
        'general_pillar_admin' => 'general',
        default => null,
    };
}

function analytics_active_ticket_sql(string $alias = 't'): string
{
    if (analytics_column_exists('tickets', 'archived_at')) {
        return $alias . '.archived_at IS NULL';
    }
    return '1=1';
}

/**
 * @param array<string,mixed> $opts days (int), org_code (string)
 * @return array{where:string,params:list<mixed>,user_level:string,route_filter:?string,days:int,label:string}
 */
function analytics_scope_for_user(?array $user = null, array $opts = []): array
{
    $user = $user ?? current_user();
    $level = analytics_resolve_user_level($user);
    $parts = [tickets_scope_sql('t'), analytics_active_ticket_sql('t')];
    $params = [];

    $route = analytics_route_filter_for_level($level);
    if ($route !== null && analytics_column_exists('tickets', 'account_route')) {
        $parts[] = 't.account_route = ?';
        $params[] = $route;
    }

    $days = array_key_exists('days', $opts)
        ? (int) $opts['days']
        : (int) ($_GET['analytics_days'] ?? 30);
    if ($days > 0) {
        $parts[] = 't.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params[] = $days;
    }

    $org = trim((string) ($opts['org_code'] ?? $_GET['org_code'] ?? ''));
    if ($org !== '' && analytics_column_exists('departments', 'organization_code')) {
        $parts[] = 'EXISTS (SELECT 1 FROM departments d_an WHERE d_an.id = t.department_id AND d_an.organization_code = ?)';
        $params[] = $org;
    }

    $label = match ($level) {
        'super_admin' => 'All account routes',
        'restricted_pillar_admin' => 'Restricted account route',
        'unrestricted_pillar_admin' => 'Unrestricted account route',
        'general_pillar_admin' => 'General / Not Sure route',
        default => 'Your tickets',
    };
    if ($days > 0) {
        $label .= ' · last ' . $days . ' days';
    } else {
        $label .= ' · all time';
    }

    return [
        'where' => implode(' AND ', $parts),
        'params' => $params,
        'user_level' => $level,
        'route_filter' => $route,
        'days' => $days,
        'label' => $label,
    ];
}

/**
 * @param array{where:string,params:list<mixed>} $scope
 */
function analytics_prepare(PDO $pdo, array $scope, string $sql, array $extraParams = []): PDOStatement
{
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($scope['params'], $extraParams));
    return $st;
}

/**
 * @param array{where:string,params:list<mixed>} $scope
 * @return array{labels:list<string>,values:list<int>}
 */
function analytics_account_route_counts(array $scope): array
{
    $labels = ['Restricted', 'Unrestricted', 'General / Not Sure'];
    $keys = ['restricted', 'unrestricted', 'general'];
    $values = [0, 0, 0];

    if (!analytics_column_exists('tickets', 'account_route')) {
        return ['labels' => $labels, 'values' => $values];
    }

    $pdo = db();
    $st = analytics_prepare(
        $pdo,
        $scope,
        'SELECT COALESCE(t.account_route, "general") AS route_key, COUNT(*) c
         FROM tickets t WHERE ' . $scope['where'] . ' GROUP BY route_key'
    );
    $map = [];
    foreach ($st->fetchAll() as $row) {
        $map[(string) $row['route_key']] = (int) $row['c'];
    }
    foreach ($keys as $i => $key) {
        $values[$i] = (int) ($map[$key] ?? 0);
    }
    return ['labels' => $labels, 'values' => $values];
}

/**
 * @param array{where:string,params:list<mixed>} $scope
 * @return array{labels:list<string>,values:list<int>}
 */
function analytics_tickets_by_request_type(array $scope): array
{
    $pdo = db();
    if (!analytics_column_exists('tickets', 'request_type')) {
        return ['labels' => ['Unknown Request Type'], 'values' => [0]];
    }
    $st = analytics_prepare(
        $pdo,
        $scope,
        'SELECT COALESCE(NULLIF(TRIM(t.request_type), ""), "Unknown Request Type") AS rt, COUNT(*) c
         FROM tickets t WHERE ' . $scope['where'] . ' GROUP BY rt ORDER BY c DESC LIMIT 12'
    );
    $labels = [];
    $values = [];
    foreach ($st->fetchAll() as $row) {
        $labels[] = (string) $row['rt'];
        $values[] = (int) $row['c'];
    }
    if (!$labels) {
        return ['labels' => ['Unknown Request Type'], 'values' => [0]];
    }
    return ['labels' => $labels, 'values' => $values];
}

/**
 * @param array{where:string,params:list<mixed>} $scope
 * @return array{labels:list<string>,values:list<int>,open:list<int>,closed:list<int>,completed:list<int>}
 */
function analytics_tickets_by_assigned_user(array $scope): array
{
    $pdo = db();
    $st = analytics_prepare(
        $pdo,
        $scope,
        'SELECT COALESCE(u.full_name, CONCAT("User #", t.assigned_to)) AS name,
                COUNT(*) AS total,
                SUM(CASE WHEN s.status_name IN ("Open","Pending Approval","Assigned") THEN 1 ELSE 0 END) AS open_cnt,
                SUM(CASE WHEN s.status_name = "Closed" OR t.work_done_at IS NOT NULL THEN 1 ELSE 0 END) AS closed_cnt,
                SUM(CASE WHEN s.status_name = "Completed" THEN 1 ELSE 0 END) AS completed_cnt
         FROM tickets t
         JOIN ticket_statuses s ON s.id = t.status_id
         LEFT JOIN users u ON u.id = t.assigned_to
         WHERE ' . $scope['where'] . ' AND t.assigned_to IS NOT NULL
         GROUP BY t.assigned_to, u.full_name
         ORDER BY total DESC
         LIMIT 15'
    );
    $labels = [];
    $values = [];
    $open = [];
    $closed = [];
    $completed = [];
    foreach ($st->fetchAll() as $row) {
        $labels[] = (string) $row['name'];
        $values[] = (int) $row['total'];
        $open[] = (int) $row['open_cnt'];
        $closed[] = (int) $row['closed_cnt'];
        $completed[] = (int) $row['completed_cnt'];
    }
    return [
        'labels' => $labels,
        'values' => $values,
        'open' => $open,
        'closed' => $closed,
        'completed' => $completed,
    ];
}

function analytics_completion_timestamp_sql(string $alias = 't'): string
{
    if (analytics_column_exists('tickets', 'final_completed_at')) {
        return 'COALESCE(' . $alias . '.final_completed_at, ' . $alias . '.date_completed)';
    }
    return $alias . '.date_completed';
}

/**
 * @param array{where:string,params:list<mixed>} $scope
 * @return array<string,mixed>
 */
function analytics_performance_summary(array $scope): array
{
    $pdo = db();
    $where = $scope['where'];
    $params = $scope['params'];

    $st = $pdo->prepare('SELECT COUNT(*) FROM tickets t WHERE ' . $where);
    $st->execute($params);
    $received = (int) $st->fetchColumn();

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where
        . ' AND (t.work_done_at IS NOT NULL OR s.status_name = "Closed")'
    );
    $st->execute($params);
    $done = (int) $st->fetchColumn();

    $compSql = analytics_completion_timestamp_sql('t');
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where
        . ' AND (s.status_name = "Completed" OR ' . $compSql . ' IS NOT NULL)'
    );
    $st->execute($params);
    $completed = (int) $st->fetchColumn();

    $st = $pdo->prepare(
        'SELECT AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.work_done_at)) FROM tickets t WHERE ' . $where . ' AND t.work_done_at IS NOT NULL'
    );
    $st->execute($params);
    $avgDoneHours = (float) ($st->fetchColumn() ?: 0);

    $st = $pdo->prepare(
        'SELECT AVG(TIMESTAMPDIFF(HOUR, t.created_at, ' . $compSql . ')) FROM tickets t WHERE ' . $where . ' AND ' . $compSql . ' IS NOT NULL'
    );
    $st->execute($params);
    $avgCompHours = (float) ($st->fetchColumn() ?: 0);

    $stuck = 0;
    $overdue = 0;
    $st = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN s.status_name = "Stuck" THEN 1 ELSE 0 END) AS stuck_cnt,
            SUM(CASE WHEN s.status_name = "Overdue" OR t.is_late = 1 OR t.sla_breach = 1 THEN 1 ELSE 0 END) AS overdue_cnt
         FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where
    );
    $st->execute($params);
    $slaRow = $st->fetch() ?: [];
    $stuck = (int) ($slaRow['stuck_cnt'] ?? 0);
    $overdue = (int) ($slaRow['overdue_cnt'] ?? 0);

    return [
        'received' => $received,
        'done' => $done,
        'completed' => $completed,
        'avg_days_done' => round($avgDoneHours / 24, 1),
        'avg_days_completion' => round($avgCompHours / 24, 1),
        'stuck' => $stuck,
        'overdue' => $overdue,
    ];
}

function analytics_staff_performance_user_sql(): string
{
    $levels = [
        'super_admin',
        'restricted_pillar_admin',
        'unrestricted_pillar_admin',
        'general_pillar_admin',
        'business_admin',
        'coordinator',
    ];
    $quoted = array_map(static fn ($l) => db()->quote($l), $levels);
    return 'r.role_key IN (' . implode(',', $quoted) . ')';
}

/**
 * Assignment / chain metrics (history + work_done), not only current assigned_to.
 *
 * @param array{where:string,params:list<mixed>} $scope
 * @return list<array<string,mixed>>
 */
function analytics_chain_performance_by_user(array $scope): array
{
    if (!sbs_table_exists('ticket_assignment_history')) {
        return [];
    }
    $pdo = db();
    $staff = analytics_staff_performance_user_sql();
    $st = analytics_prepare(
        $pdo,
        $scope,
        'SELECT u.id, u.full_name, r.role_key,
                COUNT(DISTINCT h.ticket_id) AS tickets_in_chain,
                SUM(CASE WHEN t.work_done_by = u.id THEN 1 ELSE 0 END) AS done_by_user,
                SUM(CASE WHEN t.final_completed_by = u.id OR (s.status_name = "Completed" AND h.assigned_type LIKE "%pillar%") THEN 0 ELSE 0 END) AS completed_placeholder
         FROM ticket_assignment_history h
         JOIN tickets t ON t.id = h.ticket_id
         JOIN ticket_statuses s ON s.id = t.status_id
         JOIN users u ON u.id = h.user_id
         JOIN roles r ON r.id = u.role_id
         WHERE ' . $scope['where'] . ' AND ' . $staff . '
         GROUP BY u.id, u.full_name, r.role_key
         ORDER BY tickets_in_chain DESC
         LIMIT 20'
    );
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = (int) $row['id'];
        $doneSt = $pdo->prepare(
            'SELECT COUNT(*) FROM tickets t WHERE ' . $scope['where'] . ' AND t.work_done_by = ?'
        );
        $doneSt->execute(array_merge($scope['params'], [$uid]));
        $row['done_by_user'] = (int) $doneSt->fetchColumn();
        $compSql = analytics_completion_timestamp_sql('t');
        $compSt = $pdo->prepare(
            'SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id
             WHERE ' . $scope['where'] . ' AND (t.final_completed_by = ? OR (s.status_name = "Completed" AND EXISTS (
               SELECT 1 FROM ticket_history th WHERE th.ticket_id = t.id AND th.changed_by = ? AND th.new_value = "Completed"
             )))'
        );
        $compSt->execute(array_merge($scope['params'], [$uid, $uid]));
        $row['completed_by_user'] = (int) $compSt->fetchColumn();
        $rows[] = $row;
    }
    return $rows;
}

/**
 * @param array{where:string,params:list<mixed>} $scope
 * @return array{labels:list<string>,done:list<int>,avg_hours:list<float>}
 */
function analytics_average_time_to_done_by_user(array $scope): array
{
    $pdo = db();
    $staff = analytics_staff_performance_user_sql();
    $st = analytics_prepare(
        $pdo,
        $scope,
        'SELECT u.full_name AS name,
                COUNT(*) AS done_cnt,
                AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.work_done_at)) AS avg_h
         FROM tickets t
         JOIN users u ON u.id = t.work_done_by
         JOIN roles r ON r.id = u.role_id
         WHERE ' . $scope['where'] . ' AND t.work_done_at IS NOT NULL AND t.work_done_by IS NOT NULL AND ' . $staff . '
         GROUP BY u.id, u.full_name
         HAVING done_cnt > 0
         ORDER BY done_cnt DESC
         LIMIT 12'
    );
    $labels = [];
    $done = [];
    $avgHours = [];
    foreach ($st->fetchAll() as $row) {
        $labels[] = (string) $row['name'];
        $done[] = (int) $row['done_cnt'];
        $avgHours[] = round((float) $row['avg_h'], 1);
    }
    return ['labels' => $labels, 'done' => $done, 'avg_hours' => $avgHours];
}

/**
 * @param array{where:string,params:list<mixed>} $scope
 * @return array{labels:list<string>,received:list<int>,completed:list<int>}
 */
function analytics_tickets_received_completed_over_time(array $scope): array
{
    $pdo = db();
    $compSql = analytics_completion_timestamp_sql('t');
    $st = analytics_prepare(
        $pdo,
        $scope,
        'SELECT DATE_FORMAT(t.created_at, "%Y-%m") AS ym, COUNT(*) AS received
         FROM tickets t WHERE ' . $scope['where'] . '
         GROUP BY ym ORDER BY ym ASC LIMIT 12'
    );
    $recvMap = [];
    foreach ($st->fetchAll() as $row) {
        $recvMap[(string) $row['ym']] = (int) $row['received'];
    }

    $st = analytics_prepare(
        $pdo,
        $scope,
        'SELECT DATE_FORMAT(' . $compSql . ', "%Y-%m") AS ym, COUNT(*) AS completed
         FROM tickets t
         JOIN ticket_statuses s ON s.id = t.status_id
         WHERE ' . $scope['where'] . ' AND (s.status_name = "Completed" OR ' . $compSql . ' IS NOT NULL)
         GROUP BY ym ORDER BY ym ASC LIMIT 12'
    );
    $compMap = [];
    foreach ($st->fetchAll() as $row) {
        if ($row['ym'] !== null && $row['ym'] !== '') {
            $compMap[(string) $row['ym']] = (int) $row['completed'];
        }
    }

    $labels = array_values(array_unique(array_merge(array_keys($recvMap), array_keys($compMap))));
    sort($labels);
    $received = [];
    $completed = [];
    foreach ($labels as $m) {
        $received[] = $recvMap[$m] ?? 0;
        $completed[] = $compMap[$m] ?? 0;
    }
    return ['labels' => $labels, 'received' => $received, 'completed' => $completed];
}

/**
 * @param array{where:string,params:list<mixed>} $scope
 * @return array{labels:list<string>,values:list<int>}
 */
function analytics_sla_summary(array $scope): array
{
    $sum = analytics_performance_summary($scope);
    return [
        'labels' => ['Stuck', 'Overdue / SLA risk', 'Open work not done'],
        'values' => [
            (int) $sum['stuck'],
            (int) $sum['overdue'],
            max(0, (int) $sum['received'] - (int) $sum['done']),
        ],
    ];
}

/**
 * Personal metrics for users without full analytics.
 *
 * @return array<string,int>
 */
function analytics_personal_work_metrics(?array $user = null): array
{
    $user = $user ?? current_user();
    if (!$user) {
        return [];
    }
    $uid = (int) $user['id'];
    $scope = tickets_scope_sql('t');
    $active = analytics_active_ticket_sql('t');
    $pdo = db();

    $base = '(' . $scope . ') AND ' . $active . ' AND (
        t.created_by = ? OR t.assigned_to = ? OR EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.user_id = ?)
    )';
    $params = [$uid, $uid, $uid];

    $counts = [
        'my_open' => 0,
        'my_active_work' => 0,
        'my_closed' => 0,
        'my_completed' => 0,
        'my_stuck' => 0,
        'my_overdue' => 0,
    ];

    $st = $pdo->prepare(
        'SELECT s.status_name, COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id
         WHERE ' . $base . ' GROUP BY s.status_name'
    );
    $st->execute($params);
    foreach ($st->fetchAll() as $row) {
        $name = (string) $row['status_name'];
        $c = (int) $row['c'];
        if ($name === 'Open' || $name === 'Pending Approval') {
            $counts['my_open'] += $c;
        }
        if ($name === 'Stuck') {
            $counts['my_stuck'] += $c;
        }
        if ($name === 'Completed') {
            $counts['my_completed'] += $c;
        }
        if ($name === 'Closed') {
            $counts['my_closed'] += $c;
        }
        if ($name === 'Overdue' || $name === 'Stuck') {
            $counts['my_overdue'] += $c;
        }
    }

    try {
        $st = $pdo->prepare(
            'SELECT COUNT(DISTINCT t.id) c FROM tickets t
             JOIN ticket_assignees ta ON ta.ticket_id = t.id AND ta.user_id = ? AND ta.assignment_status = "active"
             WHERE ' . $base
        );
        $st->execute(array_merge([$uid], $params));
        $counts['my_active_work'] = (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $counts['my_active_work'] = $counts['my_open'];
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM tickets t WHERE ' . $base . ' AND (t.is_late = 1 OR t.sla_breach = 1)');
    $st->execute($params);
    $counts['my_overdue'] = max($counts['my_overdue'], (int) $st->fetchColumn());

    return $counts;
}

/**
 * Bundle for Chart.js on dashboard/reports.
 *
 * @return array<string,mixed>
 */
function analytics_dashboard_payload(?array $user = null, array $opts = []): array
{
    if (!user_can_view_full_analytics($user)) {
        return [
            'can_view' => false,
            'personal' => analytics_personal_work_metrics($user),
        ];
    }
    $scope = analytics_scope_for_user($user, $opts);
    return [
        'can_view' => true,
        'scope_label' => $scope['label'],
        'days' => $scope['days'],
        'account_route' => analytics_account_route_counts($scope),
        'request_type' => analytics_tickets_by_request_type($scope),
        'assigned_user' => analytics_tickets_by_assigned_user($scope),
        'performance' => analytics_performance_summary($scope),
        'done_by_user' => analytics_average_time_to_done_by_user($scope),
        'trend' => analytics_tickets_received_completed_over_time($scope),
        'sla' => analytics_sla_summary($scope),
    ];
}
