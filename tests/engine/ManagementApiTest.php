<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/../../code/managementapi.php';
require_once __DIR__ . '/../../code/api/updateparams.php';

final class ManagementApiTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private TestDb $db;
    private string $dbPath;
    private array $campaignSettings;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/yellowtds_manage_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->campaignSettings = json_decode(
            (string)file_get_contents(__DIR__ . '/../../code/db/default.json'),
            true
        );
        $this->campaignSettings['statistics']['timezone'] = 'UTC';
        $this->db->seedCampaign(1, 'Alpha', $this->campaignSettings);
        $this->db->seedCampaign(2, 'Beta', $this->campaignSettings);
        $this->db->seedClicks([
            [
                'clickid' => 'clk-1',
                'userid' => 'u1',
                'campaign_id' => 1,
                'time' => 1700000000,
                'params' => '{"utm_campaign":"fb","source":"ads"}',
                'cost' => 0,
                'country' => 'US',
            ],
            [
                'clickid' => 'clk-2',
                'userid' => 'u2',
                'campaign_id' => 1,
                'time' => 1700003600,
                'params' => '{"utm_campaign":"fb"}',
                'cost' => 0,
                'country' => 'DE',
            ],
            [
                'clickid' => 'clk-3',
                'userid' => 'u3',
                'campaign_id' => 1,
                'time' => 1700007200,
                'params' => '{"utm_campaign":"tt"}',
                'cost' => 1.5,
                'country' => 'US',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
    }

    public function testMissingKeyIsHiddenOutsideDebug(): void
    {
        $result = ManagementApi::handleHttp('POST', [], '{"action":"campaigns.list"}', $this->apiSettings(false), $this->db);
        $this->assertSame(404, $result['status']);
        $this->assertSame('Not Found', $result['body']);
        $this->assertTrue($result['plain']);
    }

    public function testWrongKeyIsHiddenOutsideDebug(): void
    {
        $result = ManagementApi::handleHttp(
            'POST',
            ['X-Ytds-Key' => 'ffffffffffffffffffffffffffffffff'],
            '{"action":"campaigns.list"}',
            $this->apiSettings(false),
            $this->db
        );
        $this->assertSame(404, $result['status']);
        $this->assertSame('Not Found', $result['body']);
    }

    public function testDisabledApiIsHidden(): void
    {
        $settings = $this->apiSettings(false);
        $settings['apiKey'] = '';
        $result = ManagementApi::handleHttp(
            'POST',
            ['X-Ytds-Key' => self::KEY],
            '{"action":"campaigns.list"}',
            $settings,
            $this->db
        );
        $this->assertSame(404, $result['status']);
        $this->assertSame('Not Found', $result['body']);
    }

    public function testDebugShowsUnauthorizedJson(): void
    {
        $result = ManagementApi::handleHttp('POST', [], '{"action":"campaigns.list"}', $this->apiSettings(true), $this->db);
        $this->assertSame(401, $result['status']);
        $this->assertFalse($result['body']['ok']);
        $this->assertSame('unauthorized', $result['body']['code']);
    }

    public function testGetIsHiddenOutsideDebug(): void
    {
        $result = ManagementApi::handleHttp('GET', ['X-Ytds-Key' => self::KEY], '', $this->apiSettings(false), $this->db);
        $this->assertSame(404, $result['status']);
        $this->assertSame('Not Found', $result['body']);
    }

    public function testCampaignsList(): void
    {
        $result = $this->call(['action' => 'campaigns.list']);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);
        $names = array_column($result['body']['data']['campaigns'], 'name');
        $this->assertSame(['Alpha', 'Beta'], $names);
    }

    public function testClickGetAndUpdateBySubid(): void
    {
        $got = $this->call(['action' => 'click.get', 'subid' => 'clk-1']);
        $this->assertSame(200, $got['status']);
        $this->assertSame('clk-1', $got['body']['data']['clickid']);
        $this->assertSame('fb', $got['body']['data']['params']['utm_campaign']);
        $this->assertSame(0.0, $got['body']['data']['cost']);

        $updated = $this->call([
            'action' => 'click.update',
            'clickid' => 'clk-1',
            'params' => ['buyer' => 'john', 'utm_campaign' => 'fb2'],
            'cost' => 2.5,
        ]);
        $this->assertSame(200, $updated['status']);
        $this->assertSame('john', $updated['body']['data']['params']['buyer']);
        $this->assertSame('fb2', $updated['body']['data']['params']['utm_campaign']);
        $this->assertSame('ads', $updated['body']['data']['params']['source']);
        $this->assertEqualsWithDelta(2.5, $updated['body']['data']['cost'], 0.0001);

        $cleared = $this->call([
            'action' => 'click.update',
            'clickid' => 'clk-1',
            'params' => ['source' => null],
        ]);
        $this->assertArrayNotHasKey('source', $cleared['body']['data']['params']);
    }

    public function testClickNotFoundWithValidKey(): void
    {
        $result = $this->call(['action' => 'click.get', 'clickid' => 'missing']);
        $this->assertSame(404, $result['status']);
        $this->assertSame('click_not_found', $result['body']['code']);
        $this->assertFalse($result['body']['ok']);
    }

    public function testCostDistributeSplitsEvenly(): void
    {
        $result = $this->call([
            'action' => 'cost.distribute',
            'campaign_id' => 1,
            'from' => 1700000000,
            'to' => 1700007200,
            'amount' => 30,
            'filters' => ['params' => ['utm_campaign' => 'fb']],
        ]);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['body']['data']['updated']);
        $this->assertEqualsWithDelta(15.0, $result['body']['data']['cost_per_click'], 0.0001);

        $first = $this->db->get_click_by_clickid('clk-1');
        $second = $this->db->get_click_by_clickid('clk-2');
        $third = $this->db->get_click_by_clickid('clk-3');
        $this->assertEqualsWithDelta(15.0, (float)$first['cost'], 0.0001);
        $this->assertEqualsWithDelta(15.0, (float)$second['cost'], 0.0001);
        $this->assertEqualsWithDelta(1.5, (float)$third['cost'], 0.0001);
    }

    public function testStatsGetReturnsRows(): void
    {
        $result = $this->call([
            'action' => 'stats.get',
            'campaign_id' => 1,
            'from' => '2023-11-14',
            'to' => '2023-11-15',
            'columns' => ['clicks', 'costs'],
            'groupby' => ['country'],
        ]);
        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['body']['data']['rows']);
        $this->assertGreaterThanOrEqual(1, (int)$result['body']['data']['rows'][0]['clicks']);
    }

    public function testBearerKeyAndJsonKeyAreAccepted(): void
    {
        $bearer = ManagementApi::handleHttp(
            'POST',
            ['Authorization' => 'Bearer ' . self::KEY],
            json_encode(['action' => 'campaigns.list']),
            $this->apiSettings(false),
            $this->db
        );
        $this->assertSame(200, $bearer['status']);

        $jsonKey = ManagementApi::handleHttp(
            'POST',
            [],
            json_encode(['action' => 'campaigns.list', 'key' => self::KEY]),
            $this->apiSettings(false),
            $this->db
        );
        $this->assertSame(200, $jsonKey['status']);
    }

    public function testLegacyUpdateparamsHidesErrorsOutsideDebug(): void
    {
        $hidden = updateparams_process([], $this->db, false);
        $this->assertSame(404, $hidden['status']);
        $this->assertSame('Not Found', $hidden['body']);

        $missing = updateparams_process(['clickid' => 'missing'], $this->db, false);
        $this->assertSame(404, $missing['status']);
        $this->assertSame('Not Found', $missing['body']);

        $updated = updateparams_process(['clickid' => 'clk-1', 'email' => 'a@b.c', 'cost' => '9'], $this->db, true);
        $this->assertSame(200, $updated['status']);
        $click = $this->db->get_click_by_clickid('clk-1');
        $this->assertSame('a@b.c', $click['params']['email']);
        $this->assertSame(0.0, (float)$click['cost']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>|string, plain?: bool}
     */
    private function call(array $payload): array
    {
        return ManagementApi::handleHttp(
            'POST',
            ['X-Ytds-Key' => self::KEY],
            json_encode($payload),
            $this->apiSettings(false),
            $this->db
        );
    }

    /** @return array<string, mixed> */
    private function apiSettings(bool $debug): array
    {
        return [
            'apiKey' => self::KEY,
            'debug' => $debug,
            'timezone' => 'UTC',
        ];
    }
}
