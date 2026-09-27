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
// test controls. The two things CDP will not do — lose a touchend, and reuse
// an identifier — are built as TouchEvents in the page instead.

import { expect, test } from '@playwright/test';
import { seedScenarios } from '../support/scenarios.js';
import { csrfToken } from '../support/display-mode.js';

/**
 * The one scenario every round in this file plays.
 *
 * The game draws a scenario at random, and these tests lean on its shape — a
 * second chip, a second slot left empty. Left to chance, a run that drew an
 * unusual one failed for reasons that had nothing to do with dragging. The
 * scenario is still a real one from the seeded set, so validating a round
 * against the server still works; it is simply the same one every time.
 */
let pinnedScenario = null;

/** The seeded scenario with the lowest id that has at least two chips. */
async function pickScenario(page) {
    const csrf = await csrfToken(page);
    const seen = [];
    for (;;) {
        const resp = await page.request.post('/index.php', {
            headers: { 'Content-Type': 'application/json', 'X-Action': 'game/scenario', 'X-CSRF-Token': csrf },
            data: JSON.stringify({ exclude_ids: seen.map((s) => s.scenario.id) }),
        });
        if (resp.status() === 404) break;
        expect(resp.status()).toBe(200);
        seen.push(await resp.json());
    }
    const usable = seen
        .filter((s) => s.scenario.chips.length >= 2)
        .sort((a, b) => a.scenario.id - b.scenario.id);
    expect(usable.length, 'the seeded set needs a scenario with two chips').toBeGreaterThan(0);
    return usable[0];
}

