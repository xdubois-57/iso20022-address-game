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
use App\Support\Input;

class ScenarioModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all scenarios.
     *
     * @return list<array{id: int, json_data: array<string, mixed>}>
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
     * Upper bound on client-supplied exclusions, so a caller cannot force an
     * arbitrarily large IN list. Far above the number of scenarios any real
     * session plays through.
     */
    private const MAX_EXCLUDE_IDS = 500;

    /**
     * Get a random scenario, optionally excluding specific IDs.
     *
     * @param  array<mixed> $excludeIds  straight from the client, so any shape
     * @return ?array{id: int, json_data: array<string, mixed>}
     */
    public function getRandom(array $excludeIds = []): ?array
    {
        // exclude_ids comes straight from the client. array_map('intval', ...)
        // preserved the original keys, so a JSON object rather than an array
        // produced string keys against positional placeholders and a PDO error;
        // nested values raised a warning and silently became 1. Normalise to a
        // clean, bounded list of positive integers.
        $excludeIds = array_values(array_unique(array_map(
            'intval',
            array_filter($excludeIds, 'is_scalar')
        )));
        $excludeIds = array_values(array_filter($excludeIds, fn($id) => $id > 0));
        $excludeIds = array_slice($excludeIds, 0, self::MAX_EXCLUDE_IDS);

        $sql = 'SELECT id, json_data FROM scenarios';
        $params = [];
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $sql .= ' WHERE id NOT IN (' . $placeholders . ')';
            $params = $excludeIds;
        }
        // RAND() is MySQL's spelling, RANDOM() SQLite's.
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql .= $driver === 'sqlite' ? ' ORDER BY RANDOM() LIMIT 1' : ' ORDER BY RAND() LIMIT 1';
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
     *
     * @return ?array{id: int, json_data: array<string, mixed>}
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
     *
     * @param array<string, mixed> $jsonData
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
     *              across both lines must match the formatted address order
     *              (country-specific, as shown on the left panel), but
     *              the split point between lines does not matter. Each line
     *              must not exceed 70 characters.
     *
     * @param array<string, mixed> $scenario      the scenario row's json_data
     * @param array<string, mixed> $userMapping   slot id => chip value, from the client
     * @param string               $goalType      The player's chosen mode ('Structured' or 'Hybrid').
     * @param list<string>|null    $adrFieldOrder Country-specific field order derived from the formatted
     *                                            address (sent by the client). Falls back to HYBRID_FIELD_ORDER.
     *
     * @return array{score: int, maxScore: int, percentage: float, errors: list<array<string, mixed>>, perfect: bool}
     */
    public function validateAnswer(
        array $scenario,
        array $userMapping,
        string $goalType = 'Structured',
        ?array $adrFieldOrder = null
    ): array
    {
        $correct = $scenario['json_data'];
        $errors = [];
        $score = 0;
        $maxScore = 0;

        if ($goalType === 'Structured') {
            $fields = ['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry'];
            foreach ($fields as $field) {
                $expected = trim($correct[$field] ?? '');
                if ($expected === '') {
                    continue;
                }
                $maxScore++;
                // Input::string: the mapping's VALUES are client JSON too, and
                // an array here fataled in trim(). It now compares as '' and
                // is simply marked wrong.
                $userVal = trim(Input::string($userMapping[$field] ?? ''));
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
                $userVal = trim(Input::string($userMapping[$mandatory] ?? ''));
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
            // Field names only: a nested array in either line raised an
            // "Array to string conversion" warning inside array_diff() below.
            // A legitimate client sends field-name strings and is unaffected.
            $adrLine1Fields = array_values(array_filter($adrLine1Fields, 'is_string'));
            $adrLine2Fields = array_values(array_filter($adrLine2Fields, 'is_string'));

            // Expected fields with values, in country-specific formatted address order.
            // Use client-supplied order if provided, fall back to static HYBRID_FIELD_ORDER.
            $baseOrder = (!empty($adrFieldOrder))
                ? array_filter($adrFieldOrder, fn($f) => in_array($f, self::HYBRID_FIELD_ORDER, true))
                : self::HYBRID_FIELD_ORDER;
            $expectedOrder = array_values(array_filter(
                $baseOrder,
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
                $joinFields = static function (array $fields) use ($correct): string {
                    return implode(' ', array_map(
                        static fn ($f) => trim($correct[$f] ?? ''),
                        $fields
                    ));
                };
                $line1Text = $joinFields($adrLine1Fields);
                $line2Text = $joinFields($adrLine2Fields);

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
