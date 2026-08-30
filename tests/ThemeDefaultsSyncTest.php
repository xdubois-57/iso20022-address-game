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

use App\Models\ThemeModel;
use PHPUnit\Framework\TestCase;

/**
 * The theme palette exists in three places, and they have to agree.
 *
 *   1. App\Models\ThemeModel::DEFAULTS — what a fresh install serves
 *   2. `themeDefaults` in public/assets/js/app.js — what the admin panel
 *      falls back to when a colour is missing from the server's response
 *   3. the `:root` block in public/assets/css/app.css — what a page renders
 *      with when layout.php's injection has not happened
 *
 * Nothing enforced that before this file existed, and a divergence is close to
 * invisible: the admin panel would simply show slightly different swatches
 * from the ones a fresh install actually uses, and the CSS fallback would
 * paint a page in the previous brand's colours. This reads the two client-side
 * copies out of their real source files — deliberately not a transcription
 * into this test, which would only prove the transcription matched itself.
 */
class ThemeDefaultsSyncTest extends TestCase
{
    private const APP_JS  = __DIR__ . '/../public/assets/js/app.js';
    private const APP_CSS = __DIR__ . '/../public/assets/css/app.css';

    /**
     * Parse the `themeDefaults` object literal out of app.js.
     *
     * @return array<string,string>
     */
    private function jsThemeDefaults(): array
    {
        $source = file_get_contents(self::APP_JS);
        $this->assertNotFalse($source, 'app.js must be readable');

        $matched = preg_match('/var\s+themeDefaults\s*=\s*\{(.*?)\};/s', $source, $block);
        $this->assertSame(1, $matched, 'themeDefaults literal not found in app.js — has it been renamed?');

        preg_match_all("/(\w+)\s*:\s*'([^']*)'/", $block[1], $pairs, PREG_SET_ORDER);
        $this->assertNotEmpty($pairs, 'themeDefaults appears to be empty');

        $out = [];
        foreach ($pairs as [, $key, $value]) {
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Parse the CSS custom properties that carry the palette, keyed by the
     * theme key each one stands for.
     *
     * @return array<string,string>
     */
    private function cssRootPalette(): array
    {
        $source = file_get_contents(self::APP_CSS);
        $this->assertNotFalse($source, 'app.css must be readable');

        $matched = preg_match('/:root\s*\{(.*?)\}/s', $source, $block);
        $this->assertSame(1, $matched, ':root block not found in app.css');

        // The CSS variable each theme key feeds, as layout.php assigns them.
        $mapping = [
            'color_primary'       => '--game-emerald',
            'color_primary_hover' => '--pico-primary-hover',
            'color_primary_light' => '--game-light-peppermint',
            'color_bg'            => '--game-peppermint',
            'color_text'          => '--game-dark-green',
        ];

        $out = [];
        foreach ($mapping as $themeKey => $cssVar) {
            $found = preg_match('/' . preg_quote($cssVar, '/') . '\s*:\s*(#[0-9a-fA-F]{3,6})\s*;/', $block[1], $m);
            $this->assertSame(1, $found, "$cssVar has no static value in app.css :root");
            $out[$themeKey] = strtolower($m[1]);
        }
        return $out;
    }

    public function testJavaScriptDefaultsMatchThePhpDefaults(): void
    {
        $php = ThemeModel::defaults();
        $js  = $this->jsThemeDefaults();

        ksort($php);
        ksort($js);

        $this->assertSame(
            $php,
            $js,
            'ThemeModel::DEFAULTS and themeDefaults in public/assets/js/app.js have drifted apart. '
            . 'The admin panel would show different colours from the ones a fresh install uses.'
        );
    }

    public function testCssFallbackMatchesThePhpDefaults(): void
    {
        $php = ThemeModel::defaults();
        $css = $this->cssRootPalette();

        ksort($php);
        ksort($css);

        $this->assertSame(
            $php,
            $css,
            'The :root fallback in public/assets/css/app.css has drifted from ThemeModel::DEFAULTS. '
            . 'A page rendered without layout.php injection would use the wrong palette.'
        );
    }

    /**
     * Guards the two tests above against quietly passing on nothing: if a
     * rename ever made the regexes match an empty set, comparing empty to
     * empty would look green.
     */
    public function testTheParsersActuallyFoundFiveColoursEach(): void
    {
        $this->assertCount(5, $this->jsThemeDefaults());
        $this->assertCount(5, $this->cssRootPalette());
    }
}
