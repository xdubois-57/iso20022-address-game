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
use SebastianBergmann\CodeCoverage\Report\Clover;

$rawDir = $argv[1] ?? __DIR__ . '/../coverage/php/raw';
$output = $argv[2] ?? __DIR__ . '/../coverage/php/clover.xml';

$files = glob(rtrim($rawDir, '/') . '/*.cov') ?: [];
if ($files === []) {
    fwrite(STDERR, "No .cov files found in {$rawDir}\n");
    exit(1);
}

/** @var CodeCoverage|null $merged */
$merged = null;
$loaded = 0;
$skipped = 0;

foreach ($files as $file) {
    try {
        $coverage = require $file;
    } catch (\Throwable $e) {
        // A request killed mid-write leaves a truncated file. One unusable
        // fragment out of hundreds must not discard the whole run.
        $skipped++;
        continue;
    }

    if (!$coverage instanceof CodeCoverage) {
        $skipped++;
        continue;
    }

    if ($merged === null) {
        $merged = $coverage;
    } else {
        $merged->merge($coverage);
    }
    $loaded++;
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
