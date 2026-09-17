<?php

function get_tds_path(bool $withPrefix = true, bool $withSlashEnd = true): string
{
    $domain = get_request_host();
    if ($withPrefix) {
        $prefix = is_https() ? 'https://' : 'http://';
        $fullpath = $prefix . $domain . '/';
    } else {
        $fullpath = $domain . '/';
    }
    return rtrim($fullpath, '/') . get_tds_url_path($withSlashEnd);
}

// Origin-relative browser URL, including the installation subdirectory.
function get_tds_url_path(bool $withSlashEnd = true): string
{
    $fullpath = '/';
    $script_path = array_values(array_filter(explode("/", $_SERVER['SCRIPT_NAME'] ?? '/index.php'), 'strlen'));
    array_pop($script_path);

    if (count($script_path) > 0) {
        // Dirty hack for alternate entrypoint folders.
        if (in_array($script_path[count($script_path) - 1], ['js', 'api', get_admin_path_segment()], true)) {
            array_pop($script_path);
        }
        if (count($script_path) > 0) {
            $fullpath .= implode('/', $script_path);
        }
    }

    if ($withSlashEnd && !str_ends_with($fullpath, '/')) {
        $fullpath .= '/';
    } elseif (!$withSlashEnd && str_ends_with($fullpath, '/')) {
        $fullpath = substr($fullpath, 0, -1);
    }

    return $fullpath;
}

function get_admin_path_segment(): string
{
    global $cloSettings;
    $adminPath = trim((string)($cloSettings['adminPath'] ?? 'admin'), "/ \t\n\r\0\x0B");
    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $adminPath) !== 1) {
        return 'admin';
    }
    return $adminPath;
}

function get_admin_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . get_admin_path_segment();
}

function is_admin_request_path(string $reqPath): bool
{
    $parsedPath = parse_url($reqPath, PHP_URL_PATH);
    $reqPath = trim(is_string($parsedPath) ? $parsedPath : $reqPath, '/');
    $adminPath = get_admin_path_segment();

    return $reqPath === $adminPath || str_starts_with($reqPath, $adminPath . '/');
}

function get_admin_url_path(bool $withSlashEnd = true): string
{
    return rtrim(get_tds_url_path(), '/') . '/' . get_admin_path_segment() . ($withSlashEnd ? '/' : '');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    if (is_local_host($host)) {
        // On localhost/127.0.0.1 we should not trust forwarded headers,
        // otherwise dev setups can accidentally force https redirects.
        return false;
    }

    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    return false;
}

function get_request_host(): string
{
    $host = get_forwarded_host();
    if ($host === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    if ($host === '') {
        $host = 'localhost';
    }

    if (str_contains($host, ':')) {
        return $host;
    }

    $port = get_request_port();
    $https = is_https();
    $isDefaultPort = ($https && $port === 443) || (!$https && $port === 80);
    if ($port > 0 && !$isDefaultPort) {
        return $host . ':' . $port;
    }

    return $host;
}

function get_forwarded_host(): string
{
    $raw = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $parts = explode(',', $raw);
    return trim($parts[0]);
}

function get_request_port(): int
{
    $forwardedPort = trim((string)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? ''));
    if ($forwardedPort !== '' && ctype_digit($forwardedPort)) {
        return (int)$forwardedPort;
    }
    // SERVER_PORT describes the PHP/backend connection, not the public URL.
    // Nonstandard public ports are retained in Host (or explicitly forwarded).
    return is_https() ? 443 : 80;
}

function is_local_host(string $host): bool
{
    $host = trim($host);
    if ($host === '') {
        return false;
    }

    if (str_contains($host, ':')) {
        $host = explode(':', $host, 2)[0];
    }

    $host = strtolower($host);
    return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
}
