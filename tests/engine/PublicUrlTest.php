<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/paths.php';

final class PublicUrlTest extends TestCase
{
    public function testPublicUrlsAndInstallationPaths(): void
    {
        $server = $_SERVER;
        $settings = $GLOBALS['cloSettings'] ?? [];
        try {
            $GLOBALS['cloSettings']['adminPath'] = 'private-panel';
            $cases = [
                [['HTTP_HOST' => 'example.com', 'SERVER_PORT' => 80], 'http://example.com'],
                [['HTTP_HOST' => 'example.com', 'SERVER_PORT' => 443, 'HTTPS' => 'on'], 'https://example.com'],
                [['HTTP_HOST' => 'example.com', 'SERVER_PORT' => 80, 'HTTP_X_FORWARDED_PROTO' => 'https'], 'https://example.com'],
                [['HTTP_HOST' => 'example.com', 'SERVER_PORT' => 8080, 'HTTPS' => 'on'], 'https://example.com'],
                [['HTTP_HOST' => 'example.com:8443', 'SERVER_PORT' => 80, 'HTTPS' => 'on'], 'https://example.com:8443'],
                [['HTTP_HOST' => 'localhost:8080', 'SERVER_PORT' => 8080], 'http://localhost:8080'],
                [['HTTP_HOST' => 'example.com', 'SERVER_PORT' => 80, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '8443'], 'https://example.com:8443'],
            ];
            foreach ($cases as [$vars, $origin]) {
                foreach (['', '/tools/tds'] as $base) {
                    $_SERVER = $vars + ['SCRIPT_NAME' => $base . '/private-panel/login.php'];
                    self::assertSame($origin . $base . '/', get_tds_path());
                    self::assertSame($base . '/private-panel/', get_admin_url_path());
                    self::assertSame($base . '/', get_tds_url_path());
                }
            }
            $_SERVER = ['SCRIPT_NAME' => '/tools/tds/private-panel/index.php', 'HTTP_X_FORWARDED_HOST' => 'untrusted.example'];
            self::assertSame('/tools/tds/private-panel/', get_admin_url_path());
        } finally {
            $_SERVER = $server;
            $GLOBALS['cloSettings'] = $settings;
        }
    }
}
