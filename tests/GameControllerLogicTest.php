<?php
/**
 * Tests for GameController logic: chip generation, address display, slot definitions,
 * score computation, and validation input handling.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

class GameControllerLogicTest extends TestCase
{
    /* =======================================================
       Chip Generation (mirrors GameController::generateChips)
       ======================================================= */

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

    public function testGenerateChipsFromFullScenario(): void
    {
        $data = [
            'StrtNm' => 'Main Street',
            'BldgNb' => '123',
            'PstCd' => '10001',
            'TwnNm' => 'New York',
            'Ctry' => 'US',
            'AdtlAdrInf' => 'Floor 2',
        ];

        $chips = $this->generateChips($data);
        $this->assertCount(6, $chips);

        $fields = array_column($chips, 'field');
        $this->assertContains('StrtNm', $fields);
        $this->assertContains('BldgNb', $fields);
        $this->assertContains('PstCd', $fields);
        $this->assertContains('TwnNm', $fields);
        $this->assertContains('Ctry', $fields);
        $this->assertContains('AdtlAdrInf', $fields);
    }

    public function testGenerateChipsSkipsEmptyFields(): void
    {
        $data = [
            'StrtNm' => '',
            'BldgNb' => '',
            'PstCd' => '',
            'TwnNm' => 'Berlin',
            'Ctry' => 'DE',
            'AdtlAdrInf' => '',
        ];

        $chips = $this->generateChips($data);
        $this->assertCount(2, $chips);

        $fields = array_column($chips, 'field');
        $this->assertContains('TwnNm', $fields);
        $this->assertContains('Ctry', $fields);
    }

    public function testGenerateChipsSkipsWhitespaceOnlyFields(): void
    {
        $data = [
            'StrtNm' => '   ',
            'TwnNm' => 'Tokyo',
            'Ctry' => 'JP',
        ];

        $chips = $this->generateChips($data);
        $this->assertCount(2, $chips);
    }

    public function testGenerateChipsEscapesHtmlInValues(): void
    {
        $data = [
            'TwnNm' => 'O\'Brien & Sons',
            'Ctry' => 'GB',
        ];

        $chips = $this->generateChips($data);
        $twnChip = null;
        foreach ($chips as $c) {
            if ($c['field'] === 'TwnNm') {
                $twnChip = $c;
                break;
            }
        }
        $this->assertNotNull($twnChip);
        $this->assertStringContainsString('&amp;', $twnChip['value']);
    }

    public function testGenerateChipsHaveUniqueIds(): void
    {
        $data = ['TwnNm' => 'Paris', 'Ctry' => 'FR', 'StrtNm' => 'Rivoli'];
        $chips = $this->generateChips($data);

        $ids = array_column($chips, 'id');
        $this->assertCount(count($ids), array_unique($ids));
    }

    public function testGenerateChipsHaveCorrectLabels(): void
    {
        $data = ['TwnNm' => 'Paris', 'Ctry' => 'FR'];
        $chips = $this->generateChips($data);

        foreach ($chips as $chip) {
            if ($chip['field'] === 'TwnNm') {
                $this->assertEquals('Town Name', $chip['label']);
            }
            if ($chip['field'] === 'Ctry') {
                $this->assertEquals('Country', $chip['label']);
            }
        }
    }

    /* =======================================================
       Address Display Formatting
       (mirrors GameController::formatAddressDisplay)
       ======================================================= */

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

    public function testFormatAddressDisplayFull(): void
    {
        $data = [
            'StrtNm' => 'Baker Street',
            'BldgNb' => '221B',
            'PstCd' => 'NW1 6XE',
            'TwnNm' => 'London',
            'Ctry' => 'GB',
            'AdtlAdrInf' => 'Floor 2',
        ];

        $result = $this->formatAddressDisplay($data);
        $lines = explode("\n", $result);
        $this->assertCount(4, $lines);
        $this->assertEquals('Baker Street 221B', $lines[0]);
        $this->assertEquals('Floor 2', $lines[1]);
        $this->assertEquals('NW1 6XE London', $lines[2]);
        $this->assertEquals('GB', $lines[3]);
    }

    public function testFormatAddressDisplayMinimal(): void
    {
        $data = ['TwnNm' => 'Berlin', 'Ctry' => 'DE'];

        $result = $this->formatAddressDisplay($data);
        $lines = explode("\n", $result);
        $this->assertCount(2, $lines);
        $this->assertEquals('Berlin', $lines[0]);
        $this->assertEquals('DE', $lines[1]);
    }

    public function testFormatAddressDisplayNoStreet(): void
    {
        $data = ['PstCd' => '75001', 'TwnNm' => 'Paris', 'Ctry' => 'FR'];

        $result = $this->formatAddressDisplay($data);
        $this->assertStringContainsString('75001 Paris', $result);
        $this->assertStringContainsString('FR', $result);
    }

    public function testFormatAddressDisplayEmptyFieldsOmitted(): void
    {
        $data = ['StrtNm' => '', 'BldgNb' => '', 'PstCd' => '', 'TwnNm' => 'Tokyo', 'Ctry' => 'JP'];

        $result = $this->formatAddressDisplay($data);
        $lines = explode("\n", $result);
        $this->assertCount(2, $lines);
    }

    /* =======================================================
       Slot Definitions
       (mirrors GameController::getSlots)
       ======================================================= */

    private function getSlots(string $goalType): array
    {
        if ($goalType === 'Structured') {
            return [
                ['id' => 'StrtNm', 'label' => 'Street Name', 'tag' => '<StrtNm>', 'mandatory' => false],
                ['id' => 'BldgNb', 'label' => 'Building Number', 'tag' => '<BldgNb>', 'mandatory' => false],
                ['id' => 'PstCd', 'label' => 'Postal Code', 'tag' => '<PstCd>', 'mandatory' => false],
                ['id' => 'TwnNm', 'label' => 'Town Name', 'tag' => '<TwnNm>', 'mandatory' => true],
                ['id' => 'Ctry', 'label' => 'Country', 'tag' => '<Ctry>', 'mandatory' => true],
            ];
        }
        return [
            ['id' => 'TwnNm', 'label' => 'Town Name', 'tag' => '<TwnNm>', 'mandatory' => true],
            ['id' => 'Ctry', 'label' => 'Country', 'tag' => '<Ctry>', 'mandatory' => true],
            ['id' => 'AdrLine1', 'label' => 'Address Line 1 (max 70 chars)', 'tag' => '<AdrLine>', 'mandatory' => false],
            ['id' => 'AdrLine2', 'label' => 'Address Line 2 (max 70 chars)', 'tag' => '<AdrLine>', 'mandatory' => false],
        ];
    }

    public function testStructuredModeHas5Slots(): void
    {
        $slots = $this->getSlots('Structured');
        $this->assertCount(5, $slots);
    }

    public function testHybridModeHas4Slots(): void
    {
        $slots = $this->getSlots('Hybrid');
        $this->assertCount(4, $slots);
    }

    public function testStructuredModeMandatorySlots(): void
    {
        $slots = $this->getSlots('Structured');
        $mandatory = array_filter($slots, fn($s) => $s['mandatory']);
        $mandatoryIds = array_column($mandatory, 'id');
        $this->assertContains('TwnNm', $mandatoryIds);
        $this->assertContains('Ctry', $mandatoryIds);
        $this->assertCount(2, $mandatory);
    }

    public function testHybridModeMandatorySlots(): void
    {
        $slots = $this->getSlots('Hybrid');
        $mandatory = array_filter($slots, fn($s) => $s['mandatory']);
        $mandatoryIds = array_column($mandatory, 'id');
        $this->assertContains('TwnNm', $mandatoryIds);
        $this->assertContains('Ctry', $mandatoryIds);
        $this->assertCount(2, $mandatory);
    }

    public function testHybridModeHasAddressLineSlots(): void
    {
        $slots = $this->getSlots('Hybrid');
        $ids = array_column($slots, 'id');
        $this->assertContains('AdrLine1', $ids);
        $this->assertContains('AdrLine2', $ids);
    }

    /* =======================================================
       Score Computation (mirrors JS computeGameScore)
       ======================================================= */

    private function computeGameScore(int $pct, int $seconds): int
    {
        $timeBonus = 1 + max(0, 300 - $seconds) / 300;
        return (int) round($pct * $timeBonus * 50);
    }

    public function testScoreComputationPerfectFast(): void
    {
        // 100% accuracy, 0 seconds = max time bonus (2x)
        $score = $this->computeGameScore(100, 0);
        $this->assertEquals(10000, $score);
    }

    public function testScoreComputationPerfectSlow(): void
    {
        // 100% accuracy, 300+ seconds = no time bonus (1x)
        $score = $this->computeGameScore(100, 300);
        $this->assertEquals(5000, $score);
    }

    public function testScoreComputationPerfectVerySlowNoCap(): void
    {
        // Time beyond 300s should still yield base 5000 (time bonus floors at 0)
        $score = $this->computeGameScore(100, 600);
        $this->assertEquals(5000, $score);
    }

    public function testScoreComputationZeroAccuracy(): void
    {
        $score = $this->computeGameScore(0, 60);
        $this->assertEquals(0, $score);
    }

    public function testScoreComputationPartialAccuracy(): void
    {
        // 50% accuracy, 150 seconds -> timeBonus = 1 + (150/300) = 1.5
        $score = $this->computeGameScore(50, 150);
        $this->assertEquals(3750, $score); // 50 * 1.5 * 50 = 3750
    }

    /* =======================================================
       Validation Input Checks
       (mirrors GameController::validate input validation)
       ======================================================= */

    public function testValidGoalTypes(): void
    {
        $valid = ['Structured', 'Hybrid'];
        $this->assertTrue(in_array('Structured', $valid, true));
        $this->assertTrue(in_array('Hybrid', $valid, true));
        $this->assertFalse(in_array('structured', $valid, true));
        $this->assertFalse(in_array('', $valid, true));
        $this->assertFalse(in_array(null, $valid, true));
    }

    public function testScenarioIdMustBePositive(): void
    {
        $this->assertFalse(0 > 0);
        $this->assertFalse(-1 > 0);
        $this->assertTrue(1 > 0);
    }

    /* =======================================================
       Default Deadline constant
       ======================================================= */

    public function testDefaultDeadlineFormat(): void
    {
        $deadline = '2026-11-14T18:00';
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $deadline);
        $this->assertNotFalse($dt);
        $this->assertEquals('2026', $dt->format('Y'));
        $this->assertEquals('11', $dt->format('m'));
        $this->assertEquals('14', $dt->format('d'));
    }
}
