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
use App\Models\ExcelParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelParserTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/iso20022_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tmpDir . '/*');
        foreach ($files as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function createTestExcel(array $scenarioRows, array $factRows = []): string
    {
        $spreadsheet = new Spreadsheet();

        // Sheet 1: Scenarios
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Scenarios');
        $headers = ['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf', 'Type_Goal'];
        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
        }
        foreach ($scenarioRows as $rowIdx => $row) {
            foreach ($row as $col => $value) {
                $sheet->setCellValueByColumnAndRow($col + 1, $rowIdx + 2, $value);
            }
        }

        // Sheet 2: Facts
        if (!empty($factRows)) {
            $factsSheet = $spreadsheet->createSheet();
            $factsSheet->setTitle('Facts');
            $factsSheet->setCellValue('A1', 'Fact');
            foreach ($factRows as $idx => $fact) {
                $factsSheet->setCellValue('A' . ($idx + 2), $fact);
            }
        }

        $filePath = $this->tmpDir . '/test.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }

    public function testParseValidScenarios(): void
    {
        $filePath = $this->createTestExcel([
            ['Main St', '123', '10001', 'New York', 'US', '', 'Structured'],
            ['Baker St', '221B', 'NW1 6XE', 'London', 'GB', 'Floor 2', 'Hybrid'],
        ]);

        $parser = new ExcelParser();
        $result = $parser->parse($filePath);

        $this->assertEmpty($result['errors']);
        $this->assertCount(2, $result['scenarios']);
        $this->assertEquals('Structured', $result['scenarios'][0]['goal_type']);
        $this->assertEquals('New York', $result['scenarios'][0]['json_data']['TwnNm']);
        $this->assertEquals('Hybrid', $result['scenarios'][1]['goal_type']);
    }

    public function testParseMissingTownNameReportsError(): void
    {
        $filePath = $this->createTestExcel([
            ['Main St', '123', '10001', '', 'US', '', 'Structured'],
        ]);

        $parser = new ExcelParser();
        $result = $parser->parse($filePath);

        $this->assertNotEmpty($result['errors']);
        $this->assertEmpty($result['scenarios']);
    }

    public function testParseInvalidCountryCodeReportsError(): void
    {
        $filePath = $this->createTestExcel([
            ['Main St', '123', '10001', 'New York', 'USA', '', 'Structured'],
        ]);

        $parser = new ExcelParser();
        $result = $parser->parse($filePath);

        $this->assertNotEmpty($result['errors']);
        $hasCtryError = false;
        foreach ($result['errors'] as $err) {
            if (str_contains($err, 'Ctry')) {
                $hasCtryError = true;
            }
        }
        $this->assertTrue($hasCtryError);
    }

    public function testParseInvalidGoalTypeReportsError(): void
    {
        $filePath = $this->createTestExcel([
            ['Main St', '123', '10001', 'New York', 'US', '', 'Invalid'],
        ]);

        $parser = new ExcelParser();
        $result = $parser->parse($filePath);

        $this->assertNotEmpty($result['errors']);
    }

    public function testParseFacts(): void
    {
        $filePath = $this->createTestExcel(
            [['Main St', '123', '10001', 'New York', 'US', '', 'Structured']],
            ['ISO 20022 is the global standard.', 'SWIFT processes billions of messages.']
        );

        $parser = new ExcelParser();
        $result = $parser->parse($filePath);

        $this->assertEmpty($result['errors']);
        $this->assertCount(2, $result['facts']);
        $this->assertEquals('ISO 20022 is the global standard.', $result['facts'][0]);
    }

    public function testParseNonExistentFileReportsError(): void
    {
        $parser = new ExcelParser();
        $result = $parser->parse('/nonexistent/file.xlsx');

        $this->assertNotEmpty($result['errors']);
    }
}
