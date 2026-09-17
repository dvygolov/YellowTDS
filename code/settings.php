<?php

final class SettingsValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Settings validation failed');
    }
}

final class SettingsConflictException extends RuntimeException
{
}

final class SettingsManager
{
    public const CACHE_SUBDIRECTORIES = ['landings', 'whites', 'whites_curl', 'devices', 'currency', 'proxyvpn', 'runtime'];
    private const LOCAL_FILE = 'settings.local.php';
    private const LOCK_FILE = 'settings.lock';
    private const JOURNAL_FILE = 'settings.journal.json';

    public function __construct(private readonly string $root = __DIR__)
    {
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'adminPassword' => '12345qweasd',
            'adminDomain' => '',
            'adminIp' => '',
            'adminPath' => 'admin',
            'dbConnection' => 'clicks.db',
            'backupDir' => 'backups',
            'useUTP' => false,
            'debug' => true,
            'timezone' => 'Europe/Moscow',
            'conversionAttribution' => 'click_time',
            'logRetentionDays' => 30,
            'cachingDir' => 'caching',
            'plugins' => [
                'currency' => [
                    'items' => [],
                ],
                'vpn' => [
                    'mode' => 'any',
                    'items' => [],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        $local = $this->readLocalPayload();
        unset($local['_revision']);
        $defaults = self::defaults();
        return self::mergeSettings($defaults, array_intersect_key($local, $defaults));
    }

    public function revision(): int
    {
        $payload = $this->readLocalPayload();
        return max(0, (int)($payload['_revision'] ?? 0));
    }

    /** @param array<string, mixed> $settings */
    public function initializeLocal(array $settings, int $revision = 1): void
    {
        if (is_file($this->localPath())) {
            return;
        }
        $defaults = self::defaults();
        $this->writeLocalPayload(['_revision' => max(1, $revision)] + self::mergeSettings($defaults, array_intersect_key($settings, $defaults)));
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array{currency?: array<string, mixed>, vpn?: array<string, mixed>} $pluginCatalog
     * @return array{settings: array<string, mixed>, revision: int, redirect: ?string}
     */
    public function save(array $submitted, int $expectedRevision, array $pluginCatalog = []): array
    {
        $this->ensureRuntimeDirectory();
        $lockPath = $this->runtimePath(self::LOCK_FILE);
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Failed to lock settings');
        }

        try {
            $this->recoverPendingTransaction();
            $currentRevision = $this->revision();
            if ($expectedRevision !== $currentRevision) {
                throw new SettingsConflictException('Settings were changed in another request');
            }

            $current = $this->load();
            $next = $this->validate($submitted, $current, $pluginCatalog);
            if ($current['dbConnection'] !== $next['dbConnection']) {
                $this->checkpointDatabase($this->rootPath('db/' . $current['dbConnection']));
            }
            $operations = $this->buildFilesystemOperations($current, $next);
            $newRevision = $currentRevision + 1;
            $journal = [
                'committed' => false,
                'oldLocalExists' => is_file($this->localPath()),
                'oldLocal' => is_file($this->localPath()) ? base64_encode((string)file_get_contents($this->localPath())) : '',
                'applied' => [],
            ];
            $this->writeJournal($journal);

            try {
                foreach ($operations as $operation) {
                    $this->applyFilesystemOperation($operation);
                    $journal['applied'][] = $operation;
                    $this->writeJournal($journal);
                }

                $payload = ['_revision' => $newRevision] + $next;
                $this->writeLocalPayload($payload);
                $journal['committed'] = true;
                $this->writeJournal($journal);
                @unlink($this->journalPath());
            } catch (Throwable $e) {
                $this->restoreLocalFromJournal($journal);
                $this->rollbackOperations($journal['applied']);
                @unlink($this->journalPath());
                throw $e;
            }

            $redirect = $current['adminPath'] !== $next['adminPath']
                ? '../' . rawurlencode((string)$next['adminPath']) . '/'
                : null;

            return ['settings' => $next, 'revision' => $newRevision, 'redirect' => $redirect];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Removes settings for plugins that are no longer present. Intended for authenticated admin discovery.
     *
     * @param array{currency?: array<string, mixed>, vpn?: array<string, mixed>} $pluginCatalog
     * @return array{settings: array<string, mixed>, revision: int, changed: bool}
     */
    public function reconcilePlugins(array $pluginCatalog): array
    {
        $settings = $this->load();
        $normalized = $this->normalizePluginSettings($settings['plugins'] ?? [], $pluginCatalog);
        if (($settings['plugins'] ?? []) === $normalized) {
            return ['settings' => $settings, 'revision' => $this->revision(), 'changed' => false];
        }

        $settings['plugins'] = $normalized;
        $saved = $this->save($settings, $this->revision(), $pluginCatalog);
        return ['settings' => $saved['settings'], 'revision' => $saved['revision'], 'changed' => true];
    }

    /** @return array<string, mixed> */
    public function adminPayload(array $settings): array
    {
        $payload = $settings;
        $payload['adminPassword'] = '';
        return $payload;
    }

    public function recover(): void
    {
        $this->ensureRuntimeDirectory();
        $lock = fopen($this->runtimePath(self::LOCK_FILE), 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Failed to lock settings recovery');
        }
        try {
            $this->recoverPendingTransaction();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recoverPendingTransaction(): void
    {
        if (!is_file($this->journalPath())) {
            return;
        }
        $journal = json_decode((string)file_get_contents($this->journalPath()), true);
        if (!is_array($journal)) {
            throw new RuntimeException('Invalid settings transaction journal');
        }
        if (!empty($journal['committed'])) {
            @unlink($this->journalPath());
            return;
        }
        $this->restoreLocalFromJournal($journal);
        $this->rollbackOperations(is_array($journal['applied'] ?? null) ? $journal['applied'] : []);
        @unlink($this->journalPath());
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $current
     * @param array{currency?: array<string, mixed>, vpn?: array<string, mixed>} $pluginCatalog
     * @return array<string, mixed>
     */
    private function validate(array $submitted, array $current, array $pluginCatalog): array
    {
        $allowed = array_keys(self::defaults());
        $next = [];
        foreach ($allowed as $key) {
            $next[$key] = array_key_exists($key, $submitted) ? $submitted[$key] : $current[$key];
        }

        $errors = [];
        foreach (['adminPassword', 'adminDomain', 'adminIp', 'adminPath', 'dbConnection', 'backupDir', 'cachingDir', 'timezone', 'conversionAttribution'] as $field) {
            if (!is_string($next[$field] ?? null)) {
                $errors[$field] = 'Must be a string';
            } else {
                $next[$field] = trim((string)$next[$field]);
            }
        }

        if (($next['adminPassword'] ?? '') === '') {
            $next['adminPassword'] = (string)$current['adminPassword'];
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($next['adminPath'] ?? '')) !== 1) {
            $errors['adminPath'] = 'Use 1-64 letters, numbers, underscores or hyphens';
        }
        if (($next['adminDomain'] ?? '') !== '' && preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)$/', (string)$next['adminDomain']) !== 1) {
            $errors['adminDomain'] = 'Invalid domain';
        }
        if (($next['adminIp'] ?? '') !== '') {
            $adminIps = array_map(
                static fn(string $ip): string => trim($ip),
                explode(',', (string)$next['adminIp'])
            );
            $invalidAdminIps = array_filter(
                $adminIps,
                static fn(string $ip): bool => $ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false
            );
            if ($invalidAdminIps !== []) {
                $errors['adminIp'] = 'Enter valid IP addresses separated by commas';
            } else {
                $next['adminIp'] = implode(', ', array_values(array_unique($adminIps)));
            }
        }
        if (!in_array((string)($next['timezone'] ?? ''), timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Select a valid IANA timezone';
        }
        if (!in_array((string)($next['conversionAttribution'] ?? ''), ['click_time', 'conversion_time'], true)) {
            $errors['conversionAttribution'] = 'Use click_time or conversion_time';
        }
        if (!$this->isSafeName((string)($next['dbConnection'] ?? ''), 128)) {
            $errors['dbConnection'] = 'Use a file name without directories';
        }
        if (!$this->isSafeName((string)($next['backupDir'] ?? ''), 64)) {
            $errors['backupDir'] = 'Use a directory name without slashes';
        }
        $reservedBackupNames = ['admin', 'api', 'bases', 'caching', 'db', 'docs', 'js', 'logs', 'plugins', 'scripts', 'tests', 'tmp', 'ycclogs', 'temp_update'];
        $reservedBackupNames[] = (string)($next['adminPath'] ?? 'admin');
        $reservedBackupNames[] = (string)($next['cachingDir'] ?? 'caching');
        if (in_array(strtolower((string)($next['backupDir'] ?? '')), array_map('strtolower', $reservedBackupNames), true)) {
            $errors['backupDir'] = 'Use a name that does not overlap system, admin or cache directories';
        }

        if (!$this->isSafeName((string)($next['cachingDir'] ?? ''), 64)) {
            $errors['cachingDir'] = 'Use a directory name without slashes';
        }

        foreach (['useUTP', 'debug'] as $field) {
            if (!is_bool($next[$field] ?? null)) {
                $errors[$field] = 'Must be true or false';
            }
        }
        if (!is_array($next['plugins'] ?? null)) {
            $errors['plugins'] = 'Invalid plugin settings';
        } else {
            $next['plugins'] = $this->normalizePluginSettings($next['plugins'], $pluginCatalog);
        }

        if ($errors !== []) {
            throw new SettingsValidationException($errors);
        }
        return $next;
    }

    /**
     * @param mixed $pluginSettings
     * @param array{currency?: array<string, mixed>, vpn?: array<string, mixed>} $pluginCatalog
     * @return array<string, mixed>
     */
    private function normalizePluginSettings(mixed $pluginSettings, array $pluginCatalog): array
    {
        $pluginSettings = is_array($pluginSettings) ? $pluginSettings : [];
        $currencyItems = is_array($pluginSettings['currency']['items'] ?? null) ? $pluginSettings['currency']['items'] : [];
        $vpnItems = is_array($pluginSettings['vpn']['items'] ?? null) ? $pluginSettings['vpn']['items'] : [];
        $normalizedCurrency = [];
        $normalizedVpn = [];

        foreach (array_keys(is_array($pluginCatalog['currency'] ?? null) ? $pluginCatalog['currency'] : []) as $id) {
            $item = is_array($currencyItems[$id] ?? null) ? $currencyItems[$id] : [];
            $currencies = is_array($item['preferredCurrencies'] ?? null) ? $item['preferredCurrencies'] : [];
            $currencies = array_values(array_unique(array_filter(array_map(
                static fn($currency): string => strtoupper(trim((string)$currency)),
                $currencies
            ), static fn(string $currency): bool => preg_match('/^[A-Z]{3}$/', $currency) === 1)));
            $normalizedCurrency[$id] = [
                'enabled' => (bool)($item['enabled'] ?? false),
                'preferredCurrencies' => $currencies,
            ];
        }
        foreach (array_keys(is_array($pluginCatalog['vpn'] ?? null) ? $pluginCatalog['vpn'] : []) as $id) {
            $item = is_array($vpnItems[$id] ?? null) ? $vpnItems[$id] : [];
            $normalizedVpn[$id] = ['enabled' => (bool)($item['enabled'] ?? false)];
        }

        return [
            'currency' => ['items' => $normalizedCurrency],
            'vpn' => [
                'mode' => ($pluginSettings['vpn']['mode'] ?? 'any') === 'most' ? 'most' : 'any',
                'items' => $normalizedVpn,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $next
     * @return array<int, array{type: string, from?: string, to?: string, path?: string}>
     */
    private function buildFilesystemOperations(array $current, array $next): array
    {
        $operations = [];
        $errors = [];

        $oldDb = $this->rootPath('db/' . $current['dbConnection']);
        $newDb = $this->rootPath('db/' . $next['dbConnection']);
        if ($oldDb !== $newDb) {
            if (!is_file($oldDb)) {
                $errors['dbConnection'] = 'Current database file does not exist';
            } elseif (file_exists($newDb)) {
                $errors['dbConnection'] = 'Target database file already exists';
            } else {
                $operations[] = ['type' => 'rename', 'from' => $oldDb, 'to' => $newDb];
                foreach (['-wal', '-shm'] as $suffix) {
                    if (is_file($oldDb . $suffix)) {
                        if (file_exists($newDb . $suffix)) {
                            $errors['dbConnection'] = 'Target SQLite sidecar already exists';
                        } else {
                            $operations[] = ['type' => 'rename', 'from' => $oldDb . $suffix, 'to' => $newDb . $suffix];
                        }
                    }
                }
            }
        }

        $oldCacheRoot = $this->rootPath((string)$current['cachingDir']);
        $newCacheRoot = $this->rootPath((string)$next['cachingDir']);
        if (!is_dir($oldCacheRoot)) {
            if ($oldCacheRoot !== $newCacheRoot && file_exists($newCacheRoot)) {
                $errors['cachingDir'] = 'Target cache root already exists';
            } else {
                $operations[] = ['type' => 'mkdir', 'path' => $newCacheRoot];
                foreach (self::CACHE_SUBDIRECTORIES as $subdirectory) {
                    $operations[] = ['type' => 'mkdir', 'path' => $newCacheRoot . DIRECTORY_SEPARATOR . $subdirectory];
                }
            }
        } else {
            if ($oldCacheRoot !== $newCacheRoot) {
                if (file_exists($newCacheRoot)) {
                    $errors['cachingDir'] = 'Target cache root already exists';
                } else {
                    $operations[] = ['type' => 'rename', 'from' => $oldCacheRoot, 'to' => $newCacheRoot];
                }
            }
        }

        $oldAdmin = $this->rootPath((string)$current['adminPath']);
        $newAdmin = $this->rootPath((string)$next['adminPath']);
        if ($oldAdmin !== $newAdmin) {
            if (!is_dir($oldAdmin)) {
                $errors['adminPath'] = 'Current admin directory does not exist';
            } elseif (file_exists($newAdmin)) {
                $errors['adminPath'] = 'Target admin directory already exists';
            } else {
                $operations[] = ['type' => 'rename', 'from' => $oldAdmin, 'to' => $newAdmin];
            }
        }
        if (!is_int($next['logRetentionDays'] ?? null) && !is_numeric($next['logRetentionDays'] ?? null)) {
            $errors['logRetentionDays'] = 'Must be a whole number';
        } else {
            $next['logRetentionDays'] = (int)$next['logRetentionDays'];
            if ($next['logRetentionDays'] < 1 || $next['logRetentionDays'] > 3650) {
                $errors['logRetentionDays'] = 'Use a value from 1 to 3650 days';
            }
        }

        $oldBackup = $this->rootPath((string)$current['backupDir']);
        $newBackup = $this->rootPath((string)$next['backupDir']);
        if ($oldBackup !== $newBackup) {
            if (file_exists($newBackup)) {
                $errors['backupDir'] = 'Target backup directory already exists';
            } elseif (is_dir($oldBackup)) {
                $operations[] = ['type' => 'rename', 'from' => $oldBackup, 'to' => $newBackup];
            } elseif (file_exists($oldBackup)) {
                $errors['backupDir'] = 'Current backup path is not a directory';
            } else {
                $operations[] = ['type' => 'mkdir', 'path' => $newBackup];
            }
        }

        if ($errors !== []) {
            throw new SettingsValidationException($errors);
        }
        return $operations;
    }

    /** @param array{type: string, from?: string, to?: string, path?: string} $operation */
    private function applyFilesystemOperation(array $operation): void
    {
        if ($operation['type'] === 'rename') {
            if (!@rename((string)$operation['from'], (string)$operation['to'])) {
                throw new RuntimeException('Failed to rename ' . $operation['from'] . ' to ' . $operation['to']);
            }
            return;
        }
        if ($operation['type'] === 'mkdir' && !is_dir((string)$operation['path'])) {
            if (!@mkdir((string)$operation['path'], 0755, true)) {
                throw new RuntimeException('Failed to create ' . $operation['path']);
            }
        }
    }

    /** @param array<int, array<string, string>> $operations */
    private function rollbackOperations(array $operations): void
    {
        foreach (array_reverse($operations) as $operation) {
            if (($operation['type'] ?? '') === 'rename' && file_exists($operation['to'] ?? '') && !file_exists($operation['from'] ?? '')) {
                @rename($operation['to'], $operation['from']);
            } elseif (($operation['type'] ?? '') === 'mkdir' && is_dir($operation['path'] ?? '')) {
                @rmdir($operation['path']);
            }
        }
    }

    /** @param array<string, mixed> $journal */
    private function restoreLocalFromJournal(array $journal): void
    {
        if (!empty($journal['oldLocalExists'])) {
            $content = base64_decode((string)($journal['oldLocal'] ?? ''), true);
            if ($content !== false) {
                $this->writeRawLocal($content);
            }
        } else {
            @unlink($this->localPath());
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeLocalPayload(array $payload): void
    {
        $content = "<?php\n\nreturn " . var_export($payload, true) . ";\n";
        $this->writeRawLocal($content);
        try {
            $loaded = include $this->localPath();
        } catch (Throwable $e) {
            throw new RuntimeException('Generated settings.local.php is invalid', 0, $e);
        }
        if (!is_array($loaded)) {
            throw new RuntimeException('Generated settings.local.php did not return an array');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->localPath(), true);
        }
    }

    private function writeRawLocal(string $content): void
    {
        $temp = tempnam($this->root, '.settings.');
        if ($temp === false || file_put_contents($temp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary settings file');
        }
        @chmod($temp, 0640);
        $oldPath = null;
        if (DIRECTORY_SEPARATOR === '\\' && is_file($this->localPath())) {
            $oldPath = $this->localPath() . '.replacing';
            @unlink($oldPath);
            if (!@rename($this->localPath(), $oldPath)) {
                @unlink($temp);
                throw new RuntimeException('Failed to prepare settings.local.php replacement');
            }
        }
        if (!@rename($temp, $this->localPath())) {
            if ($oldPath !== null && is_file($oldPath)) {
                @rename($oldPath, $this->localPath());
            }
            @unlink($temp);
            throw new RuntimeException('Failed to replace settings.local.php');
        }
        if ($oldPath !== null) {
            @unlink($oldPath);
        }
    }

    /** @return array<string, mixed> */
    private function readLocalPayload(): array
    {
        if (!is_file($this->localPath())) {
            return [];
        }
        $payload = include $this->localPath();
        return is_array($payload) ? $payload : [];
    }

    /** @param array<string, mixed> $journal */
    private function writeJournal(array $journal): void
    {
        $json = json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($this->journalPath(), $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write settings transaction journal');
        }
    }

    private function ensureRuntimeDirectory(): void
    {
        $tmp = $this->rootPath('tmp');
        if (!is_dir($tmp) && !@mkdir($tmp, 0755, true)) {
            throw new RuntimeException('Failed to create tmp directory');
        }
    }

    private function checkpointDatabase(string $path): void
    {
        if (!class_exists('SQLite3') || !is_file($path)) {
            return;
        }
        $header = @file_get_contents($path, false, null, 0, 16);
        if ($header !== "SQLite format 3\0") {
            return;
        }
        $db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
        try {
            $db->busyTimeout(5000);
            if (!$db->exec('PRAGMA wal_checkpoint(TRUNCATE)')) {
                throw new RuntimeException('Failed to checkpoint database before rename');
            }
        } finally {
            $db->close();
        }
    }

    private function isSafeName(string $value, int $maxLength): bool
    {
        return $value !== ''
            && strlen($value) <= $maxLength
            && $value !== '.'
            && $value !== '..'
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }

    /** @param array<string, mixed> $defaults @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function mergeSettings(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($value)) {
                $defaults[$key] = self::mergeSettings($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }

    private function localPath(): string { return $this->rootPath(self::LOCAL_FILE); }
    private function journalPath(): string { return $this->runtimePath(self::JOURNAL_FILE); }
    private function runtimePath(string $name): string { return $this->rootPath('tmp/' . $name); }
    private function rootPath(string $relative): string { return $this->root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative); }
}

$cloSettings = (new SettingsManager(__DIR__))->load();

function get_cache_path(string $subdirectory): string
{
    global $cloSettings;
    if (!in_array($subdirectory, SettingsManager::CACHE_SUBDIRECTORIES, true)) {
        throw new InvalidArgumentException('Unknown cache subdirectory');
    }
    return rtrim((string)$cloSettings['cachingDir'], '/\\') . '/' . $subdirectory;
}

function cache_absolute_path(string $path): string
{
    $isAbsolute = (DIRECTORY_SEPARATOR === '\\')
        ? preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, '\\\\')
        : str_starts_with($path, '/');
    return $isAbsolute ? str_replace('\\', '/', $path) : __DIR__ . '/' . $path;
}

/** @return array<string, mixed>|null null when missing, stale or not a JSON object */
function read_json_cache(string $path, int $ttlSeconds, ?int $now = null): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $now ??= time();
    if (filemtime($path) <= $now - $ttlSeconds) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function write_json_cache(string $path, array $payload): bool
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($path, $json, LOCK_EX) !== false;
}
