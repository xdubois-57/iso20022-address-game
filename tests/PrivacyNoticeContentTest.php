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

    /**
     * The date the Privacy screen shows, and a fingerprint of everything else
     * on it.
     *
     * A privacy notice that says "Last updated: May 2026" while its text has
     * moved on is worse than one with no date at all: the date is the only
     * thing telling a reader whether what they agreed to still holds, and a
     * stale one answers the question wrongly rather than leaving it open. It
     * had gone stale — the licence paragraph on that very screen changed with
     * the move to the AGPL in September, months after the date it claimed.
     *
     * Nothing reminds anybody to touch a date. So the fingerprint does: change
     * a word of the screen's text and this test fails, naming both things that
     * have to move together.
     */
    private const LAST_UPDATED = 'September 2026';
    private const CONTENT_FINGERPRINT = '4e7a90ff374eb1a2f67f46c7a8959e681bf6e945089ba2ff972370f8aea9503b';

    /**
     * The screen's rendered text: comments removed, the "Last updated" line
     * removed, whitespace collapsed.
     *
     * All three exclusions matter. Comments go because explaining a retired
     * sentence is not publishing it. The date line goes because it is the
     * thing being checked and would otherwise make the fingerprint depend on
     * itself. Whitespace collapses because re-indenting a function is not a
     * change to a privacy notice, and a test that cried wolf over formatting
     * would be regenerated blindly the first week.
     */
    private static function privacyScreenFingerprint(): string
    {
        $body = self::privacyScreenSource();

        $lines = preg_split('/\r?\n/', $body) ?: [];
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
            if (str_starts_with($trimmed, '//') || str_contains($line, 'Last updated:')) {
                continue;
            }

            $kept[] = $line;
        }

        $text = (string) preg_replace('/\s+/', ' ', implode(' ', $kept));

        return hash('sha256', trim($text));
    }

    public function testTheScreenShowsTheRecordedLastUpdatedDate(): void
    {
        $screen = self::privacyScreenSource();

        $matched = preg_match('/Last updated: ([A-Z][a-z]+ \d{4})/', $screen, $m);
        $this->assertSame(1, $matched, 'the Privacy screen must show a "Last updated: Month Year" date');
        $this->assertSame(
            self::LAST_UPDATED,
            $m[1],
            'the date on the screen and the date recorded in this test have diverged'
        );
    }

    /**
     * The reminder. This is the test that exists to fail.
     */
    public function testChangingTheScreenForcesTheDateToBeReconsidered(): void
    {
        $this->assertSame(
            self::CONTENT_FINGERPRINT,
            self::privacyScreenFingerprint(),
            "The Privacy screen's text has changed.\n\n"
            . "This is not a failure to work around — it is the reminder. A privacy "
            . "notice carrying a date older than its own contents tells a reader that "
            . "nothing has changed since then, which is the one thing it must never say "
            . "wrongly.\n\n"
            . "Two things to do, in this order:\n"
            . "  1. Update 'Last updated:' in renderPrivacyScreen() to the month of THIS "
            . "change, and LAST_UPDATED in this test to match.\n"
            . "  2. Replace CONTENT_FINGERPRINT with the value this failure reports as "
            . "actual.\n\n"
            . "Doing only step 2 silences the test and leaves the notice lying about its "
            . "own age."
        );
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
        $this->assertStringContainsString('https://www.gnu.org/licenses/agpl-3.0.html', $renderable);
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
