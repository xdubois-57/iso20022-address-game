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

use PHPUnit\Framework\TestCase;
use App\Models\ScenarioModel;

class ScenarioValidationTest extends TestCase
{
    private ScenarioModel $model;

    protected function setUp(): void
    {
        // Create a mock PDO since we only test validation logic, not DB queries
        $pdo = $this->createMock(\PDO::class);
        $this->model = new ScenarioModel($pdo);
    }

    public function testStructuredModePerfectScore(): void
    {
        $scenario = [
            'goal_type' => 'Structured',
            'json_data' => [
                'StrtNm' => 'Main Street',
                'BldgNb' => '123',
                'PstCd' => '10001',
                'TwnNm' => 'New York',
                'Ctry' => 'US',
            ],
        ];

        $mapping = [
            'StrtNm' => 'Main Street',
            'BldgNb' => '123',
            'PstCd' => '10001',
            'TwnNm' => 'New York',
            'Ctry' => 'US',
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertTrue($result['perfect']);
        $this->assertEquals(100, $result['percentage']);
        $this->assertEmpty($result['errors']);
    }

    public function testStructuredModePartialScore(): void
    {
        $scenario = [
            'goal_type' => 'Structured',
            'json_data' => [
                'StrtNm' => 'Main Street',
                'BldgNb' => '123',
                'TwnNm' => 'New York',
                'Ctry' => 'US',
            ],
        ];

        $mapping = [
            'StrtNm' => 'Main Street',
            'BldgNb' => '999',
            'TwnNm' => 'New York',
            'Ctry' => 'US',
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertFalse($result['perfect']);
        $this->assertLessThan(100, $result['percentage']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testStructuredModeCaseInsensitive(): void
    {
        $scenario = [
            'goal_type' => 'Structured',
            'json_data' => [
                'TwnNm' => 'New York',
                'Ctry' => 'US',
            ],
        ];

        $mapping = [
            'TwnNm' => 'new york',
            'Ctry' => 'us',
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertTrue($result['perfect']);
    }

    public function testStructuredModeEmptyFieldsSkipped(): void
    {
        $scenario = [
            'goal_type' => 'Structured',
            'json_data' => [
                'StrtNm' => '',
                'TwnNm' => 'Berlin',
                'Ctry' => 'DE',
            ],
        ];

        $mapping = [
            'TwnNm' => 'Berlin',
            'Ctry' => 'DE',
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertTrue($result['perfect']);
    }

    public function testHybridModePerfectWithFieldNames(): void
    {
        $scenario = [
            'goal_type' => 'Hybrid',
            'json_data' => [
                'StrtNm' => 'Baker Street',
                'BldgNb' => '221B',
                'TwnNm' => 'London',
                'Ctry' => 'GB',
            ],
        ];

        $mapping = [
            'TwnNm' => 'London',
            'Ctry' => 'GB',
            'AdrLine1' => ['StrtNm', 'BldgNb'],
            'AdrLine2' => [],
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertTrue($result['perfect']);
        $this->assertEquals(100, $result['percentage']);
    }

    public function testHybridModeSplitAcrossLines(): void
    {
        $scenario = [
            'goal_type' => 'Hybrid',
            'json_data' => [
                'StrtNm' => 'Baker Street',
                'BldgNb' => '221B',
                'PstCd' => 'NW1 6XE',
                'TwnNm' => 'London',
                'Ctry' => 'GB',
            ],
        ];

        // Split across lines — order preserved, split point irrelevant
        $mapping = [
            'TwnNm' => 'London',
            'Ctry' => 'GB',
            'AdrLine1' => ['StrtNm', 'BldgNb'],
            'AdrLine2' => ['PstCd'],
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertTrue($result['perfect']);
    }

    public function testHybridModeWrongOrder(): void
    {
        $scenario = [
            'goal_type' => 'Hybrid',
            'json_data' => [
                'StrtNm' => 'Baker Street',
                'BldgNb' => '221B',
                'TwnNm' => 'London',
                'Ctry' => 'GB',
            ],
        ];

        // Wrong order: BldgNb before StrtNm
        $mapping = [
            'TwnNm' => 'London',
            'Ctry' => 'GB',
            'AdrLine1' => ['BldgNb', 'StrtNm'],
            'AdrLine2' => [],
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertFalse($result['perfect']);
        $hasOrderError = false;
        foreach ($result['errors'] as $err) {
            if (isset($err['error']) && str_contains($err['error'], 'wrong order')) {
                $hasOrderError = true;
            }
        }
        $this->assertTrue($hasOrderError, 'Should have a wrong order error');
    }

    public function testHybridModeMandatoryFieldMissing(): void
    {
        $scenario = [
            'goal_type' => 'Hybrid',
            'json_data' => [
                'StrtNm' => 'Baker Street',
                'TwnNm' => 'London',
                'Ctry' => 'GB',
            ],
        ];

        $mapping = [
            'TwnNm' => 'London',
            'Ctry' => 'XX',
            'AdrLine1' => ['StrtNm'],
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertFalse($result['perfect']);
    }

    public function testHybridModeMissingComponent(): void
    {
        $scenario = [
            'goal_type' => 'Hybrid',
            'json_data' => [
                'StrtNm' => 'Baker Street',
                'BldgNb' => '221B',
                'TwnNm' => 'London',
                'Ctry' => 'GB',
            ],
        ];

        // Only StrtNm placed, BldgNb missing
        $mapping = [
            'TwnNm' => 'London',
            'Ctry' => 'GB',
            'AdrLine1' => ['StrtNm'],
            'AdrLine2' => [],
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertFalse($result['perfect']);
    }

    public function testHybridModeAddressLineExceeds70Chars(): void
    {
        $longStreet = str_repeat('A', 68);
        $scenario = [
            'goal_type' => 'Hybrid',
            'json_data' => [
                'StrtNm' => $longStreet,
                'BldgNb' => '999',
                'TwnNm' => 'London',
                'Ctry' => 'GB',
            ],
        ];

        // Both fields on one line = 68 + ' ' + '999' = 72 chars > 70
        $mapping = [
            'TwnNm' => 'London',
            'Ctry' => 'GB',
            'AdrLine1' => ['StrtNm', 'BldgNb'],
            'AdrLine2' => [],
        ];

        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertFalse($result['perfect']);
        $hasLengthError = false;
        foreach ($result['errors'] as $err) {
            if (isset($err['error']) && str_contains($err['error'], '70 character')) {
                $hasLengthError = true;
            }
        }
        $this->assertTrue($hasLengthError, 'Should have a 70 character limit error');
    }

    public function testGoalTypeOverrideStructuredOnHybridScenario(): void
    {
        // Scenario stored as Hybrid, but player chooses Structured
        $scenario = [
            'goal_type' => 'Hybrid',
            'json_data' => [
                'StrtNm' => 'Main Street',
                'BldgNb' => '123',
                'TwnNm' => 'New York',
                'Ctry' => 'US',
            ],
        ];

        $mapping = [
            'StrtNm' => 'Main Street',
            'BldgNb' => '123',
            'TwnNm' => 'New York',
            'Ctry' => 'US',
        ];

        $result = $this->model->validateAnswer($scenario, $mapping, 'Structured');
        $this->assertTrue($result['perfect']);
    }

    public function testGoalTypeOverrideHybridOnStructuredScenario(): void
    {
        // Scenario stored as Structured, but player chooses Hybrid
        $scenario = [
            'goal_type' => 'Structured',
            'json_data' => [
                'StrtNm' => 'Baker Street',
                'BldgNb' => '221B',
                'TwnNm' => 'London',
                'Ctry' => 'GB',
            ],
        ];

        $mapping = [
            'TwnNm' => 'London',
            'Ctry' => 'GB',
            'AdrLine1' => ['StrtNm', 'BldgNb'],
            'AdrLine2' => [],
        ];

        $result = $this->model->validateAnswer($scenario, $mapping, 'Hybrid');
        $this->assertTrue($result['perfect']);
    }

    public function testGoalTypeDefaultsToScenarioType(): void
    {
        $scenario = [
            'goal_type' => 'Structured',
            'json_data' => [
                'TwnNm' => 'Berlin',
                'Ctry' => 'DE',
            ],
        ];

        $mapping = [
            'TwnNm' => 'Berlin',
            'Ctry' => 'DE',
        ];

        // No goalType override — should use scenario's 'Structured'
        $result = $this->model->validateAnswer($scenario, $mapping);
        $this->assertTrue($result['perfect']);
    }
}
