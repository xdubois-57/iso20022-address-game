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

/**
 * Records PHP line coverage for one HTTP request of the end-to-end run.
 *
 * Loaded through `auto_prepend_file` by scripts/e2e.sh when E2E_COVERAGE=1,
 * so it runs before public/index.php on every request the browser makes and
 * needs no cooperation from the application itself — nothing about this is
 * visible to, or reachable from, a normal install.
 *
 * Why bother: the unit suite exercises models and pure logic well, but the
 * controllers only really run when something drives the app over HTTP. The
 * browser tests already do exactly that, so measuring them turns work that
 * was already happening into coverage instead of leaving those files
 * apparently untested.
 *
 * One file is written per request into E2E_COVERAGE_DIR, because the built-in
 * server handles requests in separate processes and there is no safe way for
 * them to share one accumulating file. scripts/merge-coverage.php combines
 * them afterwards.
 */

$outputDir = getenv('E2E_COVERAGE_DIR') ?: '';

if ($outputDir === '' || !extension_loaded('pcov')) {
    return;
}

// Deliberately does NOT build a php-code-coverage object here.
//
// The first version did, and serialised one per request through the library's
// PHP report writer. That writes the whole object graph — including an entry
// for every file in the filter, covered or not — on every single request, and
// once the API traffic was routed through here too it made the suite several
// times slower and pushed tests past their timeouts.
//
// pcov's own collect() returns a plain array of file => [line => hit] for the
// handful of files a request actually touched. Writing that is cheap, and
// scripts/merge-coverage.php turns it back into real coverage data with
// RawCodeCoverageData::fromXdebugWithoutPathCoverage(), which is the exact
// shape pcov already produces.
\pcov\start();

register_shutdown_function(static function () use ($outputDir): void {
    try {
        \pcov\stop();
        $raw = \pcov\collect();
        if ($raw === []) {
            return;
        }

        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }
        // .raw rather than .cov so the merge step can tell the two producers
        // apart: PHPUnit writes a serialised CodeCoverage, this writes lines.
        $path = $outputDir . '/e2e-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.raw';
        @file_put_contents($path, serialize($raw));
    } catch (\Throwable) {
        // Never let coverage bookkeeping change the response the browser saw.
    }
});
