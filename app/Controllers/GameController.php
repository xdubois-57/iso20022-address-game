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

namespace App\Controllers;

use App\Models\Database;
use App\Models\ScenarioModel;
use App\Models\FactModel;

class GameController
{
    private ScenarioModel $scenarioModel;
    private FactModel $factModel;

    public function __construct()
    {
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $this->scenarioModel = new ScenarioModel($pdo);
        $this->factModel = new FactModel($pdo);
    }

    /**
     * GET /api/game/scenario — Load a random scenario for the player.
     */
    public function getScenario(): void
    {
        $scenario = $this->scenarioModel->getRandom();
        if (!$scenario) {
            $this->jsonResponse(['error' => 'No scenarios available. Ask an admin to upload scenarios.'], 404);
            return;
        }

        $fact = $this->factModel->getRandom();

        $this->jsonResponse([
            'scenario' => [
                'id' => $scenario['id'],
                'goal_type' => $scenario['goal_type'],
                'chips' => $this->generateChips($scenario['json_data']),
                'slots' => $this->getSlots($scenario['goal_type']),
            ],
            'fact' => $fact,
        ]);
    }

    /**
     * POST /api/game/validate — Validate the user's chip-to-slot mapping.
     */
    public function validate(): void
    {
        $input = $this->getJsonInput();
        $scenarioId = (int) ($input['scenario_id'] ?? 0);
        $mapping = $input['mapping'] ?? [];

        if ($scenarioId <= 0 || empty($mapping)) {
            $this->jsonResponse(['error' => 'Missing scenario_id or mapping'], 400);
            return;
        }

        $scenario = $this->scenarioModel->getById($scenarioId);
        if (!$scenario) {
            $this->jsonResponse(['error' => 'Scenario not found'], 404);
            return;
        }

        $result = $this->scenarioModel->validateAnswer($scenario, $mapping);
        $this->jsonResponse($result);
    }

    /**
     * Generate draggable chips from scenario data.
     * Chips are shuffled so users cannot rely on order.
     */
    private function generateChips(array $data): array
    {
        $chips = [];
        $fieldLabels = [
            'StrtNm' => 'Street Name',
            'BldgNb' => 'Building Number',
            'PstCd' => 'Postal Code',
            'TwnNm' => 'Town Name',
            'Ctry' => 'Country',
            'AdtlAdrInf' => 'Additional Info',
        ];

        foreach ($data as $field => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $chips[] = [
                'id' => 'chip_' . $field . '_' . bin2hex(random_bytes(4)),
                'field' => $field,
                'value' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
                'label' => $fieldLabels[$field] ?? $field,
            ];
        }

        shuffle($chips);
        return $chips;
    }

    /**
     * Get the target slots for the given goal type.
     */
    private function getSlots(string $goalType): array
    {
        if ($goalType === 'Structured') {
            return [
                ['id' => 'StrtNm', 'label' => 'Street Name', 'tag' => '<StrtNm>', 'mandatory' => false],
                ['id' => 'BldgNb', 'label' => 'Building Number', 'tag' => '<BldgNb>', 'mandatory' => false],
                ['id' => 'PstCd', 'label' => 'Postal Code', 'tag' => '<PstCd>', 'mandatory' => false],
                ['id' => 'TwnNm', 'label' => 'Town Name', 'tag' => '<TwnNm>', 'mandatory' => true],
                ['id' => 'Ctry', 'label' => 'Country', 'tag' => '<Ctry>', 'mandatory' => true],
                ['id' => 'AdtlAdrInf', 'label' => 'Additional Info', 'tag' => '<AdtlAdrInf>', 'mandatory' => false],
            ];
        }

        // Hybrid mode
        return [
            ['id' => 'TwnNm', 'label' => 'Town Name', 'tag' => '<TwnNm>', 'mandatory' => true],
            ['id' => 'Ctry', 'label' => 'Country', 'tag' => '<Ctry>', 'mandatory' => true],
            ['id' => 'AdrLine1', 'label' => 'Address Line 1 (max 70 chars)', 'tag' => '<AdrLine>', 'mandatory' => false],
            ['id' => 'AdrLine2', 'label' => 'Address Line 2 (max 70 chars)', 'tag' => '<AdrLine>', 'mandatory' => false],
        ];
    }

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    private function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
}
