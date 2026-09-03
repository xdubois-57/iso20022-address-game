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

use App\Controllers\AppIconController;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * The apple-touch-icon, which had no tests at all before the PMPG rebrand
 * swapped the emoji at its centre for the PMPG sunburst.
 *
 * There are two rendering paths — Imagick, and a GD fallback for the shared
 * hosts that lack it — and the risk this file exists to cover is that they
 * drift: an installation without Imagick quietly serving the old icon would
 * be a half-applied rebrand nobody would notice. GD is always exercised
 * (every path this project supports has it); Imagick is exercised in addition
 * wherever the extension is present, rather than the whole file being skipped
 * when it is not.
 */
class AppIconControllerTest extends TestCase
{
    use UsesInMemoryDatabase;

    private const ICON_SIZE = 180;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('The gd extension is required to render the icon at all.');
        }
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();
    }

    /** Render the icon through the real controller and return the PNG bytes. */
    private function renderIcon(): string
    {
        ob_start();
        (new AppIconController())->generate();
        return (string) ob_get_clean();
    }

    /**
     * Force the GD branch regardless of what the host has installed, so the
     * fallback is covered on a machine WITH Imagick too — otherwise it would
     * only ever be tested where it is already the default.
     */
    private function renderIconWithGd(): string
    {
        $controller = new AppIconController();
        $method = new \ReflectionMethod($controller, 'generateWithGd');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(
            $controller,
            self::ICON_SIZE,
            '#8abed9',
            '#3d345f',
            __DIR__ . '/../public/assets/fonts/LiberationSans-Bold.ttf',
            __DIR__ . '/../public/assets/images/pmpg-mark.png'
        );
        return (string) ob_get_clean();
    }

    /**
     * @return array{0:int,1:int} width and height of a PNG blob
     */
    private function pngDimensions(string $png): array
    {
        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info, 'the response is not a decodable image');
        $this->assertSame('image/png', $info['mime'], 'iOS accepts PNG only for apple-touch-icon');

        return [$info[0], $info[1]];
    }

    public function testGdPathRendersA180PngIcon(): void
    {
        [$w, $h] = $this->pngDimensions($this->renderIconWithGd());

        $this->assertSame(self::ICON_SIZE, $w);
        $this->assertSame(self::ICON_SIZE, $h);
    }

    public function testImagickPathRendersA180PngIcon(): void
    {
        if (!extension_loaded('imagick')) {
            // Not skipped: GD is asserted instead, so this test still proves
            // the route renders a valid icon on this host. The roadmap is
            // explicit that a missing Imagick must not leave the icon
            // untested.
            [$w, $h] = $this->pngDimensions($this->renderIconWithGd());
            $this->assertSame(self::ICON_SIZE, $w);
            $this->assertSame(self::ICON_SIZE, $h);
            return;
        }

        [$w, $h] = $this->pngDimensions($this->renderIcon());

        $this->assertSame(self::ICON_SIZE, $w);
        $this->assertSame(self::ICON_SIZE, $h);
    }

    /**
     * The white disc under the sunburst is what makes the mark legible: the
     * sunburst's lower petals fade to near white, and dropped straight onto
     * the #8abed9 background they disappear. Asserting the centre is white
     * pins that decision, so removing the disc fails here rather than
     * silently shipping a broken-looking icon.
     */
    public function testTheSunburstSitsOnAWhiteDisc(): void
    {
        $image = imagecreatefromstring($this->renderIconWithGd());
        $this->assertNotFalse($image);

        // A point inside the disc but clear of the sunburst's petals: just
        // below the disc's top edge, on the vertical centre line.
        $rgb = imagecolorsforindex($image, imagecolorat($image, 90, 24));
        imagedestroy($image);

        $this->assertGreaterThan(240, $rgb['red'], 'the disc under the mark should be white');
        $this->assertGreaterThan(240, $rgb['green']);
        $this->assertGreaterThan(240, $rgb['blue']);
    }

    /**
     * The two assets the icon composes. pmpg-mark.png is what it now draws;
     * emoji-controller.png is deliberately still in the repo so that reverting
     * to it is a one-line change if the sunburst ever disappoints at icon size.
     */
    public function testTheIconAssetsArePresent(): void
    {
        $this->assertFileExists(__DIR__ . '/../public/assets/images/pmpg-mark.png');
        $this->assertFileExists(__DIR__ . '/../public/assets/images/emoji-controller.png');
    }

    public function testTheMarkIsSquareAndTransparent(): void
    {
        $path = __DIR__ . '/../public/assets/images/pmpg-mark.png';
        [$w, $h] = getimagesize($path);

        $this->assertSame($w, $h, 'the mark is composited into a square box');

        // A cream or white ground baked into the asset would show as a
        // rectangle on top of the disc.
        $mark = imagecreatefrompng($path);
        $corner = imagecolorsforindex($mark, imagecolorat($mark, 0, 0));
        imagedestroy($mark);

        $this->assertSame(127, $corner['alpha'], 'the mark must have a transparent background');
    }
}
