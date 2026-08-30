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

use App\Controllers\ShareController;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * The 1200×630 share card: the most public surface this project has, since a
 * LinkedIn post shows it to people who will never open the game.
 *
 * The risk this file covers is a balloon landing on the PMPG logo. The layout
 * is deterministic per seed, so checking the one seed the application happens
 * to use would prove only that this single arrangement is fine, and any change
 * to the balloon count, radii or draw order would shuffle every position.
 * ShareController::planBalloons() was therefore extracted from the drawing
 * code — it is pure, so hundreds of seeds can be checked here without Imagick
 * and without an HTTP request.
 */
class ShareCardLayoutTest extends TestCase
{
    use UsesInMemoryDatabase;

    private const W = 1200;
    private const H = 630;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();
    }

    /**
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function zones(): array
    {
        $method = new \ReflectionMethod(ShareController::class, 'exclusionZones');
        $method->setAccessible(true);

        return $method->invoke(null, self::W, self::H);
    }

    public function testTheCardReservesBothTheTextBlockAndTheEndorsementStrip(): void
    {
        $zones = $this->zones();

        $this->assertCount(2, $zones, 'centre text and the endorsement strip');
        foreach ($zones as [$x1, $y1, $x2, $y2]) {
            $this->assertLessThan($x2, $x1, 'zone must have positive width');
            $this->assertLessThan($y2, $y1, 'zone must have positive height');
        }

        // The endorsement zone must actually reach the foot of the card,
        // where the logo is drawn.
        $bottoms = array_map(static fn(array $z): int => $z[3], $zones);
        $this->assertContains(self::H, $bottoms, 'the endorsement zone must extend to the bottom edge');
    }

    /**
     * Where the logo is actually DRAWN, derived from the drawing constants
     * rather than from exclusionZones().
     *
     * That independence is the point. An earlier version of this test took
     * its rectangle from exclusionZones() and so only proved "balloons
     * respect whatever zones are configured" — deleting the endorsement zone
     * left it green. Computing the rectangle from the same numbers the
     * compositing code uses means the test fails when the logo is exposed,
     * which is the thing that actually matters.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function drawnLogoRect(): array
    {
        $top = new \ReflectionMethod(ShareController::class, 'endorseLogoTop');
        $top->setAccessible(true);
        $height = new \ReflectionMethod(ShareController::class, 'endorseLogoHeight');
        $height->setAccessible(true);

        $constants = (new \ReflectionClass(ShareController::class))->getConstants();
        $logoW = $constants['ENDORSE_LOGO_W'];

        $logoTop = $top->invoke(null, self::H);

        return [
            (int) ((self::W - $logoW) / 2),
            $logoTop,
            (int) ((self::W + $logoW) / 2),
            $logoTop + $height->invoke(null),
        ];
    }

    /**
     * The point of the whole exercise: across many seeds, no balloon may
     * cover the PMPG logo.
     */
    public function testNoBalloonEverLandsOnTheLogo(): void
    {
        $zones = $this->zones();
        [$lx1, $ly1, $lx2, $ly2] = $this->drawnLogoRect();

        for ($seed = 1; $seed <= 300; $seed++) {
            foreach (ShareController::planBalloons($seed, self::W, self::H, $zones) as [$x, $y, $r]) {
                $overlaps = $x + $r > $lx1 && $x - $r < $lx2 && $y + $r > $ly1 && $y - $r < $ly2;
                $this->assertFalse(
                    $overlaps,
                    "seed $seed: a balloon at ($x,$y) r=$r covers the PMPG logo"
                );
            }
        }
    }

    /**
     * The same, for the centre text block.
     */
    public function testNoBalloonEverLandsOnTheCentreText(): void
    {
        $zones = $this->zones();

        // The text block as the drawing code lays it out: 700×360, centred.
        $tx1 = (int) ((self::W - 700) / 2);
        $ty1 = (int) ((self::H - 360) / 2);
        $tx2 = $tx1 + 700;
        $ty2 = $ty1 + 360;

        for ($seed = 1; $seed <= 300; $seed++) {
            foreach (ShareController::planBalloons($seed, self::W, self::H, $zones) as [$x, $y, $r]) {
                $overlaps = $x + $r > $tx1 && $x - $r < $tx2 && $y + $r > $ty1 && $y - $r < $ty2;
                $this->assertFalse(
                    $overlaps,
                    "seed $seed: a balloon at ($x,$y) r=$r covers the centre text"
                );
            }
        }
    }

    public function testBalloonsNeverOverlapEachOther(): void
    {
        $zones = $this->zones();

        for ($seed = 1; $seed <= 100; $seed++) {
            $balloons = ShareController::planBalloons($seed, self::W, self::H, $zones);

            foreach ($balloons as $i => [$x, $y, $r]) {
                foreach (array_slice($balloons, $i + 1) as [$ox, $oy, $orr]) {
                    $distance = sqrt((($x - $ox) ** 2) + (($y - $oy) ** 2));
                    $this->assertGreaterThanOrEqual(
                        $r + $orr + 15,
                        $distance,
                        "seed $seed: two balloons are closer than the minimum gap"
                    );
                }
            }
        }
    }

    public function testBalloonsStayInsideTheCanvas(): void
    {
        $zones = $this->zones();

        for ($seed = 1; $seed <= 100; $seed++) {
            foreach (ShareController::planBalloons($seed, self::W, self::H, $zones) as [$x, $y, $r]) {
                $this->assertGreaterThanOrEqual(0, $x - $r, "seed $seed: balloon runs off the left edge");
                $this->assertLessThanOrEqual(self::W, $x + $r, "seed $seed: balloon runs off the right edge");
                $this->assertGreaterThanOrEqual(0, $y - $r, "seed $seed: balloon runs off the top edge");
                $this->assertLessThanOrEqual(self::H, $y + $r, "seed $seed: balloon runs off the bottom edge");
            }
        }
    }

    /**
     * The card must look the same every time it is generated for a given
     * score — social platforms cache it, and a card that changed per render
     * would defeat that.
     */
    public function testPlacementIsDeterministicForAGivenSeed(): void
    {
        $zones = $this->zones();

        $this->assertSame(
            ShareController::planBalloons(42, self::W, self::H, $zones),
            ShareController::planBalloons(42, self::W, self::H, $zones)
        );
    }

    /**
     * Guards the tests above against passing vacuously: were the exclusion
     * zones ever to swallow the whole canvas, no balloon could be placed and
     * every collision assertion would hold over an empty list.
     */
    public function testTheCardStillFitsAReasonableNumberOfBalloons(): void
    {
        $zones = $this->zones();

        for ($seed = 1; $seed <= 20; $seed++) {
            $this->assertGreaterThanOrEqual(
                8,
                count(ShareController::planBalloons($seed, self::W, self::H, $zones)),
                "seed $seed: the exclusion zones are crowding the card out"
            );
        }
    }

    /**
     * The logo strip must clear the score line above it, or the card would
     * simply look broken — an overlap no balloon test would catch.
     */
    public function testTheLogoStripSitsBelowTheScoreText(): void
    {
        $top = new \ReflectionMethod(ShareController::class, 'endorseLogoTop');
        $top->setAccessible(true);
        $logoTop = $top->invoke(null, self::H);

        $height = new \ReflectionMethod(ShareController::class, 'endorseLogoHeight');
        $height->setAccessible(true);
        $logoHeight = $height->invoke(null);

        // The score is drawn 130px below centre at 80pt, so its baseline is
        // around y=445 and its descenders reach roughly y=465.
        $this->assertGreaterThan(470, $logoTop, 'the logo would collide with the score line');
        $this->assertLessThanOrEqual(self::H, $logoTop + $logoHeight, 'the logo runs off the bottom edge');
    }

    public function testTheLogoKeepsItsAspectRatio(): void
    {
        $height = new \ReflectionMethod(ShareController::class, 'endorseLogoHeight');
        $height->setAccessible(true);

        [$assetW, $assetH] = getimagesize(__DIR__ . '/../public/assets/images/pmpg-logo.png');

        $expected = 260 * $assetH / $assetW;
        $this->assertEqualsWithDelta(
            $expected,
            $height->invoke(null),
            1.0,
            'a stretched lockup is a trademark problem, not just an ugly one'
        );
    }
}
