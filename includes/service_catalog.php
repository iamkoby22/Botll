<?php
declare(strict_types=1);

/**
 * @return list<array<string,mixed>>
 */
function service_catalog_rows(): array
{
    try {
        $rows = db()->query('SELECT * FROM service_catalog ORDER BY group_name ASC, sort_order ASC, id ASC')->fetchAll();
        if ($rows) {
            return $rows;
        }
    } catch (Throwable $e) {
        /* table missing until migration */
    }

    return [
        ['id' => 1, 'group_name' => 'IT Services', 'title' => 'Laptop Request', 'description' => 'Standard laptop refresh or replacement.', 'icon_class' => 'bi-laptop', 'est_duration' => '3–7 business days', 'default_category_id' => 3, 'default_priority_id' => 3, 'default_department_id' => 3],
        ['id' => 2, 'group_name' => 'IT Services', 'title' => 'Software Access', 'description' => 'Request access to licensed applications or SSO groups.', 'icon_class' => 'bi-window-stack', 'est_duration' => '1–3 business days', 'default_category_id' => 3, 'default_priority_id' => 2, 'default_department_id' => 3],
        ['id' => 3, 'group_name' => 'IT Services', 'title' => 'VPN Access', 'description' => 'Remote access and VPN profile provisioning.', 'icon_class' => 'bi-shield-lock', 'est_duration' => '1–2 business days', 'default_category_id' => 3, 'default_priority_id' => 3, 'default_department_id' => 3],
        ['id' => 4, 'group_name' => 'HR Services', 'title' => 'Leave Request', 'description' => 'Paid time off and leave workflow.', 'icon_class' => 'bi-calendar-check', 'est_duration' => '2–4 business days', 'default_category_id' => 2, 'default_priority_id' => 2, 'default_department_id' => 2],
        ['id' => 5, 'group_name' => 'HR Services', 'title' => 'Expense Reimbursement', 'description' => 'Submit receipts for reimbursement.', 'icon_class' => 'bi-receipt', 'est_duration' => '5–10 business days', 'default_category_id' => 1, 'default_priority_id' => 2, 'default_department_id' => 1],
        ['id' => 6, 'group_name' => 'HR Services', 'title' => 'HR Document Request', 'description' => 'Letters, verifications, and policy documents.', 'icon_class' => 'bi-file-earmark-text', 'est_duration' => '2–5 business days', 'default_category_id' => 2, 'default_priority_id' => 2, 'default_department_id' => 2],
        ['id' => 7, 'group_name' => 'HR Services', 'title' => 'VP Office Request', 'description' => 'Executive office routing and approvals.', 'icon_class' => 'bi-building', 'est_duration' => '5–10 business days', 'default_category_id' => 2, 'default_priority_id' => 4, 'default_department_id' => 1],
    ];
}
