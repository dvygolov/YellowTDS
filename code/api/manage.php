<?php

require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../managementapi.php';

function management_api_request_headers(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $detected = getallheaders();
        if (is_array($detected)) {
            $headers = $detected;
        }
    }
    if ($headers === []) {
        foreach ($_SERVER as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, 'HTTP_') || !is_scalar($value)) {
                continue;
            }
            $headers[str_replace('_', '-', substr($name, 5))] = (string)$value;
        }
    }
    return $headers;
}

function management_api_run(): void
{
    global $db, $cloSettings;
    $result = ManagementApi::handleHttp(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        management_api_request_headers(),
        (string)file_get_contents('php://input'),
        is_array($cloSettings) ? $cloSettings : [],
        $db
    );
    http_response_code((int)$result['status']);
    if (!empty($result['plain'])) {
        echo is_string($result['body']) ? $result['body'] : 'Not Found';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    management_api_run();
}
