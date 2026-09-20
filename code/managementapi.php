<?php

final class ManagementApi
{
    private const ACTIONS = [
        'click.get',
        'click.update',
        'cost.distribute',
        'campaigns.list',
        'campaigns.get',
        'tokens.list',
        'tokens.values',
        'stats.get',
    ];

    private const CLICK_FIELDS = [
        'id', 'campaign_id', 'clickid', 'userid', 'time', 'ip', 'country', 'lang',
        'os', 'osver', 'client', 'clientver', 'device', 'brand', 'model', 'isp',
        'ua', 'flow', 'path', 'step', 'params', 'cost', 'payout', 'status',
    ];

    private const STAT_COLUMNS = [
        'clicks', 'uniques', 'flow_uniques', 'uniques_ratio', 'cra', 'epc', 'uepc',
        'cpc', 'ucpc', 'conversion', 'purchase', 'hold', 'reject', 'trash', 'cpa',
        'ec', 'revenue', 'costs', 'profit', 'roi',
    ];

    private const GROUP_BY_FIELDS = [
        'date', 'country', 'isp', 'lang', 'os', 'osver', 'device', 'brand', 'model',
        'client', 'clientver', 'flow', 'step', 'landing',
    ];

    private const FILTER_FIELDS = [
        'country', 'lang', 'os', 'osver', 'brand', 'model', 'device', 'isp',
        'client', 'clientver', 'flow', 'step', 'landing', 'path', 'status',
    ];

    private const FILTER_OPERATORS = ['=', '!=', 'in', 'not_in', 'is_null', 'is_not_null'];
    private const DEFAULT_STAT_COLUMNS = ['clicks', 'costs', 'revenue', 'profit', 'conversion'];
    private const MAX_BODY_BYTES = 65536;
    private const MAX_CLICKID_LENGTH = 255;
    private const MAX_COST = 1000000000;

    public function __construct(private readonly Db $db, private readonly array $settings)
    {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|string, plain?: bool}
     */
    public static function handleHttp(string $method, array $headers, string $rawBody, array $settings, Db $db): array
    {
        $api = new self($db, $settings);
        $debug = ($settings['debug'] ?? false) === true;

        if (strtoupper($method) !== 'POST') {
            return $debug
                ? $api->fail(405, 'method_not_allowed', 'Method not allowed.')
                : $api->hidden();
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            $authorized = $api->isAuthorized(self::extractKey($headers, []));
            if (!$authorized) {
                return $debug
                    ? $api->fail(400, 'invalid_json', 'Request body is too large.')
                    : $api->hidden();
            }
            return $api->fail(400, 'invalid_json', 'Request body is too large.');
        }

        $body = [];
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (!is_array($decoded)) {
                $authorized = $api->isAuthorized(self::extractKey($headers, []));
                if (!$authorized) {
                    return $debug
                        ? $api->fail(400, 'invalid_json', 'Invalid JSON.')
                        : $api->hidden();
                }
                return $api->fail(400, 'invalid_json', 'Invalid JSON.');
            }
            $body = $decoded;
        }

        if (!$api->isAuthorized(self::extractKey($headers, $body))) {
            return $debug
                ? $api->fail(401, 'unauthorized', 'Invalid or missing API key.')
                : $api->hidden();
        }

