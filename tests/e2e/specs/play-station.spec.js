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
// The end of a game, on the play station and everywhere else.
//
// Unlike gameplay.spec.js, which drives the HTTP API, these tests play the
// game through the interface — five rounds of it — because the thing under
// test is a SCREEN, and specifically which screen the game lands on. The
// point is not that the endpoints work; it is that ?mode=play never reaches
// the Hall of Fame or the share sheet, and that the bare URL still reaches
// both.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

// A dedicated screen is addressed by ?mode= AND a matching &t=; the helper
// knows both halves so this file does not have to.
import { modeUrl } from '../support/display-mode.js';

const ADMIN_PIN = '1234';
const here = path.dirname(fileURLToPath(import.meta.url));
const scenariosXlsx = path.resolve(here, '../../../public/assets/Scenarios.xlsx');

/** Scenarios must exist before a round can be played. */
async function seedScenarios(page) {
    await page.goto('/');
    const csrf = await page.evaluate(
        () => document.querySelector('meta[name="csrf-token"]')?.content || ''
    );

    const login = await page.request.post('/index.php', {
        headers: { 'Content-Type': 'application/json', 'X-Action': 'admin/login', 'X-CSRF-Token': csrf },
        data: JSON.stringify({ pin: ADMIN_PIN }),
    });
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
    expect((await upload.json()).imported.scenarios).toBeGreaterThan(0);
}

/**
 * Place one chip in one slot by dispatching the drag events the page listens
 * for.
 *
 * Synthesised rather than performed as a real pointer gesture: what matters
 * here is reaching the end of a game, five rounds away, and the drag-and-drop
 * mechanics themselves are covered by the unit tests over lib/address.js and
 * lib/scoring.js. One chip is enough to enable Validate.
 */
async function placeOneChip(page) {
    await page.evaluate(() => {
        const chip = document.querySelector('.chip');
        const slot = document.querySelector('.slot');
        const dataTransfer = new DataTransfer();
        chip.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer }));
        slot.dispatchEvent(new DragEvent('dragover', { bubbles: true, dataTransfer }));
        slot.dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer }));
    });
}

/**
 * Stop this page's score from reaching the leaderboard.
 *
 * leaderboard/submit is rate-limited to ten successful submissions per five
 * minutes per address, and that budget is shared with every other spec in the
 * run. The play station files a score automatically at the end of every game,
 * so four tests that each play a game would spend four of it — for three
 * tests that are about what the SCREEN shows and do not care whether a row
 * was written. Exactly one test below lets the real submission through, and
 * that is the one that asserts on it.
 */
async function stubSubmission(page) {
    await page.route('**/index.php', (route) => {
        if (route.request().headers()['x-action'] !== 'leaderboard/submit') {
            return route.fallback();
        }
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, entry_id: 1, rank: 1, page: 1 }),
        });
    });
}

/** Play the whole game from the welcome card to whatever ends it. */
async function playAGame(page, url, playerName) {
    await page.goto(url);
    await page.fill('#welcomeNameInput', playerName);
    await page.click('#startGameBtn');

    for (let round = 1; round <= 5; round++) {
        await expect(page.locator('.chip').first()).toBeVisible();
        await placeOneChip(page);

        const validate = page.locator('#validateBtn');
        await expect(validate).toBeEnabled();
        await validate.click();

        await page.click('#nextRoundBtn');
    }
}

