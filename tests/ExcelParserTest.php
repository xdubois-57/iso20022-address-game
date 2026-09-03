<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
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

    private function createTestExcel(array $scenarioRows): string
    {
        $spreadsheet = new Spreadsheet();

        // Sheet 1: Scenarios
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Scenarios');
        $headers = ['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf'];
        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }
        foreach ($scenarioRows as $rowIdx => $row) {
            foreach ($row as $col => $value) {
                $sheet->setCellValue([$col + 1, $rowIdx + 2], $value);
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
            ['Main St', '123', '10001', 'New York', 'US', ''],
            ['Baker St', '221B', 'NW1 6XE', 'London', 'GB', 'Floor 2'],
        ]);

        $parser = new ExcelParser();
        $result = $parser->parse($filePath);

        $this->assertEmpty($result['errors']);
        $this->assertCount(2, $result['scenarios']);
        $this->assertEquals('New York', $result['scenarios'][0]['json_data']['TwnNm']);
        $this->assertEquals('London', $result['scenarios'][1]['json_data']['TwnNm']);
    }

    public function testParseMissingTownNameReportsError(): void
    {
        $filePath = $this->createTestExcel([
            ['Main St', '123', '10001', '', 'US', ''],
        ]);

        $parser = new ExcelParser();
        $result = $parser->parse($filePath);

        $this->assertNotEmpty($result['errors']);
        $this->assertEmpty($result['scenarios']);
    }

    public function testParseInvalidCountryCodeReportsError(): void
    {
        $filePath = $this->createTestExcel([
            ['Main St', '123', '10001', 'New York', 'USA', ''],
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

    /**
     * Rows Excel keeps in the sheet's used range without any data in them —
     * a formatted-but-empty row under the data, a stray space — are padding,
     * not scenarios with a missing town. They used to be reported as errors,
     * which rejected the whole upload over rows the author could not see.
     */
    public function testBlankRowsAreSkippedRatherThanReported(): void
    {
        $filePath = $this->createTestExcel([
            ['Main St', '123', '10001', 'New York', 'US', ''],
            [null, null, null, null, null, null],
            [' ', '', '', '', '', ''],
            ['Baker St', '221B', 'NW1 6XE', 'London', 'GB', 'Floor 2'],
        ]);

        $result = (new ExcelParser())->parse($filePath);

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['scenarios']);
        $this->assertSame('London', $result['scenarios'][1]['json_data']['TwnNm']);
    }

    /**
     * A row that has SOME data but no town is still an error — skipping
     * blanks must not turn into skipping incomplete rows.
     */
    public function testPartialRowWithoutTownIsStillAnError(): void
    {
        $filePath = $this->createTestExcel([
            ['Main St', '123', '10001', '', 'US', ''],
        ]);

        $result = (new ExcelParser())->parse($filePath);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Row 2', $result['errors'][0]);
    }

    /**
     * A spare, headerless column — a note someone typed to the right of the
     * data — must not raise a deprecation per upload: an empty header cell
     * comes back as null, and trim(null) has warned since PHP 8.1.
     */
    public function testEmptyHeaderCellIsHarmless(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf', null, 'Notes'], null, 'A1');
        $sheet->fromArray(['Main St', '123', '10001', 'New York', 'US', '', null, 'a note'], null, 'A2');
        $filePath = $this->tmpDir . '/spare-column.xlsx';
        (new Xlsx($spreadsheet))->save($filePath);

        $previous = set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            $result = (new ExcelParser())->parse($filePath);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['scenarios']);
    }

    public function testParseNonExistentFileReportsError(): void
    {
        $parser = new ExcelParser();
        $result = $parser->parse('/nonexistent/file.xlsx');

        $this->assertNotEmpty($result['errors']);
    }
}
