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

// Dragging a chip by touch, and — the reason this file exists — what is left
// on the screen afterwards.
//
// A touch drag floats a copy of the chip on <body>, outside every screen, so
// nothing that redraws the game removes it. It used to be removed on touchend
// and nowhere else. A cancelled touch, or a second finger on a second chip,
// left a copy on the panel until the page was reloaded: a country code
// hovering over the next player's welcome card.
//
// Driven with raw CDP touch events rather than page.touchscreen, which can
// only tap: a drag, a cancel and a second finger all need touch points the
// test controls.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const ADMIN_PIN = '1234';
const here = path.dirname(fileURLToPath(import.meta.url));
const scenariosXlsx = path.resolve(here, '../../../public/assets/Scenarios.xlsx');

async function seedScenarios(page) {
    await page.goto('/');
    const csrf = await page.evaluate(
        () => document.querySelector('meta[name="csrf-token"]')?.content || ''
    );
    await page.request.post('/index.php', {
        headers: { 'Content-Type': 'application/json', 'X-Action': 'admin/login', 'X-CSRF-Token': csrf },
        data: JSON.stringify({ pin: ADMIN_PIN }),
    });
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

async function startRound(page) {
    await page.goto('/');
    await page.fill('#welcomeNameInput', 'Touch Player');
    await page.click('#startGameBtn');
    await expect(page.locator('.chip').first()).toBeVisible();
}

async function centreOf(locator) {
    const box = await locator.boundingBox();
    return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
}

/** Raw touch input: `send(type, points)` with points as { x, y, id }. */
async function touchInput(page) {
    const cdp = await page.context().newCDPSession(page);
    return (type, touchPoints) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints });
}

/** Floating drag copies: chips attached straight to <body>. */
const floatingCopies = (page) => page.locator('body > .chip');

test.describe('touch drag', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        try {
            await seedScenarios(page);
        } finally {
            await page.close();
        }
    });

    test('drops a chip into a slot and leaves nothing floating', async ({ page }) => {
        await startRound(page);
        const send = await touchInput(page);
        const chip = page.locator('.chip').first();
        const from = await centreOf(chip);
        const to = await centreOf(page.locator('.slot').first());

        await send('touchStart', [{ ...from, id: 1 }]);
        await expect(floatingCopies(page)).toHaveCount(1);
        await send('touchMove', [{ ...to, id: 1 }]);
        await send('touchEnd', []);

        await expect(floatingCopies(page)).toHaveCount(0);
        await expect(page.locator('.slot').first()).toHaveClass(/filled/);
        await expect(chip).toBeHidden();
    });

    test('a cancelled touch removes its floating copy and drops nothing', async ({ page }) => {
        await startRound(page);
        const send = await touchInput(page);
        const chip = page.locator('.chip').first();
        const from = await centreOf(chip);
        const to = await centreOf(page.locator('.slot').first());

        await send('touchStart', [{ ...from, id: 1 }]);
        await send('touchMove', [{ ...to, id: 1 }]);
        await send('touchCancel', []);

        await expect(floatingCopies(page)).toHaveCount(0);
        await expect(chip).not.toHaveClass(/dragging/);
        await expect(chip).toBeVisible();
        await expect(page.locator('.slot.filled')).toHaveCount(0);
        await expect(page.locator('.slot.drag-over')).toHaveCount(0);
    });

    test('a second finger on a second chip is ignored, and nothing is left behind', async ({ page }) => {
        await startRound(page);
        const send = await touchInput(page);
        const first = await centreOf(page.locator('.chip').nth(0));
        const second = await centreOf(page.locator('.chip').nth(1));
        const slot = await centreOf(page.locator('.slot').first());

        await send('touchStart', [{ ...first, id: 1 }]);
        await send('touchStart', [{ ...first, id: 1 }, { ...second, id: 2 }]);
        await expect(floatingCopies(page)).toHaveCount(1);

        // The second finger lifting is not the first finger's drop. CDP lifts
        // one finger by leaving it out of the next event's points; touchEnd
        // takes none and would lift both.
        await send('touchMove', [{ ...first, id: 1 }]);
        await expect(floatingCopies(page)).toHaveCount(1);
        await expect(page.locator('.slot.filled')).toHaveCount(0);

        await send('touchMove', [{ ...slot, id: 1 }]);
        await send('touchEnd', []);

        await expect(floatingCopies(page)).toHaveCount(0);
        await expect(page.locator('.slot').first()).toHaveClass(/filled/);
        await expect(page.locator('.chip').nth(0)).toBeHidden();
        await expect(page.locator('.chip').nth(1)).toBeVisible();
    });

    test('leaving the game mid-drag takes the floating copy with it', async ({ page }) => {
        await startRound(page);
        const send = await touchInput(page);

        await send('touchStart', [{ ...(await centreOf(page.locator('.chip').first())), id: 1 }]);
        await expect(floatingCopies(page)).toHaveCount(1);

        // The finger is still down: no touchend or touchcancel will come.
        await page.click('[data-screen="leaderboard"]');

        await expect(page.locator('.leaderboard-screen')).toBeVisible();
        await expect(floatingCopies(page)).toHaveCount(0);
    });
});
