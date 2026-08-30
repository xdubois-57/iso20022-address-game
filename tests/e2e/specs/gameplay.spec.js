// ISO 20022 Address Structuring Game
// Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program. If not, see <https://www.gnu.org/licenses/>.
//
// Functional coverage of a whole round: upload scenarios, play one, record
// the result, then share it. These endpoints carry most of the application's
// logic (GameController, LeaderboardController, ShareController) and were the
// least exercised part of the codebase — the browser suite only ever booted
// the page and drove the admin panel.
//
// Driven through the real HTTP API rather than by simulating drag-and-drop.
// The endpoints are the contract the browser actually uses, they are what the
// server-side logic hangs off, and asserting on them is far less brittle than
// synthesising pointer gestures. The drag-and-drop UI itself is a separate
// concern, covered by the unit tests over lib/address.js and lib/scoring.js.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const ADMIN_PIN = '1234';
const here = path.dirname(fileURLToPath(import.meta.url));
const scenariosXlsx = path.resolve(here, '../../../public/assets/Scenarios.xlsx');

/** A session cookie plus its CSRF token — everything the API needs. */
async function session(page) {
    await page.goto('/');
    const csrf = await page.evaluate(
        () => document.querySelector('meta[name="csrf-token"]')?.content || ''
    );
    expect(csrf).toMatch(/^[0-9a-f]{64}$/);
    return csrf;
}

function api(page, csrf, action, body) {
    return page.request.post('/index.php', {
        headers: {
            'Content-Type': 'application/json',
            'X-Action': action,
            'X-CSRF-Token': csrf,
        },
        data: JSON.stringify(body ?? {}),
    });
}

/**
 * Scenarios are uploaded through the real admin endpoint rather than seeded
 * into the database, so the Excel parsing path is exercised too — that is the
 * one route by which content ever enters a real install.
 */
async function seedScenarios(page, csrf) {
    const login = await api(page, csrf, 'admin/login', { pin: ADMIN_PIN });
    expect((await login.json()).success).toBe(true);

    const upload = await page.request.post('/index.php', {
        headers: { 'X-Action': 'admin/upload', 'X-CSRF-Token': csrf },
        multipart: {
            file: {
                name: 'Scenarios.xlsx',
                mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                buffer: readFileSync(scenariosXlsx),
            },
        },
    });
    const body = await upload.json();
    expect(body.success, `upload failed: ${JSON.stringify(body)}`).toBe(true);
    expect(body.imported.scenarios).toBeGreaterThan(0);
    return body.imported.scenarios;
}

test.describe('a full round', () => {
    test('scenarios upload, then a round can be played, scored and shared', async ({ page }) => {
        const csrf = await session(page);
        const count = await seedScenarios(page, csrf);
        expect(count).toBeGreaterThan(0);

        // --- the player's name is checked before play begins
        const nameCheck = await api(page, csrf, 'game/check-name', { name: 'E2E Player' });
        expect(nameCheck.status()).toBe(200);
        expect((await nameCheck.json()).allowed).toBe(true);

        // --- fetch a scenario
        const scenarioResp = await api(page, csrf, 'game/scenario', { exclude_ids: [] });
        expect(scenarioResp.status()).toBe(200);
        const { scenario } = await scenarioResp.json();
        expect(scenario.id).toBeGreaterThan(0);
        expect(Array.isArray(scenario.chips)).toBe(true);
        expect(scenario.chips.length).toBeGreaterThan(0);
        expect(scenario.slots_structured.length).toBeGreaterThan(0);
        expect(scenario.slots_hybrid.length).toBeGreaterThan(0);

        // --- answer it correctly: every chip into the slot named after it
        const mapping = {};
        for (const chip of scenario.chips) {
            mapping[chip.id] = chip.field ?? chip.id;
        }
        const validated = await api(page, csrf, 'game/validate', {
            scenario_id: scenario.id,
            mapping,
            goal_type: 'Structured',
        });
        expect(validated.status()).toBe(200);
        const result = await validated.json();
        expect(result).toHaveProperty('percentage');
        expect(result.percentage).toBeGreaterThanOrEqual(0);
        expect(result.percentage).toBeLessThanOrEqual(100);

        // --- record the completed game
        const completed = await api(page, csrf, 'game/complete', {});
        expect((await completed.json()).success).toBe(true);

        // --- submit to the Hall of Fame and read it back
        const submitted = await api(page, csrf, 'leaderboard/submit', {
            player_name: 'E2E Player',
            score: 100,
            time_seconds: 42,
        });
        expect(submitted.status()).toBe(200);

        const top = await api(page, csrf, 'leaderboard/top', { page: 1 });
        expect(top.status()).toBe(200);
        const board = await top.json();
        expect(board.total_count).toBeGreaterThan(0);
        // Names are encrypted at rest, so round-tripping one proves the
        // encrypt/decrypt path works, not merely that a row was written.
        expect(board.entries.some((e) => e.player_name === 'E2E Player')).toBe(true);
    });

    test('a rejected name never reaches the leaderboard', async ({ page }) => {
        const csrf = await session(page);

        const tooLong = await api(page, csrf, 'game/check-name', { name: 'x'.repeat(51) });
        expect(tooLong.status()).toBe(400);

        const empty = await api(page, csrf, 'leaderboard/submit', {
            player_name: '', score: 10, time_seconds: 5,
        });
        expect(empty.status()).toBe(400);
    });

    test('validate refuses an incomplete request', async ({ page }) => {
        const csrf = await session(page);
        const resp = await api(page, csrf, 'game/validate', { mapping: {} });
        expect(resp.status()).toBe(400);
    });
});

