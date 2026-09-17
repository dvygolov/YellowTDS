<?php
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/clmns.php';

class Tabulator
{
    private static function exact_total_bottom_calc(string $field): string
    {
        $escapedField = addcslashes($field, "'\\");
        return 'FSTARTfunction(values,data){var holder=(data||[]).find(function(row){return row&&row._stats_totals;});var totals=(holder&&holder._stats_totals)||{};if(!Object.prototype.hasOwnProperty.call(totals,\'' . $escapedField . '\'))return 0;return totals[\'' . $escapedField . '\']===null?\'—\':totals[\'' . $escapedField . '\'];}FEND';
    }

    private static function custom_metric_formatter(array $column): array
    {
        $format = $column['format'] ?? 'number';
        $params = [
            'decimal' => '.',
            'thousand' => ',',
            'precision' => max(0, (int)($column['decimals'] ?? 2)),
        ];
        if ($format === 'percent') {
            $params['symbol'] = '%';
            $params['symbolAfter'] = true;
        }

        return [
            'formatter' => 'money',
            'formatterParams' => $params,
            'bottomCalcFormatter' => 'money',
            'bottomCalcFormatterParams' => $params,
        ];
    }

    private static function build_custom_metric_column(array $column, bool $useExactTotals): array
    {
        $field = $column['field'];
        $title = $column['title'] ?? $field;
        $escapedField = addcslashes($field, "'\\");
        $tabulatorColumn = [
            'title' => $title,
            'field' => $field,
            'sorter' => 'number',
            'hozAlign' => 'right',
            'bottomCalc' => $useExactTotals
                ? 'FSTARTfunction(values,data){var holder=(data||[]).find(function(row){return row&&row._stats_totals;});var totals=(holder&&holder._stats_totals)||{};var result=((totals[\'' . $escapedField . '\'] ?? 0) * 1);return isFinite(result)?result:0;}FEND'
                : 'sum',
        ];

        return array_merge($tabulatorColumn, self::custom_metric_formatter($column));
    }

    private static function build_status_metric_column(array $column, bool $useExactTotals): array
    {
        $calculation = $column['calculation'] ?? 'current';
        $tooltip = match ($calculation) {
            'count' => 'Counts every accepted conversion row with this status. One clickid can be counted more than once.',
            'unique' => 'Counts distinct clickids that have at least one accepted row with this status.',
            'nth' => 'Counts clickids whose selected occurrence of this status falls inside the attribution period.',
            default => 'Counts clickids whose latest accepted status equals this status.',
        };
        return [
            'title' => $column['title'] ?? $column['field'],
            'field' => $column['field'],
            'headerTooltip' => $tooltip,
            'sorter' => 'number',
            'hozAlign' => 'right',
            'bottomCalc' => $useExactTotals
                ? self::exact_total_bottom_calc((string)$column['field'])
                : 'sum',
        ];
    }

    private static function build_event_metric_column(array $column, bool $useExactTotals): array
    {
        $field = (string)$column['field'];
        $title = (string)($column['title'] ?? $field);
        $parts = explode('.', $field);
        $isPerformance = ($parts[0] ?? '') === 'performance';
        $metric = $parts[1] ?? '';
        $aggregation = $parts[2] ?? 'count';
        if ($aggregation === 'count') {
            $decimals = 0;
        } elseif ($isPerformance && $metric === 'cls') {
            $decimals = 4;
        } elseif ($aggregation === 'avg') {
            $decimals = 2;
        } else {
            $decimals = 0;
        }

        $descriptions = [
            'ttfb' => 'Time to First Byte.',
            'fcp' => 'First Contentful Paint.',
            'lcp' => 'Largest Contentful Paint.',
            'inp' => 'Interaction to Next Paint. Missing when no measurable interaction occurred.',
            'cls' => 'Cumulative Layout Shift. Zero is a valid value.',
        ];
        $tooltip = $isPerformance
            ? (($descriptions[$metric] ?? strtoupper($metric)) . ' Aggregation: ' . strtoupper($aggregation) . '.')
            : ('Elapsed time from tracker start for ' . str_replace('_', ' ', $metric) . '. Aggregation: ' . strtoupper($aggregation) . '.');

        $escapedField = addcslashes($field, "'\\");
        $formatter = "FSTARTfunction(cell){var v=cell.getValue();if(v===null||v===undefined||v===''){return '—';}var n=Number(v);if(!isFinite(n)){return '—';}return n.toLocaleString(undefined,{minimumFractionDigits:" . $decimals . ',maximumFractionDigits:' . $decimals . '});}FEND';
        $bottomCalc = $useExactTotals ? self::exact_total_bottom_calc($field) : 'sum';

        return [
            'title' => $title,
            'field' => $field,
            'headerTooltip' => $tooltip,
            'sorter' => 'number',
            'hozAlign' => 'right',
            'formatter' => $formatter,
            'bottomCalc' => $bottomCalc,
            'bottomCalcFormatter' => $formatter,
        ];
    }

