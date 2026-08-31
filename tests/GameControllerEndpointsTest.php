<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace Tests;

use App\Controllers\GameController;
use App\Models\Database;
use App\Models\ScenarioModel;
use App\Models\SettingsModel;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * The public gameplay endpoints, driven through the real controller.
 *
 * The browser suite exercises the happy path over HTTP; what it cannot
 * reasonably reach is the refusal side — malformed mappings, unknown
 * scenarios — and that is where the rules players could otherwise bend
 * actually live.
 */
class GameControllerEndpointsTest extends TestCase
{
    use UsesInMemoryDatabase;

    private string $configDir;
    private SettingsModel $settings;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];

        $this->configDir = sys_get_temp_dir() . '/iso20022-game-ep-' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0700, true);
        file_put_contents(
            $this->configDir . '/credentials.php',
            "<?php return ['encryption' => ['key' => " . var_export(bin2hex(random_bytes(32)), true) . "]];\n"
        );
        putenv('ISO20022_CONFIG_DIR=' . $this->configDir);

        // Rate limiting is keyed on the caller's address; a stable one keeps
        // the buckets predictable across tests.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    }

    protected function tearDown(): void
    {
        putenv('ISO20022_CONFIG_DIR');
        foreach (glob($this->configDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->configDir);
        $_SESSION = [];
        $this->shutdownInMemoryDatabase();
    }

    /** @return array{0: mixed, 1: int} */
    private function call(string $method, array $body = []): array
    {
        $controller = new class ($body) extends GameController {
            public function __construct(private array $testBody)
            {
                parent::__construct();
            }

            protected function getJsonInput(): array
            {
                return $this->testBody;
            }
        };

        http_response_code(200);
        ob_start();
        $controller->{$method}();

        return [json_decode((string) ob_get_clean(), true), http_response_code()];
    }

    private function seedScenario(): int
    {
        return (new ScenarioModel($this->memoryPdo()))->create([
            'StrtNm' => 'Main St',
            'BldgNb' => '123',
            'PstCd' => '10001',
            'TwnNm' => 'New York',
            'Ctry' => 'US',
            'AdtlAdrInf' => 'Floor 10',
        ]);
    }

    public function testScenarioRequestReportsWhenNoneHaveBeenUploaded(): void
    {
        [$json, $status] = $this->call('getScenario', ['exclude_ids' => []]);

        $this->assertSame(404, $status);
        $this->assertStringContainsString('No scenarios', $json['error']);
    }

    public function testScenarioCarriesChipsAndBothSlotLayouts(): void
    {
        $this->seedScenario();

        [$json, $status] = $this->call('getScenario', ['exclude_ids' => []]);

        $this->assertSame(200, $status);
        $scenario = $json['scenario'];
        $this->assertGreaterThan(0, $scenario['id']);
        $this->assertNotEmpty($scenario['chips']);
        $this->assertNotEmpty($scenario['slots_structured']);
        $this->assertNotEmpty($scenario['slots_hybrid']);
        $this->assertNotEmpty($scenario['address_display']);
    }

    public function testExcludingEveryScenarioLeavesNoneToServe(): void
    {
        $this->seedScenario();
        [$first] = $this->call('getScenario', ['exclude_ids' => []]);
        $id = $first['scenario']['id'];

        [, $status] = $this->call('getScenario', ['exclude_ids' => [$id]]);

        $this->assertSame(404, $status, 'the only scenario was excluded');
    }

    // -----------------------------------------------------------------
    // Validation — the rules a player could otherwise bend
    // -----------------------------------------------------------------

    public function testValidateRefusesAMissingScenarioOrMapping(): void
    {
        [, $noId] = $this->call('validate', ['mapping' => ['a' => 'b'], 'goal_type' => 'Structured']);
        $this->assertSame(400, $noId);

        [, $noMapping] = $this->call('validate', ['scenario_id' => 1, 'mapping' => [], 'goal_type' => 'Structured']);
        $this->assertSame(400, $noMapping);
    }

    public function testValidateRefusesAnUnknownGoalType(): void
    {
        $this->seedScenario();
        [, $status] = $this->call('validate', [
            'scenario_id' => 1, 'mapping' => ['StrtNm' => 'StrtNm'], 'goal_type' => 'Freestyle',
        ]);

        $this->assertSame(400, $status);
    }

    public function testValidateRefusesAScenarioThatDoesNotExist(): void
    {
        [, $status] = $this->call('validate', [
            'scenario_id' => 999999, 'mapping' => ['StrtNm' => 'StrtNm'], 'goal_type' => 'Structured',
        ]);

        $this->assertSame(404, $status);
    }

    public function testACorrectStructuredAnswerScoresFull(): void
    {
        $this->seedScenario();
        [$scenarioResp] = $this->call('getScenario', ['exclude_ids' => []]);
        $scenario = $scenarioResp['scenario'];

        // The client posts slot field => the value dropped into it, not
        // chip id => slot id (ScenarioModel::validateAnswer compares values).
        $mapping = [];
        foreach ($scenario['chips'] as $chip) {
            $mapping[$chip['field']] = $chip['value'];
        }

        [$result, $status] = $this->call('validate', [
            'scenario_id' => $scenario['id'], 'mapping' => $mapping, 'goal_type' => 'Structured',
        ]);

        $this->assertSame(200, $status);
        $this->assertArrayHasKey('percentage', $result);
        $this->assertSame(100, (int) $result['percentage']);
    }

    public function testAWrongStructuredAnswerScoresBelowFull(): void
    {
        $this->seedScenario();
        [$scenarioResp] = $this->call('getScenario', ['exclude_ids' => []]);
        $scenario = $scenarioResp['scenario'];

        // Every chip into the same wrong slot.
        $mapping = [];
        foreach ($scenario['chips'] as $chip) {
            $mapping[$chip['field']] = 'definitely not the right value';
        }

        [$result] = $this->call('validate', [
            'scenario_id' => $scenario['id'], 'mapping' => $mapping, 'goal_type' => 'Structured',
        ]);

        $this->assertLessThan(100, (int) $result['percentage']);
    }

    public function testHybridModeIsAcceptedAsAGoalType(): void
    {
        $this->seedScenario();
        [$scenarioResp] = $this->call('getScenario', ['exclude_ids' => []]);
        $scenario = $scenarioResp['scenario'];

        $mapping = [];
        foreach ($scenario['chips'] as $chip) {
            $mapping[$chip['id']] = $chip['field'] ?? $chip['id'];
        }

        [$result, $status] = $this->call('validate', [
            'scenario_id' => $scenario['id'],
            'mapping' => $mapping,
            'goal_type' => 'Hybrid',
            'adr_field_order' => ['StrtNm', 'BldgNb'],
        ]);

        $this->assertSame(200, $status);
        $this->assertArrayHasKey('percentage', $result);
    }

    // -----------------------------------------------------------------
    // Player name
    // -----------------------------------------------------------------

    public function testAnOrdinaryNameIsAllowed(): void
    {
        [$json, $status] = $this->call('checkName', ['name' => 'Alice']);

        $this->assertSame(200, $status);
        $this->assertTrue($json['allowed']);
    }

    public function testAnEmptyOrOverlongNameIsRefused(): void
    {
        [, $empty] = $this->call('checkName', ['name' => '   ']);
        $this->assertSame(400, $empty);

        [, $long] = $this->call('checkName', ['name' => str_repeat('a', 51)]);
        $this->assertSame(400, $long);
    }

    // -----------------------------------------------------------------
    // Deadline, facts, counters
    // -----------------------------------------------------------------

    public function testDeadlineFallsBackToTheBuiltInDate(): void
    {
        [$json, $status] = $this->call('getDeadline');

        $this->assertSame(200, $status);
        $this->assertNotEmpty($json['deadline']);
    }

    public function testDeadlineReflectsWhatTheAdminStored(): void
    {
        $this->settings->set('unstructured_deadline', '2027-01-31T09:30');

        [$json] = $this->call('getDeadline');

        $this->assertSame('2027-01-31T09:30', $json['deadline']);
    }

    public function testFactsAreReadableWithoutAnySession(): void
    {
        [$json, $status] = $this->call('getFacts');

        $this->assertSame(200, $status);
        $this->assertNotEmpty($json['facts'], 'initSchema seeds the defaults');
    }

    public function testCompleteRecordsAPlayedGame(): void
    {
        [$json, $status] = $this->call('complete');

        $this->assertSame(200, $status);
        $this->assertTrue($json['success']);
        $this->assertSame(1, (new \App\Models\GameCounterModel($this->memoryPdo()))->getTotalCount());
    }

}
