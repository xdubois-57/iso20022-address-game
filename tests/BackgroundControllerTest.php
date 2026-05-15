<?php
/**
 * Tests for BackgroundController: SVG generation with theme color injection.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Models\ThemeModel;

class BackgroundControllerTest extends TestCase
{
    private string $svgPath;

    protected function setUp(): void
    {
        $this->svgPath = __DIR__ . '/../public/assets/images/world_map.svg';
        if (!file_exists($this->svgPath)) {
            $this->markTestSkipped('world_map.svg not found');
        }
    }

    /* =======================================================
       SVG Asset Integrity
       ======================================================= */

    public function testSvgFileExists(): void
    {
        $this->assertFileExists($this->svgPath);
    }

    public function testSvgContainsExpectedSourceColors(): void
    {
        $svg = file_get_contents($this->svgPath);
        // The SVG must contain these hardcoded colors for replacement to work
        $this->assertStringContainsString('#9BD5C1', $svg, 'SVG must contain land fill color #9BD5C1');
        $this->assertStringContainsString('#0477BE', $svg, 'SVG must contain stroke color #0477BE');
    }

    public function testSvgIsValidXml(): void
    {
        $svg = file_get_contents($this->svgPath);
        // Check it starts with an SVG-like structure
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    /* =======================================================
       Color Replacement Logic (mirrors BackgroundController::generate)
       ======================================================= */

    private function generateSvgWithTheme(array $theme): string
    {
        $oceanColor = $theme['color_bg'];
        $landColor  = $theme['color_primary_light'];
        $lineColor  = $theme['color_primary'];

        $svg = file_get_contents($this->svgPath);
        $svg = str_ireplace('#9BD5C1', $landColor, $svg);
        $svg = str_ireplace('#0477BE', $lineColor, $svg);

        $oceanRect = '<rect width="100%" height="100%" fill="' . htmlspecialchars($oceanColor, ENT_QUOTES) . '"/>';
        $svg = preg_replace('/(<svg[^>]*>)/', '$1' . $oceanRect, $svg, 1);

        return $svg;
    }

    public function testColorReplacementWithDefaultTheme(): void
    {
        $theme = ThemeModel::defaults();
        $svg = $this->generateSvgWithTheme($theme);

        // Original colors should be replaced
        $this->assertStringNotContainsString('#9BD5C1', $svg);
        $this->assertStringNotContainsString('#0477BE', $svg);

        // Theme colors should be present
        $this->assertStringContainsString($theme['color_primary_light'], $svg);
        $this->assertStringContainsString($theme['color_primary'], $svg);
        $this->assertStringContainsString($theme['color_bg'], $svg);
    }

    public function testColorReplacementWithCustomTheme(): void
    {
        $theme = [
            'color_primary' => '#ff0000',
            'color_primary_hover' => '#cc0000',
            'color_primary_light' => '#ffe0e0',
            'color_bg' => '#0000ff',
            'color_text' => '#111111',
        ];
        $svg = $this->generateSvgWithTheme($theme);

        $this->assertStringContainsString('#ffe0e0', $svg); // land fill
        $this->assertStringContainsString('#ff0000', $svg); // lines
        $this->assertStringContainsString('#0000ff', $svg); // ocean rect
    }

    public function testOceanRectIsInjected(): void
    {
        $theme = ThemeModel::defaults();
        $svg = $this->generateSvgWithTheme($theme);

        $this->assertStringContainsString('<rect width="100%" height="100%" fill="', $svg);
        $this->assertStringContainsString($theme['color_bg'], $svg);
    }

    public function testOceanRectIsInsertedAfterSvgTag(): void
    {
        $theme = ThemeModel::defaults();
        $svg = $this->generateSvgWithTheme($theme);

        // The rect should appear right after the opening <svg...> tag
        $svgTagEnd = strpos($svg, '>'); // end of opening <svg ...>
        $rectPos = strpos($svg, '<rect width="100%"');
        $this->assertGreaterThan($svgTagEnd, $rectPos);
        // And before any path elements
        $firstPath = strpos($svg, '<path');
        $this->assertLessThan($firstPath, $rectPos);
    }

    /* =======================================================
       XSS Prevention in Color Injection
       ======================================================= */

    public function testOceanColorIsHtmlEscaped(): void
    {
        // Simulate a malicious theme color value
        $theme = ThemeModel::defaults();
        $theme['color_bg'] = '"><script>alert(1)</script>';
        $svg = $this->generateSvgWithTheme($theme);

        // The malicious value should be escaped
        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function testColorReplacementIsCaseInsensitive(): void
    {
        // The SVG might have mixed case colors
        $theme = ThemeModel::defaults();
        $svg = $this->generateSvgWithTheme($theme);

        // After replacement, no variant of the original color should remain
        $this->assertStringNotContainsString('#9bd5c1', strtolower($svg));
        $this->assertStringNotContainsString('#0477be', strtolower($svg));
    }

    /* =======================================================
       loadTheme fallback
       ======================================================= */

    public function testDefaultThemeIsUsedAsFallback(): void
    {
        // When DB is unavailable, defaults should be used
        $defaults = ThemeModel::defaults();
        $this->assertNotEmpty($defaults['color_primary']);
        $this->assertNotEmpty($defaults['color_primary_light']);
        $this->assertNotEmpty($defaults['color_bg']);
    }
}