        unset($body['key']);
        return $api->dispatch($body);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>|string, plain?: bool}
     */
    public function dispatch(array $request): array
    {
        $action = trim((string)($request['action'] ?? ''));
        if ($action === '') {
            return $this->fail(400, 'missing_action', 'Missing action.');
        }
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->fail(400, 'unknown_action', 'Unknown action.');
        }

        try {
            return match ($action) {
                'click.get' => $this->clickGet($request),
                'click.update' => $this->clickUpdate($request),
                'cost.distribute' => $this->costDistribute($request),
                'campaigns.list' => $this->campaignsList(),
                'campaigns.get' => $this->campaignsGet($request),
                'tokens.list' => $this->tokensList($request),
                'tokens.values' => $this->tokensValues($request),
                'stats.get' => $this->statsGet($request),
            };
        } catch (InvalidArgumentException $e) {
            return $this->fail(422, 'invalid_request', $e->getMessage());
        } catch (Throwable $e) {
            ytds_log('error', 'manage-api', $e->getMessage(), ['action' => $action]);
            return $this->fail(500, 'internal_error', 'Internal server error.');
        }
    }

    public function isAuthorized(string $providedKey): bool
    {
        $configured = trim((string)($this->settings['apiKey'] ?? ''));
        if ($configured === '' || $providedKey === '') {
            return false;
        }
        return hash_equals($configured, $providedKey);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    public static function extractKey(array $headers, array $body): string
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }
            $header = strtolower($name);
            if ($header === 'x-ytds-key') {
                return trim((string)$value);
            }
            if ($header === 'authorization' && preg_match('/^Bearer\s+(\S+)/i', (string)$value, $matches) === 1) {
                return trim($matches[1]);
            }
        }
        $bodyKey = $body['key'] ?? '';
        return is_scalar($bodyKey) ? trim((string)$bodyKey) : '';
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function clickGet(array $request): array
    {
        $clickid = $this->readClickId($request);
        if ($clickid === '') {
            return $this->fail(400, 'missing_clickid', 'Missing clickid.');
        }
        $click = $this->db->get_click_by_clickid($clickid);
        if ($click === []) {
            return $this->fail(404, 'click_not_found', 'Click not found.');
        }
        return $this->ok($this->publicClick($click));
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function clickUpdate(array $request): array
    {
        $clickid = $this->readClickId($request);
        if ($clickid === '') {
            return $this->fail(400, 'missing_clickid', 'Missing clickid.');
        }
        $hasParams = array_key_exists('params', $request);
        $hasCost = array_key_exists('cost', $request);
        if (!$hasParams && !$hasCost) {
            return $this->fail(400, 'missing_fields', 'Provide params and/or cost.');
        }

        $click = $this->db->get_click_by_clickid($clickid);
        if ($click === []) {
            return $this->fail(404, 'click_not_found', 'Click not found.');
        }

        $params = null;
        if ($hasParams) {
            $params = $this->mergeParams(is_array($click['params'] ?? null) ? $click['params'] : [], $request['params']);
        }
        $cost = null;
        if ($hasCost) {
            $cost = $this->readCost($request['cost']);
        }

        if (!$this->db->update_click_fields((int)$click['id'], $params, $cost)) {
            return $this->fail(500, 'update_failed', 'Failed to update click.');
        }

        $updated = $this->db->get_click_by_clickid($clickid);
        return $this->ok($this->publicClick($updated === [] ? $click : $updated));
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function costDistribute(array $request): array
    {
        $campaignId = $this->readCampaignId($request);
        if ($campaignId === null) {
            return $this->fail(400, 'missing_campaign_id', 'Missing campaign_id.');
        }
        if ($this->db->get_campaign_name($campaignId) === '') {
            return $this->fail(404, 'campaign_not_found', 'Campaign not found.');
        }

        $amount = $this->readCost($request['amount'] ?? null);
        $timezone = $this->campaignTimezone($campaignId);
        [$startTs, $endTs] = $this->readTimeRange($request, $timezone);
        $paramEquals = $this->readParamEquals($request['filters'] ?? null);

        $result = $this->db->distribute_click_cost($campaignId, $startTs, $endTs, $amount, $paramEquals);
        if ($result['updated'] === 0) {
            return $this->fail(404, 'clicks_not_found', 'No clicks matched the cost distribution filter.');
        }
        return $this->ok([
            'campaign_id' => $campaignId,
            'updated' => $result['updated'],
            'amount' => $amount,
            'cost_per_click' => $result['cost_per_click'],
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function campaignsList(): array
    {
        $campaigns = [];
        foreach ($this->db->get_campaigns_list() as $row) {
            $campaigns[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
            ];
        }
        return $this->ok(['campaigns' => $campaigns]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function campaignsGet(array $request): array
    {
        $campaignId = $this->readCampaignId($request);
        if ($campaignId === null) {
            return $this->fail(400, 'missing_campaign_id', 'Missing campaign_id.');
        }
        $name = $this->db->get_campaign_name($campaignId);
        if ($name === '') {
            return $this->fail(404, 'campaign_not_found', 'Campaign not found.');
        }
        $settings = $this->db->get_campaign_settings($campaignId);
        return $this->ok([
            'id' => $campaignId,
            'name' => $name,
            'domains' => $this->normalizeDomains(is_array($settings) ? ($settings['domains'] ?? []) : []),
            'timezone' => $this->campaignTimezone($campaignId),
        ]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function tokensList(array $request): array
    {
        $campaignId = $this->readCampaignId($request);
        if ($campaignId !== null && $this->db->get_campaign_name($campaignId) === '') {
            return $this->fail(404, 'campaign_not_found', 'Campaign not found.');
        }
        $timezone = $campaignId !== null ? $this->campaignTimezone($campaignId) : (string)($this->settings['timezone'] ?? 'UTC');
        [$startTs, $endTs] = $this->readTimeRange($request, $timezone);
        return $this->ok([
            'campaign_id' => $campaignId,
            'from' => $startTs,
            'to' => $endTs,
            'tokens' => $this->db->get_click_param_keys($campaignId, $startTs, $endTs),
        ]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function tokensValues(array $request): array
    {
        $campaignId = $this->readCampaignId($request);
        if ($campaignId === null) {
            return $this->fail(400, 'missing_campaign_id', 'Missing campaign_id.');
        }
        if ($this->db->get_campaign_name($campaignId) === '') {
            return $this->fail(404, 'campaign_not_found', 'Campaign not found.');
        }
        $field = trim((string)($request['field'] ?? ''));
        if (str_starts_with($field, 'param.')) {
            $field = substr($field, 6);
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $field) !== 1) {
            return $this->fail(422, 'invalid_request', 'field must be a token name.');
        }
        $timezone = $this->campaignTimezone($campaignId);
        [$startTs, $endTs] = $this->readTimeRange($request, $timezone);
        return $this->ok([
            'campaign_id' => $campaignId,
            'field' => $field,
            'from' => $startTs,
            'to' => $endTs,
            'values' => $this->db->get_distinct_click_param_values($campaignId, $startTs, $endTs, $field),
        ]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function statsGet(array $request): array
    {
        $campaignId = $this->readCampaignId($request);
        if ($campaignId === null) {
            return $this->fail(400, 'missing_campaign_id', 'Missing campaign_id.');
        }
        if ($this->db->get_campaign_name($campaignId) === '') {
            return $this->fail(404, 'campaign_not_found', 'Campaign not found.');
        }

        $timezone = $this->campaignTimezone($campaignId);
        [$startTs, $endTs] = $this->readTimeRange($request, $timezone);
        $columns = $this->readStatColumns($request['columns'] ?? null);
        $groupBy = $this->readGroupBy($request['groupby'] ?? null);
        $filters = $this->readStatsFilters($request['filters'] ?? null);

        $rows = $this->db->get_statistics(
            $columns,
            $groupBy,
            $campaignId,
            (string)$startTs,
            (string)$endTs,
            $timezone,
            $filters
        );
        return $this->ok([
            'campaign_id' => $campaignId,
            'from' => $startTs,
            'to' => $endTs,
            'columns' => $columns,
            'groupby' => $groupBy,
            'rows' => $rows,
        ]);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function readClickId(array $request): string
    {
        $value = $request['clickid'] ?? $request['subid'] ?? '';
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('clickid must be a string.');
        }
        $clickid = trim((string)$value);
        if (strlen($clickid) > self::MAX_CLICKID_LENGTH) {
            throw new InvalidArgumentException('clickid is too long.');
        }
        return $clickid;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function readCampaignId(array $request): ?int
    {
        if (!array_key_exists('campaign_id', $request)) {
            return null;
        }
        if (!is_numeric($request['campaign_id']) || is_string($request['campaign_id']) && trim($request['campaign_id']) === '') {
            throw new InvalidArgumentException('campaign_id must be a positive integer.');
        }
        $campaignId = (int)$request['campaign_id'];
        if ($campaignId <= 0) {
            throw new InvalidArgumentException('campaign_id must be a positive integer.');
        }
        return $campaignId;
    }

    private function readCost(mixed $value): float
    {
        if (!is_numeric($value) || is_string($value) && trim($value) === '') {
            throw new InvalidArgumentException('Cost must be a number greater than or equal to 0.');
        }
        $cost = (float)$value;
        if (!is_finite($cost) || $cost < 0 || $cost > self::MAX_COST) {
            throw new InvalidArgumentException('Cost must be a number greater than or equal to 0.');
        }
        return $cost;
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, string>
     */
    private function mergeParams(array $existing, mixed $incoming): array
    {
        if (!is_array($incoming) || array_is_list($incoming) && $incoming !== []) {
            throw new InvalidArgumentException('params must be an object.');
        }
        foreach ($incoming as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $key) !== 1) {
                throw new InvalidArgumentException('Invalid params key.');
            }
            if (in_array($key, ['clickid', 'subid', 'cost', 'cpc', 'key', 'action'], true)) {
                throw new InvalidArgumentException('params contains a reserved key.');
            }
            if ($value === null) {
                unset($existing[$key]);
                continue;
            }
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('params values must be scalars.');
            }
            $existing[$key] = (string)$value;
        }
        return $existing;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{0: int, 1: int}
     */
    private function readTimeRange(array $request, string $timezone): array
    {
        if (!array_key_exists('from', $request) || !array_key_exists('to', $request)) {
            throw new InvalidArgumentException('from and to are required.');
        }
        $startTs = $this->parseTimeBoundary($request['from'], $timezone, false);
        $endTs = $this->parseTimeBoundary($request['to'], $timezone, true);
        if ($startTs > $endTs) {
            throw new InvalidArgumentException('from must be earlier than to.');
        }
        return [$startTs, $endTs];
    }

    private function parseTimeBoundary(mixed $value, string $timezone, bool $endOfDay): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{9,12}$/', $value) === 1)) {
            $timestamp = (int)$value;
            if ($timestamp <= 0) {
                throw new InvalidArgumentException('Invalid date.');
            }
            return $timestamp;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Invalid date.');
        }
        $raw = trim($value);
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Exception) {
            $zone = new DateTimeZone('UTC');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00'), $zone);
            if ($date === false) {
                throw new InvalidArgumentException('Invalid date.');
            }
            return $date->getTimestamp();
        }
        try {
            return (new DateTimeImmutable($raw, $zone))->getTimestamp();
        } catch (Exception) {
            throw new InvalidArgumentException('Invalid date.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function readParamEquals(mixed $filters): array
    {
        if ($filters === null || $filters === []) {
            return [];
        }
        if (!is_array($filters) || array_is_list($filters)) {
            throw new InvalidArgumentException('filters must be an object.');
        }
        $params = array_key_exists('params', $filters) ? $filters['params'] : $filters;
        if (!is_array($params) || (array_is_list($params) && $params !== [])) {
            throw new InvalidArgumentException('filters.params must be an object.');
        }
        $equals = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $key) !== 1) {
                throw new InvalidArgumentException('Invalid cost filter parameter name.');
            }
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('Cost filter values must be scalars.');
            }
            $equals[$key] = (string)$value;
        }
        return $equals;
    }

    /**
     * @return list<string>
     */
    private function readStatColumns(mixed $columns): array
    {
        if ($columns === null) {
            return self::DEFAULT_STAT_COLUMNS;
        }
        if (!is_array($columns) || $columns === []) {
            throw new InvalidArgumentException('columns must be a non-empty array.');
        }
        $selected = [];
        foreach ($columns as $column) {
            if (!is_string($column) || !in_array($column, self::STAT_COLUMNS, true)) {
                throw new InvalidArgumentException('Unknown statistics column.');
            }
            if (!in_array($column, $selected, true)) {
                $selected[] = $column;
            }
        }
        return $selected;
    }

    /**
     * @return list<string>
     */
    private function readGroupBy(mixed $groupBy): array
    {
        if ($groupBy === null) {
            return [];
        }
        if (!is_array($groupBy)) {
            throw new InvalidArgumentException('groupby must be an array.');
        }
        $selected = [];
        foreach ($groupBy as $field) {
            if (!is_string($field) || !in_array($field, self::GROUP_BY_FIELDS, true)) {
                throw new InvalidArgumentException('Unknown groupby field.');
            }
            if (!in_array($field, $selected, true)) {
                $selected[] = $field;
            }
        }
        return $selected;
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatsFilters(mixed $filters): array
    {
        if ($filters === null || $filters === []) {
            return [];
        }
        if (!is_array($filters)) {
            throw new InvalidArgumentException('filters must be an object.');
        }
        if (isset($filters['rules']) && is_array($filters['rules'])) {
            foreach ($filters['rules'] as $rule) {
                if (!is_array($rule)) {
                    throw new InvalidArgumentException('Invalid statistics filter.');
                }
                $field = (string)($rule['field'] ?? '');
                $operator = (string)($rule['operator'] ?? '');
                $isParam = str_starts_with($field, 'param.') && preg_match('/^param\.[A-Za-z0-9_]+$/', $field) === 1;
                if ((!$isParam && !in_array($field, self::FILTER_FIELDS, true)) || !in_array($operator, self::FILTER_OPERATORS, true)) {
                    throw new InvalidArgumentException('Invalid statistics filter.');
                }
            }
            $condition = strtoupper((string)($filters['condition'] ?? 'AND'));
            return [
                'condition' => $condition === 'OR' ? 'OR' : 'AND',
                'rules' => array_values($filters['rules']),
            ];
        }

        $rules = [];
        foreach ($filters as $field => $value) {
            if (!is_string($field)) {
                throw new InvalidArgumentException('Invalid statistics filter.');
            }
            if ($field === 'params') {
                if (!is_array($value)) {
                    throw new InvalidArgumentException('filters.params must be an object.');
                }
                foreach ($value as $paramName => $paramValue) {
                    if (!is_string($paramName) || preg_match('/^[A-Za-z0-9_]+$/', $paramName) !== 1 || !is_scalar($paramValue)) {
                        throw new InvalidArgumentException('Invalid statistics filter.');
                    }
                    $rules[] = ['field' => 'param.' . $paramName, 'operator' => '=', 'value' => (string)$paramValue];
                }
                continue;
            }
            if (!in_array($field, self::FILTER_FIELDS, true) || !is_scalar($value)) {
                throw new InvalidArgumentException('Invalid statistics filter.');
            }
            $rules[] = ['field' => $field, 'operator' => '=', 'value' => (string)$value];
        }
        return $rules === [] ? [] : ['condition' => 'AND', 'rules' => $rules];
    }

    private function campaignTimezone(int $campaignId): string
    {
        $settings = $this->db->get_campaign_settings($campaignId);
        $timezone = is_array($settings) ? (string)($settings['statistics']['timezone'] ?? '') : '';
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            return (string)($this->settings['timezone'] ?? 'UTC');
        }
        return $timezone;
    }

    /**
     * @param mixed $domains
     * @return list<string>
     */
    private function normalizeDomains(mixed $domains): array
    {
        if (!is_array($domains)) {
            return [];
        }
        $normalized = [];
        foreach ($domains as $domain) {
            if (is_string($domain) && trim($domain) !== '') {
                $normalized[] = trim($domain);
                continue;
            }
            if (is_array($domain) && is_string($domain['name'] ?? null) && trim($domain['name']) !== '') {
                $normalized[] = trim($domain['name']);
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $click
     * @return array<string, mixed>
     */
    private function publicClick(array $click): array
    {
        $public = [];
        foreach (self::CLICK_FIELDS as $field) {
            if (!array_key_exists($field, $click)) {
                continue;
            }
            $public[$field] = $click[$field];
        }
        $public['id'] = (int)($public['id'] ?? 0);
        $public['campaign_id'] = (int)($public['campaign_id'] ?? 0);
        $public['time'] = (int)($public['time'] ?? 0);
        $public['step'] = (int)($public['step'] ?? 0);
        $public['cost'] = (float)($public['cost'] ?? 0);
        $public['payout'] = (float)($public['payout'] ?? 0);
        if (!is_array($public['params'] ?? null)) {
            $public['params'] = [];
        }
        if (!is_array($public['path'] ?? null)) {
            $public['path'] = [];
        }
        return $public;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ok(array $data, string $code = 'ok'): array
    {
        return ['status' => 200, 'body' => ['ok' => true, 'code' => $code, 'data' => $data]];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function fail(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'code' => $code, 'message' => $message]];
    }

    /**
     * @return array{status: int, body: string, plain: bool}
     */
    private function hidden(): array
    {
        return ['status' => 404, 'body' => 'Not Found', 'plain' => true];
    }
}
