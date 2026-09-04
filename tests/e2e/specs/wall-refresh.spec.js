// ISO 20022 Address Structuring Game
// Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program. If not, see <https://www.gnu.org/licenses/>.
//
// What the wall does BETWEEN polls.
//
// The wall polls /board/data every five seconds, and every success is a new
// response object — so "has anything changed?" answered by identity was
// always yes, and the screen rebuilt its entire DOM twelve times a minute
// whether or not a number on it had moved. Nothing about that was visible in
// a screenshot, and two things that matter depended on the DOM surviving:
//
//   - the arrivals banner, four seconds each and serialised, so from the
//     second banner onwards every player who placed below the fold got about
//     one second of the acknowledgement it exists to give them;
//   - the six-minute anti-burn-in drift, restarted from zero on every rebuild,
//     which held a 42-inch panel within two hundredths of a pixel of one
//     position for a whole evening.
//
// Both are asserted here against a real running wall, because neither can be
// seen in a static render — only in what survives the next poll.

import { expect, test } from '@playwright/test';
import { gotoMode } from '../support/display-mode.js';

const POLL_MS = 5000;

/** A full board, big enough that anything new lands below the fold. */
const BOARD = Array.from({ length: 40 }, (_, i) => ({
    id: i + 1,
    rank: i + 1,
    player_name: `Player ${i + 1}`,
    game_score: 10_000 - i,
    time_seconds: 61,
    created_at: '2026-05-01 20:00:00',
}));

/** Two arrivals that placed well below the visible rows — banner material. */
const LATE = ['Banner One', 'Banner Two'].map((name, i) => ({
    id: 900 + i,
    rank: 45 + i,
    player_name: name,
    game_score: 100 - i,
    time_seconds: 900,
    created_at: '2026-05-01 21:00:00',
}));

function body(recent) {
    return JSON.stringify({
        window_hours: 24,
        total_count: BOARD.length + recent.length,
        server_time: '2026-05-01T21:00:00+00:00',
        entries: BOARD,
        recent,
    });
}

test.describe('the wall between polls', () => {
    test('leaves the screen alone when the board has not changed', async ({ page }) => {
        // The same board on every poll, so nothing on screen has any reason to
        // change. Served rather than seeded: this must hold whatever the other
        // specs in the run have left in the leaderboard.
        await page.route('**/board/data*', (route) => route.fulfill({
            status: 200, contentType: 'application/json', body: body([]),
        }));

        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-podium')).toBeVisible();

        // A mark the page itself would never write. It can only survive if the
        // element survives — which is the whole property under test.
        await page.evaluate(() => {
            document.querySelector('.wall-screen').dataset.e2eStamp = 'kept';
        });

        // Comfortably more than two polls.
        await page.waitForTimeout(POLL_MS * 2.4);

        await expect(page.locator('.wall-screen')).toHaveAttribute('data-e2e-stamp', 'kept');
    });

    test('lets the anti-burn-in drift actually run', async ({ page }) => {
        await page.route('**/board/data*', (route) => route.fulfill({
            status: 200, contentType: 'application/json', body: body([]),
        }));

        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-podium')).toBeVisible();

        const clockMs = async () => page.evaluate(() => {
            const host = document.querySelector('body[data-mode="hof"] .game-main');
            const anim = host?.getAnimations?.()[0];
            return anim ? Number(anim.currentTime) : -1;
        });

        const first = await clockMs();
        expect(first, 'the drift animation must be on a box the wall does not rebuild')
            .toBeGreaterThanOrEqual(0);

        await page.waitForTimeout(POLL_MS * 2.4);
        const later = await clockMs();

        // Restarted on every poll, this never got past one interval's worth.
        expect(later).toBeGreaterThan(POLL_MS * 1.5);
    });

    test('gives every queued banner its full run on screen', async ({ page }) => {
        test.slow();

        // Served rather than submitted, for two reasons: leaderboard/submit
        // allows ten per five minutes per address and the budget is shared
        // with every other spec, and this needs the two arrivals to land
        // BELOW the visible rows whatever height the runner's viewport is.
        // The first response primes the tracker; every one after it carries
        // the same pair, so they are new exactly once.
        let polls = 0;
        await page.route('**/board/data*', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: body(polls++ === 0 ? [] : LATE),
        }));

        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-podium')).toBeVisible();

        // Sample the zone twice a second and total up how long each distinct
        // banner was actually readable.
        const shownFor = new Map();
        for (let i = 0; i < 30; i++) {
            const text = (await page.locator('#wallBannerZone').textContent()).trim();
            if (text !== '') shownFor.set(text, (shownFor.get(text) ?? 0) + 500);
            await page.waitForTimeout(500);
        }

        const banners = [...shownFor.entries()].filter(([text]) => /just made the board/.test(text));
        expect(banners.length, `expected two banners, saw ${JSON.stringify([...shownFor.keys()])}`).toBe(2);

        // Each is given four seconds. Asserting well under that leaves room
        // for the sampling grain and a loaded runner, while still being far
        // above the ~1s a wiped banner survived.
        for (const [text, ms] of banners) {
            expect(ms, `"${text}" was on screen for only ${ms}ms`).toBeGreaterThanOrEqual(2500);
        }
    });
});

test.describe('the wall names the board it is showing', () => {
    test('captions itself with the configured window', async ({ page }) => {
        // Served rather than configured: what is under test is the caption,
        // not the admin round trip, and the wall must read the window from the
        // response rather than assume the default.
        await page.route('**/board/data*', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                window_hours: 168,
                total_count: 1,
                server_time: '2026-05-01T20:00:00+00:00',
                entries: [{
                    id: 1, rank: 1, player_name: 'Solo', game_score: 900,
                    time_seconds: 61, created_at: '2026-05-01 20:00:00',
                }],
                recent: [],
            }),
        }));

        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-window')).toHaveText('Last 7 days');
    });

    test('says "All time" when the window is switched off', async ({ page }) => {
        await page.route('**/board/data*', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                window_hours: 0,
                total_count: 1,
                server_time: '2026-05-01T20:00:00+00:00',
                entries: [{
                    id: 1, rank: 1, player_name: 'Solo', game_score: 900,
                    time_seconds: 61, created_at: '2026-05-01 20:00:00',
                }],
                recent: [],
            }),
        }));

        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-window')).toHaveText('All time');
    });

    test('stands a lone winner in the middle of the podium', async ({ page }) => {
        // The first score of an evening. Automatic grid placement put the one
        // pod in column 1, so the winner stood off to the left of an empty
        // podium; each pod names its own column now.
        await page.route('**/board/data*', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                window_hours: 24,
                total_count: 1,
                server_time: '2026-05-01T20:00:00+00:00',
                entries: [{
                    id: 1, rank: 1, player_name: 'Solo', game_score: 900,
                    time_seconds: 61, created_at: '2026-05-01 20:00:00',
                }],
                recent: [],
            }),
        }));

        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-pod')).toHaveCount(1);

        const podium = await page.locator('.wall-podium').boundingBox();
        const pod = await page.locator('.wall-pod').boundingBox();

        const podCentre = pod.x + pod.width / 2;
        const podiumCentre = podium.x + podium.width / 2;
        expect(Math.abs(podCentre - podiumCentre)).toBeLessThan(pod.width / 2);
    });
});
