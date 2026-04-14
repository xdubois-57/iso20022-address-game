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
use App\Controllers\AdminController;
use Snipe\BanBuilder\CensorWords;

class GameController
{
    private ScenarioModel $scenarioModel;

    public function __construct()
    {
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $this->scenarioModel = new ScenarioModel($pdo);
    }

    /**
     * POST /api/game/scenario — Load a random scenario for the player.
     */
    public function getScenario(): void
    {
        $input = $this->getJsonInput();
        $excludeIds = $input['exclude_ids'] ?? [];

        $scenario = $this->scenarioModel->getRandom($excludeIds);
        if (!$scenario) {
            $this->jsonResponse(['error' => 'No scenarios available. Ask an admin to upload scenarios.'], 404);
            return;
        }

        $this->jsonResponse([
            'scenario' => [
                'id' => $scenario['id'],
                'chips' => $this->generateChips($scenario['json_data']),
                'slots_structured' => $this->getSlots('Structured'),
                'slots_hybrid' => $this->getSlots('Hybrid'),
                'address_display' => $this->formatAddressDisplay($scenario['json_data']),
            ],
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
        $goalType = $input['goal_type'] ?? null;

        if ($scenarioId <= 0 || empty($mapping)) {
            $this->jsonResponse(['error' => 'Missing scenario_id or mapping'], 400);
            return;
        }

        if (!in_array($goalType, ['Structured', 'Hybrid'], true)) {
            $this->jsonResponse(['error' => 'Invalid goal_type'], 400);
            return;
        }

        $scenario = $this->scenarioModel->getById($scenarioId);
        if (!$scenario) {
            $this->jsonResponse(['error' => 'Scenario not found'], 404);
            return;
        }

        $result = $this->scenarioModel->validateAnswer($scenario, $mapping, $goalType);
        $this->jsonResponse($result);
    }

    /**
     * POST /api/game/deadline — Get the unstructured address deadline (public, no auth).
     */
    private const DEFAULT_DEADLINE = '2026-11-14T18:00';

    public function getDeadline(): void
    {
        $deadline = AdminController::fetchDeadlineStatic() ?? self::DEFAULT_DEADLINE;
        $this->jsonResponse(['deadline' => $deadline]);
    }

    /**
     * POST /api/game/facts — Get all "Did You Know" facts (public, no auth).
     */
    public function getFacts(): void
    {
        $this->jsonResponse(['facts' => AdminController::fetchFactsStatic()]);
    }

    /**
     * POST /api/game/check-name — Validate player name for profanity.
     */
    public function checkName(): void
    {
        $input = $this->getJsonInput();
        $name = trim($input['name'] ?? '');

        if ($name === '' || mb_strlen($name) > 50) {
            $this->jsonResponse(['error' => 'Name must be 1-50 characters'], 400);
            return;
        }

        $censor = new CensorWords();
        $censor->setDictionary(['en-us', 'en-uk', 'fr']);
        $result = $censor->censorString($name, true);

        if (!empty($result['matched'])) {
            $this->jsonResponse([
                'allowed' => false,
                'message' => 'Please choose a different name — offensive language is not allowed.',
            ]);
            return;
        }

        $this->jsonResponse(['allowed' => true]);
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

    /**
     * Format address data as a human-readable multi-line string (like an envelope).
     */
    private function formatAddressDisplay(array $data): string
    {
        $lines = [];
        $street = trim($data['StrtNm'] ?? '');
        $bldg = trim($data['BldgNb'] ?? '');
        if ($street !== '' || $bldg !== '') {
            $lines[] = trim($street . ' ' . $bldg);
        }
        $extra = trim($data['AdtlAdrInf'] ?? '');
        if ($extra !== '') {
            $lines[] = $extra;
        }
        $postal = trim($data['PstCd'] ?? '');
        $town = trim($data['TwnNm'] ?? '');
        if ($postal !== '' || $town !== '') {
            $lines[] = trim($postal . ' ' . $town);
        }
        $country = trim($data['Ctry'] ?? '');
        if ($country !== '') {
            $lines[] = $country;
        }
        return implode("\n", $lines);
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
