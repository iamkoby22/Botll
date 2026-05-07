<?php
/**
 * Copy to config.local.php and adjust for your environment.
 * config.local.php is gitignored.
 */
if (!defined('DB_HOST')) {
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'botll');
    define('DB_USER', 'root');
    define('DB_PASS', 'Newpassword!');
    define('APP_URL', 'http://127.0.0.1:8010');
    // If the app lives in a subfolder, set the URL path prefix (no trailing slash), e.g. '/botll'
    if (!defined('APP_WEB_BASE')) {
        define('APP_WEB_BASE', '');
    }
    define('OPENAI_API_KEY', '');
    define('OPENAI_MODEL', 'gpt-4o-mini');
    define('DB_CHARSET', 'utf8mb4');
}
