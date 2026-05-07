<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_page('my_tickets');

$uid = (int) current_user()['id'];
$pdo = db();

$sql = 'SELECT t.*, s.status_name, p.priority_name, c.category_name, cu.full_name AS created_name
        FROM tickets t
        JOIN ticket_statuses s ON s.id = t.status_id
        JOIN ticket_priorities p ON p.id = t.priority_id
        JOIN ticket_categories c ON c.id = t.category_id
        JOIN users cu ON cu.id = t.created_by
        WHERE t.created_by = ?
        ORDER BY t.created_at DESC
        LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

$pageTitle = 'My Tickets';
$activeNav = 'my_tickets';
$includeCharts = false;
$topbarSearchQuery = '';

require __DIR__ . '/includes/shell_begin.php';
?>

<div class="container-fluid px-3 px-lg-4">
    <div class="page-title-block mb-3">
        <h1>My Tickets</h1>
        <div class="subtitle">Tickets you created</div>
    </div>
    <div class="card-surface p-0 overflow-auto">
        <table class="table table-modern table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Ticket</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr>
                    <td class="fw-semibold"><?php echo e($r['ticket_number']); ?></td>
                    <td><?php echo e(short_text((string) $r['subject'], 80)); ?></td>
                    <td><?php echo e($r['priority_name']); ?></td>
                    <td><?php echo e($r['status_name']); ?></td>
                    <td><?php echo e(date('m/d/Y', strtotime((string) $r['created_at']))); ?></td>
                    <td><a class="btn btn-sm btn-accent" href="ticket_detail.php?id=<?php echo (int) $r['id']; ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows) : ?>
                <tr><td colspan="6" class="text-center text-muted py-5">You have not created any tickets yet. <a href="create_ticket.php">Create one</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/shell_end.php'; ?>
