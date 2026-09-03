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

/**
 * Pull SonarCloud's complete analysis of this project into the evidence pack.
 *
 *   SONAR_TOKEN=... php scripts/sonar-evidence.php <output-dir>
 *
 * The quality gate alone says pass or fail. It does not say what the analysis
 * found, how much of the code it covered, or where the debt sits — and an
 * evidence pack whose reader has to log in to SonarCloud to learn that is not
 * evidence. So this fetches the whole picture:
 *
 *   sonarcloud-quality-gate.json      the verdict, condition by condition
 *   sonarcloud-measures.json          every project-level metric
 *   sonarcloud-measures-by-file.json  the same, per file, so debt has a place
 *   sonarcloud-issues.json            every open issue, all pages
 *   sonarcloud-hotspots.json          security hotspots — a separate endpoint
 *   sonarcloud-report.md              the front page, for a human
 *
 * Hotspots are fetched deliberately. They live behind their own endpoint, so a
 * pack built from `issues/search` alone looks complete and quietly omits the
 * one category a security reviewer opens first.
 *
 * Without a token the script writes an UNAVAILABLE marker and exits 0. A
 * missing file reads as an oversight; a file that says "not available, and
 * why" reads as a fact, and a fork's pull request cannot see repository
 * secrets.
 */

declare(strict_types=1);

const PROJECT_KEY = 'xdubois-57_iso20022-address-game';
const API_BASE    = 'https://sonarcloud.io/api/';
const PAGE_SIZE   = 500;

/** Every metric worth recording, rather than the handful someone remembered. */
const METRICS = [
    'alert_status', 'quality_gate_details',
    'bugs', 'reliability_rating', 'reliability_remediation_effort',
    'vulnerabilities', 'security_rating', 'security_remediation_effort',
    'security_hotspots', 'security_hotspots_reviewed', 'security_review_rating',
    'code_smells', 'sqale_rating', 'sqale_index', 'sqale_debt_ratio',
    'coverage', 'line_coverage', 'branch_coverage', 'lines_to_cover',
    'uncovered_lines', 'tests', 'test_failures', 'test_errors', 'skipped_tests',
    'duplicated_lines_density', 'duplicated_blocks', 'duplicated_files',
    'ncloc', 'lines', 'statements', 'functions', 'classes', 'files',
    'comment_lines_density', 'cognitive_complexity', 'complexity',
];

$outDir = $argv[1] ?? 'evidence';
if (!is_dir($outDir) && !mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "sonar-evidence: cannot create {$outDir}\n");
    exit(1);
}

$token = (string) getenv('SONAR_TOKEN');
if ($token === '') {
    $marker = [
        'status' => 'UNAVAILABLE',
        'reason' => 'No SONAR_TOKEN was available to this run.',
    ];
    file_put_contents(
        $outDir . '/sonarcloud-quality-gate.json',
        json_encode($marker, JSON_PRETTY_PRINT) . "\n"
    );
    file_put_contents(
        $outDir . '/sonarcloud-report.md',
        "## SonarCloud\n\nUnavailable: no `SONAR_TOKEN` was available to this run.\n"
    );
    fwrite(STDERR, "sonar-evidence: SONAR_TOKEN is not set; wrote an UNAVAILABLE marker.\n");
    exit(0);
}

/**
 * One authenticated GET against the SonarCloud API.
 *
 * SonarCloud takes the token as the HTTP basic username with an empty
 * password, which is their documented scheme for tokens.
 */
function api(string $path, string $token): array
{
    $ch = curl_init(API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $token . ':',
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FAILONERROR    => false,
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status >= 400) {
        fwrite(STDERR, "sonar-evidence: GET {$path} failed (HTTP {$status}) {$error}\n");
        exit(1);
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "sonar-evidence: GET {$path} did not return JSON\n");
        exit(1);
    }

    return $decoded;
}

function writeJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/**
 * Follow the pagination to the end.
 *
 * The loop stops on a short page rather than on a page count, so it cannot
 * silently truncate the day this project has more than one page of issues —
 * which is exactly the day the report matters most.
 */
function fetchAllPages(string $path, string $key, string $token): array
{
    $all  = [];
    $page = 1;

    do {
        $sep      = str_contains($path, '?') ? '&' : '?';
        $response = api($path . $sep . 'ps=' . PAGE_SIZE . '&p=' . $page, $token);
        $batch    = $response[$key] ?? [];
        $all      = array_merge($all, $batch);
        $page++;
    } while (count($batch) === PAGE_SIZE && $page <= 20);

    return $all;
}

// ── Fetch ───────────────────────────────────────────────────────────────────
$gate = api('qualitygates/project_status?projectKey=' . PROJECT_KEY, $token);
writeJson($outDir . '/sonarcloud-quality-gate.json', $gate);

$measures = api(
    'measures/component?component=' . PROJECT_KEY . '&metricKeys=' . implode(',', METRICS),
    $token
);
writeJson($outDir . '/sonarcloud-measures.json', $measures);

$byFile = api(
    'measures/component_tree?component=' . PROJECT_KEY
    . '&qualifiers=FIL&ps=500&metricKeys='
    . 'ncloc,coverage,bugs,vulnerabilities,code_smells,duplicated_lines_density,cognitive_complexity',
    $token
);
writeJson($outDir . '/sonarcloud-measures-by-file.json', $byFile);

$issues = fetchAllPages(
    'issues/search?componentKeys=' . PROJECT_KEY . '&statuses=OPEN,CONFIRMED,REOPENED',
    'issues',
    $token
);
writeJson($outDir . '/sonarcloud-issues.json', ['total' => count($issues), 'issues' => $issues]);

$hotspots = fetchAllPages(
    'hotspots/search?projectKey=' . PROJECT_KEY,
    'hotspots',
    $token
);
writeJson($outDir . '/sonarcloud-hotspots.json', ['total' => count($hotspots), 'hotspots' => $hotspots]);

// ── Report ──────────────────────────────────────────────────────────────────
$measureMap = [];
foreach ($measures['component']['measures'] ?? [] as $measure) {
    $measureMap[$measure['metric']] = $measure['value'] ?? '';
}

/** SonarCloud returns ratings as 1..5; nobody reads "3.0". */
function rating(string $value): string
{
    $letters = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D', '5' => 'E'];

    return $letters[substr($value, 0, 1)] ?? $value;
}

function metric(array $map, string $key, string $fallback = 'n/a'): string
{
    return $map[$key] ?? $fallback;
}

$lines = [];
$lines[] = '## SonarCloud — full analysis';
$lines[] = '';
$lines[] = 'Quality gate: **' . ($gate['projectStatus']['status'] ?? 'UNKNOWN') . '**';
$lines[] = '';

$failed = array_filter(
    $gate['projectStatus']['conditions'] ?? [],
    static fn (array $c): bool => ($c['status'] ?? '') !== 'OK'
);
if ($failed !== []) {
    $lines[] = 'Failing conditions:';
    $lines[] = '';
    foreach ($failed as $condition) {
        $lines[] = '- `' . ($condition['metricKey'] ?? '?') . '` = '
            . ($condition['actualValue'] ?? '?')
            . ' (threshold ' . ($condition['errorThreshold'] ?? '?') . ')';
    }
    $lines[] = '';
}

$lines[] = '| Measure | Value |';
$lines[] = '|---|---|';

// Built row by row rather than as one concatenation per line: each of these
// reads three or four measures, and on a single line none of them could be
// scanned for the one that is wrong.
// Each value is built first and the table row written on one line. The
// arithmetic and the formatting are separate concerns, and putting them on the
// same line was how this block reached 200 characters the first time.
$m = static fn (string $key): string => metric($measureMap, $key);

