<?php
declare(strict_types=1);

/** AI platform review — metrics collection for Super Admin reports. */

function ai_review_ensure_storage(): void
{
    foreach ([
        APP_BASE_PATH . '/storage/reports',
        APP_BASE_PATH . '/storage/reports/generated',
        APP_BASE_PATH . '/storage/report_templates',
    ] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

function ai_review_template_path(): ?string
{
    ai_review_ensure_storage();
    $stored = APP_BASE_PATH . '/storage/report_templates/SBS Support Requests 2.docx';
    if (is_file($stored)) {
        return $stored;
    }
    $root = APP_BASE_PATH . '/SBS Support Requests 2.docx';
    if (is_file($root)) {
        if (!copy($root, $stored)) {
            return $root;
        }
        return $stored;
    }
    return null;
}

function ai_review_table_exists(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $st = db()->query("SHOW TABLES LIKE 'ai_review_reports'");
        $ok = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function ai_review_ensure_table(): void
{
    if (ai_review_table_exists()) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS ai_review_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            report_type VARCHAR(32) NOT NULL DEFAULT \'custom\',
            filters_json LONGTEXT NULL,
            metrics_json LONGTEXT NULL,
            ai_output LONGTEXT NULL,
            html_output LONGTEXT NULL,
            docx_path VARCHAR(500) NULL DEFAULT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_air_created (created_at),
            INDEX idx_air_user (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function ai_review_parse_filters(array $input = []): array
{
    $period = (string) ($input['period'] ?? $input['review_type'] ?? 'custom');
    $today = new DateTimeImmutable('today');
    $dateTo = trim((string) ($input['date_to'] ?? $today->format('Y-m-d')));
    $dateFrom = trim((string) ($input['date_from'] ?? ''));

    if ($period === 'weekly' || $period === 'weekly_review') {
        $dateFrom = $today->modify('-7 days')->format('Y-m-d');
        $dateTo = $today->format('Y-m-d');
        $period = 'weekly';
    } elseif ($period === 'monthly') {
        $dateFrom = $today->modify('-30 days')->format('Y-m-d');
        $dateTo = $today->format('Y-m-d');
    } elseif ($period === 'uptodate' || $period === 'up_to_date') {
        $dateFrom = ai_review_earliest_ticket_date();
        $dateTo = $today->format('Y-m-d');
        $period = 'uptodate';
    } else {
        $period = 'custom';
        if ($dateFrom === '') {
            $dateFrom = $today->modify('-7 days')->format('Y-m-d');
        }
    }

    return [
        'review_type' => $period,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'account_route' => trim((string) ($input['account_route'] ?? '')),
        'request_type' => trim((string) ($input['request_type'] ?? '')),
        'status' => trim((string) ($input['status'] ?? '')),
        'org_code' => trim((string) ($input['org_code'] ?? '')),
        'include_archived' => !empty($input['include_archived']) && (string) $input['include_archived'] !== '0',
    ];
}

function ai_review_earliest_ticket_date(): string
{
    try {
        $d = db()->query('SELECT DATE(MIN(created_at)) FROM tickets')->fetchColumn();
        if ($d) {
            return (string) $d;
        }
    } catch (Throwable $e) {
    }
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

/**
 * @param array<string,mixed> $filters
 * @return array{where:string,params:list<mixed>}
 */
function ai_review_scope_sql(array $filters): array
{
    $parts = ['1=1'];
    $params = [];

    if (!empty($filters['date_from'])) {
        $parts[] = 'DATE(t.created_at) >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $parts[] = 'DATE(t.created_at) <= ?';
        $params[] = $filters['date_to'];
    }
    $route = (string) ($filters['account_route'] ?? '');
    if ($route !== '' && sbs_column_exists('tickets', 'account_route')) {
        if ($route === 'general') {
            $parts[] = "(t.account_route = 'general' OR t.account_route IS NULL OR t.account_route = '')";
        } else {
            $parts[] = 't.account_route = ?';
            $params[] = $route;
        }
    }
    $rt = (string) ($filters['request_type'] ?? '');
    if ($rt !== '' && analytics_column_exists('tickets', 'request_type')) {
        $parts[] = 't.request_type = ?';
        $params[] = $rt;
    }
    $status = (string) ($filters['status'] ?? '');
    if ($status !== '') {
        $parts[] = 's.status_name = ?';
        $params[] = $status;
    }
    $org = (string) ($filters['org_code'] ?? '');
    if ($org !== '' && analytics_column_exists('departments', 'organization_code')) {
        $parts[] = 'EXISTS (SELECT 1 FROM departments d2 WHERE d2.id = t.department_id AND d2.organization_code = ?)';
        $params[] = $org;
    }
    if (empty($filters['include_archived']) && sbs_column_exists('tickets', 'archived_at')) {
        $parts[] = 't.archived_at IS NULL';
    }

    return ['where' => implode(' AND ', $parts), 'params' => $params];
}

/**
 * @param array<string,mixed> $filters
 */
function ai_review_build_title(array $filters): string
{
    $from = (string) ($filters['date_from'] ?? '');
    $to = (string) ($filters['date_to'] ?? '');
    $range = $from !== '' && $to !== '' ? date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to)) : '';

    if (($filters['review_type'] ?? '') === 'weekly') {
        return 'Weekly SBS Support Requests Review: ' . $range;
    }
    if (($filters['review_type'] ?? '') === 'uptodate') {
        return 'SBS Support Requests Review Up to ' . date('M j, Y', strtotime($to !== '' ? $to : 'today'));
    }
    $route = (string) ($filters['account_route'] ?? '');
    if ($route === 'restricted') {
        return 'Restricted Requests Performance Review: ' . $range;
    }
    if ($route === 'unrestricted') {
        return 'Unrestricted Requests Performance Review: ' . $range;
    }
    if ($route === 'general') {
        return 'General / Not Sure Requests Review: ' . $range;
    }
    $rt = (string) ($filters['request_type'] ?? '');
    if ($rt !== '') {
        return $rt . ' Review: ' . $range;
    }
    $org = (string) ($filters['org_code'] ?? '');
    if ($org !== '') {
        return 'SBS Support Requests Review for Org Code ' . $org . ': ' . $range;
    }
    return 'SBS Platform Review: ' . $range;
}

/** @return list<string> */
function ai_review_request_type_options(): array
{
    return [
        'Purchasing and Financial Support',
        'Non-Travel Reimbursement',
        'Travel & Related Reimbursements',
        'Grant Support',
        'Human Resources Functions',
        'Other Financial Support',
    ];
}

/**
 * @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function ai_review_collect_metrics(array $filters): array
{
    $scope = ai_review_scope_sql($filters);
    $where = $scope['where'];
    $params = $scope['params'];
    $pdo = db();

    $counts = ['total' => 0, 'open' => 0, 'pending' => 0, 'closed' => 0, 'completed' => 0, 'archived' => 0, 'stuck' => 0, 'overdue' => 0, 'sla_risk' => 0];
    $st = $pdo->prepare(
        'SELECT COUNT(*) total,
                SUM(CASE WHEN s.status_name = "Open" THEN 1 ELSE 0 END) open_cnt,
                SUM(CASE WHEN s.status_name = "Pending" THEN 1 ELSE 0 END) pending_cnt,
                SUM(CASE WHEN s.status_name = "Closed" OR t.work_done_at IS NOT NULL THEN 1 ELSE 0 END) closed_cnt,
                SUM(CASE WHEN s.status_name = "Completed" THEN 1 ELSE 0 END) completed_cnt,
                SUM(CASE WHEN t.archived_at IS NOT NULL THEN 1 ELSE 0 END) archived_cnt,
                SUM(CASE WHEN s.status_name = "Stuck" THEN 1 ELSE 0 END) stuck_cnt,
                SUM(CASE WHEN s.status_name = "Overdue" OR COALESCE(t.sla_breach,0)=1 THEN 1 ELSE 0 END) overdue_cnt,
                SUM(CASE WHEN ' . (sbs_column_exists('tickets', 'sla_risk') ? 'COALESCE(t.sla_risk,0)=1' : '0') . ' THEN 1 ELSE 0 END) sla_risk_cnt
         FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where
    );
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $counts = [
        'total' => (int) ($row['total'] ?? 0),
        'open' => (int) ($row['open_cnt'] ?? 0),
        'pending' => (int) ($row['pending_cnt'] ?? 0),
        'closed' => (int) ($row['closed_cnt'] ?? 0),
        'completed' => (int) ($row['completed_cnt'] ?? 0),
        'archived' => (int) ($row['archived_cnt'] ?? 0),
        'stuck' => (int) ($row['stuck_cnt'] ?? 0),
        'overdue' => (int) ($row['overdue_cnt'] ?? 0),
        'sla_risk' => (int) ($row['sla_risk_cnt'] ?? 0),
    ];

    $routes = ['restricted' => 0, 'unrestricted' => 0, 'general' => 0, 'unknown' => 0];
    if (sbs_column_exists('tickets', 'account_route')) {
        $rst = $pdo->prepare(
            'SELECT COALESCE(NULLIF(TRIM(t.account_route),""), "unknown") rk, COUNT(*) c FROM tickets t
             JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where . ' GROUP BY rk'
        );
        $rst->execute($params);
        while ($r = $rst->fetch(PDO::FETCH_ASSOC)) {
            $k = (string) $r['rk'];
            if (isset($routes[$k])) {
                $routes[$k] = (int) $r['c'];
            } else {
                $routes['unknown'] += (int) $r['c'];
            }
        }
    }

    $byRequestType = [];
    if (analytics_column_exists('tickets', 'request_type')) {
        $rtst = $pdo->prepare(
            'SELECT COALESCE(NULLIF(TRIM(t.request_type),""), "Unknown") rt, COUNT(*) c,
                    AVG(CASE WHEN t.work_done_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, t.created_at, t.work_done_at) END) avg_done_h,
                    AVG(CASE WHEN s.status_name = "Completed" THEN TIMESTAMPDIFF(HOUR, t.created_at, COALESCE(t.final_completed_at, t.date_completed)) END) avg_comp_h
             FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where . '
             GROUP BY rt ORDER BY c DESC'
        );
        $rtst->execute($params);
        $byRequestType = $rtst->fetchAll(PDO::FETCH_ASSOC);
    }

    $byStatus = [];
    $sst = $pdo->prepare(
        'SELECT s.status_name, COUNT(*) c FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where . ' GROUP BY s.status_name ORDER BY c DESC'
    );
    $sst->execute($params);
    $byStatus = $sst->fetchAll(PDO::FETCH_ASSOC);

    $byAssignee = [];
    $ast = $pdo->prepare(
        'SELECT COALESCE(u.full_name, "Unassigned") name, COALESCE(r.role_key, "") role_key, COUNT(*) c,
                SUM(CASE WHEN s.status_name IN ("Open","Pending") THEN 1 ELSE 0 END) openish,
                SUM(CASE WHEN s.status_name = "Closed" OR t.work_done_at IS NOT NULL THEN 1 ELSE 0 END) closedish,
                SUM(CASE WHEN s.status_name = "Completed" THEN 1 ELSE 0 END) completedish
         FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id
         LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN roles r ON r.id = u.role_id
         WHERE ' . $where . ' GROUP BY t.assigned_to, u.full_name, r.role_key ORDER BY c DESC LIMIT 25'
    );
    $ast->execute($params);
    $byAssignee = $ast->fetchAll(PDO::FETCH_ASSOC);

    $chainUsers = [];
    if (sbs_table_exists('ticket_assignment_history')) {
        $cst = $pdo->prepare(
            'SELECT u.full_name, r.role_key, COUNT(DISTINCT h.ticket_id) tickets,
                    SUM(CASE WHEN h.assigned_type = "business_admin" THEN 1 ELSE 0 END) ba_assigns,
                    SUM(CASE WHEN h.assigned_type = "coordinator" THEN 1 ELSE 0 END) coord_assigns
             FROM ticket_assignment_history h
             JOIN tickets t ON t.id = h.ticket_id JOIN ticket_statuses s ON s.id = t.status_id
             JOIN users u ON u.id = h.user_id LEFT JOIN roles r ON r.id = u.role_id
             WHERE ' . $where . ' GROUP BY h.user_id, u.full_name, r.role_key ORDER BY tickets DESC LIMIT 30'
        );
        $cst->execute($params);
        $chainUsers = $cst->fetchAll(PDO::FETCH_ASSOC);
    }

    $workDoneBy = [];
    $wdst = $pdo->prepare(
        'SELECT u.full_name, r.role_key, COUNT(*) c FROM tickets t
         JOIN ticket_statuses s ON s.id = t.status_id
         JOIN users u ON u.id = t.work_done_by LEFT JOIN roles r ON r.id = u.role_id
         WHERE ' . $where . ' AND t.work_done_by IS NOT NULL GROUP BY t.work_done_by ORDER BY c DESC'
    );
    $wdst->execute($params);
    $workDoneBy = $wdst->fetchAll(PDO::FETCH_ASSOC);

    $completedBy = [];
    if (sbs_column_exists('tickets', 'final_completed_by')) {
        $cbst = $pdo->prepare(
            'SELECT u.full_name, r.role_key, COUNT(*) c FROM tickets t
             JOIN ticket_statuses s ON s.id = t.status_id
             JOIN users u ON u.id = t.final_completed_by LEFT JOIN roles r ON r.id = u.role_id
             WHERE ' . $where . ' AND t.final_completed_by IS NOT NULL GROUP BY t.final_completed_by ORDER BY c DESC'
        );
        $cbst->execute($params);
        $completedBy = $cbst->fetchAll(PDO::FETCH_ASSOC);
    }

    $bottlenecks = [
        'closed_not_completed' => 0,
        'no_activity_14d' => 0,
        'pending_over_7d' => 0,
    ];
    $inactiveSql = sbs_column_exists('tickets', 'last_activity_at')
        ? 'SUM(CASE WHEN t.last_activity_at IS NOT NULL AND t.last_activity_at < DATE_SUB(NOW(), INTERVAL 14 DAY) AND s.status_name NOT IN ("Completed","Closed") THEN 1 ELSE 0 END)'
        : '0';
    $bn = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN (s.status_name = "Closed" OR t.work_done_at IS NOT NULL) AND s.status_name != "Completed" THEN 1 ELSE 0 END) closed_not_completed,
            ' . $inactiveSql . ' no_activity_14d,
            SUM(CASE WHEN s.status_name = "Pending" AND t.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) pending_over_7d
         FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where
    );
    $bn->execute($params);
    $bottlenecks = array_merge($bottlenecks, $bn->fetch(PDO::FETCH_ASSOC) ?: []);

    $comments = ['total' => 0, 'tickets_with_comments' => 0];
    if (sbs_table_exists('ticket_comments')) {
        $cm = $pdo->prepare(
            'SELECT COUNT(*) FROM ticket_comments c JOIN tickets t ON t.id = c.ticket_id JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where
        );
        $cm->execute($params);
        $comments['total'] = (int) $cm->fetchColumn();
        $cm2 = $pdo->prepare(
            'SELECT COUNT(DISTINCT c.ticket_id) FROM ticket_comments c JOIN tickets t ON t.id = c.ticket_id JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where
        );
        $cm2->execute($params);
        $comments['tickets_with_comments'] = (int) $cm2->fetchColumn();
    }

    $notifySubs = 0;
    if (sbs_table_exists('ticket_notification_subscriptions')) {
        $ns = $pdo->prepare(
            'SELECT COUNT(*) FROM ticket_notification_subscriptions sub
             JOIN tickets t ON t.id = sub.ticket_id JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where
        );
        $ns->execute($params);
        $notifySubs = (int) $ns->fetchColumn();
    }

    $archiveSummary = ['manual' => 0, 'bulk' => 0, 'threshold' => 0];
    if (sbs_column_exists('tickets', 'archive_reason')) {
        $ar = $pdo->prepare(
            'SELECT COALESCE(t.archive_reason, "manual") reason, COUNT(*) c FROM tickets t
             JOIN ticket_statuses s ON s.id = t.status_id WHERE ' . $where . ' AND t.archived_at IS NOT NULL GROUP BY reason'
        );
        $ar->execute($params);
        while ($arow = $ar->fetch(PDO::FETCH_ASSOC)) {
            $rk = (string) $arow['reason'];
            if (isset($archiveSummary[$rk])) {
                $archiveSummary[$rk] = (int) $arow['c'];
            }
        }
    }

    $analyticsScope = ['where' => $where, 'params' => $params];
    $charts = [
        'account_route' => analytics_account_route_counts($analyticsScope),
        'request_type' => analytics_tickets_by_request_type($analyticsScope),
        'assigned_user' => analytics_tickets_by_assigned_user($analyticsScope),
        'performance' => analytics_performance_summary($analyticsScope),
        'trend' => analytics_tickets_received_completed_over_time($analyticsScope),
        'sla' => analytics_sla_summary($analyticsScope),
    ];

    return [
        'filters' => $filters,
        'generated_on' => date('Y-m-d H:i:s'),
        'system_name' => defined('APP_DISPLAY_NAME') ? APP_DISPLAY_NAME : 'SBS Support Requests',
        'counts' => $counts,
        'routes' => $routes,
        'by_request_type' => $byRequestType,
        'by_status' => $byStatus,
        'by_assignee' => $byAssignee,
        'chain_users' => $chainUsers,
        'work_done_by' => $workDoneBy,
        'completed_by' => $completedBy,
        'bottlenecks' => $bottlenecks,
        'comments' => $comments,
        'notify_subscriptions' => $notifySubs,
        'archive_summary' => $archiveSummary,
        'charts' => $charts,
        'performance' => $charts['performance'],
    ];
}

function user_can_access_ai_reviews(?array $user = null): bool
{
    return user_is_super_admin($user);
}

function ai_review_require_super_admin(): void
{
    require_login();
    if (!user_can_access_ai_reviews()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body style="font-family:system-ui;padding:2rem;"><h1>403 Forbidden</h1><p>AI Reviews are available to Super Admin only.</p><p><a href="dashboard.php">Dashboard</a></p></body></html>';
        exit;
    }
}
