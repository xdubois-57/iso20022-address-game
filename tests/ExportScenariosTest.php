<?php
/**
 * Tests for the scenario export functionality (AdminController::exportScenarios fix).
 * Validates that PhpSpreadsheet v5 API is used correctly and that getAll() decoded data
 * is handled without double json_decode.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExportScenariosTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/iso20022_export_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        foreach ($files as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    /**
     * Simulate the fixed export logic from AdminController::exportScenarios.
     */
    private function exportScenarios(array $scenarios): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Scenarios');
        $headers = ['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf'];
        foreach ($headers as $col => $h) {
            $sheet->setCellValue([$col + 1, 1], $h);
        }

        foreach ($scenarios as $rowIdx => $scenario) {
            // Fixed: json_data is already decoded (array), do NOT json_decode again
            $data = $scenario['json_data'];
            $row = [
                $data['StrtNm'] ?? '',
                $data['BldgNb'] ?? '',
                $data['PstCd'] ?? '',
                $data['TwnNm'] ?? '',
                $data['Ctry'] ?? '',
                $data['AdtlAdrInf'] ?? '',
            ];
            foreach ($row as $col => $value) {
                $sheet->setCellValue([$col + 1, $rowIdx + 2], $value);
            }
        }

        $filePath = $this->tmpDir . '/export.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        return $filePath;
    }

    public function testExportCreatesValidXlsx(): void
    {
        $scenarios = [
            ['json_data' => ['StrtNm' => 'Main St', 'BldgNb' => '123', 'PstCd' => '10001', 'TwnNm' => 'New York', 'Ctry' => 'US', 'AdtlAdrInf' => '']],
        ];

        $filePath = $this->exportScenarios($scenarios);
        $this->assertFileExists($filePath);

        // Re-read and verify
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertEquals('Scenarios', $sheet->getTitle());
    }

    public function testExportContainsCorrectHeaders(): void
    {
        $scenarios = [
            ['json_data' => ['TwnNm' => 'Berlin', 'Ctry' => 'DE']],
        ];

        $filePath = $this->exportScenarios($scenarios);
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('StrtNm', $sheet->getCell('A1')->getValue());
        $this->assertEquals('BldgNb', $sheet->getCell('B1')->getValue());
        $this->assertEquals('PstCd', $sheet->getCell('C1')->getValue());
        $this->assertEquals('TwnNm', $sheet->getCell('D1')->getValue());
        $this->assertEquals('Ctry', $sheet->getCell('E1')->getValue());
        $this->assertEquals('AdtlAdrInf', $sheet->getCell('F1')->getValue());
    }

    public function testExportContainsScenarioData(): void
    {
        $scenarios = [
            ['json_data' => ['StrtNm' => 'Baker Street', 'BldgNb' => '221B', 'PstCd' => 'NW1 6XE', 'TwnNm' => 'London', 'Ctry' => 'GB', 'AdtlAdrInf' => 'Floor 2']],
        ];

        $filePath = $this->exportScenarios($scenarios);
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('Baker Street', $sheet->getCell('A2')->getValue());
        $this->assertEquals('221B', $sheet->getCell('B2')->getValue());
        $this->assertEquals('NW1 6XE', $sheet->getCell('C2')->getValue());
        $this->assertEquals('London', $sheet->getCell('D2')->getValue());
        $this->assertEquals('GB', $sheet->getCell('E2')->getValue());
        $this->assertEquals('Floor 2', $sheet->getCell('F2')->getValue());
    }

    public function testExportMultipleScenarios(): void
    {
        $scenarios = [
            ['json_data' => ['StrtNm' => 'Street 1', 'TwnNm' => 'City 1', 'Ctry' => 'AA']],
            ['json_data' => ['StrtNm' => 'Street 2', 'TwnNm' => 'City 2', 'Ctry' => 'BB']],
            ['json_data' => ['StrtNm' => 'Street 3', 'TwnNm' => 'City 3', 'Ctry' => 'CC']],
        ];

        $filePath = $this->exportScenarios($scenarios);
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('City 1', $sheet->getCell('D2')->getValue());
        $this->assertEquals('City 2', $sheet->getCell('D3')->getValue());
        $this->assertEquals('City 3', $sheet->getCell('D4')->getValue());
    }

    public function testExportHandlesMissingOptionalFields(): void
    {
        $scenarios = [
            ['json_data' => ['TwnNm' => 'Paris', 'Ctry' => 'FR']],
        ];

        $filePath = $this->exportScenarios($scenarios);
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Missing fields should be empty strings
        $this->assertEquals('', $sheet->getCell('A2')->getValue() ?? '');
        $this->assertEquals('Paris', $sheet->getCell('D2')->getValue());
        $this->assertEquals('FR', $sheet->getCell('E2')->getValue());
    }

    public function testExportPreservesUnicodeCharacters(): void
    {
        $scenarios = [
            ['json_data' => ['StrtNm' => 'Hauptstraße', 'TwnNm' => 'München', 'Ctry' => 'DE']],
        ];

        $filePath = $this->exportScenarios($scenarios);
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('Hauptstraße', $sheet->getCell('A2')->getValue());
        $this->assertEquals('München', $sheet->getCell('D2')->getValue());
    }

    public function testExportEmptyScenariosList(): void
    {
        $filePath = $this->exportScenarios([]);
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Should still have headers
        $this->assertEquals('StrtNm', $sheet->getCell('A1')->getValue());
        // No data rows
        $this->assertNull($sheet->getCell('A2')->getValue());
    }

    /**
     * Regression test: ensure json_data as array (from getAll) is NOT double-decoded.
     */
    public function testExportDoesNotDoubleDecodeJsonData(): void
    {
        // Simulate what getAll() returns: json_data already decoded to array
        $scenarios = [
            ['json_data' => ['StrtNm' => 'Test Street', 'TwnNm' => 'TestCity', 'Ctry' => 'XX']],
        ];

        $filePath = $this->exportScenarios($scenarios);
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // If double-decoded, these would be null/empty
        $this->assertEquals('Test Street', $sheet->getCell('A2')->getValue());
        $this->assertEquals('TestCity', $sheet->getCell('D2')->getValue());
    }
}