async function startRound(page) {
    await page.route('**/index.php', (route) => {
        if (route.request().headers()['x-action'] !== 'game/scenario') return route.fallback();
        return route.fulfill({ json: pinnedScenario });
    });
    await page.goto('/');
    await page.fill('#welcomeNameInput', 'Touch Player');
    await page.click('#startGameBtn');
    await expect(page.locator('.chip').first()).toBeVisible();
    // The round on screen is the pinned one, not a random draw.
    await expect(page.locator('#chipContainer .chip')).toHaveCount(pinnedScenario.scenario.chips.length);
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

/**
 * A touch event built in the page, for what CDP cannot produce: a touch whose
 * end never arrives, and an identifier handed out again.
 */
async function syntheticTouch(page, type, { chipIndex, slotIndex, identifier }) {
    await page.evaluate(({ type, chipIndex, slotIndex, identifier }) => {
        const target = chipIndex === undefined
            ? document.querySelectorAll('.slot')[slotIndex]
            : document.querySelectorAll('.chip')[chipIndex];
        const box = target.getBoundingClientRect();
        const touch = new Touch({
            identifier,
            target,
            clientX: box.left + box.width / 2,
            clientY: box.top + box.height / 2,
        });
        const down = type === 'touchstart' ? [touch] : [];
        target.dispatchEvent(new TouchEvent(type, {
            bubbles: true,
            cancelable: true,
            touches: down,
            targetTouches: down,
            changedTouches: [touch],
        }));
    }, { type, chipIndex, slotIndex, identifier });
}

test.describe('touch drag', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        try {
            await seedScenarios(page);
            pinnedScenario = await pickScenario(page);
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

    test('a drag whose end was lost gives way to the next finger, even one given the same identifier', async ({ page }) => {
        await startRound(page);
        const chips = page.locator('#chipContainer .chip');
        const [first, second] = [chips.nth(0), chips.nth(1)];

        // Android numbers touches from 0 again once every finger is up. The
        // first drag's touchend is never delivered, so to the page finger 0
        // is still carrying the first chip when a new finger 0 lands on the
        // second.
        await syntheticTouch(page, 'touchstart', { chipIndex: 0, identifier: 0 });
        await syntheticTouch(page, 'touchstart', { chipIndex: 1, identifier: 0 });

        await expect(floatingCopies(page)).toHaveCount(1);
        await expect(floatingCopies(page)).toHaveText(await second.textContent());
        await expect(first).not.toHaveClass(/dragging/);

        await syntheticTouch(page, 'touchend', { slotIndex: 0, identifier: 0 });

        await expect(floatingCopies(page)).toHaveCount(0);
        await expect(page.locator('.slot').first()).toHaveClass(/filled/);
        await expect(second).toBeHidden();
        await expect(first).toBeVisible();
    });

    test('the browser cannot start its own drag of a chip already being dragged by touch', async ({ page }) => {
        await startRound(page);
        const send = await touchInput(page);
        const chip = page.locator('.chip').first();

        await send('touchStart', [{ ...(await centreOf(chip)), id: 1 }]);

        // What a long press on a draggable="true" chip does in Chrome on a
        // touch screen. Let through, it cancels the touch and the chip snaps
        // back to where it started.
        const prevented = await chip.evaluate((el) => {
            const drag = new DragEvent('dragstart', {
                bubbles: true,
                cancelable: true,
                dataTransfer: new DataTransfer(),
            });
            el.dispatchEvent(drag);
            return drag.defaultPrevented;
        });

        expect(prevented).toBe(true);
        await expect(floatingCopies(page)).toHaveCount(1);
        await send('touchEnd', []);
    });

    test('the chip lands in the slot under the finger, which is the one highlighted', async ({ page }) => {
        await startRound(page);
        const send = await touchInput(page);

        // A long chip, such as a street name. The floating copy is drawn 40px
        // left of the finger, so its centre sits width/2 - 40 to the right of
        // it — 80px at this width — and the centre is where the drop used to
        // be worked out from. Widened here rather than looked for: a chip's
        // width is the scenario's text, and looking for a long one is how
        // this test came to fail on CI.
        const chip = page.locator('#chipContainer .chip').first();
        await chip.evaluate((el) => { el.style.minWidth = '240px'; });

        // Near the slot's right edge: inside it, with the copy's centre well
        // outside it.
        const slot = page.locator('.slot').first();
        const box = await slot.boundingBox();
        const to = { x: box.x + box.width - 5, y: box.y + box.height / 2 };

        await send('touchStart', [{ ...(await centreOf(chip)), id: 1 }]);

        // The copy has to be wide for this to test anything: a narrow one's
        // centre sits beside the finger, and the old code would pass too.
        const copy = await floatingCopies(page).boundingBox();
        expect(copy.width, 'the floating copy must carry the chip\'s width').toBeGreaterThan(200);

        await send('touchMove', [{ ...to, id: 1 }]);
        await expect(slot).toHaveClass(/drag-over/);
        await send('touchEnd', []);

        await expect(slot).toHaveClass(/filled/);
        await expect(chip).toBeHidden();
    });

    test('scoring the round mid-drag takes the floating copy with it and places nothing', async ({ page }) => {
        await startRound(page);
        const send = await touchInput(page);
        const slots = page.locator('.slot');

        // One chip placed, so there is something to validate.
        await send('touchStart', [{ ...(await centreOf(page.locator('#chipContainer .chip').nth(0))), id: 1 }]);
        await send('touchMove', [{ ...(await centreOf(slots.nth(0))), id: 1 }]);
        await send('touchEnd', []);
        await expect(slots.nth(0)).toHaveClass(/filled/);

        // A second chip held by one finger while another taps Validate.
        const held = page.locator('#chipContainer .chip').nth(1);
        await send('touchStart', [{ ...(await centreOf(held)), id: 2 }]);
        await send('touchMove', [{ ...(await centreOf(slots.nth(1))), id: 2 }]);
        await expect(floatingCopies(page)).toHaveCount(1);

        await page.click('#validateBtn');

        await expect(page.locator('#roundResultOverlay')).toBeVisible();
        await expect(floatingCopies(page)).toHaveCount(0);

        // Lifting the finger now drops nothing into a round already scored.
        await send('touchEnd', []);
        await expect(slots.nth(1)).not.toHaveClass(/filled/);
        await expect(held).not.toHaveClass(/dragging/);
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
