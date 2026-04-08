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

use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelParser
{
    /**
     * Expected column headers for scenario data.
     */
    private const SCENARIO_COLUMNS = [
        'StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf', 'Type_Goal',
    ];

    /**
     * Parse the uploaded Excel file and return scenarios + facts.
     *
     * Sheet 1 ("Scenarios"): Address scenarios
     * Sheet 2 ("Facts"): "Did you know?" messages
     *
     * @return array{scenarios: array, facts: array, errors: array}
     */
    public function parse(string $filePath): array
    {
        $result = ['scenarios' => [], 'facts' => [], 'errors' => []];

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Exception $e) {
            $result['errors'][] = 'Failed to open Excel file: ' . $e->getMessage();
            return $result;
        }

        // Parse Scenarios sheet
        $result = $this->parseScenarios($spreadsheet, $result);

        // Parse Facts sheet
        $result = $this->parseFacts($spreadsheet, $result);

        return $result;
    }

    /**
     * Parse the Scenarios sheet (first sheet).
     */
    private function parseScenarios(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $result): array
    {
        try {
            $sheet = $spreadsheet->getSheet(0);
        } catch (\Exception $e) {
            $result['errors'][] = 'Scenarios sheet not found.';
            return $result;
        }

        $rows = $sheet->toArray(null, true, true, true);
        if (empty($rows)) {
            $result['errors'][] = 'Scenarios sheet is empty.';
            return $result;
        }

        // First row is header
        $headerRow = array_shift($rows);
        $headers = array_map('trim', array_values($headerRow));

        // Validate required columns exist
        foreach (self::SCENARIO_COLUMNS as $col) {
            if (!in_array($col, $headers)) {
                $result['errors'][] = "Missing required column: $col";
            }
        }

        if (!empty($result['errors'])) {
            return $result;
        }

        // Map column letters to field names
        $colMap = [];
        foreach ($headerRow as $letter => $name) {
            $colMap[trim($name)] = $letter;
        }

        $rowNum = 2;
        foreach ($rows as $row) {
            $scenario = [];
            foreach (self::SCENARIO_COLUMNS as $col) {
                $scenario[$col] = trim((string) ($row[$colMap[$col]] ?? ''));
            }

            // Validate mandatory fields
            if (empty($scenario['TwnNm'])) {
                $result['errors'][] = "Row $rowNum: TwnNm (Town Name) is mandatory.";
                $rowNum++;
                continue;
            }
            if (empty($scenario['Ctry']) || strlen($scenario['Ctry']) !== 2) {
                $result['errors'][] = "Row $rowNum: Ctry must be a 2-letter ISO country code.";
                $rowNum++;
                continue;
            }

            $goalType = $scenario['Type_Goal'];
            if (!in_array($goalType, ['Structured', 'Hybrid'])) {
                $result['errors'][] = "Row $rowNum: Type_Goal must be 'Structured' or 'Hybrid'.";
                $rowNum++;
                continue;
            }

            // Remove Type_Goal from data, store separately
            $jsonData = $scenario;
            unset($jsonData['Type_Goal']);

            $result['scenarios'][] = [
                'json_data' => $jsonData,
                'goal_type' => $goalType,
            ];
            $rowNum++;
        }

        return $result;
    }

    /**
     * Parse the Facts sheet (second sheet).
     */
    private function parseFacts(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $result): array
    {
        try {
            $sheet = $spreadsheet->getSheet(1);
        } catch (\Exception $e) {
            // Facts sheet is optional
            return $result;
        }

        $rows = $sheet->toArray(null, true, true, true);
        if (empty($rows)) {
            return $result;
        }

        // Skip header row
        array_shift($rows);

        foreach ($rows as $row) {
            $values = array_values($row);
            $text = trim((string) ($values[0] ?? ''));
            if ($text !== '') {
                $result['facts'][] = $text;
            }
        }

        return $result;
    }
}