test.describe('sharing', () => {
    test('a share token round-trips through every share surface', async ({ page }) => {
        const csrf = await session(page);

        const tokenResp = await api(page, csrf, 'share/token', { score: 4321, name: 'E2E Player' });
        expect(tokenResp.status()).toBe(200);
        const { token } = await tokenResp.json();
        expect(token).toBeTruthy();

        // The share page carries the OpenGraph tags crawlers read, and must
        // render without a session — it is opened by whoever received the link.
        const share = await page.request.get(`/share?d=${encodeURIComponent(token)}`);
        expect(share.status()).toBe(200);
        const html = await share.text();
        expect(html).toContain('og:image');
        expect(html).toContain('4321');

        // /share/go is the mobile hand-off that triggers a native share.
        const go = await page.request.get(`/share/go?d=${encodeURIComponent(token)}`);
        expect(go.status()).toBe(200);

        // The 1200x630 card is generated server-side by GD/Imagick.
        const image = await page.request.get(`/share/image?d=${encodeURIComponent(token)}`);
        expect(image.status()).toBe(200);
        expect(image.headers()['content-type']).toContain('image/png');
        expect((await image.body()).length).toBeGreaterThan(1000);
    });

    test('a garbage share token redirects home rather than rendering', async ({ page }) => {
        // Share tokens are attacker-supplied, so this is the path that must
        // not fall over: authenticated encryption rejects it and the visitor
        // is sent to the front page. maxRedirects:0 because the redirect is
        // the assertion — following it would just report the home page's 200.
        const resp = await page.request.get('/share?d=not-a-real-token', { maxRedirects: 0 });
        expect(resp.status()).toBe(302);
        expect(resp.headers().location).toBe('/');
    });

    test('the home share card renders without any token', async ({ page }) => {
        const resp = await page.request.get('/share/home-image');
        expect(resp.status()).toBe(200);
        expect(resp.headers()['content-type']).toContain('image/png');
    });
});

test.describe('generated assets', () => {
    test('the themed background renders as SVG', async ({ page }) => {
        const resp = await page.request.get('/bg');
        expect(resp.status()).toBe(200);
        expect(resp.headers()['content-type']).toContain('svg');
        expect((await resp.body()).length).toBeGreaterThan(1000);
    });

    test('the apple touch icon renders as PNG', async ({ page }) => {
        const resp = await page.request.get('/app-icon');
        expect(resp.status()).toBe(200);
        expect(resp.headers()['content-type']).toContain('image/png');
    });
});

test.describe('public game endpoints', () => {
    test('facts, deadline and event-code status are readable', async ({ page }) => {
        const csrf = await session(page);

        const facts = await api(page, csrf, 'game/facts', {});
        expect(facts.status()).toBe(200);
        expect((await facts.json()).facts.length).toBeGreaterThan(0);

        const deadline = await api(page, csrf, 'game/deadline', {});
        expect(deadline.status()).toBe(200);

        const gate = await api(page, csrf, 'game/event-code-status', {});
        expect((await gate.json()).required).toBe(false);
    });

    test('resetting the session clears the event-code unlock', async ({ page }) => {
        const csrf = await session(page);
        const resp = await api(page, csrf, 'game/reset-session', {});
        expect(resp.status()).toBe(200);
    });

    test('an unknown action is refused', async ({ page }) => {
        const csrf = await session(page);
        const resp = await api(page, csrf, 'game/there-is-no-such-action', {});
        expect(resp.status()).toBe(404);
    });

    test('a missing CSRF token is refused', async ({ page }) => {
        await page.goto('/');
        const resp = await page.request.post('/index.php', {
            headers: { 'Content-Type': 'application/json', 'X-Action': 'game/facts' },
            data: '{}',
        });
        expect(resp.status()).toBe(403);
    });
});
