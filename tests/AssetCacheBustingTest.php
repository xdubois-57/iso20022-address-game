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
 * Every asset URL the page hands out must change when the asset does.
 *
 * This is not a theoretical property. A stale app.css renders the site with
 * the PMPG logo at its natural 1095px and the layout in ruins, and the
 * failure is invisible from the server: the files on disk are correct and
 * every test passes while a browser quietly serves yesterday's copy.
 *
 * The drift this guards against is the module map falling behind app.js.
 * app.js's own URL is versioned by its <script> tag, but its `import`s are
 * resolved by the browser and carry whatever the import map says — so an
 * import added to app.js without a matching map entry is fetched
 * unversioned, and nothing anywhere fails until someone edits that file
 * months later and their change does not appear.
 */
class AssetCacheBustingTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../app/Views/layout.php';
    private const APP_JS = __DIR__ . '/../public/assets/js/app.js';

    private function layout(): string
    {
        return (string) file_get_contents(self::LAYOUT);
    }

    /**
     * Every relative `import` app.js makes, as the specifier written in the
     * source — the thing the import map has to key on.
     *
     * @return list<string>
     */
    private function appJsImports(): array
    {
        preg_match_all(
            '#^\s*import\s[^\'"]*[\'"]\./([^\'"]+)[\'"]#m',
            (string) file_get_contents(self::APP_JS),
            $m
        );

        return $m[1];
    }

    /** The lib names layout.php feeds into the import map. */
    private function mappedModules(): array
    {
        preg_match(
            "#foreach \(\[([^\]]+)\] as \\\$lib\)#",
            $this->layout(),
            $m
        );
        $this->assertNotEmpty($m, 'the import map loop must be findable in layout.php');

        preg_match_all("#'([a-z]+)'#", $m[1], $libs);

        return $libs[1];
    }

    public function testEveryModuleAppJsImportsIsVersionedByTheImportMap(): void
    {
        $mapped = $this->mappedModules();
        $this->assertNotEmpty($mapped);

        foreach ($this->appJsImports() as $specifier) {
            // Only lib/ modules are mapped; anything else would be a new
            // shape of import that needs its own decision, so fail loudly.
            $this->assertStringStartsWith(
                'lib/',
                $specifier,
                "app.js imports './{$specifier}', which the import map does not cover"
            );

            $name = basename($specifier, '.js');
            $this->assertContains(
                $name,
                $mapped,
                "app.js imports './{$specifier}' but layout.php's import map has no entry for it — "
                    . 'the browser would fetch it unversioned and never see a change'
            );
        }
    }

    public function testTheImportMapKeysAreUrlLikeNotBareSpecifiers(): void
    {
        // A key without './' is a BARE specifier and matches only an import
        // written exactly that way — so the map silently does nothing.
        $this->assertStringContainsString(
            "\$moduleVersions['./assets/js/lib/'",
            $this->layout(),
            'import map keys must start with ./ or they match nothing'
        );
    }

    public function testTheImportMapPrecedesTheModuleScript(): void
    {
        $layout = $this->layout();
        $map = strpos($layout, 'type="importmap"');
        $module = strpos($layout, 'type="module"');

        $this->assertNotFalse($map);
        $this->assertNotFalse($module);
        $this->assertLessThan($map === false ? 0 : $module, $map, 'an import map after the module script is ignored');
    }

    public function testAssetUrlCombinesMtimeAndReleaseStamp(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('filemtime($fullPath)', $layout);
        $this->assertStringContainsString('assetReleaseStamp()', $layout);
        $this->assertMatchesRegularExpression(
            '#\$path \. \'\?v=\' \. \$mtime#',
            $layout,
            'the version must carry the mtime AND the release stamp: an FTP upload that '
                . 'preserves timestamps leaves the mtime alone, and an edit without a release '
                . 'leaves the commit alone'
        );
    }

    public function testTheShellDeclaresItselfUncacheable(): void
    {
        // The shell mints every versioned URL on the page, so a cached copy
        // keeps handing out the previous ones for as long as it lives.
        $this->assertStringContainsString(
            "header('Cache-Control: no-store, no-cache, must-revalidate');",
            $this->layout()
        );
    }

    public function testNoAssetUrlCarriesAHandWrittenVersion(): void
    {
        // '?v=1' shipped in app.js for the PMPG logo: a literal version can
        // never change, so replacing the file would never have reached a
        // browser that already had it.
        foreach ([self::APP_JS, self::LAYOUT] as $file) {
            // Comments are stripped first: this file's own history is
            // documented in prose that quotes the very literal it forbids,
            // and a test that cannot survive being explained is no good.
            $code = preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m'],
                '',
                (string) file_get_contents($file)
            );

            $this->assertDoesNotMatchRegularExpression(
                '#\?v=\d+[\'"]#',
                (string) $code,
                basename($file) . ' carries a hard-coded ?v= that can never change'
            );
        }
    }
}
