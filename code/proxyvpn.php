<?php

require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/plugins/registry.php';

class ProxyVpnDetector
{
    public const CACHE_TTL = 21600;

    public static function isProxyOrVpn(string $ip, ?array $server = null): bool
    {
        $server ??= $_SERVER;

        $config = self::config();
        $mode = self::mode($config['mode'] ?? 'any');
        $detectorIds = self::detectorIds($config['items'] ?? []);
        if (empty($detectorIds)) {
            add_log('trace', '[proxyvpn] Detection is disabled');
            return false;
        }

        if (self::xffConflictDetected($ip, $server)) {
            add_log('trace', "[proxyvpn:xff] X-Forwarded-For conflicts with request IP $ip");
            return true;
        }

        $cacheKey = self::cacheKey($ip, $mode, $detectorIds);
        $cached = self::readCache($cacheKey);
        if ($cached !== null) {
            add_log('trace', "[proxyvpn] Cache hit for $ip: " . ($cached ? 'proxy' : 'clean'));
            return $cached;
        }

        $registry = PluginRegistry::vpnPlugins();
        $plugins = [];
        foreach ($detectorIds as $detectorId) {
            if (!isset($registry[$detectorId])) {
                add_error_log("[proxyvpn:$detectorId] Unknown proxy/VPN plugin configured");
                continue;
            }
            $plugins[$detectorId] = $registry[$detectorId];
        }

        if (empty($plugins)) {
            add_error_log('[proxyvpn] No valid proxy/VPN detectors configured');
            return true;
        }

        $requests = [];
        foreach ($plugins as $detectorId => $plugin) {
            try {
                $requests[] = $plugin->buildDetectionRequest($ip, $server);
            } catch (Throwable $e) {
                add_error_log("[proxyvpn:$detectorId] Failed to build request: " . $e->getMessage());
            }
        }

        $responses = HttpClient::sendParallel($requests);
        $details = [];
        $errors = [];
        $successful = 0;
        $positive = 0;

        foreach ($plugins as $detectorId => $plugin) {
            if (!isset($responses[$detectorId])) {
                $errors[$detectorId] = 'No response';
                add_error_log("[proxyvpn:$detectorId] No response from plugin");
                continue;
            }

            try {
                $result = $plugin->parseDetectionResponse($responses[$detectorId]);
                if ($result === null) {
                    $errors[$detectorId] = 'Null result';
                    add_error_log("[proxyvpn:$detectorId] Null result");
                    continue;
                }
                $successful++;
                if ($result) {
                    $positive++;
                }
                $details[$detectorId] = $result;
            } catch (Throwable $e) {
                $errors[$detectorId] = $e->getMessage();
                add_error_log("[proxyvpn:$detectorId] " . $e->getMessage());
            }
        }

        if ($successful === 0) {
            add_error_log("[proxyvpn] All detectors failed for $ip; fail-closed");
            self::writeCache($cacheKey, true, $mode, $details, $errors);
            return true;
        }

        $detected = self::decide($mode, $positive, $successful);
        add_log('trace', "[proxyvpn] $ip mode=$mode positive=$positive successful=$successful result=" . ($detected ? 'proxy' : 'clean'));
        self::writeCache($cacheKey, $detected, $mode, $details, $errors);
        return $detected;
    }

    public static function decide(string $mode, int $positive, int $successful): bool
    {
        if ($successful <= 0) {
            return true;
        }
        if ($mode === 'most') {
            return $positive > ($successful / 2);
        }
        return $positive > 0;
    }

    public static function xffConflictDetected(string $ip, array $server): bool
    {
        $header = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($header === '') {
            return false;
        }

        $parts = array_map('trim', explode(',', $header));
        $forwardedIp = (string)($parts[0] ?? '');
        return $forwardedIp !== '' && $forwardedIp !== $ip;
    }

    private static function mode(string $mode): string
    {
        return $mode === 'most' ? 'most' : 'any';
    }

    /** @return array<int, string> */
    private static function detectorIds(mixed $detectors): array
    {
        if (!is_array($detectors)) {
            return [];
        }
        $ids = [];
        foreach ($detectors as $id => $config) {
            if (is_array($config) && !empty($config['enabled'])) {
                $ids[] = trim((string)$id);
            }
        }
        return array_values(array_filter($ids, static fn($id) => $id !== ''));
    }

    private static function config(): array
    {
        global $cloSettings;
        $config = $cloSettings['plugins']['vpn'] ?? [];
        return is_array($config) ? $config : [];
    }

    private static function cacheKey(string $ip, string $mode, array $detectorIds): string
    {
        return sha1($ip . '|' . $mode . '|' . implode(',', $detectorIds));
    }

    private static function cacheDir(): string
    {
        return cache_absolute_path(get_cache_path('proxyvpn'));
    }

    private static function cacheFile(string $cacheKey): string
    {
        return self::cacheDir() . '/' . $cacheKey . '.json';
    }

    private static function readCache(string $cacheKey): ?bool
    {
        $data = read_json_cache(self::cacheFile($cacheKey), self::CACHE_TTL);
        return is_array($data) && array_key_exists('detected', $data) ? (bool)$data['detected'] : null;
    }

    private static function writeCache(string $cacheKey, bool $detected, string $mode, array $details, array $errors): void
    {
        write_json_cache(self::cacheFile($cacheKey), [
            'generatedAt' => time(),
            'ttl' => self::CACHE_TTL,
            'mode' => $mode,
            'detected' => $detected,
            'details' => $details,
            'errors' => $errors,
        ]);
    }
}
