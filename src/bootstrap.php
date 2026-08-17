<?php

declare(strict_types=1);

const APP_NAME = 'ClipLocal';

/** @return never */
function abort_request(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

/** @param array<string, mixed> $payload */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function media_root(): string
{
    $configured = getenv('MEDIA_ROOT');
    $root = realpath($configured !== false && $configured !== ''
        ? $configured
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'media');

    if ($root === false || !is_dir($root) || !is_readable($root)) {
        throw new RuntimeException('The configured media folder is unavailable or unreadable.');
    }

    return rtrim($root, DIRECTORY_SEPARATOR);
}

function validate_local_request(): void
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostname = explode(':', trim($host, '[]'))[0] ?? '';
    $allowedHosts = ['localhost', '127.0.0.1', '::1'];

    if (!in_array($hostname, $allowedHosts, true)) {
        abort_request(403, 'This application is available only through localhost.');
    }

    $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
        abort_request(403, 'Cross-site requests are not allowed.');
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if (!in_array($originHost, $allowedHosts, true)) {
            abort_request(403, 'The request origin is not allowed.');
        }
    }
}

function disable_output_buffering(): void
{
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    ini_set('output_buffering', '0');
    ini_set('zlib.output_compression', '0');
    set_time_limit(0);
}

function safe_filename(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? 'clip';
    $value = trim($value, '.-_');

    return $value !== '' ? substr($value, 0, 100) : 'clip';
}
