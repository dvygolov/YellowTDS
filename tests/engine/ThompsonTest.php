<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/../../code/abtest.php';

class ThompsonTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/yellowtds_thompson_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->db->seedCampaign(1, 'Test Campaign');

        // Make $db global so AbTest methods can access it
        $GLOBALS['db'] = $this->db;
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
        unset($GLOBALS['db']);
    }

    private function makeCampaign(): Campaign
    {
        $settings = json_decode(file_get_contents(__DIR__ . '/../../code/db/default.json'), true);
        return new Campaign(1, $settings);
    }

    public function testEqualSelectionPreservesStickyAndItemIndexes(): void
    {
        $campaign = $this->makeCampaign();
        $campaign->saveUserFlow = true;
        $abtest = new AbTest($campaign);
        $cookies = $_COOKIE;
        try {
            $_COOKIE['white'] = 'saved';
            self::assertSame(['saved', 1], $abtest->select_item(['other', 'saved'], 'white', false));
            self::assertSame(['only', 0], $abtest->select_item(['only'], 'white', false));

            $_COOKIE['white'] = 'db';
            self::assertSame(['db', 1], $abtest->select_item(['admin', 'db'], 'white', true));

            $campaign->saveUserFlow = false;
            mt_srand(17);
            $expected = $abtest->select_distributed(['admin', 'db'], 'white', true, 'equal', []);
            mt_srand(17);
            self::assertSame($expected, $abtest->select_item(['admin', 'db'], 'white', true));
        } finally {
            $_COOKIE = $cookies;
            mt_srand();
        }
    }

    // ── random_beta returns values in [0,1] ──

    public function testRandomBetaRange(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $val = AbTest::random_beta(1.0, 1.0);
            $this->assertGreaterThanOrEqual(0.0, $val);
            $this->assertLessThanOrEqual(1.0, $val);
        }
    }

    // ── Cold start = uniform random (Beta(1,1)) ──

    public function testColdStartIsRandom(): void
    {
        $c = $this->makeCampaign();
        $abtest = new AbTest($c);

        $counts = ['landA' => 0, 'landB' => 0];
        for ($i = 0; $i < 200; $i++) {
            $result = $abtest->select_thompson_variant(['landA', 'landB'], 0, 'Flow 1', 'Lead');
            $counts[$result]++;
        }

        // With no data, both should get picked (not all one)
        $this->assertGreaterThan(10, $counts['landA'], 'landA should be picked sometimes');
        $this->assertGreaterThan(10, $counts['landB'], 'landB should be picked sometimes');
    }

    // ── Thompson picks higher-CR variant more often ──

    public function testThompsonPrefersHigherCR(): void
    {
        // landA: 100 impressions, 50 conversions (50% CR)
        // landB: 100 impressions, 5 conversions (5% CR)
        $clicks = [];
        for ($i = 0; $i < 100; $i++) {
            $clicks[] = ['subid' => 'a' . $i, 'path' => '["landA"]', 'step' => 0, 'flow' => 'Flow 1', 'status' => $i < 50 ? 'Lead' : null];
        }
        for ($i = 0; $i < 100; $i++) {
            $clicks[] = ['subid' => 'b' . $i, 'path' => '["landB"]', 'step' => 0, 'flow' => 'Flow 1', 'status' => $i < 5 ? 'Lead' : null];
        }
        $this->db->seedClicks($clicks);

        $c = $this->makeCampaign();
        $abtest = new AbTest($c);

        $counts = ['landA' => 0, 'landB' => 0];
        for ($i = 0; $i < 200; $i++) {
            $result = $abtest->select_thompson_variant(['landA', 'landB'], 0, 'Flow 1', 'Lead');
            $counts[$result]++;
        }

        // landA (50% CR) should be picked much more often than landB (5% CR)
        $this->assertGreaterThan($counts['landB'], $counts['landA'], 'Higher CR variant should be preferred');
        $this->assertGreaterThan(150, $counts['landA'], 'landA should dominate with 50% vs 5% CR');
    }

    // ── optimize_for=Lead only counts Lead, not Purchase ──

    public function testOptimizeForLeadIgnoresPurchase(): void
    {
        // landA: 50 impressions, 0 Leads, 25 Purchases
        // landB: 50 impressions, 25 Leads, 0 Purchases
        $clicks = [];
        for ($i = 0; $i < 50; $i++) {
            $clicks[] = ['subid' => 'a' . $i, 'path' => '["landA"]', 'step' => 0, 'flow' => 'Flow 1', 'status' => $i < 25 ? 'Purchase' : null];
        }
        for ($i = 0; $i < 50; $i++) {
            $clicks[] = ['subid' => 'b' . $i, 'path' => '["landB"]', 'step' => 0, 'flow' => 'Flow 1', 'status' => $i < 25 ? 'Lead' : null];
        }
        $this->db->seedClicks($clicks);

        $c = $this->makeCampaign();
        $abtest = new AbTest($c);

        $counts = ['landA' => 0, 'landB' => 0];
        for ($i = 0; $i < 200; $i++) {
            $result = $abtest->select_thompson_variant(['landA', 'landB'], 0, 'Flow 1', 'Lead');
            $counts[$result]++;
        }

        // When optimizing for Lead, landB (50% Lead CR) should beat landA (0% Lead CR)
        $this->assertGreaterThan($counts['landA'], $counts['landB'], 'Lead-optimized should prefer landB');
    }

    // ── Funnel mode picks best combo (multi-step) ──

    public function testFunnelModePicksBestCombo(): void
    {
        // ["pre1","landA"]: 100 imp, 40 conv (40% CR) — best combo
        // ["pre1","landB"]: 100 imp, 5 conv (5% CR)
        // ["pre2","landA"]: 100 imp, 5 conv (5% CR)
        // ["pre2","landB"]: 100 imp, 5 conv (5% CR)
        $clicks = [];
        for ($i = 0; $i < 100; $i++) {
            $clicks[] = ['subid' => 'p1a' . $i, 'path' => '["pre1","landA"]', 'step' => 0, 'flow' => 'Flow 1', 'status' => $i < 40 ? 'Lead' : null];
        }
        for ($i = 0; $i < 100; $i++) {
            $clicks[] = ['subid' => 'p1b' . $i, 'path' => '["pre1","landB"]', 'step' => 0, 'flow' => 'Flow 1', 'status' => $i < 5 ? 'Lead' : null];
        }
        for ($i = 0; $i < 100; $i++) {
            $clicks[] = ['subid' => 'p2a' . $i, 'path' => '["pre2","landA"]', 'step' => 0, 'flow' => 'Flow 1', 'status' => $i < 5 ? 'Lead' : null];
        }
        for ($i = 0; $i < 100; $i++) {
            $clicks[] = ['subid' => 'p2b' . $i, 'path' => '["pre2","landB"]', 'step' => 0, 'flow' => 'Flow 1', 'status' => $i < 5 ? 'Lead' : null];
        }
        $this->db->seedClicks($clicks);

        $c = $this->makeCampaign();
        $abtest = new AbTest($c);

        $comboCounts = [];
        for ($i = 0; $i < 200; $i++) {
            $result = $abtest->select_thompson_funnel_multi([['pre1', 'pre2'], ['landA', 'landB']], 'Flow 1', 'Lead');
            $key = implode('|', $result);
            $comboCounts[$key] = ($comboCounts[$key] ?? 0) + 1;
        }

        // pre1+landA (40% CR) should dominate
        $bestCombo = $comboCounts['pre1|landA'] ?? 0;
        $this->assertGreaterThan(100, $bestCombo, 'Best funnel combo should be picked most often');
    }

    // ── get_funnel_stats returns correct aggregates ──

    public function testGetFunnelStats(): void
    {
        $this->db->seedClicks([
            ['subid' => 'a1', 'path' => '["pre1","land1"]', 'step' => 0, 'flow' => 'F1', 'status' => 'Lead'],
            ['subid' => 'a2', 'path' => '["pre1","land1"]', 'step' => 0, 'flow' => 'F1', 'status' => null],
            ['subid' => 'a3', 'path' => '["pre1","land2"]', 'step' => 0, 'flow' => 'F1', 'status' => 'Lead'],
            ['subid' => 'a4', 'path' => '["pre2","land1"]', 'step' => 0, 'flow' => 'F1', 'status' => null],
        ]);

        $stats = $this->db->get_funnel_stats(1, 'F1', 'Lead');
        $map = [];
        foreach ($stats as $row) {
            $map[$row['path']] = $row;
        }

        $this->assertEquals(2, $map['["pre1","land1"]']['impressions']);
        $this->assertEquals(1, $map['["pre1","land1"]']['conversions']);
        $this->assertEquals(1, $map['["pre1","land2"]']['impressions']);
        $this->assertEquals(1, $map['["pre1","land2"]']['conversions']);
        $this->assertEquals(1, $map['["pre2","land1"]']['impressions']);
        $this->assertEquals(0, $map['["pre2","land1"]']['conversions']);
    }

    // ── get_variant_stats returns correct aggregates ──

    public function testGetVariantStats(): void
    {
        $this->db->seedClicks([
            ['subid' => 'a1', 'path' => '["land1"]', 'step' => 0, 'flow' => 'F1', 'status' => 'Lead'],
            ['subid' => 'a2', 'path' => '["land1"]', 'step' => 0, 'flow' => 'F1', 'status' => null],
            ['subid' => 'a3', 'path' => '["land2"]', 'step' => 0, 'flow' => 'F1', 'status' => 'Lead'],
            ['subid' => 'a4', 'path' => '["land2"]', 'step' => 0, 'flow' => 'F1', 'status' => 'Lead'],
        ]);

        $stats = $this->db->get_variant_stats(1, 'F1', 0, 'Lead');
        $map = [];
        foreach ($stats as $row) {
            $map[$row['variant']] = $row;
        }

        $this->assertEquals(2, $map['land1']['impressions']);
        $this->assertEquals(1, $map['land1']['conversions']);
        $this->assertEquals(2, $map['land2']['impressions']);
        $this->assertEquals(2, $map['land2']['conversions']);
    }

    // ── FlowSettings new fields default correctly ──

    public function testFlowSettingsThompsonDefaults(): void
    {
        $data = [
            'name' => 'Test',
            'filters' => [],
            'steps' => [['action' => 'folder', 'folders' => [[
                'name' => 'l', 'loadtype' => 'base', 'weight' => 100,
                'mvt' => ['enabled' => false, 'tests' => []],
            ]], 'redirect' => ['urls' => [], 'type' => 302]]]
        ];
        $flow = FlowSettings::fromArray($data);
        $this->assertEquals('equal', $flow->distribution);
        $this->assertEquals('Lead', $flow->optimize_for);
        $this->assertEquals('funnels', $flow->optimize_mode);
    }

    public function testFlowSettingsThompsonExplicit(): void
    {
        $data = [
            'name' => 'Test',
            'filters' => [],
            'distribution' => 'thompson',
            'optimize_for' => 'Purchase',
            'optimize_mode' => 'separate',
            'steps' => [
                ['action' => 'folder', 'folders' => [[
                    'name' => 'p1', 'loadtype' => 'base', 'weight' => 100,
                    'mvt' => ['enabled' => false, 'tests' => []],
                ]], 'redirect' => ['urls' => [], 'type' => 302]],
                ['action' => 'folder', 'folders' => [[
                    'name' => 'l1', 'loadtype' => 'base', 'weight' => 100,
                    'mvt' => ['enabled' => false, 'tests' => []],
                ]], 'redirect' => ['urls' => [], 'type' => 302]]
            ]
        ];
        $flow = FlowSettings::fromArray($data);
        $this->assertEquals('thompson', $flow->distribution);
        $this->assertEquals('Purchase', $flow->optimize_for);
        $this->assertEquals('separate', $flow->optimize_mode);
    }

    // ── compute_win_probabilities tests ──

    public function testWinProbHigherCRGetsHigherProbability(): void
    {
        $statsMap = [
            'landA' => ['imp' => 200, 'conv' => 100], // 50% CR
            'landB' => ['imp' => 200, 'conv' => 10],  // 5% CR
        ];
        $result = AbTest::compute_win_probabilities($statsMap, 5000);

        $this->assertArrayHasKey('landA', $result);
        $this->assertArrayHasKey('landB', $result);
        $this->assertGreaterThan($result['landB'], $result['landA'], 'Higher CR should have higher win probability');
        $this->assertGreaterThan(90, $result['landA'], 'landA should dominate with 50% vs 5% CR');
    }

    public function testWinProbEqualCRGivesRoughly5050(): void
    {
        $statsMap = [
            'landA' => ['imp' => 100, 'conv' => 50],
            'landB' => ['imp' => 100, 'conv' => 50],
        ];
        $result = AbTest::compute_win_probabilities($statsMap, 10000);

        $this->assertArrayHasKey('landA', $result);
        $this->assertArrayHasKey('landB', $result);
        // Each should be roughly 50%, allow ±15% margin
        $this->assertGreaterThan(35, $result['landA']);
        $this->assertLessThan(65, $result['landA']);
    }

    public function testWinProbSingleVariantReturnsEmpty(): void
    {
        $statsMap = [
            'landA' => ['imp' => 100, 'conv' => 50],
        ];
        $result = AbTest::compute_win_probabilities($statsMap);
        $this->assertEmpty($result, 'Single variant should return empty (need at least 2)');
    }

    public function testWinProbEmptyStatsReturnsEmpty(): void
    {
        $result = AbTest::compute_win_probabilities([]);
        $this->assertEmpty($result);
    }

    public function testWinProbFunnelCombos(): void
    {
        $statsMap = [
            'pre1 + land1' => ['imp' => 200, 'conv' => 80],  // 40% CR — best
            'pre1 + land2' => ['imp' => 200, 'conv' => 10],   // 5% CR
            'pre2 + land1' => ['imp' => 200, 'conv' => 10],   // 5% CR
        ];
        $result = AbTest::compute_win_probabilities($statsMap, 5000);

        $this->assertArrayHasKey('pre1 + land1', $result);
        $keys = array_keys($result);
        $this->assertEquals('pre1 + land1', $keys[0], 'Best funnel combo should be first (sorted desc)');
        $this->assertGreaterThan(80, $result['pre1 + land1']);
    }
}
