<?php

require_once __DIR__ . "/../cookies.php";
require_once __DIR__ . "/../logging.php";
require_once __DIR__ . "/../settings.php";
require_once __DIR__ . "/../paths.php";
require_once __DIR__ . "/../runtimeconfig.php";

class Db
{
    public const STEP_EVENT_CREATED = 'created';
    public const STEP_EVENT_SAVED = 'saved';
    public const STEP_EVENT_NOT_FOUND = 'not_found';
    public const STEP_EVENT_NOT_ALLOWED = 'not_allowed';
    public const STEP_EVENT_STORAGE_ERROR = 'storage_error';
    private const EVENT_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const MAX_EVENT_ELAPSED_MS = 2592000000;
    private const MAX_PERFORMANCE_TIMING_MS = 86400000;
    private const PERFORMANCE_METRICS = ['ttfb', 'fcp', 'lcp', 'inp', 'cls'];
    private const EVENT_JSON_TREE_METRIC_THRESHOLD = 32;
    private const EVENT_JSON_TREE_UNFILTERED_THRESHOLD = 96;

    private const DERIVED_STATS_DEPENDENCIES = [
        'uniques_ratio' => ['clicks', 'uniques'],
        'cra' => ['clicks', 'conversion'],
        'roi' => ['revenue', 'costs'],
        'epc' => ['revenue', 'clicks'],
        'uepc' => ['revenue', 'uniques'],
        'cpc' => ['costs', 'clicks'],
        'ucpc' => ['costs', 'uniques'],
        'ec' => ['revenue', 'conversion'],
        'cpa' => ['costs', 'conversion'],
        'profit' => ['revenue', 'costs'],
    ];

    private $dbPath;
    private ?SQLite3 $readDb = null;
    private ?SQLite3 $writeDb = null;
    private int $lastTransactionErrorCode = 0;
    private string $lastTransactionErrorMessage = '';

    public function __construct()
    {
        global $cloSettings;
        $this->dbPath = __DIR__ . '/' . $cloSettings['dbConnection'];
        if (!file_exists($this->dbPath)) {
            $created = $this->create_new_db();
            if (!$created)
                die("Couldn't create the SQLite database! Read logs for additional info.");
        }
    }

    private static function decode_click_row(array &$click): void
    {
        if (array_key_exists('unique_hash', $click) && is_string($click['unique_hash'])) {
            $click['unique_hash'] = bin2hex($click['unique_hash']);
        }
        if (array_key_exists('path', $click) && is_string($click['path']) && $click['path'] !== '') {
            $decodedPath = json_decode($click['path'], true);
            $click['path'] = is_array($decodedPath) ? $decodedPath : [];
        }

        foreach (['params'] as $jsonField) {
            if (!array_key_exists($jsonField, $click)) {
                continue;
            }
            if (empty($click[$jsonField])) {
                $click[$jsonField] = [];
                continue;
            }
            $decoded = json_decode($click[$jsonField], true);
            $click[$jsonField] = is_array($decoded) ? $decoded : [];
        }
    }

    public static function normalize_stats_columns_config(array $columns): array
    {
        $normalized = [];
        foreach ($columns as $column) {
            if (is_string($column)) {
                $normalized[] = ['field' => $column, 'width' => -1];
                continue;
            }
            if (!is_array($column)) {
                continue;
            }

            $rawField = $column['field'] ?? '';
            if (!is_string($rawField)) {
                continue;
            }
            $field = trim($rawField);
            if ($field === '') {
                continue;
            }

            $normalizedColumn = ['field' => $field, 'width' => (int)($column['width'] ?? -1)];
            if (!empty($column['custom'])) {
                $custom = self::normalize_custom_metric_column($column);
                if ($custom === null) {
                    continue;
                }
                $normalizedColumn = array_merge($normalizedColumn, $custom, ['custom' => true]);
            } elseif (!empty($column['status_metric'])) {
                $statusMetric = self::normalize_status_metric_column($column);
                if ($statusMetric === null) {
                    continue;
                }
                $normalizedColumn = array_merge($normalizedColumn, $statusMetric, ['status_metric' => true]);
            } elseif (isset($column['title']) && is_string($column['title']) && trim($column['title']) !== '') {
                $normalizedColumn['title'] = trim($column['title']);
            }

            $normalized[] = $normalizedColumn;
        }
        return $normalized;
    }

    public static function normalize_custom_metric_column(array $column): ?array
    {
        $field = trim((string)($column['field'] ?? ''));
        $title = trim((string)($column['title'] ?? ''));
        $formula = strtolower(preg_replace('/\s+/', '', (string)($column['formula'] ?? '')));
        $format = trim((string)($column['format'] ?? 'number'));
        $decimals = max(0, min(8, (int)($column['decimals'] ?? 2)));

        if (!preg_match('/^custom\.[a-z0-9_]+$/', $field) || $title === '' || $formula === '') {
            return null;
        }
        if (!in_array($format, ['number', 'percent', 'currency'], true)) {
            $format = 'number';
        }

        $dependencies = self::extract_formula_dependencies($formula);
        if ($dependencies === null || $dependencies === []) {
            return null;
        }

        return [
            'field' => $field,
            'title' => $title,
            'formula' => $formula,
            'format' => $format,
            'decimals' => $decimals,
        ];
    }

    public static function normalize_status_metric_column(array $column): ?array
    {
        $field = strtolower(trim((string)($column['field'] ?? '')));
        $title = trim((string)($column['title'] ?? ''));
        $status = trim((string)($column['status'] ?? ''));
        $calculation = strtolower(trim((string)($column['calculation'] ?? 'current')));
        $occurrence = max(1, (int)($column['occurrence'] ?? 1));
        if (preg_match('/^status\.[a-z0-9_]+$/', $field) !== 1 || $title === '' || $status === '') {
            return null;
        }
        if (!in_array($calculation, ['current', 'count', 'unique', 'nth'], true)) {
            return null;
        }
        return [
            'field' => $field,
            'title' => $title,
            'status' => $status,
            'calculation' => $calculation,
            'occurrence' => $occurrence,
        ];
    }

    public static function extract_formula_dependencies(string $formula): ?array
    {
        $tokens = self::tokenize_formula($formula);
        if ($tokens === null) {
            return null;
        }

        $deps = [];
        foreach ($tokens as $token) {
            if ($token['type'] !== 'field') {
                continue;
            }
            $field = $token['value'];
            if (str_starts_with($field, 'custom.')) {
                return null;
            }
            $deps[$field] = $field;
        }

        return array_values($deps);
    }

    private static function tokenize_formula(string $formula): ?array
    {
        $formula = strtolower(preg_replace('/\s+/', '', $formula));
        if ($formula === '') {
            return null;
        }

        $tokens = [];
        $offset = 0;
        $length = strlen($formula);
        while ($offset < $length) {
            $chunk = substr($formula, $offset);
            if (preg_match('/^([A-Za-z][A-Za-z0-9_\.]*|\d+(?:\.\d+)?|[()+\-*\/])/', $chunk, $matches) !== 1) {
                return null;
            }
            $raw = $matches[1];
            $offset += strlen($raw);

            if (preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
                $tokens[] = ['type' => 'number', 'value' => (float)$raw];
            } elseif (in_array($raw, ['+', '-', '*', '/', '(', ')'], true)) {
                $tokens[] = ['type' => 'operator', 'value' => $raw];
            } else {
                if (!preg_match('/^(?:[a-z][a-z0-9_]*|status\.[a-z0-9_]+|custom\.[a-z0-9_]+)$/', $raw)) {
                    return null;
                }
                $tokens[] = ['type' => 'field', 'value' => $raw];
            }
        }

        if (!self::has_valid_formula_syntax($tokens)) {
            return null;
        }

        return $tokens;
    }

    private static function has_valid_formula_syntax(array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }

        $balance = 0;
        $expectsOperand = true;

        foreach ($tokens as $token) {
            if ($token['type'] === 'number' || $token['type'] === 'field') {
                if (!$expectsOperand) {
                    return false;
                }
                $expectsOperand = false;
                continue;
            }

            $op = $token['value'];
            if ($op === '(') {
                if (!$expectsOperand) {
                    return false;
                }
                $balance++;
                continue;
            }

            if ($op === ')') {
                if ($expectsOperand || $balance === 0) {
                    return false;
                }
                $balance--;
                $expectsOperand = false;
                continue;
            }

            if ($expectsOperand) {
                if ($op === '-') {
                    continue;
                }
                return false;
            }

            $expectsOperand = true;
        }

