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
 * Merges every .cov file under coverage/php/raw/ into a single Clover report.
 *
 * Two producers write into that directory: PHPUnit (`--coverage-php`, one
 * file for the whole unit run) and scripts/e2e-coverage-prepend.php (one file
 * per HTTP request the browser made). Merging them is the point — a line
 * covered only by the browser tests is still covered, and SonarCloud is given
 * one number for the project rather than two partial ones that each look
 * worse than reality.
 *
 * Usage: php scripts/merge-coverage.php [raw-dir] [output-clover]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Report\Clover;

$rawDir = $argv[1] ?? __DIR__ . '/../coverage/php/raw';
$output = $argv[2] ?? __DIR__ . '/../coverage/php/clover.xml';

// Two producers, two formats: PHPUnit writes a serialised CodeCoverage
// (.cov), and scripts/e2e-coverage-prepend.php writes raw pcov line data
// (.raw) because building a full object per HTTP request was far too slow.
$covFiles = glob(rtrim($rawDir, '/') . '/*.cov') ?: [];
$rawFiles = glob(rtrim($rawDir, '/') . '/*.raw') ?: [];

if ($covFiles === [] && $rawFiles === []) {
    fwrite(STDERR, "No coverage files found in {$rawDir}\n");
    exit(1);
}

/** @var CodeCoverage|null $merged */
$merged = null;
$loaded = 0;
$skipped = 0;

foreach ($covFiles as $file) {
    try {
        $coverage = require $file;
    } catch (\Throwable) {
        // A run killed mid-write leaves a truncated file. One unusable
        // fragment must not discard the whole report.
        $skipped++;
        continue;
    }

    if (!$coverage instanceof CodeCoverage) {
        $skipped++;
        continue;
    }

    $merged === null ? $merged = $coverage : $merged->merge($coverage);
    $loaded++;
}

if ($rawFiles !== []) {
    // Raw files carry only the lines that executed, so something must supply
    // the denominator — otherwise files no request ever touched would simply
    // be absent and coverage would read far higher than it is.
    //
    // That something is the PHPUnit report loaded above, whose filter already
    // names every file under app/. Appending into it also sidesteps building a
    // second CodeCoverage, which would demand a coverage driver in this
    // process: merging is pure data handling and has no reason to need pcov
    // installed wherever it runs.
    if ($merged === null) {
        fwrite(
            STDERR,
            "Found .raw files but no .cov to merge them into.\n"
            . "Run `composer test:coverage` first: the unit report supplies the\n"
            . "list of files that were NOT executed, without which the result\n"
            . "would overstate coverage.\n"
        );
        exit(1);
    }

    foreach ($rawFiles as $file) {
        $raw = @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]);
        if (!is_array($raw) || $raw === []) {
            $skipped++;
            continue;
        }

        try {
            $merged->append(
                RawCodeCoverageData::fromXdebugWithoutPathCoverage($raw),
                'e2e-' . basename($file)
            );
            $loaded++;
        } catch (\Throwable) {
            $skipped++;
        }
    }
}

if ($merged === null) {
    fwrite(STDERR, "No usable coverage data in {$rawDir}\n");
    exit(1);
}

$outputDir = dirname($output);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

(new Clover())->process($merged, $output);

$xml = simplexml_load_file($output);
$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? 100 * $covered / $statements : 0.0;

printf(
    "Merged %d coverage file(s)%s -> %s\n  %.1f%% of statements (%d/%d)\n",
    $loaded,
    $skipped > 0 ? " ({$skipped} unusable, skipped)" : '',
    $output,
    $percent,
    $covered,
    $statements
);
