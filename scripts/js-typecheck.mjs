#!/usr/bin/env node
/*
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

// The JavaScript static-analysis gate - `npm run typecheck`.
//
// Runs `tsc --noEmit` over the plain JavaScript in public/assets/js/ (see
// tsconfig.json: a checker, never a build step) and compares what comes back
// against js-typecheck-baseline.json, failing only on a finding that is NEW.
//
// WHY A BASELINE AT ALL
// ---------------------------------------------------------------------
// PHPStan has this built in; tsc does not. Most of this codebase predates any
// JavaScript type checking and leans throughout on document.getElementById()
// and querySelector() returning the generic Element/HTMLElement rather than
// the subtype the calling code assumes - `.value`, `.disabled` and `.tagName`
// do not exist on the generic type. Those are dozens of findings, none of them
// a regression, and none of them fixable as part of installing the tool.
//
// Without a baseline the job would be red permanently and everybody would
// learn to ignore it, which is strictly worse than having no analysis: a gate
// nobody reads still costs a minute on every push and buys nothing.
//
// KEYED BY (FILE, CODE, MESSAGE) AND NEVER BY LINE
// ---------------------------------------------------------------------
// This is the part that decides whether the tool is usable. A baseline that
// remembered line numbers would report a fresh "new finding" for every
// pre-existing one below an edit, because inserting five lines at the top of
// app.js moves all of them. Grouping by file + error code + message, and
// recording how many occurrences are accepted, means an unrelated edit
// elsewhere in the same file changes nothing.
//
// REGENERATING
// ---------------------------------------------------------------------
//   node scripts/js-typecheck.mjs --generate-baseline
//
// Only ever to deliberately accept existing debt you are not fixing right
// now. NEVER to silence a finding your own change just introduced - fix that
// one. A baseline regenerated to hide a regression turns the gate into
// decoration while leaving everybody sure it works.

import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const tscBin = path.join(repoRoot, 'node_modules', '.bin', process.platform === 'win32' ? 'tsc.cmd' : 'tsc');
const tsconfigPath = path.join(repoRoot, 'tsconfig.json');
const baselinePath = path.join(repoRoot, 'js-typecheck-baseline.json');

const generateBaseline = process.argv.includes('--generate-baseline');

// JSON has no comment syntax, so the file carries its warning as `//` lines
// that this script strips before parsing. The warning is the reason the file
// exists; putting it anywhere else would mean nobody opening the file reads it.
const BASELINE_HEADER = [
    '// Pre-existing JavaScript static-analysis findings, accepted as of the run that',
    '// generated this file. See scripts/js-typecheck.mjs.',
    '//',
    '// Regenerate ONLY to deliberately accept existing debt you are not fixing right',
    '// now. NEVER to silence a finding your own change just introduced - fix that one.',
    '//',
    '// Keyed by file + error code + message, never by line number: an edit elsewhere',
    '// in a file must not report the findings below it as new.',
    '',
].join('\n');

if (!existsSync(tscBin)) {
    console.error(`ERROR: ${tscBin} not found - run 'npm ci' first (see README.md, Running Tests).`);
    process.exit(1);
}

const result = spawnSync(tscBin, ['-p', tsconfigPath, '--noEmit'], { cwd: repoRoot, encoding: 'utf8' });

if (result.error) {
    console.error(`ERROR: could not run tsc: ${result.error.message}`);
    process.exit(1);
}

const stdout = result.stdout || '';
const stderr = result.stderr || '';

// tsc writes one diagnostic per line as "file(line,col): error TSxxxx: message".
// Its own fatal errors - an unreadable tsconfig, a pattern matching no input -
// go to stderr instead and produce no parseable diagnostics at all.
const DIAGNOSTIC = /^(.+?)\((\d+),(\d+)\): error (TS\d+): (.+)$/;

const diagnostics = [];
for (const line of stdout.split('\n')) {
    const match = DIAGNOSTIC.exec(line.trim());
    if (match) {
        diagnostics.push({
            // Normalised to forward slashes so a baseline generated on one
            // platform still matches on another.
            file: match[1].split(path.sep).join('/'),
            line: Number(match[2]),
            column: Number(match[3]),
            code: match[4],
            message: match[5],
        });
    }
}

// A non-zero exit with nothing parseable means the check did not run. That has
// to be a hard failure and never a silent "0 findings": a broken tsconfig would
// otherwise read exactly like a clean codebase.
if (diagnostics.length === 0 && result.status !== 0) {
    console.error(`ERROR: tsc exited ${result.status} with no parseable diagnostics - the check did not run:`);
    console.error(stdout.trim() || '(no stdout)');
    if (stderr.trim() !== '') console.error(stderr.trim());
    process.exit(1);
}

const keyOf = (d) => `${d.file} ${d.code} ${d.message}`;

/** @type {Map<string, {file: string, code: string, message: string, occurrences: {line: number, column: number}[]}>} */
const current = new Map();
for (const d of diagnostics) {
    const key = keyOf(d);
    let group = current.get(key);
    if (!group) {
        group = { file: d.file, code: d.code, message: d.message, occurrences: [] };
        current.set(key, group);
    }
    group.occurrences.push({ line: d.line, column: d.column });
}

