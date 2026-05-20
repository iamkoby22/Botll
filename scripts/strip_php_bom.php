<?php
$root = dirname(__DIR__);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$fixed = 0;
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $b = file_get_contents($path);
    if ($b === false || strlen($b) < 3) {
        continue;
    }
    if (substr($b, 0, 3) === "\xEF\xBB\xBF") {
        file_put_contents($path, substr($b, 3));
        echo "stripped BOM: " . str_replace($root . DIRECTORY_SEPARATOR, '', $path) . "\n";
        $fixed++;
    }
}
echo "Done. Fixed $fixed files.\n";