$reliability   = rating($m('reliability_rating')) . ' — ' . $m('bugs') . ' bug(s)';
$security      = rating($m('security_rating')) . ' — ' . $m('vulnerabilities') . ' vulnerability(ies)';
$review        = rating($m('security_review_rating')) . ' — ' . $m('security_hotspots')
    . ' hotspot(s), ' . $m('security_hotspots_reviewed') . '% reviewed';
$maintain      = rating($m('sqale_rating')) . ' — ' . $m('code_smells') . ' code smell(s), '
    . $m('sqale_index') . ' min of debt';
$coverageText  = $m('coverage') . '% (' . $m('uncovered_lines') . ' uncovered of '
    . $m('lines_to_cover') . ')';
$duplication   = $m('duplicated_lines_density') . '% over ' . $m('duplicated_blocks') . ' block(s)';
$size          = $m('ncloc') . ' lines of code, ' . $m('files') . ' file(s)';
$complexity    = $m('cognitive_complexity') . ' cognitive, ' . $m('complexity') . ' cyclomatic';

foreach ([
    'Reliability'     => $reliability,
    'Security'        => $security,
    'Security review' => $review,
    'Maintainability' => $maintain,
    'Coverage'        => $coverageText,
    'Duplication'     => $duplication,
    'Size'            => $size,
    'Complexity'      => $complexity,
] as $label => $value) {
    $lines[] = '| ' . $label . ' | ' . $value . ' |';
}

$lines[] = '';

$lines[] = '### Open issues (' . count($issues) . ')';
$lines[] = '';

if ($issues === []) {
    $lines[] = 'None.';
} else {
    $bySeverity = [];
    $byRule     = [];
    foreach ($issues as $issue) {
        $severity = $issue['severity'] ?? 'UNKNOWN';
        $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
        $ruleKey = ($issue['rule'] ?? '?') . '|' . ($issue['type'] ?? '?') . '|' . $severity;
        $byRule[$ruleKey] = ($byRule[$ruleKey] ?? 0) + 1;
    }

    $order = ['BLOCKER' => 0, 'CRITICAL' => 1, 'MAJOR' => 2, 'MINOR' => 3, 'INFO' => 4];
    uksort($bySeverity, static fn ($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));

    $lines[] = '| Severity | Count |';
    $lines[] = '|---|---|';
    foreach ($bySeverity as $severity => $count) {
        $lines[] = '| ' . $severity . ' | ' . $count . ' |';
    }
    $lines[] = '';

    arsort($byRule);
    $lines[] = '| Rule | Type | Severity | Count |';
    $lines[] = '|---|---|---|---|';
    foreach ($byRule as $key => $count) {
        [$rule, $type, $severity] = explode('|', $key);
        $lines[] = '| `' . $rule . '` | ' . $type . ' | ' . $severity . ' | ' . $count . ' |';
    }
}

$lines[] = '';
$lines[] = '### Security hotspots (' . count($hotspots) . ')';
$lines[] = '';
if ($hotspots === []) {
    $lines[] = 'None.';
} else {
    $byStatus = [];
    foreach ($hotspots as $hotspot) {
        $status = ($hotspot['vulnerabilityProbability'] ?? '?') . ' / ' . ($hotspot['status'] ?? '?');
        $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
    }
    $lines[] = '| Probability / status | Count |';
    $lines[] = '|---|---|';
    foreach ($byStatus as $status => $count) {
        $lines[] = '| ' . $status . ' | ' . $count . ' |';
    }
}

$lines[] = '';
$lines[] = 'The machine-readable record is alongside this file: '
    . '`sonarcloud-quality-gate.json`, `sonarcloud-measures.json`, '
    . '`sonarcloud-measures-by-file.json`, `sonarcloud-issues.json` and '
    . '`sonarcloud-hotspots.json`.';
$lines[] = '';

file_put_contents($outDir . '/sonarcloud-report.md', implode("\n", $lines));

fwrite(
    STDERR,
    'sonar-evidence: wrote ' . count($issues) . ' issue(s) and '
    . count($hotspots) . " hotspot(s) to {$outDir}\n"
);
