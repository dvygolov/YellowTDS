<?php

require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/plugins/registry.php';

class CurrencyRateManager
{
    public const CACHE_TTL = 21600;

    private static function cacheDir(): string
    {
        return cache_absolute_path(get_cache_path('currency'));
    }

    private static function cacheFile(): string
    {
        return self::cacheDir() . '/rates.json';
    }

    public static function isCacheFresh(): bool
    {
        return read_json_cache(self::cacheFile(), self::CACHE_TTL) !== null;
    }

    public static function refreshIfStale(): bool
    {
        if (self::isCacheFresh()) {
            return true;
        }
        return self::refresh(true);
    }

    public static function refresh(bool $force = false): bool
    {
        if (!$force && self::isCacheFresh()) {
            return true;
        }

        $configuredSources = self::configuredSources();
        $registry = PluginRegistry::currencyPlugins();
        $plugins = [];
        foreach ($configuredSources as $sourceId => $_preferredCurrencies) {
            if (!isset($registry[$sourceId])) {
                add_error_log("[currency:$sourceId] Unknown currency plugin configured");
                continue;
            }
            $plugins[$sourceId] = $registry[$sourceId];
        }

        if (empty($plugins)) {
            add_error_log('[currency] No valid currency plugins configured');
            return false;
        }

        $requests = [];
        foreach ($plugins as $sourceId => $plugin) {
            try {
                $requests[] = $plugin->buildRatesRequest();
            } catch (Throwable $e) {
                add_error_log("[currency:$sourceId] Failed to build request: " . $e->getMessage());
            }
        }

        $responses = HttpClient::sendParallel($requests);
        $sourceRates = [];
        $errors = [];
        foreach ($plugins as $sourceId => $plugin) {
            if (!isset($responses[$sourceId])) {
                $errors[$sourceId] = 'No response';
                add_error_log("[currency:$sourceId] No response from plugin");
                continue;
            }

            try {
                $sourceRates[$sourceId] = $plugin->parseRatesResponse($responses[$sourceId]);
            } catch (Throwable $e) {
                $errors[$sourceId] = $e->getMessage();
                add_error_log("[currency:$sourceId] " . $e->getMessage());
            }
        }

        if (empty($sourceRates)) {
            add_error_log('[currency] Failed to refresh rates: all currency plugins failed');
            return false;
        }

        $rates = self::mergeRates($sourceRates, $configuredSources);
        if (empty($rates)) {
            add_error_log('[currency] Failed to refresh rates: merged rates are empty');
            return false;
        }

        $payload = [
            'generatedAt' => time(),
            'ttl' => self::CACHE_TTL,
            'rates' => $rates,
            'sources' => array_keys($sourceRates),
            'errors' => $errors,
        ];
        return write_json_cache(self::cacheFile(), $payload);
    }

    /**
     * @param array<string, array<string, float>> $sourceRates
     * @param array<string, array<int, string>> $configuredSources
     * @return array<string, float>
     */
    public static function mergeRates(array $sourceRates, array $configuredSources): array
    {
        $rates = ['USD' => 1.0];

        foreach (array_keys($configuredSources) as $sourceId) {
            if (empty($sourceRates[$sourceId])) {
                continue;
            }
            foreach ($sourceRates[$sourceId] as $currency => $rate) {
                $currency = strtoupper((string)$currency);
                $rate = (float)$rate;
                if ($currency === '' || $rate <= 0 || isset($rates[$currency])) {
                    continue;
                }
                $rates[$currency] = $rate;
            }
        }

        foreach ($configuredSources as $sourceId => $preferredCurrencies) {
            if (empty($sourceRates[$sourceId]) || !is_array($preferredCurrencies)) {
                continue;
            }
            foreach ($preferredCurrencies as $currency) {
                $currency = strtoupper((string)$currency);
                $rate = (float)($sourceRates[$sourceId][$currency] ?? 0);
                if ($currency !== '' && $rate > 0) {
                    $rates[$currency] = $rate;
                }
            }
        }

        ksort($rates);
        return $rates;
    }

    /** @return array<string, array<int, string>> */
    public static function configuredSources(): array
    {
        global $cloSettings;
        $items = $cloSettings['plugins']['currency']['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $sources = [];
        foreach ($items as $sourceId => $config) {
            if (!is_array($config) || empty($config['enabled'])) {
                continue;
            }
            $preferred = is_array($config['preferredCurrencies'] ?? null)
                ? $config['preferredCurrencies']
                : [];
            $sources[(string)$sourceId] = array_values($preferred);
        }
        return $sources;
    }

    /** @return array<string, float> */
    public static function getCachedRates(): array
    {
        $file = self::cacheFile();
        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !isset($data['rates']) || !is_array($data['rates'])) {
            add_error_log('[currency] Invalid rates cache format');
            return [];
        }

        $rates = [];
        foreach ($data['rates'] as $currency => $rate) {
            $currency = strtoupper((string)$currency);
            $rate = (float)$rate;
            if ($currency !== '' && $rate > 0) {
                $rates[$currency] = $rate;
            }
        }
        return $rates;
    }
}

class CurrencyConverter
{
    public static function convert(float $amount, string $from): float
    {
        $from = strtoupper(trim($from));
        if ($from === '' || $from === 'USD') {
            return $amount;
        }

        CurrencyRateManager::refreshIfStale();
        $rates = CurrencyRateManager::getCachedRates();
        $rate = $rates[$from] ?? null;
        if ($rate === null || $rate <= 0) {
            add_error_log("[currency] Unknown currency $from; payout left unchanged");
            return $amount;
        }

        return round($amount * (float)$rate, 2);
    }
}
