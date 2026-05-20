<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/ai_review_generator.php';

ai_review_require_super_admin();

$id = (int) ($_GET['id'] ?? 0);
$type = (string) ($_GET['type'] ?? 'docx');
$row = $id > 0 ? ai_review_load_saved($id) : null;
if (!$row) {
    http_response_code(404);
    exit('Report not found.');
}

$path = (string) ($row['docx_path'] ?? '');
if ($type !== 'docx' || $path === '' || !is_file($path)) {
    http_response_code(404);
    exit('Word export is not available for this report.');
}

$realBase = realpath(APP_BASE_PATH . '/storage/reports');
$realFile = realpath($path);
if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
    http_response_code(403);
    exit('Invalid file.');
}

$name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) ($row['title'] ?? 'report')) . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . (string) filesize($realFile));
readfile($realFile);
exit;
