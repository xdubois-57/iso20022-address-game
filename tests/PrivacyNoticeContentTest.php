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

/**
 * What the Privacy screen is allowed to say about who stands behind the game.
 *
 * The answer is: the authors, and nothing else. Not a supporting organisation,
 * and — the half that is easy to get wrong while fixing the other — not a
 * denial of one either. Both sentences have stood on this screen at different
 * times and both went stale: "not affiliated with or endorsed by any
 * organisation" stopped being true when a lockup went onto the home screen,
 * and naming a supporter put this screen in the business of speaking for a
 * third party. Silence is the only position that cannot rot.
 *
 * Asserted against public/assets/js/app.js's source. The screen is built by a
 * string-concatenating function inside the SPA's single IIFE, which cannot be
 * imported under jsdom without booting the whole application against a fake
 * document; the rendered result is asserted end to end by
 * tests/e2e/specs/privacy.spec.js. This test is the fast half, and the half
 * that also guards the rest of the file.
 */
class PrivacyNoticeContentTest extends TestCase
{
    private const APP_JS = __DIR__ . '/../public/assets/js/app.js';

    private static function source(): string
    {
        return (string) file_get_contents(self::APP_JS);
    }

    /**
     * The body of renderPrivacyScreen(), from its opening line to the closing
     * brace at the function's own indentation.
     */
    private static function privacyScreenSource(): string
    {
        $source = self::source();

        $start = strpos($source, 'function renderPrivacyScreen()');
        self::assertNotFalse($start, 'renderPrivacyScreen() must still exist in app.js');

        $end = strpos($source, "\n    }\n", $start);
        self::assertNotFalse($end, 'could not find the end of renderPrivacyScreen()');

        return substr($source, $start, $end - $start);
    }

    public function testPrivacyScreenNamesNoSupportingOrganisation(): void
    {
        $screen = self::privacyScreenSource();

        $this->assertStringNotContainsString('PMPG', $screen);
        $this->assertStringNotContainsString('Payments Market Practice Group', $screen);
    }

    /**
     * Everything app.js can actually put on a screen: its source with the
     * comments taken out.
     *
     * Scanning the raw file would be simpler and wrong. The comment above
     * renderPrivacyScreen() quotes the retired wording precisely so nobody
     * puts it back, and a check that forbade naming the mistake would forbid
     * explaining it. Only lines that reach the DOM are searched here.
     *
     * Comments are recognised by shape rather than parsed: a line whose first
     * non-space characters are `//`, and everything between `/*` and its
     * close. A `//` inside a string (every https:// in the file) never starts
     * a line, so it survives — which is what makes this safe to apply to the
     * whole file.
     */
    private static function renderableSource(): string
    {
        $lines = preg_split('/\r?\n/', self::source()) ?: [];
        $kept = [];
        $inBlock = false;

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if ($inBlock) {
                if (str_contains($line, '*' . '/')) {
                    $inBlock = false;
                }
                continue;
            }
            if (str_starts_with($trimmed, '/*')) {
                if (!str_contains($trimmed, '*' . '/')) {
                    $inBlock = true;
                }
                continue;
            }
            if (str_starts_with($trimmed, '//')) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * The trap. Removing the endorsement must not bring back the denial that
     * the endorsement replaced — the page has to stay quiet, not swap one
     * claim for the opposite one while the welcome card carries a lockup.
     *
     * Checked across the whole of app.js, not just the Privacy screen: the
     * sentence would be just as false in a footer or a share card.
     */
    public function testTheAffiliationDenialIsNotReintroducedAnywhere(): void
    {
        $this->assertStringNotContainsStringIgnoringCase(
            'not affiliated',
            self::renderableSource(),
            'the "not affiliated with or endorsed by any organisation" wording must stay gone'
        );
    }

    /** The comment stripper must not quietly swallow the strings it walks past. */
    public function testTheCommentStripperKeepsWhatTheScreenRenders(): void
    {
        $renderable = self::renderableSource();

        $this->assertStringContainsString('<h3>1. Data Controller</h3>', $renderable);
        $this->assertStringContainsString('https://www.gnu.org/licenses/gpl-3.0.html', $renderable);
    }

    /**
     * The GDPR half is untouched by all of the above: section 1 names the two
     * data controllers, and only them. Naming anyone else there would be an
     * inaccurate declaration, and dropping either name would be a worse one.
     */
    public function testTheDataControllerParagraphStillNamesBothAuthorsAndNobodyElse(): void
    {
        $screen = self::privacyScreenSource();

        $this->assertStringContainsString('<h3>1. Data Controller</h3>', $screen);
        $this->assertStringContainsString(
            'The data controllers for this application are <strong>Xavier Dubois</strong> '
            . 'and <strong>Niel Buchan</strong>',
            $screen
        );
    }

    /** The authors are still credited — silence about a supporter, not about them. */
    public function testTheAuthorsAreStillCredited(): void
    {
        $screen = self::privacyScreenSource();

        $this->assertStringContainsString('Xavier Dubois', $screen);
        $this->assertStringContainsString('Niel Buchan', $screen);
    }
}