test.describe.serial('the end of a game', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        try {
            await seedScenarios(page);
        } finally {
            await page.close();
        }
    });

    test('?mode=play celebrates, points at the wall, and never shows a rank', async ({ page }) => {
        await stubSubmission(page);
        await playAGame(page, await modeUrl(page, 'play'), 'Rafael Costa');

        // 1. the personal hook — first name only
        await expect(page.locator('.play-hook')).toHaveText('Nice one, Rafael');

        // 2. the score, very large
        const score = page.locator('.play-score');
        await expect(score).toBeVisible();
        const fontSize = await score.evaluate(
            (el) => parseFloat(getComputedStyle(el).fontSize)
        );
        expect(fontSize).toBeGreaterThan(60);

        // 3. three figures, and nothing that implies a standing
        await expect(page.locator('.play-stat')).toHaveCount(3);
        await expect(page.locator('.play-stat-label')).toHaveText([
            'addresses', 'time', 'accuracy',
        ]);

        // 4. the centrepiece: the reward redirected to the panel next door
        await expect(page.locator('.play-wall-cue')).toContainText(
            'Your name is going up on the wall'
        );

        // The whole reason this screen exists. Nothing here may lead a player
        // into the Hall of Fame, and no rank may appear.
        await expect(page.locator('.leaderboard-screen')).toHaveCount(0);
        await expect(page.locator('.leaderboard-table')).toHaveCount(0);
        await expect(page.locator('[data-screen="leaderboard"]')).toHaveCount(0);
        await expect(page.locator('#appContainer')).not.toContainText(/rank/i);
    });

    test('?mode=play exposes no share affordance at all', async ({ page }) => {
        const shareCalls = [];
        page.on('request', (req) => {
            if (req.headers()['x-action'] === 'share/token') shareCalls.push(req.url());
        });

        await stubSubmission(page);
        await playAGame(page, await modeUrl(page, 'play'), 'Share Free');

        // Not merely hidden: absent. hasNativeShare() returns true as soon as
        // 'ontouchstart' is in window, which it is on a Windows touch panel,
        // and navigator.share opens the OS share pane OVER the kiosk with no
        // way out a player will find.
        for (const selector of [
            '#shareScoreBtn', '#desktopShareRow', '#linkedinShareBtn',
            '#copyLinkBtn', '#kioskQrContainer', '.btn-share',
        ]) {
            await expect(page.locator(selector), selector).toHaveCount(0);
        }

        // And the share path is never entered — no token is even minted for
        // it, which is what "does not enter the path" means concretely. The
        // listener has been recording since before the game started, so this
        // covers the whole run and not just the moment after it.
        expect(shareCalls).toEqual([]);
    });

    test('?mode=play files the score and hands the station back on its own', async ({ page }) => {
        await playAGame(page, await modeUrl(page, 'play'), 'Auto Return');

        await expect(page.locator('.play-wall-cue')).toBeVisible();

        // The score goes to the database without the player being asked to
        // submit it — the wall is what celebrates it publicly, and a run
        // nobody submitted is a name that never reaches the wall it was just
        // promised.
        await expect.poll(async () => {
            const board = await (await page.request.get('/board/data')).json();
            return board.entries.concat(board.recent).some((e) => e.player_name === 'Auto Return');
        }, { timeout: 15_000 }).toBe(true);

        // Eight seconds later the station is free for the next player, with
        // nobody having touched it.
        await expect(page.locator('#welcomeNameInput')).toBeVisible({ timeout: 20_000 });
        await expect(page.locator('.play-result')).toHaveCount(0);
    });

    test('?mode=play offers Play again at a size you can hit standing up', async ({ page }) => {
        await stubSubmission(page);
        await playAGame(page, await modeUrl(page, 'play'), 'Again Please');

        const again = page.locator('#playAgainBtn');
        await expect(again).toBeVisible();

        // 72px, roughly 35mm on a 42-inch 1080p panel. The 44px that suits a
        // phone held in the hand is too small at arm's length.
        const box = await again.boundingBox();
        expect(box.height).toBeGreaterThanOrEqual(72);

        await again.click();
        await expect(page.locator('#welcomeNameInput')).toBeVisible();

        // The hand-back timer must not fire after the player took over — it
        // would throw them out of the game they just started.
        await page.fill('#welcomeNameInput', 'Second Run');
        await page.click('#startGameBtn');
        await expect(page.locator('.chip').first()).toBeVisible();
        await page.waitForTimeout(9000);
        await expect(page.locator('.chip').first()).toBeVisible();
    });

    test('the bare URL still ends on the Hall of Fame, with the player highlighted', async ({ page }) => {
        // The regression that matters. Mobile, desktop and the iPad kiosk keep
        // the ending they have always had.
        await playAGame(page, '/', 'Classic Ending');

        await expect(page.locator('.final-score-screen')).toBeVisible();
        await expect(page.locator('#submitFinalScoreBtn')).toBeVisible();
        await expect(page.locator('.play-result')).toHaveCount(0);
        await expect(page.locator('.play-wall-cue')).toHaveCount(0);

        await page.click('#submitFinalScoreBtn');
        await expect(page.locator('.leaderboard-screen h2')).toHaveText('Hall of Fame');
        await expect(page.locator('tr.my-entry')).toBeVisible();
        await expect(page.locator('tr.my-entry')).toContainText('Classic Ending');
    });

    test('the bare URL still offers sharing where it did before', async ({ page }) => {
        // No submission on this path either — the share buttons are rendered
        // by showFinalScore() itself, before anything is filed.
        await stubSubmission(page);
        await playAGame(page, '/', 'Still Sharing');

        await expect(page.locator('.final-score-screen')).toBeVisible();

        // Headless Chromium on a desktop viewport has no navigator.share, so
        // this is the desktop half of setupShareButtons() — LinkedIn plus Copy
        // Link — exactly as it was before display modes existed.
        await expect(page.locator('#desktopShareRow')).toBeVisible();
        await expect(page.locator('#linkedinShareBtn')).toBeVisible();
        await expect(page.locator('#copyLinkBtn')).toBeVisible();
        await expect(page.locator('#linkedinShareBtn')).toHaveAttribute(
            'href', /linkedin\.com\/sharing\/share-offsite/
        );
    });
});
