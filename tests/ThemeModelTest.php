<?php
/**
 * Tests for ThemeModel: defaults, get/save, hex validation, hexToRgb.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Models\Database;
use App\Models\ThemeModel;

class ThemeModelTest extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $db = Database::getInstance();
        if (!$db->isConnected() && !$db->connect()) {
            $this->markTestSkipped('Database not available');
        }
        $this->pdo = $db->getPdo();
        $db->initSchema();

        // Clean theme settings before each test
        $this->pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'color_%'");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'color_%'");
        }
    }

    /* =======================================================
       Defaults
       ======================================================= */

    public function testDefaultsReturnsExpectedKeys(): void
    {
        $defaults = ThemeModel::defaults();
        $this->assertArrayHasKey('color_primary', $defaults);
        $this->assertArrayHasKey('color_primary_hover', $defaults);
        $this->assertArrayHasKey('color_primary_light', $defaults);
        $this->assertArrayHasKey('color_bg', $defaults);
        $this->assertArrayHasKey('color_text', $defaults);
        $this->assertCount(5, $defaults);
    }

    public function testDefaultsContainsValidHexColors(): void
    {
        foreach (ThemeModel::defaults() as $key => $value) {
            $this->assertMatchesRegularExpression(
                '/^#[0-9a-fA-F]{6}$/',
                $value,
                "Default $key must be a valid 6-char hex color"
            );
        }
    }

    public function testDefaultsMatchTestSystemColors(): void
    {
        $defaults = ThemeModel::defaults();
        $this->assertEquals('#00364a', $defaults['color_primary']);
        $this->assertEquals('#00a3d7', $defaults['color_primary_hover']);
        $this->assertEquals('#caf0fe', $defaults['color_primary_light']);
        $this->assertEquals('#94e3fe', $defaults['color_bg']);
        $this->assertEquals('#00364a', $defaults['color_text']);
    }

    /* =======================================================
       get() — returns defaults when no DB values
       ======================================================= */

    public function testGetReturnsDefaultsWhenNoDbValues(): void
    {
        $tm = new ThemeModel($this->pdo);
        $theme = $tm->get();
        $this->assertEquals(ThemeModel::defaults(), $theme);
    }

    /* =======================================================
       save() and get() round-trip
       ======================================================= */

    public function testSaveAndGetRoundTrip(): void
    {
        $tm = new ThemeModel($this->pdo);
        $custom = [
            'color_primary' => '#ff0000',
            'color_primary_hover' => '#cc0000',
            'color_primary_light' => '#ffe0e0',
            'color_bg' => '#ffffff',
            'color_text' => '#111111',
        ];
        $tm->save($custom);

        $result = $tm->get();
        $this->assertEquals($custom, $result);
    }

    public function testSavePartialOnlyUpdatesProvidedKeys(): void
    {
        $tm = new ThemeModel($this->pdo);
        $tm->save(['color_primary' => '#abcdef']);

        $result = $tm->get();
        $this->assertEquals('#abcdef', $result['color_primary']);
        // Other keys should remain at defaults
        $this->assertEquals(ThemeModel::defaults()['color_bg'], $result['color_bg']);
    }

    public function testSaveIgnoresInvalidHex(): void
    {
        $tm = new ThemeModel($this->pdo);
        $tm->save([
            'color_primary' => 'not-a-color',
            'color_bg' => '#xyz123',
            'color_text' => '#00ff00',
        ]);

        $result = $tm->get();
        // Invalid values should not be saved — defaults remain
        $this->assertEquals(ThemeModel::defaults()['color_primary'], $result['color_primary']);
        $this->assertEquals(ThemeModel::defaults()['color_bg'], $result['color_bg']);
        // Valid value should be saved
        $this->assertEquals('#00ff00', $result['color_text']);
    }

    public function testSaveIgnoresUnknownKeys(): void
    {
        $tm = new ThemeModel($this->pdo);
        $tm->save(['unknown_key' => '#ffffff', 'color_primary' => '#123456']);

        $result = $tm->get();
        $this->assertEquals('#123456', $result['color_primary']);
        // unknown_key should not appear
        $this->assertArrayNotHasKey('unknown_key', $result);
    }

    public function testSaveNormalizesToLowercase(): void
    {
        $tm = new ThemeModel($this->pdo);
        $tm->save(['color_primary' => '#AABBCC']);

        $result = $tm->get();
        $this->assertEquals('#aabbcc', $result['color_primary']);
    }

    public function testSaveAcceptsShortHex(): void
    {
        $tm = new ThemeModel($this->pdo);
        $tm->save(['color_primary' => '#abc']);

        $result = $tm->get();
        $this->assertEquals('#abc', $result['color_primary']);
    }

    /* =======================================================
       hexToRgb()
       ======================================================= */

    public function testHexToRgbValid6Char(): void
    {
        $this->assertEquals([0, 54, 74], ThemeModel::hexToRgb('#00364a'));
        $this->assertEquals([255, 255, 255], ThemeModel::hexToRgb('#ffffff'));
        $this->assertEquals([0, 0, 0], ThemeModel::hexToRgb('#000000'));
    }

    public function testHexToRgbValid3Char(): void
    {
        $this->assertEquals([255, 255, 255], ThemeModel::hexToRgb('#fff'));
        $this->assertEquals([0, 0, 0], ThemeModel::hexToRgb('#000'));
        $this->assertEquals([170, 187, 204], ThemeModel::hexToRgb('#abc'));
    }

    public function testHexToRgbWithoutHash(): void
    {
        $this->assertEquals([255, 0, 0], ThemeModel::hexToRgb('ff0000'));
    }

    public function testHexToRgbInvalidReturnsNull(): void
    {
        $this->assertNull(ThemeModel::hexToRgb(''));
        $this->assertNull(ThemeModel::hexToRgb('xyz'));
        $this->assertNull(ThemeModel::hexToRgb('#gg0000'));
        $this->assertNull(ThemeModel::hexToRgb('#1234'));
    }
}
