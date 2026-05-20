<?php
declare(strict_types=1);

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * @return array{type:string,message:string}|null
 */
function flash_get(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    if (!isset($f['type'], $f['message'])) {
        return null;
    }
    return ['type' => (string) $f['type'], 'message' => (string) $f['message']];
}
