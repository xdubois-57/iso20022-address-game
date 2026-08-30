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

$autoload = __DIR__ . '/../vendor/autoload.php';
$outputDir = getenv('E2E_COVERAGE_DIR') ?: '';

if ($outputDir === '' || !is_file($autoload) || !extension_loaded('pcov')) {
    return;
}

require_once $autoload;

if (!class_exists(\SebastianBergmann\CodeCoverage\CodeCoverage::class)) {
    return;
}

$filter = new \SebastianBergmann\CodeCoverage\Filter();
$filter->includeDirectory(__DIR__ . '/../app');

try {
    $driver = (new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter);
} catch (\Throwable) {
    // No usable driver: let the request proceed uninstrumented rather than
    // taking the whole end-to-end run down over a coverage concern.
    return;
}

$coverage = new \SebastianBergmann\CodeCoverage\CodeCoverage($driver, $filter);
$coverage->start('e2e-request');

register_shutdown_function(static function () use ($coverage, $outputDir): void {
    try {
        $coverage->stop();
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }
        // Unique per request: pid plus a random suffix, since several
        // requests can share a pid over the life of the run.
        $path = $outputDir . '/e2e-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.cov';
        (new \SebastianBergmann\CodeCoverage\Report\PHP())->process($coverage, $path);
    } catch (\Throwable) {
        // Never let coverage bookkeeping change the response the browser saw.
    }
});
