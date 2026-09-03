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
use Tests\Support\UsesInMemoryDatabase;

/**
 * How the sharing setting reaches the browser: one attribute on <body>, the
 * same mechanism as data-mode.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SharingShellTest extends TestCase
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

    /** Render layout.php exactly as public/index.php does, sharing enabled. */
    private function renderSharingEnabled(): string
    {
        $sharingEnabled = true;
        ob_start();
        require __DIR__ . '/../app/Views/layout.php';
        return (string) ob_get_clean();
    }

    /** The same, with the setting switched off. */
    private function renderSharingDisabled(): string
    {
        $sharingEnabled = false;
        ob_start();
        require __DIR__ . '/../app/Views/layout.php';
        return (string) ob_get_clean();
    }

    /**
     * $sharingEnabled never defined at all — a separate method rather than an
     * unset(), so the variable genuinely does not exist in the include's
     * scope.
     */
    private function renderWithoutTheVariable(): string
    {
        ob_start();
        require __DIR__ . '/../app/Views/layout.php';
        return (string) ob_get_clean();
    }

    /**
     * The default renders the <body> tag it always rendered.
     *
     * Asserted as the absence of the attribute rather than as
     * data-sharing="on": a default installation's markup must stay
     * indistinguishable from what it was before this setting existed, exactly
     * as ?mode's absence is.
     */
    public function testAnEnabledInstallationCarriesNoAttribute(): void
    {
        $this->assertStringNotContainsString('data-sharing', $this->renderSharingEnabled());
    }

    public function testADisabledInstallationSaysSoOnTheBody(): void
    {
        $html = $this->renderSharingDisabled();

        $this->assertStringContainsString('<body data-sharing="off">', $html);
    }

    /**
     * A missing variable must not take the share buttons away. layout.php is
     * included directly by tests and could be by a future entry point;
     * defaulting the other way would make "somebody forgot to set it" look
     * exactly like "an administrator switched it off".
     */
    public function testAnUndefinedVariableMeansEnabled(): void
    {
        $html = $this->renderWithoutTheVariable();

        $this->assertStringNotContainsString('data-sharing', $html);
        // …and no warning leaked into the markup on the way.
        $this->assertStringNotContainsString('Warning', $html);
        $this->assertStringNotContainsString('Undefined variable', $html);
    }

    /** The two attributes are independent and coexist on the same tag. */
    public function testTheModeAndTheSharingAttributeDoNotDisplaceEachOther(): void
    {
        $displayMode = 'hof';
        $sharingEnabled = false;

        ob_start();
        require __DIR__ . '/../app/Views/layout.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<body data-mode="hof" data-sharing="off">', $html);
    }
}
