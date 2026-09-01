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
use Tests\Support\UsesInMemoryDatabase;

/**
 * The shell a dedicated screen is served, and the shell everything else is
 * served.
 *
 * The point of the whole mode mechanism is that the nav is ABSENT from the
 * markup for the two dedicated screens rather than hidden after the fact —
 * hiding it in JavaScript would flash the menus on every load of an
 * unattended wall, and leave four buttons in the DOM for anyone tabbing
 * through. Asserting on the rendered HTML is the only place that property is
 * actually observable.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DisplayModeShellTest extends TestCase
{
    use UsesInMemoryDatabase;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();
    }

    /**
     * Render app/Views/layout.php exactly as public/index.php does, with the
     * mode already resolved.
     *
     * The whitelisting itself lives in index.php and is asserted separately
     * below, against that file's own source: index.php cannot simply be
     * included here, since it is a front controller that starts a session,
     * sends headers and dispatches on the request method.
     */
    private function renderShell(?string $mode): string
    {
        return $mode === null ? $this->renderWithoutMode() : $this->renderWithMode($mode);
    }

    /** $displayMode set, exactly as index.php leaves it before the require. */
    private function renderWithMode(string $displayMode): string
    {
        ob_start();
        require __DIR__ . '/../app/Views/layout.php';
        return (string) ob_get_clean();
    }

    /**
     * $displayMode never defined at all — a separate method rather than an
     * unset(), so the variable genuinely does not exist in the include's
     * scope. layout.php has to cope with that without emitting a warning into
     * the middle of the <body> tag.
     */
    private function renderWithoutMode(): string
    {
        ob_start();
        require __DIR__ . '/../app/Views/layout.php';
        return (string) ob_get_clean();
    }

    public function testBareUrlKeepsTheNavAndTheHamburger(): void
    {
        $html = $this->renderShell(null);

        $this->assertStringContainsString('<nav class="header-nav"', $html);
        $this->assertStringContainsString('id="hamburgerBtn"', $html);
        $this->assertStringNotContainsString('data-mode=', $html);

        // All four buttons, not merely the container.
        foreach (['data-screen="game"', 'data-screen="leaderboard"', 'data-screen="admin"', 'id="stopBtn"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testWallShellHasNoNav(): void
    {
        $html = $this->renderShell('hof');

        $this->assertStringContainsString('data-mode="hof"', $html);
        $this->assertStringNotContainsString('header-nav', $html);
        $this->assertStringNotContainsString('hamburgerBtn', $html);
        $this->assertStringNotContainsString('data-screen="leaderboard"', $html);

        // Not display:none — the markup itself must be gone.
        $this->assertStringNotContainsString('nav-btn', $html);
    }

    public function testPlayShellHasNoNav(): void
    {
        $html = $this->renderShell('play');

        $this->assertStringContainsString('data-mode="play"', $html);
        $this->assertStringNotContainsString('header-nav', $html);
        $this->assertStringNotContainsString('hamburgerBtn', $html);
        $this->assertStringNotContainsString('nav-btn', $html);
    }

    public function testTheTitleSurvivesInBothModes(): void
    {
        foreach (['hof', 'play'] as $mode) {
            $this->assertStringContainsString(
                '<h1 class="logo">ISO 20022 Address Game</h1>',
                $this->renderShell($mode),
                "the title must stay visible in ?mode={$mode}"
            );
        }
    }

    public function testWallFooterDropsPrivacyAndGithubButKeepsTheEndorsement(): void
    {
        $html = $this->renderShell('hof');

        $this->assertStringNotContainsString('data-screen="privacy"', $html);
        $this->assertStringNotContainsString('footerGithubLink', $html);

        // The whole reason the footer is not simply dropped: on a wall, the
        // endorsement is what that space is for.
        $this->assertStringContainsString('footer-endorsement', $html);
        $this->assertStringContainsString('alt="Payments Market Practice Group"', $html);

        // The lockup stands on its own: the visible label was dropped. Matched
        // as element text (`>Supported by<`) rather than as a bare substring,
        // because the OpenGraph description in the same document still says
        // the game is supported by the PMPG — that is prose, not a label, and
        // it stays.
        $this->assertStringNotContainsString('>Supported by<', $html);
    }

    public function testPlayFooterKeepsPrivacyAndGithub(): void
    {
        $html = $this->renderShell('play');

        // A player standing at the station must still be able to read the
        // privacy notice.
        $this->assertStringContainsString('data-screen="privacy"', $html);
        $this->assertStringContainsString('footerGithubLink', $html);
        $this->assertStringContainsString('footer-endorsement', $html);
    }

    /**
     * An unknown mode is not an error, it is the default.
     *
     * layout.php is the last line of defence here (index.php's allowlist is
     * asserted below), and it has to fall back silently: an unattended screen
     * whose URL has a typo should serve the ordinary game rather than
     * something nobody is present to notice.
     */
    public function testAnUnknownModeRendersTheOrdinaryShell(): void
    {
        foreach (['nimportequoi', 'kiosk', 'HOF', '../hof', '" onload="x'] as $bogus) {
            $html = $this->renderShell($bogus);

            $this->assertStringContainsString('<nav class="header-nav"', $html, "mode={$bogus}");
            $this->assertStringNotContainsString('data-mode=', $html, "mode={$bogus}");
        }
    }

    /**
     * The allowlist in the front controller, read from its source.
     *
     * index.php is not includable from a test — it is a front controller that
     * starts a session and dispatches — so this asserts on the one property
     * that matters: the set of accepted values is spelled out literally, and
     * 'kiosk' is not in it. The iPad kiosk keeps its Admin toggle; adding it
     * here would quietly create a second way to turn it on.
     */
    public function testFrontControllerAllowlistIsStrictAndExcludesKiosk(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../public/index.php');

        $this->assertMatchesRegularExpression(
            "#in_array\(\\\$displayMode, \['', 'hof', 'play'\], true\)#",
            $source,
            'the ?mode allowlist must stay a literal, strict, three-value list'
        );

        // Asserted through the allowlist rather than by searching the whole
        // file for the word: the comment above it says why kiosk is absent,
        // and a test that forbids saying so would forbid explaining it.
        preg_match("#in_array\(\\\$displayMode, \[([^\]]*)\]#", $source, $m);
        $this->assertStringNotContainsString('kiosk', $m[1] ?? 'kiosk');
    }

    /**
     * The mode is read from the URL, not from the session.
     *
     * A session flag does not survive the reboot of an unattended PC, which
     * is the entire reason this mechanism exists. Storing the mode in
     * $_SESSION would look like a harmless tidy-up and would silently undo it.
     */
    public function testTheModeIsNotStoredInTheSession(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../public/index.php');

        $this->assertStringContainsString("\$displayMode = \$_GET['mode'] ?? '';", $source);
        $this->assertStringNotContainsString("\$_SESSION['mode']", $source);
        $this->assertStringNotContainsString("\$_SESSION['display_mode']", $source);
    }
}