if (generateBaseline) {
    /** @type {Record<string, {code: string, message: string, count: number}[]>} */
    const baseline = {};
    for (const group of current.values()) {
        baseline[group.file] ??= [];
        baseline[group.file].push({
            code: group.code,
            message: group.message,
            count: group.occurrences.length,
        });
    }
    // Sorted, so regenerating produces a diff a reviewer can read rather than
    // a reshuffle of the whole file.
    for (const file of Object.keys(baseline)) {
        baseline[file].sort((a, b) => a.code.localeCompare(b.code) || a.message.localeCompare(b.message));
    }
    const sorted = Object.fromEntries(Object.entries(baseline).sort(([a], [b]) => a.localeCompare(b)));

    writeFileSync(baselinePath, `${BASELINE_HEADER}${JSON.stringify(sorted, null, 4)}\n`);
    console.log(
        `Baseline written to ${path.relative(repoRoot, baselinePath)}: `
        + `${diagnostics.length} finding(s) across ${current.size} distinct (file, code, message) group(s).`
    );
    process.exit(0);
}

function loadBaseline() {
    if (!existsSync(baselinePath)) return {};
    return JSON.parse(readFileSync(baselinePath, 'utf8').replace(/^(\/\/.*\n)+/, ''));
}

const baseline = loadBaseline();

const newFindings = [];
for (const group of current.values()) {
    const accepted = (baseline[group.file] || [])
        .find((entry) => entry.code === group.code && entry.message === group.message);
    const acceptedCount = accepted ? accepted.count : 0;

    if (group.occurrences.length > acceptedCount) {
        newFindings.push({ ...group, newCount: group.occurrences.length - acceptedCount });
    }
}

// Findings that no longer reproduce. Reported, never failed on: a stale
// baseline entry is somebody having fixed something, and failing the build for
// it would teach people not to.
let staleCount = 0;
for (const [file, entries] of Object.entries(baseline)) {
    for (const entry of entries) {
        const stillPresent = current.get(`${file} ${entry.code} ${entry.message}`)?.occurrences.length ?? 0;
        if (stillPresent < entry.count) staleCount += entry.count - stillPresent;
    }
}

if (newFindings.length > 0) {
    console.error('New JavaScript static-analysis findings, not in js-typecheck-baseline.json:\n');
    for (const finding of newFindings) {
        console.error(
            `${finding.file} - ${finding.code}: ${finding.message} `
            + `(${finding.newCount} new, ${finding.occurrences.length} total this run)`
        );
        for (const at of finding.occurrences) {
            console.error(`    ${finding.file}:${at.line}:${at.column}`);
        }
    }
    console.error(
        `\n${newFindings.length} group(s) introduce new occurrences. Fix them. `
        + 'If this really is pre-existing debt you are not touching, accept it deliberately with '
        + "'node scripts/js-typecheck.mjs --generate-baseline' - never to hide a finding your own change introduced."
    );
    process.exit(1);
}

console.log(
    `JavaScript static analysis OK: ${diagnostics.length} pre-existing finding(s) `
    + 'within the accepted baseline (js-typecheck-baseline.json), 0 new.'
);
if (staleCount > 0) {
    console.log(
        `Note: ${staleCount} baselined occurrence(s) no longer reproduce. `
        + 'The baseline could be regenerated to shrink it; this is informational and does not fail the build.'
    );
}
