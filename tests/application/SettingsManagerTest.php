<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/settings.php';

class SettingsManagerTest extends TestCase
{
    private string $root;
    private SettingsManager $manager;
    private array $catalog = [
        'currency' => ['frankfurter' => [], 'turkish' => [], 'custom' => []],
        'vpn' => ['blackbox' => [], 'ipintel' => [], 'customvpn' => []],
    ];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ywb_settings_' . bin2hex(random_bytes(5));
        mkdir($this->root . '/admin', 0755, true);
        mkdir($this->root . '/db', 0755, true);
        mkdir($this->root . '/tmp', 0755, true);
        mkdir($this->root . '/backups', 0755, true);
        file_put_contents($this->root . '/backups/keep.zip', 'backup');
        foreach (['landings', 'whites', 'whites_curl', 'devices', 'currency', 'proxyvpn', 'runtime'] as $dir) {
            mkdir($this->root . '/caching/' . $dir, 0755, true);
        }
        file_put_contents($this->root . '/db/clicks.db', 'db');
        file_put_contents($this->root . '/db/clicks.db-wal', 'wal');
        file_put_contents($this->root . '/db/clicks.db-shm', 'shm');
        $this->manager = new SettingsManager($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDefaultsWorkWithoutLocalFile(): void
    {
        $settings = $this->manager->load();
        $this->assertSame('admin', $settings['adminPath']);
        $this->assertSame('', $settings['apiKey']);
        $this->assertSame(30, $settings['logRetentionDays']);
        $this->assertSame('Europe/Moscow', $settings['timezone']);
        $this->assertSame('click_time', $settings['conversionAttribution']);
        $this->assertSame(0, $this->manager->revision());
        $this->assertFileDoesNotExist($this->root . '/settings.local.php');
    }

    public function testSaveWritesSilentPhpArrayAndHidesPassword(): void
    {
        $settings = $this->manager->load();
        $settings['adminPassword'] = 'plain-secret';
        $saved = $this->manager->save($settings, 0, $this->catalog);

        $this->assertSame(1, $saved['revision']);
        ob_start();
        $payload = include $this->root . '/settings.local.php';
        $output = ob_get_clean();
        $this->assertSame('', $output);
        $this->assertSame('plain-secret', $payload['adminPassword']);
        $this->assertSame('', $this->manager->adminPayload($saved['settings'])['adminPassword']);
    }

    public function testApiKeyIsValidatedAndReturnedToAdmin(): void
    {
        $settings = $this->manager->load();
        $settings['apiKey'] = 'short';
        try {
            $this->manager->save($settings, 0, $this->catalog);
            $this->fail('Expected validation exception');
        } catch (SettingsValidationException $e) {
            $this->assertArrayHasKey('apiKey', $e->errors);
        }

        $settings['apiKey'] = '0123456789abcdef0123456789abcdef';
        $saved = $this->manager->save($settings, 0, $this->catalog);
        $this->assertSame('0123456789abcdef0123456789abcdef', $saved['settings']['apiKey']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $this->manager->adminPayload($saved['settings'])['apiKey']);
    }

    public function testStaleRevisionIsRejected(): void
    {
        $settings = $this->manager->load();
        $this->manager->save($settings, 0, $this->catalog);
        $this->expectException(SettingsConflictException::class);
        $this->manager->save($settings, 0, $this->catalog);
    }

    public function testPhysicalPathsAreRenamedTogether(): void
    {
        $settings = $this->manager->load();
        $settings['adminPath'] = 'secret-admin';
        $settings['dbConnection'] = 'events.sqlite';
        $settings['backupDir'] = 'restore-points';
        $settings['cachingDir'] = 'runtime-cache';
        $saved = $this->manager->save($settings, 0, $this->catalog);

        $this->assertDirectoryExists($this->root . '/secret-admin');
        $this->assertDirectoryDoesNotExist($this->root . '/admin');
        $this->assertFileExists($this->root . '/db/events.sqlite');
        $this->assertFileExists($this->root . '/db/events.sqlite-wal');
        $this->assertFileExists($this->root . '/db/events.sqlite-shm');
        $this->assertFileExists($this->root . '/restore-points/keep.zip');
        $this->assertDirectoryDoesNotExist($this->root . '/backups');
        $this->assertDirectoryExists($this->root . '/runtime-cache/landings');
        $this->assertDirectoryExists($this->root . '/runtime-cache/runtime');
        $this->assertSame('../secret-admin/', $saved['redirect']);
    }

    public function testCollisionDoesNotApplyPartialRenames(): void
    {
        mkdir($this->root . '/occupied-admin');
        $settings = $this->manager->load();
        $settings['adminPath'] = 'occupied-admin';
        $settings['dbConnection'] = 'renamed.db';

        try {
            $this->manager->save($settings, 0, $this->catalog);
            $this->fail('Expected validation exception');
        } catch (SettingsValidationException $e) {
            $this->assertArrayHasKey('adminPath', $e->errors);
        }
        $this->assertFileExists($this->root . '/db/clicks.db');
        $this->assertFileDoesNotExist($this->root . '/db/renamed.db');
    }

    public function testLogRetentionMustBeWithinSupportedRange(): void
    {
        $settings = $this->manager->load();
        $settings['logRetentionDays'] = 0;
        try {
            $this->manager->save($settings, 0, $this->catalog);
            $this->fail('Expected validation exception');
        } catch (SettingsValidationException $e) {
            $this->assertArrayHasKey('logRetentionDays', $e->errors);
        }
    }

    public function testConversionAttributionAndTimezoneAreValidated(): void
    {
        $settings = $this->manager->load();
        $settings['timezone'] = 'Not/A_Zone';
        $settings['conversionAttribution'] = 'per_table';
        try {
            $this->manager->save($settings, 0, $this->catalog);
            $this->fail('Expected validation exception');
        } catch (SettingsValidationException $e) {
            $this->assertArrayHasKey('timezone', $e->errors);
            $this->assertArrayHasKey('conversionAttribution', $e->errors);
        }
    }

    public function testAdminIpListIsValidatedAndNormalized(): void
    {
        $settings = $this->manager->load();
        $settings['adminIp'] = '198.51.100.10, 203.0.113.15, 198.51.100.10, 2001:db8::10';
        $saved = $this->manager->save($settings, 0, $this->catalog);

        $this->assertSame(
            '198.51.100.10, 203.0.113.15, 2001:db8::10',
            $saved['settings']['adminIp']
        );
    }

    public function testAdminIpListRejectsInvalidEntries(): void
    {
        $settings = $this->manager->load();
        $settings['adminIp'] = '198.51.100.10, not-an-ip';

        try {
            $this->manager->save($settings, 0, $this->catalog);
            $this->fail('Expected validation exception');
        } catch (SettingsValidationException $e) {
            $this->assertSame(
                'Enter valid IP addresses separated by commas',
                $e->errors['adminIp'] ?? null
            );
        }
    }

    public function testBackupDirectoryCannotOverlapSystemStorage(): void
    {
        $settings = $this->manager->load();
        $settings['backupDir'] = 'caching';

        try {
            $this->manager->save($settings, 0, $this->catalog);
            $this->fail('Expected validation exception');
        } catch (SettingsValidationException $e) {
            $this->assertArrayHasKey('backupDir', $e->errors);
        }
        $this->assertDirectoryExists($this->root . '/backups');
    }

    public function testRemovedPluginSettingsArePrunedOnReconcile(): void
    {
        $settings = $this->manager->load();
        $settings['plugins']['currency']['items']['custom'] = ['enabled' => true, 'preferredCurrencies' => ['EUR']];
        $settings['plugins']['vpn']['items']['customvpn'] = ['enabled' => true];
        $this->manager->save($settings, 0, $this->catalog);

        $catalogWithoutCustom = [
            'currency' => ['frankfurter' => [], 'turkish' => []],
            'vpn' => ['blackbox' => [], 'ipintel' => []],
        ];
        $result = $this->manager->reconcilePlugins($catalogWithoutCustom);
        $this->assertTrue($result['changed']);
        $this->assertArrayNotHasKey('custom', $result['settings']['plugins']['currency']['items']);
        $this->assertArrayNotHasKey('customvpn', $result['settings']['plugins']['vpn']['items']);
    }

    public function testRecoveryRestoresFilesystemAndPreviousLocalFile(): void
    {
        $this->manager->initializeLocal($this->manager->load());
        $oldLocal = file_get_contents($this->root . '/settings.local.php');
        rename($this->root . '/admin', $this->root . '/interrupted-admin');
        file_put_contents($this->root . '/settings.local.php', '<?php return ["_revision" => 2, "adminPath" => "interrupted-admin"];');
        file_put_contents($this->root . '/tmp/settings.journal.json', json_encode([
            'committed' => false,
            'oldLocalExists' => true,
            'oldLocal' => base64_encode($oldLocal),
            'applied' => [[
                'type' => 'rename',
                'from' => $this->root . '/admin',
                'to' => $this->root . '/interrupted-admin',
            ]],
        ]));

        $this->manager->recover();

        $this->assertDirectoryExists($this->root . '/admin');
        $this->assertDirectoryDoesNotExist($this->root . '/interrupted-admin');
        $this->assertSame(1, $this->manager->revision());
        $this->assertSame('admin', $this->manager->load()['adminPath']);
        $this->assertFileDoesNotExist($this->root . '/tmp/settings.journal.json');
    }

    private function remove(string $path): void
    {
        if (!file_exists($path)) return;
        if (!is_dir($path)) { @unlink($path); return; }
        foreach (array_diff(scandir($path), ['.', '..']) as $item) {
            $this->remove($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}
