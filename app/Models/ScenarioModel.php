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

namespace App\Models;

use PDO;

class ScenarioModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all scenarios.
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, json_data, goal_type FROM scenarios ORDER BY id');
        $rows = $stmt->fetchAll();
        return array_map(function ($row) {
            $row['json_data'] = json_decode($row['json_data'], true);
            return $row;
        }, $rows);
    }

    /**
     * Get a random scenario.
     */
    public function getRandom(): ?array
    {
        $stmt = $this->pdo->query('SELECT id, json_data, goal_type FROM scenarios ORDER BY RAND() LIMIT 1');
        $row = $stmt->fetch();
        if ($row) {
            $row['json_data'] = json_decode($row['json_data'], true);
        }
        return $row ?: null;
    }

    /**
     * Get a scenario by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, json_data, goal_type FROM scenarios WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $row['json_data'] = json_decode($row['json_data'], true);
        }
        return $row ?: null;
    }

    /**
     * Insert a new scenario.
     */
    public function create(array $jsonData, string $goalType): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO scenarios (json_data, goal_type) VALUES (?, ?)');
        $stmt->execute([json_encode($jsonData), $goalType]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Delete all scenarios (used before Excel re-import).
     */
    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM scenarios');
    }

    /**
     * Validate a user's chip-to-slot mapping against the scenario.
     *
     * Structured Mode: Each chip must land in its exact semantic slot.
     * Hybrid Mode: TwnNm and Ctry are mandatory; rest can go into AdrLine (max 70 chars each).
     */
    public function validateAnswer(array $scenario, array $userMapping): array
    {
        $correct = $scenario['json_data'];
        $goalType = $scenario['goal_type'];
        $errors = [];
        $score = 0;
        $maxScore = 0;

        if ($goalType === 'Structured') {
            $fields = ['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf'];
            foreach ($fields as $field) {
                $expected = trim($correct[$field] ?? '');
                if ($expected === '') {
                    continue;
                }
                $maxScore++;
                $userVal = trim($userMapping[$field] ?? '');
                if (mb_strtolower($userVal) === mb_strtolower($expected)) {
                    $score++;
                } else {
                    $errors[] = [
                        'field' => $field,
                        'expected' => $expected,
                        'got' => $userVal,
                    ];
                }
            }
        } else {
            // Hybrid mode
            // Mandatory: TwnNm and Ctry
            foreach (['TwnNm', 'Ctry'] as $mandatory) {
                $expected = trim($correct[$mandatory] ?? '');
                $maxScore++;
                $userVal = trim($userMapping[$mandatory] ?? '');
                if (mb_strtolower($userVal) === mb_strtolower($expected)) {
                    $score++;
                } else {
                    $errors[] = [
                        'field' => $mandatory,
                        'expected' => $expected,
                        'got' => $userVal,
                        'mandatory' => true,
                    ];
                }
            }

            // Address lines validation
            $adrLine1 = trim($userMapping['AdrLine1'] ?? '');
            $adrLine2 = trim($userMapping['AdrLine2'] ?? '');
            $maxScore++;

            if (mb_strlen($adrLine1) > 70) {
                $errors[] = ['field' => 'AdrLine1', 'error' => 'Exceeds 70 character limit'];
            } elseif (mb_strlen($adrLine2) > 70) {
                $errors[] = ['field' => 'AdrLine2', 'error' => 'Exceeds 70 character limit'];
            } else {
                // Check all non-mandatory components appear in the address lines
                $combined = mb_strtolower($adrLine1 . ' ' . $adrLine2);
                $allPresent = true;
                foreach (['StrtNm', 'BldgNb', 'PstCd', 'AdtlAdrInf'] as $optField) {
                    $val = trim($correct[$optField] ?? '');
                    if ($val !== '' && mb_strpos($combined, mb_strtolower($val)) === false) {
                        $allPresent = false;
                        $errors[] = [
                            'field' => $optField,
                            'error' => "Component '$val' not found in address lines",
                        ];
                    }
                }
                if ($allPresent) {
                    $score++;
                }
            }
        }

        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100) : 0;

        return [
            'score' => $score,
            'maxScore' => $maxScore,
            'percentage' => $percentage,
            'errors' => $errors,
            'perfect' => count($errors) === 0,
        ];
    }
}
