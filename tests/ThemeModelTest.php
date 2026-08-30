<?php
/**
 * Tests for ThemeModel: defaults, get/save, hex validation, hexToRgb.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Models\Database;
use App\Models\ThemeModel;
use Tests\Support\UsesInMemoryDatabase;

class ThemeModelTest extends TestCase
{
    use UsesInMemoryDatabase;

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        // In-memory SQLite: never the developer's configured server.
        $this->pdo = $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();
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

    public function testDefaultsAreThePmpgPalette(): void
    {
        $defaults = ThemeModel::defaults();
        $this->assertSame('#3d345f', $defaults['color_primary'], 'PMPG violet');
        $this->assertSame('#2c2646', $defaults['color_primary_hover']);
        $this->assertSame('#dceaf3', $defaults['color_primary_light']);
        $this->assertSame('#8abed9', $defaults['color_bg'], 'sunburst blue');
        $this->assertSame('#3d345f', $defaults['color_text']);
    }

    /**
     * Lower-case matters: save() normalises what it stores to lower case, so a
     * default written in mixed case would not compare equal to the same colour
     * saved through the admin panel — and the sync test against the JavaScript
     * copy would need to know which spelling to expect.
     */
    public function testDefaultsAreLowerCase(): void
    {
        foreach (ThemeModel::defaults() as $key => $value) {
            $this->assertSame(strtolower($value), $value, "Default $key must be lower-case");
        }
    }

    /* =======================================================
       reset() — the migration path for a deployed installation
       ======================================================= */

    public function testResetRemovesStoredThemeRows(): void
    {
        $model = new ThemeModel($this->pdo);
        $model->save([
            'color_primary' => '#111111',
            'color_bg'      => '#222222',
        ]);
        $this->assertSame('#111111', $model->get()['color_primary']);

        $model->reset();

        $rows = $this->pdo
            ->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'color_%'")
            ->fetchColumn();
        $this->assertSame(0, (int) $rows, 'reset() must DELETE the rows, not rewrite them');
    }

    /**
     * The exact scenario of an installation deployed under the old teal
     * palette: it saved a theme once, so it does not follow the new defaults
     * until an admin presses the button. After that it must be
     * indistinguishable from a fresh install.
     */
    public function testResetRestoresThePmpgDefaultsOnAnInstallationThatSavedATheme(): void
    {
        $model = new ThemeModel($this->pdo);
        $model->save([
            'color_primary'       => '#00364a',
            'color_primary_hover' => '#00a3d7',
            'color_primary_light' => '#caf0fe',
            'color_bg'            => '#94e3fe',
            'color_text'          => '#00364a',
        ]);

        $returned = $model->reset();

        $this->assertSame(ThemeModel::defaults(), $returned, 'reset() returns the theme now in force');
        $this->assertSame(ThemeModel::defaults(), $model->get(), 'and get() agrees on a later read');
    }

    public function testResetIsSafeWhenNoThemeWasEverSaved(): void
    {
        $model = new ThemeModel($this->pdo);

        $this->assertSame(ThemeModel::defaults(), $model->reset());
    }

    /**
     * Deleting rather than rewriting is what keeps an installation tracking
     * the defaults. Were reset() to write today's palette back as explicit
     * rows, the installation would look right today and then silently ignore
     * every future change of defaults — so assert the absence of rows, not
     * merely the resulting colours.
     */
    public function testResetLeavesTheInstallationFollowingDefaultsRatherThanPinned(): void
    {
        $model = new ThemeModel($this->pdo);
        $model->save(['color_primary' => '#abcdef']);
        $model->reset();

        // Simulate a future change of defaults by writing a row by hand and
        // confirming get() picks it up — i.e. nothing is shadowing the key.
        $this->pdo
            ->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute(['color_primary', '#123456']);

        $this->assertSame('#123456', (new ThemeModel($this->pdo))->get()['color_primary']);
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
