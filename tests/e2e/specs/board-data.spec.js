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
// GET /board/data — the wall's data source.
//
// The property worth defending here is not the shape of the JSON, it is that
// the route needs NOTHING from a session. Every other API route is a POST
// carrying a CSRF token bound to a PHP session with a 24-minute lifetime; a
// wall polling all evening would lose it around midnight and fail silently in
// front of a room. So the request below is issued from a context with no
// cookie and no token at all, and the assertion is that it still answers.

import { expect, test } from '@playwright/test';

const ADMIN_PIN = '1234';

/** An authenticated admin session, for the settings half of these tests. */
async function adminSession(page) {
    await page.goto('/');
    const csrf = await page.evaluate(
        () => document.querySelector('meta[name="csrf-token"]')?.content || ''
    );
    const login = await page.request.post('/index.php', {
        headers: { 'Content-Type': 'application/json', 'X-Action': 'admin/login', 'X-CSRF-Token': csrf },
        data: JSON.stringify({ pin: ADMIN_PIN }),
    });
    expect((await login.json()).success).toBe(true);
    return csrf;
}

test.describe('GET /board/data', () => {
    test('answers with no cookie and no CSRF token', async ({ playwright }) => {
        // A brand-new request context: no storage state, so no session cookie
        // can leak in from another test and make this pass for the wrong
        // reason. This is the closest a browser test gets to plain curl.
        const anonymous = await playwright.request.newContext({
            baseURL: process.env.E2E_BASE_URL,
        });

        try {
            const resp = await anonymous.get('/board/data');
            expect(resp.status()).toBe(200);
            expect(resp.headers()['content-type']).toContain('application/json');
            expect(resp.headers()['cache-control']).toContain('no-store');

            const body = await resp.json();
            expect(typeof body.window_hours).toBe('number');
            expect(typeof body.total_count).toBe('number');
            expect(typeof body.server_time).toBe('string');
            expect(Array.isArray(body.entries)).toBe(true);
            expect(Array.isArray(body.recent)).toBe(true);

            // The response must not have handed out a session either — a
            // Set-Cookie here would mean session_start() ran, which is what
            // declaring the route above it exists to avoid.
            expect(resp.headers()['set-cookie']).toBeUndefined();
        } finally {
            await anonymous.dispose();
        }
    });

    test('the limit is capped by the server whatever the client asks for', async ({ request }) => {
        const resp = await request.get('/board/data?limit=9999');
        expect(resp.status()).toBe(200);
        expect((await resp.json()).entries.length).toBeLessThanOrEqual(50);

        // Garbage is clamped rather than rejected: nobody is present to read
        // an error on a wall.
        for (const limit of ['0', '-3', 'abc', '']) {
            const odd = await request.get(`/board/data?limit=${limit}`);
            expect(odd.status(), `limit=${limit}`).toBe(200);
            expect((await odd.json()).entries.length).toBeLessThanOrEqual(50);
        }
    });

    test('every entry carries a server-computed rank', async ({ page }) => {
        // Seeded first, because the interesting assertions below are all
        // per-entry: on an empty leaderboard this test would pass by iterating
        // over nothing, which is the shape of a test that never fails.
        await page.goto('/');
        const csrf = await page.evaluate(
            () => document.querySelector('meta[name="csrf-token"]')?.content || ''
        );
        for (const [name, score, seconds] of [['Board One', 90, 40], ['Board Two', 70, 55]]) {
            const submitted = await page.request.post('/index.php', {
                headers: {
                    'Content-Type': 'application/json',
                    'X-Action': 'leaderboard/submit',
                    'X-CSRF-Token': csrf,
                },
                data: JSON.stringify({ player_name: name, score, time_seconds: seconds }),
            });
            expect((await submitted.json()).success).toBe(true);
        }

        const body = await (await page.request.get('/board/data')).json();
        expect(body.entries.length).toBeGreaterThanOrEqual(2);
        expect(body.recent.length).toBeGreaterThanOrEqual(2);
        expect(body.total_count).toBeGreaterThanOrEqual(2);

        // Ranks over the visible top are 1, 2, 3, … — computed by the server,
        // not inferred by the page from an array index.
        expect(body.entries.map((e) => e.rank)).toEqual(
            body.entries.map((_, i) => i + 1)
        );

        for (const entry of [...body.entries, ...body.recent]) {
            expect(typeof entry.rank).toBe('number');
            expect(entry.rank).toBeGreaterThan(0);
            // The allowlist of public columns — nothing the Hall of Fame does
            // not already show. encrypted_name reaching an unauthenticated
            // route would be the failure that matters.
            expect(Object.keys(entry).sort()).toEqual(
                ['created_at', 'game_score', 'id', 'player_name', 'rank', 'time_seconds']
            );
        }
    });

    test('the window setting round-trips through the admin screen', async ({ page }) => {
        const csrf = await adminSession(page);

        const save = async (hours) => page.request.post('/index.php', {
            headers: {
                'Content-Type': 'application/json',
                'X-Action': 'admin/set-board-window',
                'X-CSRF-Token': csrf,
            },
            data: JSON.stringify({ window_hours: hours }),
        });

        expect((await (await save(6)).json()).window_hours).toBe(6);
        expect((await (await page.request.get('/board/data')).json()).window_hours).toBe(6);

        // 0 is a value, not an absence.
        expect((await (await save(0)).json()).window_hours).toBe(0);
        expect((await (await page.request.get('/board/data')).json()).window_hours).toBe(0);

        // Out of range and non-numeric are refused rather than clamped on the
        // way in, so an organiser sees that their entry did not take.
        expect((await save(9999)).status()).toBe(400);
        expect((await save(-1)).status()).toBe(400);
        expect((await save('lots')).status()).toBe(400);

        // Back to the default, so the rest of the run sees a normal instance.
        expect((await (await save(24)).json()).window_hours).toBe(24);
    });

    test('the window setting is refused without an admin session', async ({ playwright }) => {
        const anonymous = await playwright.request.newContext({
            baseURL: process.env.E2E_BASE_URL,
        });

        try {
            // Reading is public; writing is not. A CSRF token alone is no
            // credential — the anonymous context gets one from the shell.
            const shell = await anonymous.get('/');
            const csrf = (await shell.text()).match(
                /<meta name="csrf-token" content="([0-9a-f]{64})"/
            )[1];

            const resp = await anonymous.post('/index.php', {
                headers: {
                    'Content-Type': 'application/json',
                    'X-Action': 'admin/set-board-window',
                    'X-CSRF-Token': csrf,
                },
                data: JSON.stringify({ window_hours: 1 }),
            });
            expect(resp.status()).toBe(401);
        } finally {
            await anonymous.dispose();
        }
    });
});
