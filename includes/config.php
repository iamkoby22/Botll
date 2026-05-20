<?php
declare(strict_types=1);

/** Demo-safe: log errors, do not print warnings/notices to the browser. */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('BOTLL_DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('BOTLL_DB_NAME') ?: 'botll');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('BOTLL_DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('BOTLL_DB_PASSWORD') !== false ? (string) getenv('BOTLL_DB_PASSWORD') : '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
}
if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
}

if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', dirname(__DIR__));
}

if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', APP_BASE_PATH . '/uploads/tickets');
}

if (!defined('SESSION_NAME')) {
    define('SESSION_NAME', 'botll_sid');
}

if (!defined('APP_DISPLAY_NAME')) {
    define('APP_DISPLAY_NAME', 'SBS Support Requests');
}

/** Web path prefix if the app is not at domain root (e.g. "/botll"). Leading slash, no trailing slash, or empty string. */
if (!defined('APP_WEB_BASE')) {
    $wb = trim((string) (getenv('BOTLL_WEB_BASE') ?: ''), '/');
    define('APP_WEB_BASE', $wb === '' ? '' : '/' . $wb);
}
