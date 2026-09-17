<?php
require_once __DIR__ . '/cookies.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/campaign.php';

class AbTest
{
    private Campaign $campaign;
    public function __construct(Campaign $campaign)
    {
        $this->campaign = $campaign;
    }

    public function select_item(array $items, string $item_type, bool $is_folder): array
    {
        return $this->select_distributed($items, $item_type, $is_folder, 'equal', []);
    }

    private function get_saved_item(string $item_type, array $items, bool $is_folder): string
    {
        $item = get_cookie($item_type);

        if (!in_array($item, $items, true) || ($is_folder && !$this->is_folder_valid($item))) {
            return '';
        }

        return $item;
    }

    private function is_folder_valid(string $item): bool
    {
        return is_dir(__DIR__ . '/' . $item);
    }

    private function get_random_item(array $items): string
    {
        $random_index = rand(0, count($items) - 1);
        $item = $items[$random_index];
        return $item;
    }

    private function get_weighted_item(array $items, array $weights): string
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return $this->get_random_item($items);
        }
        $rand = mt_rand(1, (int)$total);
        $cumulative = 0;
        for ($i = 0; $i < count($items); $i++) {
            $cumulative += $weights[$i] ?? 0;
            if ($rand <= $cumulative) {
                return $items[$i];
            }
        }
        return $items[count($items) - 1];
    }

    public function select_distributed(array $items, string $item_type, bool $is_folder, string $distribution, array $weights): array
    {
        if (empty($items))
            add_error_log("No items found for $item_type in campaign {$this->campaign->campaignId}!", false, true);

        $item = '';

        if ($this->campaign->saveUserFlow && !str_starts_with($item_type, 'step_')) {
            $item = $this->get_saved_item($item_type, $items, $is_folder);
        }

        if ($item === '') {
            $item = ($distribution === 'weighted' && !empty($weights))
                ? $this->get_weighted_item($items, $weights)
                : $this->get_random_item($items);
        }

        return [$item, array_search($item, $items)];
    }

    /**
     * Thompson Sampling: pick best combination across all steps (funnel mode).
     * @param array $allStepItems [stepIndex => [item1, item2, ...], ...]
     * @return array planned path [chosenItem0, chosenItem1, ...]
     */
    public function select_thompson_funnel_multi(array $allStepItems, string $flowName, string $status): array
    {
        global $db;
        $stats = $db->get_funnel_stats($this->campaign->campaignId, $flowName, $status);

        // Build statsMap keyed by path JSON string
        $statsMap = [];
        foreach ($stats as $row) {
            $key = $row['path'];
            $statsMap[$key] = ['imp' => (int)$row['impressions'], 'conv' => (int)$row['conversions']];
        }

        // Generate all combinations using cartesian product
        $combos = [[]];
        foreach ($allStepItems as $stepItems) {
            $newCombos = [];
            foreach ($combos as $combo) {
                foreach ($stepItems as $item) {
                    $newCombos[] = array_merge($combo, [$item]);
                }
            }
            $combos = $newCombos;
        }

        $bestScore = -1;
        $bestCombo = $combos[0] ?? [];

        foreach ($combos as $combo) {
            $key = json_encode($combo);
            $imp = $statsMap[$key]['imp'] ?? 0;
            $conv = $statsMap[$key]['conv'] ?? 0;
            $score = self::random_beta($conv + 1, $imp - $conv + 1);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCombo = $combo;
            }
        }

        return $bestCombo;
    }

    /**
     * Thompson Sampling: pick best single variant (separate mode).
     * @param int $stepIndex step index
     * @return string winning variant name
     */
    public function select_thompson_variant(array $items, int $stepIndex, string $flowName, string $status): string
    {
        if (empty($items)) return '';

        global $db;
        $stats = $db->get_variant_stats($this->campaign->campaignId, $flowName, $stepIndex, $status);

        $statsMap = [];
        foreach ($stats as $row) {
            $statsMap[$row['variant']] = ['imp' => (int)$row['impressions'], 'conv' => (int)$row['conversions']];
        }

        $bestScore = -1;
        $bestItem = $items[0];

        foreach ($items as $item) {
            $imp = $statsMap[$item]['imp'] ?? 0;
            $conv = $statsMap[$item]['conv'] ?? 0;
            $score = self::random_beta($conv + 1, $imp - $conv + 1);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestItem = $item;
            }
        }

        return $bestItem;
    }

    /**
     * Compute win probability for each variant via Monte Carlo simulation.
     * @param array $statsMap ['variantName' => ['imp' => N, 'conv' => N], ...]
     * @param int $numSamples number of simulations
     * @return array ['variantName' => probabilityPercent, ...] sorted desc
     */
    public static function compute_win_probabilities(array $statsMap, int $numSamples = 5000): array
    {
        if (count($statsMap) < 2) return [];

        $winCount = array_fill_keys(array_keys($statsMap), 0);

        for ($i = 0; $i < $numSamples; $i++) {
            $bestScore = -1;
            $bestKey = null;
            foreach ($statsMap as $key => $s) {
                $imp = $s['imp'] ?? 0;
                $conv = $s['conv'] ?? 0;
                $score = self::random_beta($conv + 1, $imp - $conv + 1);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestKey = $key;
                }
            }
            if ($bestKey !== null) {
                $winCount[$bestKey]++;
            }
        }

        $result = [];
        foreach ($winCount as $key => $wins) {
            $result[$key] = round($wins / $numSamples * 100);
        }
        arsort($result);
        return $result;
    }

    // ── Beta distribution sampling (Marsaglia & Tsang) ──

    public static function random_beta(float $alpha, float $beta): float
    {
        $a = self::gamma_sample($alpha);
        $b = self::gamma_sample($beta);
        return $a / ($a + $b);
    }

    private static function gamma_sample(float $shape): float
    {
        if ($shape < 1.0) {
            $shape += 1.0;
            $u = mt_rand() / mt_getrandmax();
            return self::gamma_sample($shape) * pow($u, 1.0 / ($shape - 1));
        }

        $d = $shape - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);

        while (true) {
            do {
                $x = self::gaussian();
                $v = 1.0 + $c * $x;
            } while ($v <= 0);

            $v = $v * $v * $v;
            $u = mt_rand() / mt_getrandmax();

            if ($u < 1.0 - 0.0331 * ($x * $x) * ($x * $x)) {
                return $d * $v;
            }

            if (log($u) < 0.5 * $x * $x + $d * (1 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    private static function gaussian(): float
    {
        static $z1 = null, $hasSpare = false;

        if ($hasSpare) {
            $hasSpare = false;
            return $z1;
        }

        $hasSpare = true;

        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();

        $r = sqrt(-2.0 * log($u1));
        $theta = 2.0 * M_PI * $u2;

        $z0 = $r * cos($theta);
        $z1 = $r * sin($theta);

        return $z0;
    }

}
