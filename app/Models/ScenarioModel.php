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
        $stmt = $this->pdo->query('SELECT id, json_data FROM scenarios ORDER BY id');
        $rows = $stmt->fetchAll();
        return array_map(function ($row) {
            $row['json_data'] = json_decode($row['json_data'], true);
            return $row;
        }, $rows);
    }

    /**
     * Get a random scenario, optionally excluding specific IDs.
     */
    public function getRandom(array $excludeIds = []): ?array
    {
        $sql = 'SELECT id, json_data FROM scenarios';
        $params = [];
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $sql .= ' WHERE id NOT IN (' . $placeholders . ')';
            $params = array_map('intval', $excludeIds);
        }
        $sql .= ' ORDER BY RAND() LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
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
        $stmt = $this->pdo->prepare('SELECT id, json_data FROM scenarios WHERE id = ?');
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
    public function create(array $jsonData): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO scenarios (json_data) VALUES (?)');
        $stmt->execute([json_encode($jsonData)]);
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
     * Expected order of non-mandatory fields in hybrid address lines.
     */
    private const HYBRID_FIELD_ORDER = ['StrtNm', 'BldgNb', 'AdtlAdrInf', 'PstCd'];

    /**
     * Validate a user's chip-to-slot mapping against the scenario.
     *
     * Structured Mode: Each chip must land in its exact semantic slot.
     * Hybrid Mode: TwnNm and Ctry are mandatory slots; remaining components
     *              go into AdrLine1/AdrLine2 as field-name arrays. The order
     *              across both lines must match the natural address order, but
     *              the split point between lines does not matter. Each line
     *              must not exceed 70 characters.
     *
     * @param string $goalType The player's chosen mode ('Structured' or 'Hybrid').
     */
    public function validateAnswer(array $scenario, array $userMapping, string $goalType = 'Structured'): array
    {
        $correct = $scenario['json_data'];
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
            // Mandatory: TwnNm and Ctry (value comparison)
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

            // Address lines: arrays of field names in placement order
            $adrLine1Fields = $userMapping['AdrLine1'] ?? [];
            $adrLine2Fields = $userMapping['AdrLine2'] ?? [];
            if (!is_array($adrLine1Fields)) {
                $adrLine1Fields = [];
            }
            if (!is_array($adrLine2Fields)) {
                $adrLine2Fields = [];
            }

            // Expected fields with values, in natural address order
            $expectedOrder = array_values(array_filter(
                self::HYBRID_FIELD_ORDER,
                function ($f) use ($correct) {
                    return trim($correct[$f] ?? '') !== '';
                }
            ));

            $userFieldOrder = array_merge($adrLine1Fields, $adrLine2Fields);
            $maxScore++;

            $missing = array_diff($expectedOrder, $userFieldOrder);
            if (!empty($missing)) {
                foreach ($missing as $f) {
                    $val = trim($correct[$f] ?? '');
                    $errors[] = ['field' => $f, 'error' => "Component '$val' not found in address lines"];
                }
            } elseif ($userFieldOrder !== $expectedOrder) {
                $errors[] = ['field' => 'AdrLine', 'error' => 'Components are in the wrong order'];
            } else {
                // Check 70-character limit per line
                $line1Text = implode(' ', array_map(function ($f) use ($correct) {
                    return trim($correct[$f] ?? '');
                }, $adrLine1Fields));
                $line2Text = implode(' ', array_map(function ($f) use ($correct) {
                    return trim($correct[$f] ?? '');
                }, $adrLine2Fields));

                if (mb_strlen($line1Text) > 70) {
                    $errors[] = ['field' => 'AdrLine1', 'error' => 'Exceeds 70 character limit'];
                } elseif (mb_strlen($line2Text) > 70) {
                    $errors[] = ['field' => 'AdrLine2', 'error' => 'Exceeds 70 character limit'];
                } else {
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
