<?php

declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function short_text(string $text, int $max = 80): string
{
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, max(1, $max - 1)) . '…';
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_verify(?string $token): bool
{
    return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

/**
 * @param array<string, mixed> $query
 */
function build_query_url(string $path, array $query): string
{
    $q = http_build_query($query);
    return $q === '' ? $path : $path . '?' . $q;
}

/**
 * @return array<int, string>
 */
function tilia_rate_limit_ok(): array
{
    $now = time();
    if (empty($_SESSION['tilia_rl']) || !is_array($_SESSION['tilia_rl'])) {
        $_SESSION['tilia_rl'] = ['count' => 0, 'window' => $now];
    }
    $win = (int) ($_SESSION['tilia_rl']['window'] ?? $now);
    $cnt = (int) ($_SESSION['tilia_rl']['count'] ?? 0);
    if ($now - $win > 60) {
        $_SESSION['tilia_rl'] = ['count' => 1, 'window' => $now];
        return [true, ''];
    }
    if ($cnt >= 40) {
        return [false, 'Too many messages. Please wait a minute and try again.'];
    }
    $_SESSION['tilia_rl']['count'] = $cnt + 1;
    return [true, ''];
}