        return $balance === 0 && !$expectsOperand;
    }

    private static function evaluate_formula(string $formula, array $values): float
    {
        $tokens = self::tokenize_formula($formula);
        if ($tokens === null) {
            return 0.0;
        }

        $output = [];
        $operators = [];
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

        foreach ($tokens as $index => $token) {
            if ($token['type'] === 'number') {
                $output[] = $token;
                continue;
            }
            if ($token['type'] === 'field') {
                $output[] = ['type' => 'number', 'value' => (float)($values[$token['value']] ?? 0.0)];
                continue;
            }

            $op = $token['value'];
            if ($op === '(') {
                $operators[] = $op;
                continue;
            }
            if ($op === ')') {
                while (!empty($operators) && end($operators) !== '(') {
                    $output[] = ['type' => 'operator', 'value' => array_pop($operators)];
                }
                if (empty($operators)) {
                    return 0.0;
                }
                array_pop($operators);
                continue;
            }

            $previous = $tokens[$index - 1]['value'] ?? null;
            if ($op === '-' && ($index === 0 || in_array($previous, ['+', '-', '*', '/', '('], true))) {
                $output[] = ['type' => 'number', 'value' => 0.0];
            }

            while (!empty($operators)) {
                $top = end($operators);
                if ($top === '(' || ($precedence[$top] ?? 0) < ($precedence[$op] ?? 0)) {
                    break;
                }
                $output[] = ['type' => 'operator', 'value' => array_pop($operators)];
            }
            $operators[] = $op;
        }

        while (!empty($operators)) {
            $op = array_pop($operators);
            if ($op === '(' || $op === ')') {
                return 0.0;
            }
            $output[] = ['type' => 'operator', 'value' => $op];
        }

        $stack = [];
        foreach ($output as $token) {
            if ($token['type'] === 'number') {
                $stack[] = (float)$token['value'];
                continue;
            }
            if (count($stack) < 2) {
                return 0.0;
            }
            $b = array_pop($stack);
            $a = array_pop($stack);
            $stack[] = match ($token['value']) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => abs($b) < 1e-12 ? 0.0 : $a / $b,
                default => 0.0,
            };
        }

        if (count($stack) !== 1 || !is_finite($stack[0])) {
            return 0.0;
        }
        return (float)$stack[0];
    }

    private static function split_requested_stats_columns(array $selectedColumns): array
    {
        $normalizedColumns = self::normalize_stats_columns_config($selectedColumns);
        $selectedFields = [];
        $customColumns = [];
        $statusColumns = [];
        foreach ($normalizedColumns as $column) {
            $field = $column['field'];
            $selectedFields[] = $field;
            if (!empty($column['custom'])) {
                $customColumns[] = $column;
            }
            if (!empty($column['status_metric'])) {
                $statusColumns[$field] = $column;
            }
        }

        if ($normalizedColumns === [] && $selectedColumns !== []) {
            foreach ($selectedColumns as $field) {
                if (is_string($field)) {
                    $selectedFields[] = $field;
                }
            }
        }

        return [$selectedFields, $customColumns, $normalizedColumns, $statusColumns];
    }

    /**
     * @return array{kind:string,name:string,aggregation:string,base:string}|null
     */
    private static function parse_event_metric_field(string $field): ?array
    {
        if (preg_match(
            '/^event\.([a-z][a-z0-9_]{0,63})\.(count|avg|p75|min|max)$/',
            $field,
            $matches
        ) === 1) {
            return [
                'kind' => 'event',
                'name' => $matches[1],
                'aggregation' => $matches[2],
                'base' => 'event.' . $matches[1],
            ];
        }
        if (preg_match(
            '/^performance\.(ttfb|fcp|lcp|inp|cls)\.(count|avg|p75|min|max)$/',
            $field,
            $matches
        ) === 1) {
            return [
                'kind' => 'performance',
                'name' => $matches[1],
                'aggregation' => $matches[2],
                'base' => 'performance.' . $matches[1],
            ];
        }
        return null;
    }

    private static function apply_custom_columns_to_tree(array &$tree, array $customColumns): void
    {
        foreach ($tree as &$row) {
            self::apply_custom_columns_to_row($row, $customColumns);
            if (!empty($row['_children']) && is_array($row['_children'])) {
                self::apply_custom_columns_to_tree($row['_children'], $customColumns);
            }
        }
    }

    private static function apply_custom_columns_to_row(array &$row, array $customColumns): void
    {
        foreach ($customColumns as $column) {
            $row[$column['field']] = round(self::evaluate_formula($column['formula'], $row), 8);
        }
    }

    private static function expand_stats_dependencies(array $fields): array
    {
        $expanded = [];
        $queue = array_values($fields);

        while ($queue !== []) {
            $field = array_shift($queue);
            if (!is_string($field) || $field === '' || in_array($field, $expanded, true)) {
                continue;
            }

            $expanded[] = $field;
            foreach (self::DERIVED_STATS_DEPENDENCIES[$field] ?? [] as $dependency) {
                if (!in_array($dependency, $expanded, true)) {
                    $queue[] = $dependency;
                }
            }
        }

        return $expanded;
    }

    private static function filter_tree_fields(array &$tree, array $allowedFields): void
    {
        foreach ($tree as &$row) {
            $children = [];
            if (isset($row['_children']) && is_array($row['_children'])) {
                $children = $row['_children'];
                self::filter_tree_fields($children, $allowedFields);
            }

            $filtered = [];
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $row)) {
                    $filtered[$field] = $row[$field];
                }
            }
            if (array_key_exists('group', $row)) {
                $filtered['group'] = $row['group'];
            }
            if ($children !== []) {
                $filtered['_children'] = $children;
            }
            if (isset($row['_stats_totals']) && is_array($row['_stats_totals'])) {
                $filtered['_stats_totals'] = $row['_stats_totals'];
            }

            $row = $filtered;
        }
    }

    private static function filter_stats_totals_fields(array $totals, array $allowedFields): array
    {
        $filtered = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $totals)) {
                $filtered[$field] = $totals[$field];
            }
        }
        return $filtered;
    }

    private static function attach_stats_totals_to_tree(array &$tree, array $totals): void
    {
        if ($tree !== []) {
            $tree[0]['_stats_totals'] = $totals;
        }
    }

    private static function sort_tree(array &$tree, array $orderby): void
    {
        if (!empty($orderby)) {
            usort($tree, function ($a, $b) use ($orderby) {
                foreach ($orderby as $rule) {
                    $field = $rule['field'] ?? '';
                    $dir = $rule['dir'] ?? 'asc';
                    $va = $a[$field] ?? 0;
                    $vb = $b[$field] ?? 0;
                    $cmp = is_numeric($va) && is_numeric($vb)
                        ? ((float)$va <=> (float)$vb)
                        : strcasecmp((string)$va, (string)$vb);
                    if ($dir === 'desc') {
                        $cmp = -$cmp;
                    }
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }
                return 0;
            });
        }

        foreach ($tree as &$row) {
            if (!empty($row['_children']) && is_array($row['_children'])) {
                self::sort_tree($row['_children'], $orderby);
            }
        }
    }

    private function create_new_db(): bool
    {
        $db = null;
        try {
            // Read SQL schema and initial settings
            $createTableSQL = @file_get_contents(__DIR__ . "/db.sql");
            if ($createTableSQL === false) {
                throw new Exception("Failed to read database schema file");
            }

            $settingsJson = @file_get_contents(__DIR__ . '/common.json');
            if ($settingsJson === false) {
                throw new Exception("Failed to read common settings file");
            }

            // Initialize database
            $db = new SQLite3($this->dbPath, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
            $db->busyTimeout(5000);


            // Create tables
            $result = $db->exec($createTableSQL);
            if ($result === false) {
                throw new Exception($db->lastErrorMsg());
            }

            // Insert initial settings
            $query = "INSERT INTO common (settings) VALUES (:settings)";
            $stmt = $db->prepare($query);

            if ($stmt === false) {
                throw new Exception($db->lastErrorMsg());
            }

            $stmt->bindValue(':settings', $settingsJson, SQLITE3_TEXT);
            $result = $stmt->execute();

            if ($result === false) {
                throw new Exception($db->lastErrorMsg());
            }

            add_log("trace", "Successfully initialized database with schema and common settings");
            return true;
        } catch (Exception $e) {
            if (isset($db)) {
                $db->exec('ROLLBACK');
                add_log("errors", "Failed to initialize database: " . $e->getMessage());
            } else {
                die("Critical error initializing database: " . $e->getMessage());
            }
            return false;
        } finally {
            if (isset($db))
                $db->close();
        }
    }

    private function open_db(bool $readOnly = false): SQLite3
    {
        if ($readOnly && $this->readDb !== null) {
            return $this->readDb;
        }
        if (!$readOnly && $this->writeDb !== null) {
            return $this->writeDb;
        }

        $db = new SQLite3($this->dbPath, $readOnly ? SQLITE3_OPEN_READONLY : SQLITE3_OPEN_READWRITE);
        $db->busyTimeout(5000);

        // Optimizations
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA journal_mode = wal');
        $db->exec('PRAGMA mmap_size = 268435456');    // 256MB memory mapping
        $db->exec('PRAGMA cache_size = -64000');      // 64MB cache pages  
        $db->exec('PRAGMA temp_store = MEMORY');      // temporary data in RAM

        if (!$readOnly) {
            $db->exec('PRAGMA synchronous = OFF');    // only for writing
            $this->writeDb = $db;
        } else {
            $this->readDb = $db;
        }

        return $db;
    }

    public function immediate_transaction(callable $callback): mixed
    {
        $db = $this->open_db();
        $this->lastTransactionErrorCode = 0;
        $this->lastTransactionErrorMessage = '';
        if (!@$db->exec('BEGIN IMMEDIATE')) {
            $this->capture_transaction_error($db);
            throw new RuntimeException('Failed to begin immediate transaction: ' . $db->lastErrorMsg());
        }

        try {
            $result = $callback($db);
            if (!@$db->exec('COMMIT')) {
                $this->capture_transaction_error($db);
                throw new RuntimeException('Failed to commit immediate transaction: ' . $db->lastErrorMsg());
            }
            return $result;
        } catch (Throwable $e) {
            if ($this->lastTransactionErrorCode === 0) {
                $this->capture_transaction_error($db);
            }
            @$db->exec('ROLLBACK');
            throw $e;
        }
    }

    private function capture_transaction_error(SQLite3 $db): void
    {
        $this->lastTransactionErrorCode = $db->lastErrorCode();
        $this->lastTransactionErrorMessage = $db->lastErrorMsg();
    }

    public function last_write_error_code(): int
    {
        return $this->lastTransactionErrorCode !== 0
            ? $this->lastTransactionErrorCode
            : ($this->writeDb?->lastErrorCode() ?? 0);
    }

    public function last_write_error_message(): string
    {
        return $this->lastTransactionErrorMessage !== ''
            ? $this->lastTransactionErrorMessage
            : ($this->writeDb?->lastErrorMsg() ?? '');
    }
    public function __destruct()
    {
        if ($this->readDb !== null) {
            $this->readDb->close();
            $this->readDb = null;
        }
        if ($this->writeDb !== null) {
            $this->writeDb->close();
            $this->writeDb = null;
        }
    }

    public function get_clicks_paginated(string $filter, int $startdate, int $enddate, ?int $campId, int $page, int $size, string $sortField = 'time', string $sortDir = 'desc', array $filters = [], array $paramColumns = [], string $searchTerm = ''): array
    {
        $allowedSort = ['id','time','ip','country','lang','os','osver','client','clientver','device','brand','model','isp','ua','userid','clickid','flow','path','step','status','payout','reason'];
        // Support sorting by param.* fields via json_extract
        $sortExpr = 'time';
        if (in_array($sortField, $allowedSort)) {
            $sortExpr = $sortField;
        } elseif (str_starts_with($sortField, 'param.')) {
            $key = substr($sortField, 6);
            if (preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                $sortExpr = "json_extract(params, '\$.$key')";
            }
        }
        $sortDir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';
        $offset = ($page - 1) * $size;

        switch ($filter) {
            case 'blocked':
                $table = 'blocked';
                $where = "time BETWEEN ? AND ? AND campaign_id = ?";
                $bindParams = [$startdate => SQLITE3_INTEGER, $enddate => SQLITE3_INTEGER, $campId => SQLITE3_INTEGER];
                break;
            case 'leads':
                $table = 'clicks';
                $where = "time BETWEEN ? AND ? AND campaign_id = ? AND status IS NOT NULL";
                $bindParams = [$startdate => SQLITE3_INTEGER, $enddate => SQLITE3_INTEGER, $campId => SQLITE3_INTEGER];
                break;
            case 'trafficback':
                $table = 'trafficback';
                $where = "time BETWEEN ? AND ?";
                $bindParams = [$startdate => SQLITE3_INTEGER, $enddate => SQLITE3_INTEGER];
                break;
            default: // allowed
                $table = 'clicks';
                $where = "time BETWEEN ? AND ? AND campaign_id = ?";
                $bindParams = [$startdate => SQLITE3_INTEGER, $enddate => SQLITE3_INTEGER, $campId => SQLITE3_INTEGER];
                break;
        }
        $tableFilterFields = match ($table) {
            'blocked' => ['country', 'lang', 'os', 'osver', 'brand', 'model', 'device', 'isp', 'client', 'clientver', 'reason'],
            'trafficback' => ['country', 'lang', 'os', 'osver', 'brand', 'model', 'device', 'isp', 'client', 'clientver'],
            default => ['country', 'lang', 'os', 'osver', 'brand', 'model', 'device', 'isp', 'client', 'clientver', 'flow', 'step', 'path', 'status'],
        };

        // Build filter WHERE clauses (positional ? placeholders)
        $filterWhere = '';
        // Ordered list of [value, type] for all bind params
        $bindList = [];
        foreach ($bindParams as $val => $type) $bindList[] = [$val, $type];

        if (!empty($filters) && !empty($filters['rules']) && is_array($filters['rules'])) {
            $filterParts = [];
            foreach ($filters['rules'] as $rule) {
                $field = $rule['field'] ?? '';
                $op = $rule['operator'] ?? '';
                $value = $rule['value'] ?? '';
                if (!str_starts_with($field, 'param.') && !in_array($field, $tableFilterFields, true)) {
                    continue;
                }

                $sqlField = self::resolveFilterField($field);
                if ($sqlField === null || !in_array($op, self::FILTER_OPERATORS)) {
                    continue;
                }

                switch ($op) {
                    case '=':
                        $filterParts[] = "$sqlField = ?";
                        $bindList[] = [$value, SQLITE3_TEXT];
                        break;
                    case '!=':
                        $filterParts[] = "$sqlField != ?";
                        $bindList[] = [$value, SQLITE3_TEXT];
                        break;
                    case 'in':
                        $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                        $filterParts[] = "$sqlField IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                        foreach ($vals as $v) $bindList[] = [$v, SQLITE3_TEXT];
                        break;
                    case 'not_in':
                        $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                        $filterParts[] = "$sqlField NOT IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                        foreach ($vals as $v) $bindList[] = [$v, SQLITE3_TEXT];
                        break;
                    case 'is_null':
                        $filterParts[] = "($sqlField IS NULL OR $sqlField = '')";
                        break;
                    case 'is_not_null':
                        $filterParts[] = "($sqlField IS NOT NULL AND $sqlField != '')";
                        break;
                }
            }
            $condition = ($filters['condition'] ?? 'AND') === 'OR' ? ' OR ' : ' AND ';
            if (!empty($filterParts)) {
                $filterWhere = ' AND (' . implode($condition, $filterParts) . ')';
            }
        }

        $searchTerm = trim($searchTerm);
        $searchWhere = '';
        if ($searchTerm !== '' && in_array($filter, ['allowed', 'leads'], true)) {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm);
            $likePattern = '%' . $escapedSearch . '%';
            $searchWhere = " AND (userid LIKE ? ESCAPE '\\' OR clickid LIKE ? ESCAPE '\\')";
            $bindList[] = [$likePattern, SQLITE3_TEXT];
            $bindList[] = [$likePattern, SQLITE3_TEXT];
        }

        $countQuery = "SELECT COUNT(*) as total FROM $table WHERE $where$filterWhere$searchWhere";
        $countResult = $this->exec_read_query($countQuery, $bindList, true);
        $total = (int)($countResult['total'] ?? 0);

        $dataQuery = "SELECT * FROM $table WHERE $where$filterWhere$searchWhere ORDER BY $sortExpr COLLATE NOCASE $sortDir LIMIT $size OFFSET $offset";
        $clicks = $this->exec_read_query($dataQuery, $bindList);
        foreach ($clicks as &$click) {
            self::decode_click_row($click);
            // Extract requested param columns
            foreach ($paramColumns as $key) {
                $click["param.$key"] = $click['params'][$key] ?? null;
            }
        }

        return [
            'last_page' => max(1, (int)ceil($total / $size)),
            'data' => $clicks,
        ];
    }

    public function get_click_by_clickid(string $clickid): array
    {
        if (empty($clickid)) {
            add_log("trace", "Skipping click retrieval - empty clickid provided");
            return [];
        }

        $query = "SELECT * FROM clicks WHERE clickid = :clickid ORDER BY time DESC LIMIT 1";
        $clicks = $this->exec_read_query($query, [[$clickid, SQLITE3_TEXT]]);
        foreach ($clicks as &$click) {
            self::decode_click_row($click);
        }
        return $clicks[0] ?? [];
    }

    private function get_stats_source_select_parts(array $selectedFields, array $statusColumns): array
    {
        $selectParts = [];
        $builtInStatuses = [
            'purchase' => 'Purchase',
            'hold' => 'Lead',
            'reject' => 'Reject',
            'trash' => 'Trash',
        ];
        foreach ($selectedFields as $field) {
            if (isset($statusColumns[$field])) {
                $column = $statusColumns[$field];
                $status = str_replace("'", "''", (string)$column['status']);
                $alias = str_replace('"', '""', (string)$field);
                $calculation = $column['calculation'];
                $expression = match ($calculation) {
                    'current' => "SUM(CASE WHEN current_status_event = 1 AND metric_status = '{$status}' COLLATE NOCASE THEN 1 ELSE 0 END)",
                    'count' => "SUM(CASE WHEN is_conversion = 1 AND metric_status = '{$status}' COLLATE NOCASE THEN 1 ELSE 0 END)",
                    'unique' => "COUNT(DISTINCT CASE WHEN is_conversion = 1 AND metric_status = '{$status}' COLLATE NOCASE THEN clickid END)",
                    'nth' => "COUNT(DISTINCT CASE WHEN is_conversion = 1 AND metric_status = '{$status}' COLLATE NOCASE AND status_occurrence = " . (int)$column['occurrence'] . " THEN clickid END)",
                    default => '0',
                };
                $selectParts[] = $expression . ' AS "' . $alias . '"';
                continue;
            }
            if (isset($builtInStatuses[$field])) {
                $status = $builtInStatuses[$field];
                $selectParts[] = "SUM(CASE WHEN current_status_event = 1 AND metric_status = '{$status}' COLLATE NOCASE THEN 1 ELSE 0 END) AS {$field}";
                continue;
            }
            switch ($field) {
                case 'clicks':
                    $selectParts[] = 'SUM(is_click) AS clicks';
                    break;
                case 'uniques':
                    $selectParts[] = "CASE WHEN SUM(is_click) = SUM(CASE WHEN is_click = 1 AND unique_flags IS NOT NULL THEN 1 ELSE 0 END) THEN COALESCE(SUM(CASE WHEN is_click = 1 AND (unique_flags & 2) != 0 THEN 1 ELSE 0 END), 0) ELSE NULL END AS uniques";
                    break;
                case 'flow_uniques':
                    $selectParts[] = "CASE WHEN SUM(is_click) = SUM(CASE WHEN is_click = 1 AND unique_flags IS NOT NULL THEN 1 ELSE 0 END) THEN COALESCE(SUM(CASE WHEN is_click = 1 AND (unique_flags & 1) != 0 THEN 1 ELSE 0 END), 0) ELSE NULL END AS flow_uniques";
                    break;
                case 'uniques_ratio':
                    $selectParts[] = "CASE WHEN SUM(is_click) = SUM(CASE WHEN is_click = 1 AND unique_flags IS NOT NULL THEN 1 ELSE 0 END) THEN COALESCE(SUM(CASE WHEN is_click = 1 AND (unique_flags & 2) != 0 THEN 1 ELSE 0 END) * 100.0 / NULLIF(SUM(is_click), 0), 0) ELSE NULL END AS uniques_ratio";
                    break;
                case '_uniqueness_counted':
                    $selectParts[] = 'SUM(CASE WHEN is_click = 1 AND unique_flags IS NOT NULL THEN 1 ELSE 0 END) AS _uniqueness_counted';
                    break;
                case '_uniqueness_total':
                    $selectParts[] = 'SUM(is_click) AS _uniqueness_total';
                    break;
                case 'conversion':
                    $selectParts[] = 'SUM(is_initial) AS conversion';
                    break;
                case 'cra':
                    $selectParts[] = 'COALESCE(SUM(is_initial) * 100.0 / NULLIF(SUM(is_click), 0), 0) AS cra';
                    break;
                case 'revenue':
                    $selectParts[] = 'SUM(conversion_payout) AS revenue';
                    break;
                case 'costs':
                    $selectParts[] = 'SUM(click_cost) AS costs';
                    break;
                case 'profit':
                    $selectParts[] = '(SUM(conversion_payout) - SUM(click_cost)) AS profit';
                    break;
                case 'roi':
                    $selectParts[] = 'COALESCE((SUM(conversion_payout) - SUM(click_cost)) * 100.0 / NULLIF(SUM(click_cost), 0), 0) AS roi';
                    break;
                case 'epc':
                    $selectParts[] = 'COALESCE(SUM(conversion_payout) / NULLIF(SUM(is_click), 0), 0) AS epc';
                    break;
                case 'uepc':
                    $selectParts[] = 'CASE WHEN SUM(is_click) = SUM(CASE WHEN is_click = 1 AND unique_flags IS NOT NULL THEN 1 ELSE 0 END) THEN COALESCE(SUM(conversion_payout) / NULLIF(SUM(CASE WHEN is_click = 1 AND (unique_flags & 2) != 0 THEN 1 ELSE 0 END), 0), 0) ELSE NULL END AS uepc';
                    break;
                case 'cpc':
                    $selectParts[] = 'COALESCE(SUM(click_cost) / NULLIF(SUM(is_click), 0), 0) AS cpc';
                    break;
                case 'ucpc':
                    $selectParts[] = 'CASE WHEN SUM(is_click) = SUM(CASE WHEN is_click = 1 AND unique_flags IS NOT NULL THEN 1 ELSE 0 END) THEN COALESCE(SUM(click_cost) / NULLIF(SUM(CASE WHEN is_click = 1 AND (unique_flags & 2) != 0 THEN 1 ELSE 0 END), 0), 0) ELSE NULL END AS ucpc';
                    break;
                case 'ec':
                    $selectParts[] = 'COALESCE(SUM(conversion_payout) / NULLIF(SUM(is_initial), 0), 0) AS ec';
                    break;
                case 'cpa':
                    $selectParts[] = 'COALESCE(SUM(click_cost) / NULLIF(SUM(is_initial), 0), 0) AS cpa';
                    break;
                default:
                    break;
            }
        }
        return $selectParts;
    }

    private const FILTERABLE_FIELDS = [
        'country', 'lang', 'os', 'osver', 'brand', 'model', 'device',
        'isp', 'client', 'clientver', 'flow', 'step', 'landing', 'path', 'status', 'reason'
    ];

    private const FILTER_OPERATORS = ['=', '!=', 'in', 'not_in', 'is_null', 'is_not_null'];

    private static function resolveFilterField(string $field): ?string {
        if (in_array($field, self::FILTERABLE_FIELDS)) {
            return $field;
        }
        if (str_starts_with($field, 'param.')) {
            $key = substr($field, 6);
            if (preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                return "json_extract(params, '\$.$key')";
            }
        }
        return null;
    }

    private function buildFilterWhere(array $filters, bool $stepScoped = true): array {
        $filterWhere = '';
        $filterBinds = [];
        if (empty($filters) || !isset($filters['rules']) || !is_array($filters['rules'])) {
            return [$filterWhere, $filterBinds];
        }

        $filterParts = [];
        $stepFilterParts = [];
        foreach ($filters['rules'] as $i => $rule) {
            $field = $rule['field'] ?? '';
            $op = $rule['operator'] ?? '';
            $value = $rule['value'] ?? '';

            $targetsReachedStep = !$stepScoped && in_array($field, ['step', 'landing'], true);
            $sqlField = $targetsReachedStep
                ? 'filter_step.' . ($field === 'step' ? 'step' : 'variant')
                : self::resolveFilterField($field);
            if ($sqlField === null || !in_array($op, self::FILTER_OPERATORS)) {
                continue;
            }

            $paramName = ":filter_{$i}";
            $part = null;
            switch ($op) {
                case '=':
                    $part = "$sqlField = $paramName";
                    $filterBinds[$paramName] = $value;
                    break;
                case '!=':
                    $part = "$sqlField != $paramName";
                    $filterBinds[$paramName] = $value;
                    break;
                case 'in':
                    $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                    $placeholders = [];
                    foreach ($vals as $vi => $v) {
                        $p = ":filter_{$i}_{$vi}";
                        $placeholders[] = $p;
                        $filterBinds[$p] = $v;
                    }
                    $part = $placeholders === []
                        ? '0 = 1'
                        : "$sqlField IN (" . implode(',', $placeholders) . ')';
                    break;
                case 'not_in':
                    $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                    $placeholders = [];
                    foreach ($vals as $vi => $v) {
                        $p = ":filter_{$i}_{$vi}";
                        $placeholders[] = $p;
                        $filterBinds[$p] = $v;
                    }
                    $part = $placeholders === []
                        ? '1 = 1'
                        : "$sqlField NOT IN (" . implode(',', $placeholders) . ')';
                    break;
                case 'is_null':
                    $part = "$sqlField IS NULL";
                    break;
                case 'is_not_null':
                    $part = "$sqlField IS NOT NULL";
                    break;
            }
            if ($part === null) {
                continue;
            }
            if ($targetsReachedStep) {
                $stepFilterParts[] = $part;
            } else {
                $filterParts[] = $part;
            }
        }
        $condition = ($filters['condition'] ?? 'AND') === 'OR' ? ' OR ' : ' AND ';
        if ($stepFilterParts !== []) {
            $filterParts[] = 'EXISTS (SELECT 1 FROM click_steps filter_step '
                . 'WHERE filter_step.clickid = c.clickid '
                . 'AND filter_step.step <= c.attribution_step '
                . 'AND (' . implode($condition, $stepFilterParts) . '))';
        }
        if (!empty($filterParts)) {
            $filterWhere = ' AND (' . implode($condition, $filterParts) . ')';
        }

        return [$filterWhere, $filterBinds];
    }

    /**
     * @return array{select:array<int,string>,group:array<int,string>,order:array<int,string>,fields:array<int,string>}
     */
    private function build_stats_group_parts(
        array $groupByFields,
        string $timezone,
        array $mvtGrouping = []
    ): array
    {
        $selectParts = [];
        $groupByParts = [];
        $orderByParts = [];
        $normalizedFields = [];

        foreach ($groupByFields as $field) {
            if ($field === 'mvt' && $mvtGrouping !== []) {
                $selectParts[] = "COALESCE(NULLIF(mvt_data, ''), '{}') AS mvt";
                $groupByParts[] = 'mvt';
                $orderByParts[] = 'mvt';
                $normalizedFields[] = 'mvt';
                continue;
            }
            if ($mvtGrouping !== [] && preg_match('/^mvt\\.(\d+)$/', (string)$field, $matches) === 1) {
                $testNumber = (int)$matches[1];
                $alias = 'mvt_test_' . $testNumber;
                $selectParts[] = "COALESCE(json_extract(mvt_data, '$.\"" . $testNumber . "\"'), '-') AS " . $alias;
                $groupByParts[] = $alias;
                $orderByParts[] = $alias;
                $normalizedFields[] = $alias;
                continue;
            }
            if ($field === 'date') {
                $dateTime = new DateTime('now', new DateTimeZone($timezone));
                $offsetInSeconds = $dateTime->getOffset();
                $hours = floor($offsetInSeconds / 3600);
                $minutes = floor(($offsetInSeconds % 3600) / 60);
                $offsetFormatted = sprintf('%+03d:%02d', $hours, $minutes);
                $selectParts[] = "strftime('%Y-%m-%d', datetime(stat_time, 'unixepoch', '{$offsetFormatted}')) AS date";
                $groupByParts[] = 'date';
                $orderByParts[] = 'date';
                $normalizedFields[] = 'date';
                continue;
            }

            if (in_array($field, [
                'country', 'lang', 'os', 'osver', 'brand', 'model', 'device',
                'isp', 'client', 'clientver', 'flow', 'step', 'landing', 'path',
            ], true)) {
                $selectParts[] = $field;
                $groupByParts[] = $field;
                $orderByParts[] = $field;
                $normalizedFields[] = $field;
                continue;
            }

            $jsonKey = str_starts_with($field, 'param.') ? substr($field, 6) : $field;
            if (preg_match('/^[a-zA-Z0-9_]+$/', $jsonKey) !== 1) {
                continue;
            }
            $selectParts[] = "COALESCE(json_extract(params, '$." . $jsonKey . "'), 'unknown') AS " . $jsonKey;
            $groupByParts[] = $jsonKey;
            $orderByParts[] = $jsonKey;
            $normalizedFields[] = $jsonKey;
        }

        return [
            'select' => $selectParts,
            'group' => $groupByParts,
            'order' => $orderByParts,
            'fields' => $normalizedFields,
        ];
    }

    private function query_statistics_level(
        array $queryFields,
        array $statusColumns,
        array $groupByFields,
        int $campId,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters,
        array $mvtGrouping = []
    ): array {
        global $cloSettings;

        $mvtScoped = $mvtGrouping !== [];
        $stepScoped = $mvtScoped
            || array_intersect(['step', 'landing'], $groupByFields) !== [];
        $conversionTime = ($cloSettings['conversionAttribution'] ?? 'click_time') === 'conversion_time';
        $conversionStatTime = $conversionTime ? 'cv.time' : 'c.time';
        if ($stepScoped) {
            $clickCurrentStatus = 'NULL';
            $clickCurrentFlag = '0';
            $conversionCurrentFlag = "CASE WHEN cv.changes_status = 1 AND cv.id = (
                SELECT cv2.id FROM conversions cv2
                WHERE cv2.clickid = cv.clickid AND cv2.changes_status = 1
                ORDER BY cv2.time DESC, cv2.id DESC LIMIT 1
            ) THEN 1 ELSE 0 END";
            $clickStep = 'cs.step';
            $clickLanding = 'cs.variant';
            $clickMvt = 'cs.mvt';
            $clickStepJoin = ' INNER JOIN click_steps cs ON cs.clickid = c.clickid';
            $conversionStep = 'cs.step';
            $conversionLanding = 'cs.variant';
            $conversionMvt = 'cs.mvt';
            $conversionStepJoin = ' INNER JOIN click_steps cs ON cs.clickid = c.clickid AND cs.step <= cv.step';
        } else {
            $clickCurrentStatus = $conversionTime ? 'NULL' : 'c.status';
            $clickCurrentFlag = $conversionTime
                ? '0'
                : "CASE WHEN c.status IS NOT NULL AND c.status <> '' THEN 1 ELSE 0 END";
            $conversionCurrentFlag = $conversionTime
                ? "CASE WHEN cv.changes_status = 1 AND cv.id = (
                    SELECT cv2.id FROM conversions cv2
                    WHERE cv2.clickid = cv.clickid AND cv2.changes_status = 1
                    ORDER BY cv2.time DESC, cv2.id DESC LIMIT 1
                ) THEN 1 ELSE 0 END"
                : '0';
            $clickStep = 'c.step';
            $clickLanding = 'NULL';
            $clickMvt = "'{}'";
            $clickStepJoin = '';
            $conversionStep = 'cv.step';
            $conversionLanding = 'NULL';
            $conversionMvt = "'{}'";
            $conversionStepJoin = '';
        }

        $baseQuery = "WITH stats_source AS (
            SELECT c.id, c.campaign_id, c.country, c.lang, c.os, c.osver, c.brand, c.model, c.device,
                   c.isp, c.client, c.clientver, c.flow, {$clickStep} AS step, {$clickLanding} AS landing,
                   {$clickMvt} AS mvt_data,
                   c.path, c.status, c.params, c.unique_flags, c.clickid, c.step AS attribution_step,
                   c.time AS stat_time,
                   1 AS is_click, 0 AS is_conversion, 0 AS is_initial,
                   c.cost AS click_cost, 0.0 AS conversion_payout,
                   {$clickCurrentStatus} AS metric_status, 0 AS status_occurrence,
                   {$clickCurrentFlag} AS current_status_event
            FROM clicks c{$clickStepJoin}
            WHERE c.campaign_id = :sourceCampId
            UNION ALL
            SELECT c.id, c.campaign_id, c.country, c.lang, c.os, c.osver, c.brand, c.model, c.device,
                   c.isp, c.client, c.clientver, c.flow, {$conversionStep} AS step, {$conversionLanding} AS landing,
                   {$conversionMvt} AS mvt_data,
                   c.path, c.status, c.params, NULL AS unique_flags, c.clickid, cv.step AS attribution_step,
                   {$conversionStatTime} AS stat_time,
                   0 AS is_click, 1 AS is_conversion, cv.is_initial,
                   0.0 AS click_cost, cv.payout AS conversion_payout,
                   cv.status AS metric_status, cv.status_occurrence,
                   {$conversionCurrentFlag} AS current_status_event
            FROM conversions cv
            INNER JOIN clicks c ON c.clickid = cv.clickid{$conversionStepJoin}
            WHERE cv.campaign_id = :sourceConversionCampId
        ) SELECT %s
        FROM stats_source c
        WHERE campaign_id = :campid AND stat_time BETWEEN :startDate AND :endDate";

        $selectParts = $this->get_stats_source_select_parts($queryFields, $statusColumns);
        $groupParts = $this->build_stats_group_parts(
            $groupByFields,
            $timezone,
            $mvtGrouping
        );
        $selectParts = array_merge($selectParts, $groupParts['select']);
        if ($selectParts === []) {
            return [];
        }

        [$filterWhere, $filterBinds] = $this->buildFilterWhere($filters, $stepScoped);
        $groupByClause = $groupParts['group'] !== []
            ? ' GROUP BY ' . implode(', ', $groupParts['group'])
            : '';
        $orderByClause = $groupParts['order'] !== []
            ? ' ORDER BY ' . implode(', ', $groupParts['order'])
            : '';
        $mvtWhere = $mvtScoped
            ? ' AND flow = :mvtFlow AND step = :mvtStep AND landing = :mvtLanding'
            : '';
        $sqlQuery = sprintf($baseQuery, implode(', ', $selectParts))
            . $filterWhere . $mvtWhere . $groupByClause . $orderByClause;

        $db = $this->open_db(true);
        $stmt = $db->prepare($sqlQuery);
        if ($stmt === false) {
            add_log('errors', 'Error preparing statistics statement: ' . $db->lastErrorMsg());
            return [];
        }
        $stmt->bindValue(':campid', $campId, SQLITE3_INTEGER);
        $stmt->bindValue(':sourceCampId', $campId, SQLITE3_INTEGER);
        $stmt->bindValue(':sourceConversionCampId', $campId, SQLITE3_INTEGER);
        $stmt->bindValue(':startDate', $startDate, SQLITE3_INTEGER);
        $stmt->bindValue(':endDate', $endDate, SQLITE3_INTEGER);
        if ($mvtScoped) {
            $stmt->bindValue(':mvtFlow', (string)$mvtGrouping['flow'], SQLITE3_TEXT);
            $stmt->bindValue(':mvtStep', (int)$mvtGrouping['step'], SQLITE3_INTEGER);
            $stmt->bindValue(':mvtLanding', (string)$mvtGrouping['landing'], SQLITE3_TEXT);
        }
        foreach ($filterBinds as $param => $value) {
            $stmt->bindValue($param, $value, SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        if ($result === false) {
            add_log('errors', 'Error executing statistics statement: ' . $db->lastErrorMsg());
            return [];
        }

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @return array<int,array<int,array<string,mixed>>> Rows keyed by grouping depth.
     */
    private function query_event_statistics_levels(
        array $eventMetricFields,
        array $groupByFields,
        int $campId,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters,
        array $mvtGrouping = []
    ): array {
        $metricDefinitions = [];
        $p75MetricDefinitions = [];
        foreach ($eventMetricFields as $field) {
            $metric = self::parse_event_metric_field($field);
            if ($metric === null) {
                continue;
            }
            $jsonPath = $metric['kind'] === 'event'
                ? '$.' . $metric['name']
                : '$.performance.' . $metric['name'];
            $metricDefinitions[$metric['base']] = $jsonPath;
            if ($metric['aggregation'] === 'p75') {
                $p75MetricDefinitions[$metric['base']] = $jsonPath;
            }
        }

        if ($metricDefinitions === []) {
            return [];
        }

        $sourceColumns = "c.campaign_id, c.country, c.lang, c.os, c.osver, c.brand, c.model, c.device,
            c.isp, c.client, c.clientver, c.flow, cs.step, cs.variant AS landing,
            c.path, c.status, c.params, c.clickid, c.time AS stat_time, cs.events,
            cs.mvt AS mvt_data";

        $groupParts = $this->build_stats_group_parts(
            $groupByFields,
            $timezone,
            $mvtGrouping
        );
        [$filterWhere, $filterBinds] = $this->buildFilterWhere($filters);
        if ($mvtGrouping !== []) {
            $filterWhere .= ' AND flow = :mvtFlow AND step = :mvtStep AND landing = :mvtLanding';
        }
        $levelFieldsByDepth = [];
        for ($level = 0; $level <= count($groupParts['fields']); $level++) {
            $levelFieldsByDepth[$level] = array_slice($groupParts['fields'], 0, $level);
        }
        $groupSelect = $groupParts['select'] !== []
            ? implode(', ', $groupParts['select']) . ', '
            : '';
        $leafSelect = $groupParts['fields'] !== []
            ? implode(', ', $groupParts['fields']) . ', '
            : '';
        $finalGroupFields = array_merge($groupParts['fields'], ['metric_base']);

        $db = $this->open_db(true);
        $buildSamplesCte = static function (array $definitions) use (
            $sourceColumns,
            $groupSelect,
            $filterWhere
        ): string {
            if (count($definitions) > self::EVENT_JSON_TREE_METRIC_THRESHOLD) {
                // A wide, sparse selection is cheaper as one JSON-tree walk
                // than as requested-paths × click_steps lookups. At the maximum
                // catalog size, filtering the small canonical leaf set in PHP
                // also avoids a large SQL IN lookup for every leaf.
                $pathFilter = '';
                if (count($definitions) <= self::EVENT_JSON_TREE_UNFILTERED_THRESHOLD) {
                    $jsonPaths = array_map(
                        static fn(string $path): string => "'" . str_replace("'", "''", $path) . "'",
                        array_values($definitions)
                    );
                    $pathFilter = ' AND jt.fullkey IN (' . implode(', ', $jsonPaths) . ')';
                }
                return "WITH event_steps AS (
                        SELECT {$sourceColumns}
                        FROM clicks c
                        INNER JOIN click_steps cs ON cs.clickid = c.clickid
                        WHERE c.campaign_id = :eventSourceCampId
                    ),
                    event_samples AS (
                        SELECT {$groupSelect}
                               CASE WHEN jt.path = '$.performance'
                                    THEN 'performance.' ELSE 'event.' END || jt.key AS metric_base,
                               CAST(jt.value AS REAL) AS metric_value
                        FROM event_steps c
                        INNER JOIN json_tree(c.events) jt
                        WHERE c.stat_time BETWEEN :startDate AND :endDate{$filterWhere}
                          AND jt.type IN ('integer', 'real'){$pathFilter}
                          AND jt.path IN ('$', '$.performance')
                    )";
            }

            $definitionRows = [];
            foreach ($definitions as $metricBase => $jsonPath) {
                $definitionRows[] = sprintf(
                    "('%s', '%s')",
                    str_replace("'", "''", $metricBase),
                    str_replace("'", "''", $jsonPath)
                );
            }
            return "WITH metric_definitions(metric_base, json_path) AS (
                    VALUES " . implode(', ', $definitionRows) . "
                ),
                event_steps AS (
                    SELECT {$sourceColumns}
                    FROM clicks c
                    INNER JOIN click_steps cs ON cs.clickid = c.clickid
                    WHERE c.campaign_id = :eventSourceCampId
                ),
                event_samples AS (
                    SELECT {$groupSelect}md.metric_base,
                           CAST(json_extract(c.events, md.json_path) AS REAL) AS metric_value
                    FROM event_steps c
                    CROSS JOIN metric_definitions md
                    WHERE c.stat_time BETWEEN :startDate AND :endDate{$filterWhere}
                      AND json_valid(c.events) = 1
                      AND json_type(c.events, md.json_path) IN ('integer', 'real')
                )";
        };
        $execute = static function (string $sql) use (
            $db,
            $campId,
            $startDate,
            $endDate,
            $filterBinds,
            $mvtGrouping
        ): SQLite3Result|false {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return false;
            }
            $stmt->bindValue(':eventSourceCampId', $campId, SQLITE3_INTEGER);
            $stmt->bindValue(':startDate', $startDate, SQLITE3_INTEGER);
            $stmt->bindValue(':endDate', $endDate, SQLITE3_INTEGER);
            if ($mvtGrouping !== []) {
                $stmt->bindValue(':mvtFlow', (string)$mvtGrouping['flow'], SQLITE3_TEXT);
                $stmt->bindValue(':mvtStep', (int)$mvtGrouping['step'], SQLITE3_INTEGER);
                $stmt->bindValue(':mvtLanding', (string)$mvtGrouping['landing'], SQLITE3_TEXT);
            }
            foreach ($filterBinds as $param => $value) {
                $stmt->bindValue($param, $value, SQLITE3_TEXT);
            }
            return $stmt->execute();
        };

        $aggregateSql = $buildSamplesCte($metricDefinitions)
            . " SELECT {$leafSelect}metric_base,
                       COUNT(*) AS sample_count,
                       SUM(metric_value) AS metric_sum,
                       MIN(metric_value) AS metric_min,
                       MAX(metric_value) AS metric_max
                FROM event_samples
                GROUP BY " . implode(', ', $finalGroupFields);
        $aggregateResult = $execute($aggregateSql);
        if ($aggregateResult === false) {
            add_log('errors', 'Error executing event aggregate statement: ' . $db->lastErrorMsg());
            return [];
        }

        // Exact parent aggregates can be derived from the deepest groups without
        // rescanning or sorting every hierarchy prefix in SQLite.
        $statesByLevel = [];
        $presentMetricBases = [];
        while ($leaf = $aggregateResult->fetchArray(SQLITE3_ASSOC)) {
            $metricBase = (string)$leaf['metric_base'];
            if (!isset($metricDefinitions[$metricBase])) {
                continue;
            }
            $presentMetricBases[$metricBase] = true;
            $sampleCount = (int)$leaf['sample_count'];
            $metricSum = (float)$leaf['metric_sum'];
            $metricMin = (float)$leaf['metric_min'];
            $metricMax = (float)$leaf['metric_max'];

            foreach (
                self::stats_group_keys_by_depth($leaf, $groupParts['fields'])
                as $level => $groupKey
            ) {
                $levelFields = $levelFieldsByDepth[$level];
                if (!isset($statesByLevel[$level][$groupKey][$metricBase])) {
                    $groupValues = [];
                    foreach ($levelFields as $field) {
                        $groupValues[$field] = $leaf[$field] ?? null;
                    }
                    $statesByLevel[$level][$groupKey][$metricBase] = $groupValues + [
                        'metric_base' => $metricBase,
                        'sample_count' => 0,
                        'metric_sum' => 0.0,
                        'metric_min' => $metricMin,
                        'metric_max' => $metricMax,
                        'metric_p75' => null,
                        'p75_seen' => 0,
                        'p75_target' => 0,
                    ];
                }

                $state =& $statesByLevel[$level][$groupKey][$metricBase];
                $state['sample_count'] += $sampleCount;
                $state['metric_sum'] += $metricSum;
                $state['metric_min'] = min($state['metric_min'], $metricMin);
                $state['metric_max'] = max($state['metric_max'], $metricMax);
                unset($state);
            }
        }

        $activeP75MetricDefinitions = array_intersect_key(
            $p75MetricDefinitions,
            $presentMetricBases
        );
        if ($activeP75MetricDefinitions !== []) {
            foreach ($statesByLevel as &$groups) {
                foreach ($groups as &$metrics) {
                    foreach ($metrics as &$state) {
                        if (isset($activeP75MetricDefinitions[$state['metric_base']])) {
                            $state['p75_target'] = intdiv(
                                3 * $state['sample_count'] + 3,
                                4
                            );
                        }
                    }
                    unset($state);
                }
                unset($metrics);
            }
            unset($groups);

            // The global value order also orders every group's subsequence. One
            // streaming pass therefore finds nearest-rank P75 for every prefix,
            // while memory remains proportional to the number of result groups.
            $sampleSql = $buildSamplesCte($activeP75MetricDefinitions)
                . " SELECT {$leafSelect}metric_base, metric_value
                    FROM event_samples
                    ORDER BY metric_base, metric_value";
            $sampleResult = $execute($sampleSql);
            if ($sampleResult === false) {
                add_log('errors', 'Error executing event percentile statement: ' . $db->lastErrorMsg());
                return [];
            }

            $groupFieldCount = count($groupParts['fields']);
            while ($sample = $sampleResult->fetchArray(SQLITE3_NUM)) {
                $metricBase = (string)$sample[$groupFieldCount];
                if (!isset($activeP75MetricDefinitions[$metricBase])) {
                    continue;
                }
                $metricValue = (float)$sample[$groupFieldCount + 1];
                foreach (
                    self::stats_group_keys_from_values($sample, $groupFieldCount)
                    as $level => $groupKey
                ) {
                    if (!isset($statesByLevel[$level][$groupKey][$metricBase])) {
                        continue;
                    }
                    $state =& $statesByLevel[$level][$groupKey][$metricBase];
                    $state['p75_seen']++;
                    if ($state['p75_seen'] === $state['p75_target']) {
                        $state['metric_p75'] = $metricValue;
                    }
                    unset($state);
                }
            }
        }

        $rowsByLevel = [];
        foreach ($statesByLevel as $level => $groups) {
            foreach ($groups as $metrics) {
                foreach ($metrics as $state) {
                    $sampleCount = (int)$state['sample_count'];
                    $metricAverage = $sampleCount > 0
                        ? (float)$state['metric_sum'] / $sampleCount
                        : null;
                    unset($state['metric_sum'], $state['p75_seen'], $state['p75_target']);
                    $state['metric_avg'] = $metricAverage;
                    $rowsByLevel[$level][] = $state;
                }
            }
        }
        return $rowsByLevel;
    }

    /**
     * @return array<int,string> Collision-safe group keys keyed by prefix depth.
     */
    private static function stats_group_keys_by_depth(array $row, array $groupFields): array
    {
        $values = [];
        foreach ($groupFields as $field) {
            $values[] = $row[$field] ?? 'unknown';
        }
        return self::stats_group_keys_from_values($values, count($groupFields));
    }

    /**
     * @return array<int,string> Collision-safe group keys keyed by prefix depth.
     */
    private static function stats_group_keys_from_values(array $values, int $fieldCount): array
    {
        $keys = [''];
        $key = '';
        for ($index = 0; $index < $fieldCount; $index++) {
            $value = (string)($values[$index] ?? 'unknown');
            $key .= strlen($value) . ':' . $value . ';';
            $keys[] = $key;
        }
        return $keys;
    }

    private static function stats_group_key(array $row, array $groupFields): string
    {
        $keys = self::stats_group_keys_by_depth($row, $groupFields);
        return $keys[count($groupFields)] ?? '';
    }

    private static function merge_event_metrics_into_rows(
        array &$rows,
        array $eventRows,
        array $groupFields,
        array $eventMetricFields
    ): void {
        $metricsByGroup = [];
        foreach ($eventRows as $eventRow) {
            $groupKey = self::stats_group_key($eventRow, $groupFields);
            $metricBase = (string)($eventRow['metric_base'] ?? '');
            if ($metricBase === '') {
                continue;
            }
            $metricsByGroup[$groupKey][$metricBase] = $eventRow;
        }

        foreach ($rows as &$row) {
            $groupKey = self::stats_group_key($row, $groupFields);
            foreach ($eventMetricFields as $field) {
                $metric = self::parse_event_metric_field($field);
                if ($metric === null) {
                    continue;
                }
                $sample = $metricsByGroup[$groupKey][$metric['base']] ?? null;
                if ($sample === null) {
                    $row[$field] = $metric['aggregation'] === 'count' ? 0 : null;
                    continue;
                }
                $sourceField = match ($metric['aggregation']) {
                    'count' => 'sample_count',
                    'avg' => 'metric_avg',
                    'p75' => 'metric_p75',
                    'min' => 'metric_min',
                    'max' => 'metric_max',
                };
                $value = $sample[$sourceField] ?? null;
                $row[$field] = $metric['aggregation'] === 'count'
                    ? (int)($value ?? 0)
                    : ($value === null ? null : (float)$value);
            }
        }
    }

    private function build_exact_stats_tree(
        array $rowsByLevel,
        array $groupFields
    ): array {
        if ($groupFields === []) {
            return $rowsByLevel[0] ?? [];
        }

        $rowsByParent = [];
        for ($depth = 1; $depth <= count($groupFields); $depth++) {
            $parentFields = array_slice($groupFields, 0, $depth - 1);
            foreach ($rowsByLevel[$depth] ?? [] as $row) {
                $parentKey = self::stats_group_key($row, $parentFields);
                $rowsByParent[$depth][$parentKey][] = $row;
            }
        }

        return $this->build_indexed_stats_tree($rowsByParent, $groupFields, 1, '');
    }

    /**
     * @param array<int,array<string,array<int,array<string,mixed>>>> $rowsByParent
     */
    private function build_indexed_stats_tree(
        array $rowsByParent,
        array $groupFields,
        int $depth,
        string $parentKey
    ): array {
        $rows = $rowsByParent[$depth][$parentKey] ?? [];
        $currentField = $groupFields[$depth - 1];
        $tree = [];

        foreach ($rows as $row) {
            $groupValue = $row[$currentField] ?? 'unknown';
            $node = $row;
            foreach ($groupFields as $field) {
                unset($node[$field]);
            }
            $node['group'] = is_numeric($groupValue) ? (string)$groupValue : $groupValue;
            if ($depth < count($groupFields)) {
                $childParentFields = array_slice($groupFields, 0, $depth);
                $children = $this->build_indexed_stats_tree(
                    $rowsByParent,
                    $groupFields,
                    $depth + 1,
                    self::stats_group_key($row, $childParentFields)
                );
                if ($children !== []) {
                    $node['_children'] = $children;
                }
            }
            $tree[] = $node;
        }
        return $tree;
    }

    private static function format_mvt_group_rows(
        array &$rows,
        array $mvtGrouping
    ): void {
        $selectedTests = is_array($mvtGrouping['tests'] ?? null)
            ? $mvtGrouping['tests']
            : ((int)($mvtGrouping['test'] ?? 0) > 0 ? [$mvtGrouping['test']] : []);
        if ($mvtGrouping === [] || $selectedTests !== []) {
            return;
        }
        foreach ($rows as &$row) {
            if (!array_key_exists('mvt', $row)) {
                continue;
            }
            $assignment = json_decode((string)$row['mvt'], true);
            if (!is_array($assignment) || $assignment === []) {
                $row['mvt'] = '-';
                continue;
            }
            $maxTest = max(array_map('intval', array_keys($assignment)));
            $label = '';
            for ($test = 1; $test <= $maxTest; $test++) {
                $label .= isset($assignment[(string)$test])
                    ? (string)$assignment[(string)$test]
                    : '-';
            }
            $row['mvt'] = $label === '' ? '-' : $label;
        }
        unset($row);
    }

    public function get_statistics(
        array $selectedColumns,
        array $groupByFields,
        int $campId,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters = [],
        array $orderby = [],
        array $mvtGrouping = []
    ): array {
        [$selectedFields, $customColumns, $_normalizedColumns, $statusColumns] =
            self::split_requested_stats_columns($selectedColumns);
        if ($selectedFields === []) {
            return [];
        }
        $queryFields = $selectedFields;
        foreach ($customColumns as $customColumn) {
            foreach (self::extract_formula_dependencies($customColumn['formula']) ?? [] as $dependency) {
                if (!in_array($dependency, $queryFields, true)) {
                    $queryFields[] = $dependency;
                }
            }
        }
        $queryFields = self::expand_stats_dependencies($queryFields);

        $eventMetricFields = [];
        $coreQueryFields = [];
        foreach ($queryFields as $field) {
            if (self::parse_event_metric_field($field) !== null) {
                $eventMetricFields[] = $field;
                continue;
            }
            if (str_starts_with($field, 'custom.')) {
                continue;
            }
            $coreQueryFields[] = $field;
        }
        $eventMetricFields = array_values(array_unique($eventMetricFields));
        if (!in_array('clicks', $coreQueryFields, true)) {
            // Required to build groups even for an event-only report.
            $coreQueryFields[] = 'clicks';
        }

        if (array_intersect(
            ['uniques', 'flow_uniques', 'uniques_ratio', 'uepc', 'ucpc'],
            $coreQueryFields
        ) !== []) {
            $coreQueryFields[] = '_uniqueness_counted';
            $coreQueryFields[] = '_uniqueness_total';
            $coreQueryFields = array_values(array_unique($coreQueryFields));
        }

        $effectiveGroupBy = $groupByFields;
        if ($mvtGrouping !== []) {
            $requestedMvtTests = is_array($mvtGrouping['tests'] ?? null)
                ? $mvtGrouping['tests']
                : ((int)($mvtGrouping['test'] ?? 0) > 0 ? [$mvtGrouping['test']] : []);
            $mvtTests = array_values(array_unique(array_filter(array_map(
                static fn($test): int => (int)$test,
                $requestedMvtTests
            ), static fn(int $test): bool => $test > 0)));
            $mvtFields = $mvtTests === []
                ? ['mvt']
                : array_map(static fn(int $test): string => 'mvt.' . $test, $mvtTests);
            $rewrittenGroupBy = [];
            $insertedMvt = false;
            foreach ($effectiveGroupBy as $field) {
                if ($field !== 'mvt') {
                    $rewrittenGroupBy[] = $field;
                    continue;
                }
                if (!$insertedMvt) {
                    array_push($rewrittenGroupBy, ...$mvtFields);
                    $insertedMvt = true;
                }
            }
            if (!$insertedMvt) {
                array_push($rewrittenGroupBy, ...$mvtFields);
            }
            $effectiveGroupBy = $rewrittenGroupBy;
        }
        $rowsByLevel = [];
        $normalizedGroupBy = [];
        $eventRowsByLevel = $eventMetricFields !== []
            ? $this->query_event_statistics_levels(
                $eventMetricFields,
                $effectiveGroupBy,
                $campId,
                $startDate,
                $endDate,
                $timezone,
                $filters,
                $mvtGrouping
            )
            : [];
        for ($level = 0; $level <= count($effectiveGroupBy); $level++) {
            $levelGroupBy = array_slice($effectiveGroupBy, 0, $level);
            $groupParts = $this->build_stats_group_parts(
                $levelGroupBy,
                $timezone,
                $mvtGrouping
            );
            $normalizedGroupBy = $level === count($effectiveGroupBy)
                ? $groupParts['fields']
                : $normalizedGroupBy;

            $levelRows = $this->query_statistics_level(
                $coreQueryFields,
                $statusColumns,
                $levelGroupBy,
                $campId,
                $startDate,
                $endDate,
                $timezone,
                $filters,
                $mvtGrouping
            );
            if ($eventMetricFields !== []) {
                self::merge_event_metrics_into_rows(
                    $levelRows,
                    $eventRowsByLevel[count($groupParts['fields'])] ?? [],
                    $groupParts['fields'],
                    $eventMetricFields
                );
            }
            self::format_mvt_group_rows($levelRows, $mvtGrouping);
            $rowsByLevel[$level] = $levelRows;
        }

        $tree = $this->build_exact_stats_tree($rowsByLevel, $normalizedGroupBy);
        $statsTotals = $rowsByLevel[0][0] ?? array_fill_keys($queryFields, 0);
        if ($customColumns !== []) {
            self::apply_custom_columns_to_row($statsTotals, $customColumns);
            self::apply_custom_columns_to_tree($tree, $customColumns);
        }
        $statsTotals = self::filter_stats_totals_fields($statsTotals, $selectedFields);
        self::sort_tree($tree, $orderby);
        self::attach_stats_totals_to_tree($tree, $statsTotals);
        self::filter_tree_fields($tree, $selectedFields);
        return $tree;
    }

    private function add_click(string $query, array $click): bool
    {
        try {
            $db = $this->open_db();
            $stmt = $db->prepare($query);

            if ($stmt === false) {
                throw new Exception($db->lastErrorMsg());
            }

            foreach ($click as $key => $value) {
                if (!isset($value)) {
                    add_log("warning", "Null value found for field '$key' in click data");
                    $value = '';
                }
                $stmt->bindValue(':' . $key, $value);
            }

            $result = $stmt->execute();
            if ($result === false) {
                throw new Exception($db->lastErrorMsg());
            }

            add_log("trace", "Successfully added click for IP: " . ($click['ip'] ?? 'unknown'));
            return true;
        } catch (Exception $e) {
            add_log("errors", "Failed to add click: " . $e->getMessage() . ", Data: " . json_encode($click));
            return false;
        }
    }

    public function add_trafficback_click($data): bool
    {
        $click = $this->prepare_click_data($data);
        $query = "INSERT INTO trafficback (time, ip, country, lang, os, osver, brand, model, isp, client, clientver, ua, params) VALUES (:time, :ip, :country, :lang, :os, :osver, :brand, :model, :isp, :client, :clientver, :ua, :params)";
        return $this->add_click($query, $click);
    }

    public function add_white_click($data, $reason, $campId): bool
    {
        $click = $this->prepare_click_data($data, $campId);
        $click['reason'] = $reason;
        $query = "INSERT INTO blocked (campaign_id, time, ip, country, lang, os, osver, brand, model, isp, client, clientver, ua, reason, params) VALUES (:campaign_id, :time, :ip, :country, :lang, :os, :osver, :brand, :model, :isp, :client, :clientver, :ua, :reason, :params)";
        return $this->add_click($query, $click);
    }

    public function add_black_click(string $userid, string $clickid, $data, array $path, string $flow, int $campId): bool
    {
        $click = $this->prepare_click_data($data, $campId);
        $click['userid'] = $userid;
        $click['clickid'] = $clickid;
        $click['flow'] = empty($flow) ? 'unknown' : $flow;
        $click['path'] = json_encode($path);
        $click['step'] = 0;
        $click['status'] = null;

        $query = "INSERT INTO clicks (campaign_id, time, ip, country, lang, os, osver, client, clientver, device, brand, model, isp, ua, userid, clickid, flow, path, step, params, cost, status) VALUES (:campaign_id, :time, :ip, :country, :lang, :os, :osver, :client, :clientver, :device, :brand, :model, :isp, :ua, :userid, :clickid, :flow, :path, :step, :params, :cpc, NULL)";

        return $this->add_click($query, $click);
    }

    public function insert_black_click_in_transaction(
        SQLite3 $db,
        string $userid,
        string $clickid,
        array $click,
        array $path,
        string $flow,
        int $campId,
        ?string $uniqueHash,
        int $uniqueFlags,
        int $time
    ): void {
        $click['time'] = $time;
        $pathJson = json_encode(array_values($path));
        if ($pathJson === false) {
            throw new RuntimeException('Failed to encode click path');
        }

        $query = "INSERT INTO clicks (
                    campaign_id, time, ip, country, lang, os, osver, client, clientver,
                    device, brand, model, isp, ua, userid, unique_hash, unique_flags,
                    clickid, flow, path, step, params, cost, status
                  ) VALUES (
                    :campaign_id, :time, :ip, :country, :lang, :os, :osver, :client, :clientver,
                    :device, :brand, :model, :isp, :ua, :userid, :unique_hash, :unique_flags,
                    :clickid, :flow, :path, 0, :params, :cpc, NULL
                  )";
        $stmt = @$db->prepare($query);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare atomic click insert: ' . $db->lastErrorMsg());
        }

        $textFields = [
            'ip', 'country', 'lang', 'os', 'osver', 'client', 'clientver', 'device',
            'brand', 'model', 'isp', 'ua', 'params'
        ];
        $stmt->bindValue(':campaign_id', $campId, SQLITE3_INTEGER);
        $stmt->bindValue(':time', $time, SQLITE3_INTEGER);
        foreach ($textFields as $field) {
            $stmt->bindValue(':' . $field, (string)($click[$field] ?? ''), SQLITE3_TEXT);
        }
        $stmt->bindValue(':userid', $userid, SQLITE3_TEXT);
        $stmt->bindValue(':unique_hash', $uniqueHash, $uniqueHash === null ? SQLITE3_NULL : SQLITE3_BLOB);
        $stmt->bindValue(':unique_flags', $uniqueFlags, SQLITE3_INTEGER);
        $stmt->bindValue(':clickid', $clickid, SQLITE3_TEXT);
        $stmt->bindValue(':flow', $flow === '' ? 'unknown' : $flow, SQLITE3_TEXT);
        $stmt->bindValue(':path', $pathJson, SQLITE3_TEXT);
        $stmt->bindValue(':cpc', (float)($click['cpc'] ?? 0), SQLITE3_FLOAT);
        if (@$stmt->execute() === false) {
            throw new RuntimeException('Failed to insert atomic click: ' . $db->lastErrorMsg());
        }
    }

    public function prepare_black_click_for_transaction(array $data, int $campId): array
    {
        return $this->prepare_click_data($data, $campId);
    }

    public function insert_click_step_in_transaction(
        SQLite3 $db,
        string $clickid,
        int $step,
        string $variant,
        int $time,
        array $mvt = []
    ): void {
        $stmt = @$db->prepare(
            'INSERT INTO click_steps (clickid, step, variant, time, mvt) '
            . 'VALUES (:clickid, :step, :variant, :time, :mvt)'
        );
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare atomic click step insert: ' . $db->lastErrorMsg());
        }
        $stmt->bindValue(':clickid', $clickid, SQLITE3_TEXT);
        $stmt->bindValue(':step', $step, SQLITE3_INTEGER);
        $stmt->bindValue(':variant', $variant, SQLITE3_TEXT);
        $stmt->bindValue(':time', $time, SQLITE3_INTEGER);
        $stmt->bindValue(
            ':mvt',
            json_encode((object)$mvt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            SQLITE3_TEXT
        );
        if (@$stmt->execute() === false) {
            throw new RuntimeException('Failed to insert atomic click step: ' . $db->lastErrorMsg());
        }
    }

    public function add_click_step(
        string $clickid,
        int $step,
        string $variant,
        array $mvt = []
    ): bool
    {
        if (empty($clickid)) {
            add_log("warning", "Skipping step insertion - empty clickid provided");
            return false;
        }
        if ($step < 0) {
            add_log("warning", "Skipping step insertion - invalid step provided: $step");
            return false;
        }

        if (!$this->clickid_exists($clickid)) {
            add_log("warning", "Skipping step insertion - clickid not found: $clickid");
            return false;
        }

        try {
            $db = $this->open_db();
            $db->exec('BEGIN IMMEDIATE');

            $insertStmt = $db->prepare(
                "INSERT OR IGNORE INTO click_steps (clickid, step, variant, time, mvt) "
                . "VALUES (:clickid, :step, :variant, :time, :mvt)"
            );
            if ($insertStmt === false) {
                throw new Exception('Failed to prepare click_steps insert: ' . $db->lastErrorMsg());
            }
            $insertStmt->bindValue(':clickid', $clickid, SQLITE3_TEXT);
            $insertStmt->bindValue(':step', $step, SQLITE3_INTEGER);
            $insertStmt->bindValue(':variant', $variant, SQLITE3_TEXT);
            $insertStmt->bindValue(':time', time(), SQLITE3_INTEGER);
            $insertStmt->bindValue(
                ':mvt',
                json_encode((object)$mvt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                SQLITE3_TEXT
            );
            if ($insertStmt->execute() === false) {
                throw new Exception('Failed to insert click step: ' . $db->lastErrorMsg());
            }

            $updateStmt = $db->prepare("UPDATE clicks SET step = MAX(step, :newStep) WHERE clickid = :clickid");
            if ($updateStmt === false) {
                throw new Exception('Failed to prepare click step update: ' . $db->lastErrorMsg());
            }
            $updateStmt->bindValue(':newStep', $step, SQLITE3_INTEGER);
            $updateStmt->bindValue(':clickid', $clickid, SQLITE3_TEXT);
            if ($updateStmt->execute() === false) {
                throw new Exception('Failed to update click current step: ' . $db->lastErrorMsg());
            }

            $db->exec('COMMIT');
            return true;
        } catch (Exception $e) {
            $this->writeDb?->exec('ROLLBACK');
            add_log('errors', $e->getMessage());
            return false;
        }
    }

    public function get_click_step_variant(string $clickid, int $step): ?string
    {
        if ($clickid === '' || $step < 0) {
            return null;
        }

        $row = $this->exec_read_query(
            'SELECT variant FROM click_steps WHERE clickid = ? AND step = ? LIMIT 1',
            [[$clickid, SQLITE3_TEXT], [$step, SQLITE3_INTEGER]],
            true
        );
        return isset($row['variant']) && is_string($row['variant']) && $row['variant'] !== ''
            ? $row['variant']
            : null;
    }

    public function get_click_step_mvt(string $clickid, int $step): array
    {
        if ($clickid === '' || $step < 0) {
            return [];
        }
        $row = $this->exec_read_query(
            'SELECT mvt FROM click_steps WHERE clickid = ? AND step = ? LIMIT 1',
            [[$clickid, SQLITE3_TEXT], [$step, SQLITE3_INTEGER]],
            true
        );
        $decoded = json_decode((string)($row['mvt'] ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function update_click_step_mvt(string $clickid, int $step, array $mvt): bool
    {
        if ($clickid === '' || $step < 0) {
            return false;
        }
        $json = json_encode((object)$mvt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        return $this->exec_write_query(
            'UPDATE click_steps SET mvt = :mvt WHERE clickid = :clickid AND step = :step',
            [
                $json => SQLITE3_TEXT,
                $clickid => SQLITE3_TEXT,
                $step => SQLITE3_INTEGER,
            ]
        ) !== false;
    }

    public function update_leaddata(string $clickid, array $leaddata): bool
    {
        if (empty($clickid)) {
            add_log("warning", "Skipping lead addition - empty clickid provided");
            return false;
        }

        $encoded = json_encode($leaddata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return false;
        }
        $updateQuery = "UPDATE clicks SET leaddata = :leaddata WHERE id = (SELECT id FROM clicks WHERE clickid = :clickid ORDER BY time DESC LIMIT 1)";
        return $this->exec_update_query($updateQuery, [$encoded => SQLITE3_TEXT, $clickid => SQLITE3_TEXT]);
    }

    /** @return array{accepted:bool,code:string,message:string,click?:array,conversion_id?:int} */
    public function record_conversion(
        string $clickid,
        int $campaignId,
        string $status,
        string $rawStatus,
        string $source,
        ?string $tid,
        ?string $tidParameter,
        float $payout,
        string $currency,
        bool $payoutProvided,
        bool $tidDeduplicationEnabled,
        string $paidRepeatWithoutTid
    ): array {
        try {
            return $this->immediate_transaction(function (SQLite3 $db) use (
                $clickid,
                $campaignId,
                $status,
                $rawStatus,
                $source,
                $tid,
                $tidParameter,
                $payout,
                $currency,
                $payoutProvided,
                $tidDeduplicationEnabled,
                $paidRepeatWithoutTid
            ): array {
                $clickStmt = $db->prepare('SELECT * FROM clicks WHERE clickid = :clickid LIMIT 1');
                if ($clickStmt === false) {
                    throw new Exception('Failed to prepare click lookup: ' . $db->lastErrorMsg());
                }
                $clickStmt->bindValue(':clickid', $clickid, SQLITE3_TEXT);
                $clickResult = $clickStmt->execute();
                $click = $clickResult === false ? [] : ($clickResult->fetchArray(SQLITE3_ASSOC) ?: []);
                if ($click === [] || (int)$click['campaign_id'] !== $campaignId) {
                    return ['accepted' => false, 'code' => 'click_not_found', 'message' => 'Clickid not found.'];
                }

                $storedTid = $tid;
                $storedTidParameter = $storedTid === null ? null : $tidParameter;
                if ($tidDeduplicationEnabled && $storedTid !== null) {
                    $tidStmt = $db->prepare(
                        'SELECT 1 FROM conversions '
                        . 'WHERE campaign_id = :campaign AND tid_parameter = :tid_parameter '
                        . 'AND tid = :tid AND tid <> \'\' LIMIT 1'
                    );
                    if ($tidStmt === false) {
                        throw new Exception('Failed to prepare transaction lookup: ' . $db->lastErrorMsg());
                    }
                    $tidStmt->bindValue(':campaign', $campaignId, SQLITE3_INTEGER);
                    $tidStmt->bindValue(':tid_parameter', $storedTidParameter, SQLITE3_TEXT);
                    $tidStmt->bindValue(':tid', $storedTid, SQLITE3_TEXT);
                    $tidResult = $tidStmt->execute();
                    if ($tidResult === false) {
                        throw new Exception('Failed to execute transaction lookup: ' . $db->lastErrorMsg());
                    }
                    if ($tidResult->fetchArray(SQLITE3_NUM) !== false) {
                        return [
                            'accepted' => false,
                            'code' => 'duplicate_tid',
                            'message' => 'Transaction ID was already used for this parameter.',
                        ];
                    }
                }

                $currentStatus = isset($click['status']) ? (string)$click['status'] : '';
                $sameStatus = $currentStatus !== '' && strcasecmp($currentStatus, $status) === 0;
                if ($sameStatus) {
                    if (!$payoutProvided || $payout <= 0) {
                        return ['accepted' => false, 'code' => 'duplicate_status', 'message' => 'The click already has this status.'];
                    }
                    if ($tidDeduplicationEnabled && $storedTid === null) {
                        return ['accepted' => false, 'code' => 'tid_required', 'message' => 'A new tid is required for a paid repeat status.'];
                    }
                    if (!$tidDeduplicationEnabled && $tid === null && $paidRepeatWithoutTid !== 'upsell') {
                        return ['accepted' => false, 'code' => 'duplicate_status', 'message' => 'Paid repeat without tid is disabled.'];
                    }
                }

                $initial = $currentStatus === '';
                $changesStatus = !$sameStatus;
                $occurrenceStmt = $db->prepare('SELECT COUNT(*) + 1 FROM conversions WHERE clickid = :clickid AND status = :status COLLATE NOCASE');
                $occurrenceStmt->bindValue(':clickid', $clickid, SQLITE3_TEXT);
                $occurrenceStmt->bindValue(':status', $status, SQLITE3_TEXT);
                $occurrenceResult = $occurrenceStmt->execute();
                $occurrenceRow = $occurrenceResult === false ? false : $occurrenceResult->fetchArray(SQLITE3_NUM);
                $occurrence = (int)(is_array($occurrenceRow) ? ($occurrenceRow[0] ?? 1) : 1);

                $insert = $db->prepare(
                    'INSERT INTO conversions '
                    . '(clickid,campaign_id,flow,step,time,status,raw_status,source,tid,tid_parameter,payout,currency,is_initial,changes_status,status_occurrence) '
                    . 'VALUES (:clickid,:campaign,:flow,:step,:time,:status,:raw_status,:source,:tid,:tid_parameter,:payout,:currency,:initial,:changes_status,:occurrence)'
                );
                $insert->bindValue(':clickid', $clickid, SQLITE3_TEXT);
                $insert->bindValue(':campaign', $campaignId, SQLITE3_INTEGER);
                $insert->bindValue(':flow', (string)($click['flow'] ?? 'unknown'), SQLITE3_TEXT);
                $insert->bindValue(':step', max(0, (int)($click['step'] ?? 0)), SQLITE3_INTEGER);
                $insert->bindValue(':time', time(), SQLITE3_INTEGER);
                $insert->bindValue(':status', $status, SQLITE3_TEXT);
                $insert->bindValue(':raw_status', $rawStatus, SQLITE3_TEXT);
                $insert->bindValue(':source', $source, SQLITE3_TEXT);
                $insert->bindValue(':tid', $storedTid, $storedTid === null ? SQLITE3_NULL : SQLITE3_TEXT);
                $insert->bindValue(
                    ':tid_parameter',
                    $storedTidParameter,
                    $storedTidParameter === null ? SQLITE3_NULL : SQLITE3_TEXT
                );
                $insert->bindValue(':payout', $payout, SQLITE3_FLOAT);
                $insert->bindValue(':currency', $currency, SQLITE3_TEXT);
                $insert->bindValue(':initial', $initial ? 1 : 0, SQLITE3_INTEGER);
                $insert->bindValue(':changes_status', $changesStatus ? 1 : 0, SQLITE3_INTEGER);
                $insert->bindValue(':occurrence', $occurrence, SQLITE3_INTEGER);
                if ($insert->execute() === false) {
                    throw new RuntimeException('Failed to insert conversion: ' . $db->lastErrorMsg());
                }
                $conversionId = (int)$db->lastInsertRowID();

                $update = $db->prepare('UPDATE clicks SET status = :status, payout = COALESCE(payout, 0) + :payout WHERE clickid = :clickid');
                $update->bindValue(':status', $status, SQLITE3_TEXT);
                $update->bindValue(':payout', $payout, SQLITE3_FLOAT);
                $update->bindValue(':clickid', $clickid, SQLITE3_TEXT);
                if ($update->execute() === false || $db->changes() !== 1) {
                    throw new RuntimeException('Failed to update click conversion snapshot: ' . $db->lastErrorMsg());
                }

                $click['status'] = $status;
                $click['payout'] = (float)($click['payout'] ?? 0) + $payout;
                self::decode_click_row($click);
                return [
                    'accepted' => true,
                    'code' => 'accepted',
                    'message' => 'Conversion accepted.',
                    'conversion_id' => $conversionId,
                    'click' => $click,
                ];
            });
        } catch (Throwable $e) {
            add_log('errors', 'Conversion transaction failed: ' . $e->getMessage());
            $duplicate = str_contains(strtolower($e->getMessage()), 'unique');
            return [
                'accepted' => false,
                'code' => $duplicate ? 'duplicate_conversion' : 'storage_error',
                'message' => $duplicate ? 'Conversion was already recorded.' : 'Failed to record conversion.',
            ];
        }
    }

    public function count_conversions_for_cap(
        int $campaignId,
        ?string $flow,
        array $statuses,
        int $startTime,
        int $endTime,
        ?int $stopAfter = null
    ): int {
        $statuses = array_values(array_unique(array_filter(array_map('strval', $statuses))));
        if ($statuses === []) {
            return 0;
        }
        $binds = [[$campaignId, SQLITE3_INTEGER], [$startTime, SQLITE3_INTEGER], [$endTime, SQLITE3_INTEGER]];
        $statusSql = implode(',', array_fill(0, count($statuses), '?'));
        $sql = 'SELECT 1 FROM conversions WHERE campaign_id = ? AND time >= ? AND time < ? '
            . "AND status COLLATE NOCASE IN ($statusSql)";
        foreach ($statuses as $statusName) {
            $binds[] = [$statusName, SQLITE3_TEXT];
        }
        if ($flow !== null) {
            $sql .= ' AND flow = ?';
            $binds[] = [$flow, SQLITE3_TEXT];
        }
        if ($stopAfter !== null) {
            $sql .= ' LIMIT ?';
            $binds[] = [max(1, $stopAfter), SQLITE3_INTEGER];
        }
        $sql = 'SELECT COUNT(*) AS total FROM (' . $sql . ')';
        $row = $this->exec_read_query($sql, $binds, true);
        return (int)($row['total'] ?? 0);
    }

    public function get_conversion_status_history_count(int $campaignId, string $status): int
    {
        $row = $this->exec_read_query(
            'SELECT COUNT(*) AS total FROM conversions WHERE campaign_id = :campaign AND status = :status COLLATE NOCASE',
            [[$campaignId, SQLITE3_INTEGER], [$status, SQLITE3_TEXT]],
            true
        );
        return (int)($row['total'] ?? 0);
    }

    public function get_current_status_click_count(int $campaignId, string $status): int
    {
        $row = $this->exec_read_query(
            'SELECT COUNT(*) AS total FROM clicks WHERE campaign_id = :campaign AND status = :status COLLATE NOCASE',
            [[$campaignId, SQLITE3_INTEGER], [$status, SQLITE3_TEXT]],
            true
        );
        return (int)($row['total'] ?? 0);
    }

    public function update_click_params(int $clickId, array $params): bool
    {
        if (empty($clickId)) {
            add_log("warning", "Skipping params update - empty click ID provided");
            return false;
        }

        $paramsJson = json_encode($params);
        if ($paramsJson === false) {
            add_log("warning", "Failed to encode params to JSON for click ID: $clickId");
            return false;
        }

        $updateQuery = "UPDATE clicks SET params = :params WHERE id = :id";
        return $this->exec_update_query($updateQuery, [$paramsJson => SQLITE3_TEXT, $clickId => SQLITE3_INTEGER]);
    }

    public function update_click_fields(int $clickId, ?array $params = null, ?float $cost = null): bool
    {
        if ($clickId <= 0 || ($params === null && $cost === null)) {
            return false;
        }

        $sets = [];
        if ($params !== null) {
            $sets[] = 'params = :params';
        }
        if ($cost !== null) {
            $sets[] = 'cost = :cost';
        }

        try {
            $db = $this->open_db();
            $stmt = $db->prepare('UPDATE clicks SET ' . implode(', ', $sets) . ' WHERE id = :id');
            if ($stmt === false) {
                throw new Exception('Failed to prepare click field update: ' . $db->lastErrorMsg());
            }
            if ($params !== null) {
                $paramsJson = json_encode($params);
                if ($paramsJson === false) {
                    throw new Exception('Failed to encode params to JSON for click ID: ' . $clickId);
                }
                $stmt->bindValue(':params', $paramsJson, SQLITE3_TEXT);
            }
            if ($cost !== null) {
                $stmt->bindValue(':cost', $cost, SQLITE3_FLOAT);
            }
            $stmt->bindValue(':id', $clickId, SQLITE3_INTEGER);
            if ($stmt->execute() === false) {
                throw new Exception('Failed to update click fields for ID: ' . $clickId);
            }
            return true;
        } catch (Exception $e) {
            add_log('errors', $e->getMessage());
            return false;
        }
    }

    /**
     * @param array<string, string> $paramEquals
     * @return array{updated: int, cost_per_click: float}
     */
    public function distribute_click_cost(int $campaignId, int $startTs, int $endTs, float $amount, array $paramEquals = []): array
    {
        $where = 'campaign_id = :campaign_id AND time BETWEEN :start AND :end';
        $paramBinds = [];
        $index = 0;
        foreach ($paramEquals as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
                throw new InvalidArgumentException('Invalid cost filter parameter name.');
            }
            $placeholder = ':p' . $index;
            $where .= ' AND json_extract(params, \'$.' . $key . '\') = ' . $placeholder;
            $paramBinds[$placeholder] = (string)$value;
            $index++;
        }

        $bindCommon = static function (SQLite3Stmt $stmt) use ($campaignId, $startTs, $endTs, $paramBinds): void {
            $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_INTEGER);
            $stmt->bindValue(':start', $startTs, SQLITE3_INTEGER);
            $stmt->bindValue(':end', $endTs, SQLITE3_INTEGER);
            foreach ($paramBinds as $placeholder => $value) {
                $stmt->bindValue($placeholder, $value, SQLITE3_TEXT);
            }
        };

        $db = $this->open_db();
        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM clicks WHERE ' . $where);
        if ($countStmt === false) {
            throw new RuntimeException('Failed to prepare cost distribution count: ' . $db->lastErrorMsg());
        }
        $bindCommon($countStmt);
        $countResult = $countStmt->execute();
        $countRow = $countResult === false ? false : $countResult->fetchArray(SQLITE3_ASSOC);
        $updated = (int)(is_array($countRow) ? ($countRow['total'] ?? 0) : 0);
        if ($updated === 0) {
            return ['updated' => 0, 'cost_per_click' => 0.0];
        }

        $share = $amount / $updated;
        $updateStmt = $db->prepare('UPDATE clicks SET cost = :cost WHERE ' . $where);
        if ($updateStmt === false) {
            throw new RuntimeException('Failed to prepare cost distribution update: ' . $db->lastErrorMsg());
        }
        $updateStmt->bindValue(':cost', $share, SQLITE3_FLOAT);
        $bindCommon($updateStmt);
        if ($updateStmt->execute() === false) {
            throw new RuntimeException('Failed to distribute click cost: ' . $db->lastErrorMsg());
        }

        return ['updated' => $updated, 'cost_per_click' => $share];
    }

    /**
     * @return list<string>
     */
    public function get_click_param_keys(?int $campaignId, int $startTs, int $endTs): array
    {
        $sql = 'SELECT DISTINCT je.key AS key FROM clicks c, json_each(c.params) je '
            . 'WHERE c.time BETWEEN :start AND :end AND je.key <> \'\'';
        if ($campaignId !== null) {
            $sql .= ' AND c.campaign_id = :campaign_id';
        }
        $sql .= ' ORDER BY je.key ASC LIMIT 200';

        $db = $this->open_db(true);
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            add_log('errors', 'Failed to prepare param key query: ' . $db->lastErrorMsg());
            return [];
        }
        $stmt->bindValue(':start', $startTs, SQLITE3_INTEGER);
        $stmt->bindValue(':end', $endTs, SQLITE3_INTEGER);
        if ($campaignId !== null) {
            $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        if ($result === false) {
            add_log('errors', 'Failed to execute param key query: ' . $db->lastErrorMsg());
            return [];
        }
        $keys = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $key = (string)($row['key'] ?? '');
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * @return list<string>
     */
    public function get_distinct_click_param_values(int $campaignId, int $startTs, int $endTs, string $key): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $key) !== 1) {
            throw new InvalidArgumentException('Invalid token name.');
        }
        $path = '$.' . $key;
        $sql = 'SELECT DISTINCT json_extract(params, :path) AS value FROM clicks '
            . 'WHERE campaign_id = :campaign_id AND time BETWEEN :start AND :end '
            . 'AND json_extract(params, :path) IS NOT NULL AND json_extract(params, :path) <> \'\' '
            . 'ORDER BY value ASC LIMIT 5000';

        $db = $this->open_db(true);
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            add_log('errors', 'Failed to prepare param value query: ' . $db->lastErrorMsg());
            return [];
        }
        $stmt->bindValue(':path', $path, SQLITE3_TEXT);
        $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_INTEGER);
        $stmt->bindValue(':start', $startTs, SQLITE3_INTEGER);
        $stmt->bindValue(':end', $endTs, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if ($result === false) {
            add_log('errors', 'Failed to execute param value query: ' . $db->lastErrorMsg());
            return [];
        }
        $values = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $values[] = (string)($row['value'] ?? '');
        }
        return $values;
    }

    public function save_step_event(
        string $clickid,
        int $stepIndex,
        string $variant,
        string $eventName,
        int $elapsedMilliseconds
    ): string {
        if (
            $clickid === ''
            || $stepIndex < 0
            || $variant === ''
            || preg_match(self::EVENT_NAME_PATTERN, $eventName) !== 1
            || $elapsedMilliseconds < 0
            || $elapsedMilliseconds > self::MAX_EVENT_ELAPSED_MS
        ) {
            return self::STEP_EVENT_STORAGE_ERROR;
        }

        return $this->write_step_event_value(
            $clickid,
            $stepIndex,
            $variant,
            $eventName,
            $elapsedMilliseconds,
            null
        );
    }

    /** @param array<string, int|float> $performance */
    public function save_step_performance(
        string $clickid,
        int $stepIndex,
        string $variant,
        array $performance
    ): string {
        $performance = self::normalize_step_performance($performance);
        if ($clickid === '' || $stepIndex < 0 || $variant === '' || $performance === null) {
            return self::STEP_EVENT_STORAGE_ERROR;
        }

        return $this->write_step_event_value(
            $clickid,
            $stepIndex,
            $variant,
            null,
            null,
            $performance
        );
    }

    /** @return array<string, int|float>|null */
    private static function normalize_step_performance(array $performance): ?array
    {
        if ($performance === [] || count($performance) > count(self::PERFORMANCE_METRICS)) {
            return null;
        }

        $normalized = [];
        foreach (self::PERFORMANCE_METRICS as $metric) {
            if (!array_key_exists($metric, $performance)) {
                continue;
            }
            $value = $performance[$metric];
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
                return null;
            }
            if ($metric === 'cls') {
                $value = round((float)$value, 4);
                if ($value < 0 || $value > 100) {
                    return null;
                }
                $normalized[$metric] = $value;
                continue;
            }
            $value = (int)round((float)$value);
            if ($value < 0 || $value > self::MAX_PERFORMANCE_TIMING_MS) {
                return null;
            }
            $normalized[$metric] = $value;
        }

        return count($normalized) === count($performance) ? $normalized : null;
    }

    /**
     * Ordinary values and the performance packet are written directly by
     * SQLite JSON functions. The WHERE condition makes every key immutable
     * after its first successful write, including concurrent retries.
     *
     * @param array<string, int|float>|null $performance
     */
    private function write_step_event_value(
        string $clickid,
        int $stepIndex,
        string $variant,
        ?string $eventName,
        ?int $elapsedMilliseconds,
        ?array $performance
    ): string {
        try {
            $readDb = $this->open_db(true);
            $targetStmt = $readDb->prepare(
                'SELECT cs.id, cs.events, json_valid(cs.events) AS events_valid, '
                . 'json_type(cs.events) AS events_type, cmp.settings '
                . 'FROM click_steps cs '
                . 'INNER JOIN clicks c ON c.clickid = cs.clickid '
                . 'INNER JOIN campaigns cmp ON cmp.id = c.campaign_id '
                . 'WHERE cs.clickid = :clickid AND cs.step = :step AND cs.variant = :variant '
                . 'LIMIT 1'
            );
            if ($targetStmt === false) {
                throw new RuntimeException('Failed to prepare click-step lookup: ' . $readDb->lastErrorMsg());
            }
            $targetStmt->bindValue(':clickid', $clickid, SQLITE3_TEXT);
            $targetStmt->bindValue(':step', $stepIndex, SQLITE3_INTEGER);
            $targetStmt->bindValue(':variant', $variant, SQLITE3_TEXT);
            $targetResult = $targetStmt->execute();
            if ($targetResult === false) {
                throw new RuntimeException('Failed to read click-step events: ' . $readDb->lastErrorMsg());
            }
            $target = $targetResult->fetchArray(SQLITE3_ASSOC);
            $targetResult->finalize();
            $targetStmt->close();
            if (!is_array($target)) {
                return self::STEP_EVENT_NOT_FOUND;
            }
            if (
                (int)($target['events_valid'] ?? 0) !== 1
                || ($target['events_type'] ?? null) !== 'object'
            ) {
                throw new RuntimeException('Click-step events must contain a JSON object');
            }

            try {
                $storedEvents = json_decode(
                    (string)$target['events'],
                    true,
                    32,
                    JSON_THROW_ON_ERROR
                );
                $campaignSettings = json_decode(
                    (string)($target['settings'] ?? ''),
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $e) {
                throw new RuntimeException('Invalid event or campaign JSON', 0, $e);
            }
            if (!is_array($storedEvents) || !is_array($campaignSettings)) {
                throw new RuntimeException('Event and campaign JSON must contain objects');
            }
            $configuredEvents = self::campaign_event_settings($campaignSettings);

            if ($eventName !== null) {
                if (!$configuredEvents->accepts($eventName)) {
                    return self::STEP_EVENT_NOT_ALLOWED;
                }
                if (array_key_exists($eventName, $storedEvents)) {
                    return self::STEP_EVENT_SAVED;
                }
                $writeDb = $this->open_db();
                $jsonPath = '$.' . $eventName;
                $updateStmt = $writeDb->prepare(
                    'UPDATE click_steps '
                    . 'SET events = json_set(events, :path, :value) '
                    . 'WHERE id = :id AND json_type(events, :path) IS NULL'
                );
                if ($updateStmt === false) {
                    throw new RuntimeException('Failed to prepare ordinary event write: ' . $writeDb->lastErrorMsg());
                }
                $updateStmt->bindValue(':path', $jsonPath, SQLITE3_TEXT);
                $updateStmt->bindValue(':value', $elapsedMilliseconds, SQLITE3_INTEGER);
            } else {
                if (!$configuredEvents->performanceTrackingUse) {
                    return self::STEP_EVENT_NOT_ALLOWED;
                }
                if (array_key_exists('performance', $storedEvents)) {
                    return self::STEP_EVENT_SAVED;
                }
                $writeDb = $this->open_db();
                $performanceJson = json_encode(
                    $performance,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if ($performanceJson === false) {
                    throw new RuntimeException('Failed to encode performance packet');
                }
                $updateStmt = $writeDb->prepare(
                    "UPDATE click_steps "
                    . "SET events = json_set(events, '$.performance', json(:value)) "
                    . "WHERE id = :id AND json_type(events, '$.performance') IS NULL"
                );
                if ($updateStmt === false) {
                    throw new RuntimeException('Failed to prepare performance write: ' . $writeDb->lastErrorMsg());
                }
                $updateStmt->bindValue(':value', $performanceJson, SQLITE3_TEXT);
            }

            $updateStmt->bindValue(':id', (int)$target['id'], SQLITE3_INTEGER);
            $updateResult = $updateStmt->execute();
            if ($updateResult === false) {
                throw new RuntimeException('Failed to write click-step events: ' . $writeDb->lastErrorMsg());
            }
            $created = $writeDb->changes() === 1;
            $updateResult->finalize();
            $updateStmt->close();

            // A concurrent request may have won the guarded update. That is a
            // successful idempotent retry, but only the atomic winner may
            // trigger side effects such as an outbound S2S postback.
            return $created ? self::STEP_EVENT_CREATED : self::STEP_EVENT_SAVED;
        } catch (Throwable $e) {
            add_log('errors', 'Failed to save click-step event: ' . $e->getMessage());
            return self::STEP_EVENT_STORAGE_ERROR;
        }
    }

    private static function campaign_event_settings(array $campaignSettings): EventSettings
    {
        if (!class_exists(EventSettings::class, false)) {
            require_once __DIR__ . '/../campaign.php';
        }
        return EventSettings::fromArray($campaignSettings['events'] ?? []);
    }

    public function get_funnel_stats(int $campId, string $flowName, string $status): array
    {
        $query = "SELECT path, COUNT(*) AS impressions, COUNT(CASE WHEN status = :status THEN 1 END) AS conversions FROM clicks WHERE campaign_id = :cid AND flow = :flow GROUP BY path";
        return $this->exec_read_query($query, [[$status, SQLITE3_TEXT], [$campId, SQLITE3_INTEGER], [$flowName, SQLITE3_TEXT]]);
    }

    public function get_variant_stats(int $campId, string $flowName, int $stepIndex, string $status): array
    {
        $query = "
            SELECT
                cs.variant AS variant,
                COUNT(*) AS impressions,
                COUNT(CASE WHEN c.status = :status THEN 1 END) AS conversions
            FROM click_steps cs
            INNER JOIN clicks c ON c.clickid = cs.clickid
            WHERE c.campaign_id = :cid AND c.flow = :flow AND cs.step = :step
            GROUP BY cs.variant
        ";
        return $this->exec_read_query($query, [
            [$status, SQLITE3_TEXT],
            [$campId, SQLITE3_INTEGER],
            [$flowName, SQLITE3_TEXT],
            [$stepIndex, SQLITE3_INTEGER],
        ]);
    }

    private function clickid_exists(string $clickid): bool
    {
        if (empty($clickid)) {
            add_log("warning", "Empty clickid provided for existence check");
            return false;
        }
        $query = "SELECT COUNT(*) AS count FROM clicks WHERE clickid = :clickid";
        $res = $this->exec_read_query($query, [[$clickid, SQLITE3_TEXT]], true);
        return $res['count'] > 0;
    }

    private function prepare_click_data($data, $campId = null): array
    {
        $data["time"] = (new DateTime())->getTimestamp();
        if (!is_null($campId))
            $data["campaign_id"] = $campId;

        $query = [];
        if (!empty($_SERVER['QUERY_STRING'])) {
            parse_str($_SERVER['QUERY_STRING'], $query);
        }

        if (array_key_exists("cpc", $query)) {
            $data["cpc"] = $query["cpc"];
            unset($query["cpc"]);
        }

        $data["params"] = json_encode($query);
        return $data;
    }

    public function add_campaign($name, bool $refreshRuntime = true): bool|int
    {
        global $cloSettings;
        $query = "INSERT INTO campaigns (name, settings) VALUES (:name, :settings)";

        $settingsJson = file_get_contents(__DIR__ . '/default.json');
        $settings = json_decode($settingsJson, true);
        $settings['apikey'] = $this->generate_api_key();
        $settings['statistics']['timezone'] = (string)($cloSettings['timezone'] ?? 'Europe/Moscow');
        $settingsJson = json_encode($settings);
        $campaignId = $this->exec_write_query($query, [$name => SQLITE3_TEXT, $settingsJson => SQLITE3_TEXT], true);
        if (is_int($campaignId) && $refreshRuntime) {
            $this->rebuild_runtime_cache();
        }
        return $campaignId;
    }

    private function generate_api_key(): string
    {
        return sprintf(
            '%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535)
        );
    }

    public function get_campaign_by_apikey(string $apikey): array
    {
        $query = "SELECT * FROM campaigns WHERE settings->>'apikey' = :apikey";
        $camp = $this->exec_read_query($query, [[$apikey, SQLITE3_TEXT]], true);
        if (isset($camp['settings'])) {
            $camp['settings'] = json_decode($camp['settings'], true);
        }
        return $camp;
    }

    public function clone_campaign($id, bool $refreshRuntime = true): bool|int
    {
        $source = $this->exec_read_query(
            'SELECT name, settings FROM campaigns WHERE id = :id',
            [[$id, SQLITE3_INTEGER]],
            true
        );
        $settings = json_decode((string)($source['settings'] ?? ''), true);
        if (!is_array($settings)) {
            return false;
        }
        $settings['domains'] = [];
        if (is_array($settings['white']['domainfilter'] ?? null)) {
            $settings['white']['domainfilter']['use'] = false;
            $settings['white']['domainfilter']['domains'] = [];
        }
        $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($settingsJson === false) {
            return false;
        }
        $campaignId = $this->exec_write_query(
            'INSERT INTO campaigns (name, settings) VALUES (:name, :settings)',
            [((string)($source['name'] ?? '') . ' (Clone)') => SQLITE3_TEXT, $settingsJson => SQLITE3_TEXT],
            true
        );
        if (is_int($campaignId) && $refreshRuntime) {
            $this->rebuild_runtime_cache();
        }
        return $campaignId;
    }

    public function get_campaign_name(int $id): string
    {
        $query = "SELECT name FROM campaigns WHERE id = :id";
        $arr = $this->exec_read_query($query, [[$id, SQLITE3_INTEGER]], true);
        return $arr['name'] ?? '';
    }

    public function get_campaigns_list(): array
    {
        $query = "SELECT id, name FROM campaigns ORDER BY name COLLATE NOCASE ASC";
        return $this->exec_read_query($query, []);
    }

    public function get_campaign_settings(int $id): array
    {
        $query = "SELECT settings FROM campaigns WHERE id = :id";
        $arr = $this->exec_read_query($query, [[$id, SQLITE3_INTEGER]], true);
        $settings = json_decode($arr['settings'], true);
        return $settings;
    }

    /** @return array<int, array{id: int, name: string, settings: array<string, mixed>}> */
    public function get_campaign_runtime_rows(): array
    {
        $rows = $this->exec_read_query('SELECT id, name, settings FROM campaigns ORDER BY id ASC', []);
        foreach ($rows as &$row) {
            $settings = json_decode((string)($row['settings'] ?? ''), true);
            if (!is_array($settings)) {
                throw new RuntimeException('Campaign ' . (int)($row['id'] ?? 0) . ' has invalid settings JSON.');
            }
            $row['id'] = (int)$row['id'];
            $row['name'] = (string)$row['name'];
            $row['settings'] = $settings;
        }
        unset($row);
        return $rows;
    }

    public function rebuild_runtime_cache(): bool
    {
        try {
            RuntimeCampaignCache::rebuild($this);
            return true;
        } catch (Throwable $e) {
            RuntimeCampaignCache::invalidateDomainsSnapshot();
            add_log('errors', 'Runtime campaign cache rebuild failed: ' . $e->getMessage());
            return false;
        }
    }

    public function get_campaign_by_domain(): array|bool
    {
        $domain = get_request_host();
        $cached = RuntimeCampaignCache::findCampaign($domain);
        if ($cached['available']) {
            if (is_array($cached['campaign'])) {
                add_log('trace', 'Found cached campaign for domain ' . $domain . ': ' . $cached['campaign']['id']);
            }
            return $cached['campaign'];
        }

        $query = "SELECT * FROM campaigns";
        $campaigns = $this->exec_read_query($query, []);
        foreach ($campaigns as $campaign) {
            if (empty($campaign['settings'])) {
                continue;
            }
            $settings = json_decode($campaign['settings'], true);
            if (!isset($settings['domains'])) {
                continue;
            }
            if ($this->match_domain($settings['domains'], $domain)) {
                add_log("trace", "Found matching campaign for domain $domain: " . $campaign['id']);
                $campaign['settings'] = $settings;
                return $campaign;
            }
        }
        return false;
    }

    private function match_domain($domains, $domainToMatch): bool
    {
        try {
            return RuntimeCampaignCache::matches(is_array($domains) ? $domains : [], (string)$domainToMatch);
        } catch (CampaignDomainException $e) {
            add_log('errors', 'Invalid campaign domain configuration: ' . $e->getMessage());
            return false;
        }
    }

    public function rename_campaign(int $id, string $name, bool $refreshRuntime = true): bool
    {
        $query = "UPDATE campaigns SET name = :name WHERE id = :id";
        $saved = $this->exec_write_query($query, [$name => SQLITE3_TEXT, $id => SQLITE3_INTEGER]);
        if ($saved && $refreshRuntime) {
            $this->rebuild_runtime_cache();
        }
        return $saved;
    }

    public function save_campaign_settings(
        int $id,
        array $settings,
        bool $refreshRuntime = true,
        bool $validateDomains = true
    ): bool
    {
        $settings = RuntimeCampaignCache::normalizeSettingsDomains($settings);
        if ($validateDomains) {
            RuntimeCampaignCache::validateCandidate($this, $id, $settings);
        }
        $query = "UPDATE campaigns SET settings = :settings WHERE id = :id";
        $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($settingsJson === false) {
            return false;
        }
        $saved = $this->exec_write_query($query, [$settingsJson => SQLITE3_TEXT, $id => SQLITE3_INTEGER]);
        if ($saved && $refreshRuntime) {
            $this->rebuild_runtime_cache();
        }
        return $saved;
    }


    public function delete_campaign(int $id, bool $refreshRuntime = true): bool
    {
        $query = "DELETE FROM campaigns WHERE id = :id";
        $deleted = $this->exec_write_query($query, [$id => SQLITE3_INTEGER]);
        if ($deleted && $refreshRuntime) {
            $this->rebuild_runtime_cache();
        }
        return $deleted;
    }

    public function get_campaigns($startDate, $endDate, array $selectFields, array $filters = []): array
    {
        global $cloSettings;
        $bindList = [];
        $bindList[] = [$startDate, SQLITE3_INTEGER];
        $bindList[] = [$endDate, SQLITE3_INTEGER];

        $filterJoin = '';
        if (!empty($filters) && !empty($filters['rules']) && is_array($filters['rules'])) {
            $filterParts = [];
            foreach ($filters['rules'] as $rule) {
                $field = $rule['field'] ?? '';
                $op = $rule['operator'] ?? '';
                $value = $rule['value'] ?? '';

                $sqlField = self::resolveFilterField($field);
                if ($sqlField === null || !in_array($op, self::FILTER_OPERATORS)) continue;

                if (str_starts_with($sqlField, "json_extract(")) {
                    $sqlField = str_replace("json_extract(params,", "json_extract(s.params,", $sqlField);
                } else {
                    $sqlField = "s.$sqlField";
                }

                switch ($op) {
                    case '=':
                        $filterParts[] = "$sqlField = ?";
                        $bindList[] = [$value, SQLITE3_TEXT];
                        break;
                    case '!=':
                        $filterParts[] = "$sqlField != ?";
                        $bindList[] = [$value, SQLITE3_TEXT];
                        break;
                    case 'in':
                        $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                        $filterParts[] = "$sqlField IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                        foreach ($vals as $v) $bindList[] = [$v, SQLITE3_TEXT];
                        break;
                    case 'not_in':
                        $vals = is_array($value) ? $value : array_map('trim', explode(',', $value));
                        $filterParts[] = "$sqlField NOT IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                        foreach ($vals as $v) $bindList[] = [$v, SQLITE3_TEXT];
                        break;
                    case 'is_null':
                        $filterParts[] = "($sqlField IS NULL OR $sqlField = '')";
                        break;
                    case 'is_not_null':
                        $filterParts[] = "($sqlField IS NOT NULL AND $sqlField != '')";
                        break;
                }
            }
            $condition = ($filters['condition'] ?? 'AND') === 'OR' ? ' OR ' : ' AND ';
            if (!empty($filterParts)) {
                $filterJoin = ' AND (' . implode($condition, $filterParts) . ')';
            }
        }

        $conversionTime = ($cloSettings['conversionAttribution'] ?? 'click_time') === 'conversion_time';
        $conversionStatTime = $conversionTime ? 'cv.time' : 'c.time';
        $clickCurrentStatus = $conversionTime ? 'NULL' : 'c.status';
        $clickCurrentFlag = $conversionTime ? '0' : "CASE WHEN c.status IS NOT NULL AND c.status <> '' THEN 1 ELSE 0 END";
        $conversionCurrentFlag = $conversionTime
            ? "CASE WHEN cv.changes_status = 1 AND cv.id = (SELECT cv2.id FROM conversions cv2 WHERE cv2.clickid = cv.clickid AND cv2.changes_status = 1 ORDER BY cv2.time DESC, cv2.id DESC LIMIT 1) THEN 1 ELSE 0 END"
            : '0';
        $selectParts = $this->get_stats_source_select_parts($selectFields, []);
        $selectClause = $selectParts === [] ? 'COALESCE(SUM(is_click), 0) AS clicks' : implode(',', $selectParts);
        $query = "WITH stats_source AS (
            SELECT c.campaign_id, c.country, c.lang, c.os, c.osver, c.brand, c.model, c.device,
                   c.isp, c.client, c.clientver, c.flow, c.step, c.path, c.status, c.params,
                   c.unique_flags, c.clickid, c.time AS stat_time, 1 AS is_click, 0 AS is_conversion,
                   0 AS is_initial, c.cost AS click_cost, 0.0 AS conversion_payout,
                   {$clickCurrentStatus} AS metric_status, 0 AS status_occurrence,
                   {$clickCurrentFlag} AS current_status_event
            FROM clicks c
            UNION ALL
            SELECT c.campaign_id, c.country, c.lang, c.os, c.osver, c.brand, c.model, c.device,
                   c.isp, c.client, c.clientver, c.flow, c.step, c.path, c.status, c.params,
                   NULL AS unique_flags, c.clickid, {$conversionStatTime} AS stat_time, 0 AS is_click, 1 AS is_conversion,
                   cv.is_initial, 0.0 AS click_cost, cv.payout AS conversion_payout,
                   cv.status AS metric_status, cv.status_occurrence,
                   {$conversionCurrentFlag} AS current_status_event
            FROM conversions cv INNER JOIN clicks c ON c.clickid = cv.clickid
        )
        SELECT cmp.id, cmp.name, $selectClause
        FROM campaigns cmp
        LEFT JOIN stats_source s ON s.campaign_id=cmp.id AND s.stat_time BETWEEN ? AND ?$filterJoin
        GROUP BY cmp.id";

        $campaigns = $this->exec_read_query($query, $bindList);
        foreach ($campaigns as &$campaign) {
            if (empty($campaign['settings']))
                continue;
            $campaign['settings'] = json_decode($campaign['settings'], true);
        }
        return $campaigns;
    }

    public function get_common_settings(): array
    {
        $query = "SELECT settings FROM common";

        $arr = $this->exec_read_query($query, [], true);
        $settings = json_decode($arr['settings'], true);
        if ($settings === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse common settings JSON: " . json_last_error_msg());
        }
        return $settings;
    }

    public function set_common_settings(array $s): bool
    {
        $query = "UPDATE common SET settings=:settings";
        $settingsJson = json_encode($s);
        if ($settingsJson === false) {
            throw new Exception("Failed to encode settings to JSON: " . json_last_error_msg());
        }
        return $this->exec_write_query($query, [$settingsJson => SQLITE3_TEXT]);
    }


    private function exec_write_query(string $query, array $p, bool $returnId = false): bool|int
    {
        try {
            $db = $this->open_db();
            $db->exec('BEGIN IMMEDIATE');
            $stmt = $db->prepare($query);

            if ($stmt === false) {
                throw new Exception("Error preparing $query: " . $db->lastErrorMsg());
            }

            $keys = array_keys($p);
            foreach ($keys as $index => $key) {
                $bound = $stmt->bindValue($index + 1, $key, $p[$key]);
                if ($bound === false) {
                    throw new Exception("Error binding $key to $query: " . $db->lastErrorMsg());
                }
            }

            $result = $stmt->execute();

            if ($result === false) {
                throw new Exception("Error executing $query: " . $db->lastErrorMsg());
            }

            $db->exec('COMMIT');
            add_log("trace", "Successfully executed $query");
            return $returnId ? $db->lastInsertRowID() : true;
        } catch (Exception $e) {
            $this->writeDb?->exec('ROLLBACK');
            add_log("errors", $e->getMessage());
            return false;
        }
    }

    private function exec_update_query(string $query, array $p): bool
    {
        try {
            $db = $this->open_db();
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                throw new Exception("Failed to prepare $query: " . $db->lastErrorMsg());
            }

            $keys = array_keys($p);
            foreach ($keys as $index => $key) {
                $bound = $stmt->bindValue($index + 1, $key, $p[$key]);
                if ($bound === false) {
                    throw new Exception("Failed to bind $key to $query: " . $db->lastErrorMsg());
                }
            }

            $result = $stmt->execute();
            if ($result === false) {
                throw new Exception("Failed to execute $query: " . $db->lastErrorMsg());
            }

            if ($db->changes() === 0) {
                add_log("errors", "No rows affected when $query");
                return false;
            }
            return true;
        } catch (Exception $e) {
            add_log("errors", $e->getMessage());
            return false;
        }
    }

    /**
     * @param array<int, array{0: mixed, 1: int}> $binds value/type pairs, bound in order
     * @return array<int|string, mixed>
     */
    private function exec_read_query(string $query, array $binds, bool $firstOnly = false): array
    {
        try {
            $db = $this->open_db(true);
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                throw new Exception("Error preparing $query: " . $db->lastErrorMsg());
            }

            foreach ($binds as $index => $pair) {
                $bound = $stmt->bindValue($index + 1, $pair[0], $pair[1]);
                if ($bound === false) {
                    throw new Exception("Error binding param " . ($index + 1) . " to $query: " . $db->lastErrorMsg());
                }
            }

            $result = $stmt->execute();
            if ($result === false) {
                throw new Exception("Error executing $query: " . $db->lastErrorMsg());
            }

            $arr = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $arr[] = $row;
            }
            return $firstOnly ? $arr[0] ?? [] : $arr;
        } catch (Exception $e) {
            add_error_log($e->getMessage());
            return [];
        }
    }

}

$db = new Db();