    public static function get_stats_columns(
        array $columns,
        ?string $groupByClmnTitle = null,
        array $groupByFields = [],
        bool $useExactTotals = true
    ): string
    {
        $columns = Db::normalize_stats_columns_config($columns);
        $columnSettings = TableColumns::$statsClmns;
        $tabulatorColumns = [];

        // Prepend the group column titled "Groups" — it holds the tree hierarchy
        if (!empty($groupByFields)) {
            $groupCol = $columnSettings['group'];
            $groupCol['title'] = 'Groups';
            $tabulatorColumns[] = $groupCol;
        }

        for ($i = 0; $i < count($columns); $i++) {
            $field = $columns[$i]['field'];
            // Skip the group column (already prepended) and groupby dimension columns
            if ($field === 'group' || in_array($field, $groupByFields))
                continue;
            $width = $columns[$i]['width'] ?? -1;
            if (array_key_exists($field, $columnSettings)) {
                $tabulatorColumn = $columnSettings[$field];
                if ($useExactTotals && $field !== 'group') {
                    $tabulatorColumn['bottomCalc'] = self::exact_total_bottom_calc($field);
                }
                $tabulatorColumns[] = $tabulatorColumn;
            } elseif (!empty($columns[$i]['custom'])) {
                $tabulatorColumns[] = self::build_custom_metric_column($columns[$i], $useExactTotals);
            } elseif (!empty($columns[$i]['status_metric'])) {
                $tabulatorColumns[] = self::build_status_metric_column($columns[$i], $useExactTotals);
            } elseif (preg_match('/^(?:event\.[a-z][a-z0-9_]{0,63}|performance\.(?:ttfb|fcp|lcp|inp|cls))\.(?:count|avg|p75|min|max)$/', $field) === 1) {
                $tabulatorColumns[] = self::build_event_metric_column($columns[$i], $useExactTotals);
            } else {
                $tabulatorColumns[] = ["title" => $field, "field" => $field];
            }
            if ($width === -1)
                continue;
            $tabulatorColumns[count($tabulatorColumns) - 1]["width"] = $width;
        }

        $clmnsJson = json_encode($tabulatorColumns, JSON_UNESCAPED_SLASHES);
        $clmnsJson = str_replace('"FSTART', '', $clmnsJson);
        $clmnsJson = str_replace('FEND"', '', $clmnsJson);
        return $clmnsJson;
    }


    public static function get_clicks_columns(?int $campId, string $timezone, array $columns): string
    {
        $columnSettings = TableColumns::$clickClmns;

        $defaultColumns =
        [
            "userid" => [
                "title" => "UserID",
                "field" => "userid",
                "headerTooltip" => "Persistent user identifier",
                "headerSort" => false,
                "editor" => false,
            ],
            "clickid" => [
                "title" => "ClickID",
                "field" => "clickid",
                "headerTooltip" => "Click identifier for full funnel pass",
                "headerSort" => false,
                "editor" => false,
            ],
            "time" => [
                "title" => "Time",
                "field" => "time",
                "formatter" => "datetime",
                "formatterParams" => [
                        "inputFormat" => "unix",
                        "outputFormat" => "yyyy-MM-dd HH:mm:ss",
                        "timezone" => "$timezone"
                    ],
                "headerTooltip" => "Date and time according to selected timezone",
                "sorter" => "datetime",
                "sorterParams" => [
                        "format" => "unix"
                ],
                "editor" => false
            ]
        ];

        $tabulatorColumns = [];
        for ($i = 0; $i < count($columns); $i++) {
            $clmn = $columns[$i]['field'];
            $width = $columns[$i]['width'] ?? -1;
            if (array_key_exists($clmn, $columnSettings)) {
                $tabulatorColumns[] = $columnSettings[$clmn];
            } else if (array_key_exists($clmn, $defaultColumns)) {
                $tabulatorColumns[] = $defaultColumns[$clmn];
            } elseif (str_starts_with($clmn, 'param.')) {
                $paramKey = substr($clmn, 6);
                $tabulatorColumns[] = [
                    "title" => "$paramKey\u{1F310}",
                    "field" => "param.$paramKey",
                    "editor" => false,
                    "headerFilter" => false,
                    "headerTooltip" => "URL parameter: $paramKey",
                ];
            } else {
                $tabulatorColumns[] = ["title" => $clmn, "field" => $clmn];
            }
            if ($width === -1)
                continue;
            $tabulatorColumns[count($tabulatorColumns) - 1]["width"] = $width;
        }
        $clmnsJson = json_encode($tabulatorColumns, JSON_UNESCAPED_SLASHES);
        $clmnsJson = str_replace('"FSTART', '', $clmnsJson);
        $clmnsJson = str_replace('FEND"', '', $clmnsJson);
        return $clmnsJson;
    }

    public static function get_campaigns_columns(array $columns): string
    {
        $nameWidth = 90;
        foreach ($columns as $col) {
            if (($col['field'] ?? '') === 'name' && isset($col['width']) && $col['width'] > 0)
                $nameWidth = $col['width'];
        }

        $defaultClmns = <<<JSON
    [
        {
            "title": "ID",
            "field": "id",
            "visible": false,
        },
        {
            "title": "Name",
            "formatter": function(cell) {
                const data = cell.getRow().getData();
                const id = data.id;
                const name = data.name;
                if (!id) return name || '';
                return `<div class="camp-name-cell">
                    <a href="statistics.php?campId=\${id}\${getStartDateEndDateParams()}" class="camp-name-link" title="Open statistics">\${name}</a>
                    <button class="camp-menu-btn" title="Actions"><i class="bi bi-three-dots-vertical"></i></button>
                </div>`;
            },
            "field": "name",
            "headerFilter": false,
            "width": $nameWidth,
            "editor":false,
            "cellClick": campNameCellClick,
            "bottomCalc":() => "TOTAL"
        },
JSON;

        $filteredColumns = array_values(array_filter($columns, fn($c) => !in_array($c['field'] ?? '', ['name', 'actions'])));
        $statColumns = Tabulator::get_stats_columns($filteredColumns, null, [], false);
        $defaultClmns .= substr($statColumns, 1);
        return $defaultClmns;
    }
}
