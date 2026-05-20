<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_start();
}
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/password_gate.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sbs_roles.php';
require_once __DIR__ . '/sbs_workflow.php';
require_once __DIR__ . '/sbs_chain.php';
require_once __DIR__ . '/ticket_service.php';
require_once __DIR__ . '/assignment_workflow.php';
require_once __DIR__ . '/ticket_routing.php';
require_once __DIR__ . '/request_logic.php';
require_once __DIR__ . '/super_admin_confirm.php';
require_once __DIR__ . '/mentions.php';
require_once __DIR__ . '/analytics_metrics.php';
require_once __DIR__ . '/ai_review_metrics.php';
require_once __DIR__ . '/flash.php';
