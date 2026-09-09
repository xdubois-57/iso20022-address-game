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
// The on-screen keyboard, which exists on the play station and nowhere else.
//
// It is not a convenience: Windows only raises its own touch keyboard when it
// detects no physical keyboard, and the station has one plugged in and tucked
// away. Without this component the name field cannot be filled at all, so
// "a name can be composed by tapping alone, and the game starts" is the whole
// test — everything else here is about it not appearing where it would be a
// regression.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

// A dedicated screen is addressed by ?mode= AND a matching &t=; the helper
// knows both halves so this file does not have to.
import { gotoMode } from '../support/display-mode.js';

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

/**
 * Serve a known set of "Did you know?" facts to this page.
 *
 * The fact card sits under the welcome card and takes height the keyboard
 * would otherwise have, so any assertion about how tall a key is, is also an
 * assertion about whichever fact the rotation landed on and how many lines it
 * wrapped to. That is the layout behaving as designed — and it is also why a
 * test measuring a key has to say which fact it measured it against.
 *
 * Everything but game/facts falls through to the real instance.
 */
async function stubFacts(page, facts) {
    await page.route('**/index.php', (route) => {
        if (route.request().headers()['x-action'] !== 'game/facts') {
            return route.fallback();
        }
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ facts }),
        });
    });
}

/** One fact, short enough to sit on a single line at any width used here. */
const stubOneShortFact = (page) => stubFacts(page, [{ id: 1, content: 'ISO 20022 is a standard.' }]);

/** Tap a key by its face. */
function key(page, label) {
    return page.locator('.touch-key', { hasText: new RegExp(`^${label}$`) }).first();
}

async function tap(page, ...labels) {
    for (const label of labels) {
        await key(page, label).click();
    }
}

test.describe('the on-screen keyboard', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        try {
            await seedScenarios(page);
        } finally {
            await page.close();
        }
    });

    test('is absent on the bare URL and in kiosk mode', async ({ page }) => {
        // A phone and an iPad both raise a perfectly good system keyboard;
        // putting this one in front of it would be a regression for the three
        // contexts that already work.
        await page.goto('/');
        await expect(page.locator('#welcomeNameInput')).toBeVisible();
        await expect(page.locator('#touchKeyboard')).toHaveCount(0);

        // Kiosk mode is a session flag set from the Admin screen, and turning
        // it on must not conjure a keyboard either.
        await page.click('[data-screen="admin"]');
        for (const digit of ADMIN_PIN) await page.click(`.pin-key[data-digit="${digit}"]`);
        await page.click('.pin-key-submit');
        await expect(page.locator('.admin-dashboard')).toBeVisible();
        await page.locator('.kiosk-toggle .kiosk-slider').click();
        await expect(page.locator('.kiosk-label')).toHaveText('Enabled');

        await page.click('[data-screen="game"]');
        await expect(page.locator('#welcomeNameInput')).toBeVisible();
        await expect(page.locator('#touchKeyboard')).toHaveCount(0);
    });

    test('composes a name by tapping alone and starts the game', async ({ page }) => {
        await gotoMode(page, 'play');
        await expect(page.locator('#touchKeyboard')).toBeVisible();

        // Not a single keyboard event and not one fill(): every character
        // below arrives through a tap, which is the only input this station
        // will ever receive.
        await tap(page, 'S', 'Ø', 'R', 'E', 'N', 'space', 'K');

        // Capitalised where a name is capitalised, lower case in the middle of
        // a word — tapping alone has to produce something that looks like a
        // name, not SØREN K.
        await expect(page.locator('#welcomeNameInput')).toHaveValue('Søren K');

        await tap(page, 'Start');
        await expect(page.locator('.chip').first()).toBeVisible();
    });

    test('carries the accented characters the room will actually need', async ({ page }) => {
        await gotoMode(page, 'play');

        // The roadmap's minimum, one key at a time. A standards forum fills a
        // room with Scandinavian, Irish, German and Hispanic names, and
        // without these they all go up on the wall misspelt.
        for (const accent of ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', 'Ü', 'Ç', 'Ø', 'Å']) {
            await expect(key(page, accent), accent).toBeVisible();
        }
    });

    test('backspace and clear behave as a keyboard should', async ({ page }) => {
        await gotoMode(page, 'play');

        await tap(page, 'A', 'B', 'C');
        await expect(page.locator('#welcomeNameInput')).toHaveValue('Abc');

        await tap(page, '⌫');
        await expect(page.locator('#welcomeNameInput')).toHaveValue('Ab');

        await tap(page, 'clear');
        await expect(page.locator('#welcomeNameInput')).toHaveValue('');

        // Backspace on an empty field is a no-op, not an error.
        await tap(page, '⌫');
        await expect(page.locator('#welcomeNameInput')).toHaveValue('');
    });

    test('respects the field maxlength, which assignment would otherwise bypass', async ({ page }) => {
        await gotoMode(page, 'play');

        // maxlength constrains typing, not assignment — a keyboard that
        // writes into .value has to enforce it itself or a 60-character name
        // reaches a column that holds 50.
        await page.evaluate(() => {
            document.getElementById('welcomeNameInput').value = 'x'.repeat(50);
        });
        await tap(page, 'A');

        await expect(page.locator('#welcomeNameInput')).toHaveValue('x'.repeat(50));
    });

    test('never asks the device for a keyboard of its own', async ({ page }) => {
        await gotoMode(page, 'play');

        // Every key ends by putting the caret back in the field, inside a
        // click handler — a real user gesture, which is exactly when a touch
        // device raises its own keyboard. On a tablet play station that put
        // the system keyboard over the one the player was using and scrolled
        // the card away to make room for it. inputmode="none" is the only
        // thing that stops it, and Playwright cannot see an OS keyboard, so
        // the attribute is what this test can honestly assert.
        await expect(page.locator('#welcomeNameInput')).toHaveAttribute('inputmode', 'none');

        // It is still a real, focusable field: the caret has to be visible,
        // and it has to come back after a tap.
        await tap(page, 'A');
        const focused = await page.evaluate(
            () => document.activeElement?.id
        );
        expect(focused).toBe('welcomeNameInput');
    });

    test('leaves the system keyboard alone everywhere else', async ({ page }) => {
        // The three contexts that already work do so BECAUSE the device
        // raises its own keyboard; suppressing it there would leave a phone
        // with no way to enter a name at all.
        await page.goto('/');
        await expect(page.locator('#welcomeNameInput')).toBeVisible();
        await expect(page.locator('#welcomeNameInput')).not.toHaveAttribute('inputmode', 'none');
    });

    test('keys are big enough to hit standing at a 42-inch panel', async ({ page }) => {
        // The panel, at the resolution it runs at. The default viewport this
        // suite uses is a 720-pixel-tall desktop window, which is not the
        // screen this rule is about and has no room for the keyboard this
        // rule asks for — see the test below for what happens there.
        await page.setViewportSize({ width: 1920, height: 1080 });

        // One fact, one line of it, because the keys are what is LEFT of the
        // screen and the fact card underneath them is part of what takes it.
        // Unpinned, the height under test moves with whichever fact the
        // rotation happened to land on and how many lines it wrapped to —
        // measured at 51px against one fact and 47.7px against another, which
        // makes any number asserted here a measurement of the seed data
        // rather than of the rule. That variability is the design working;
        // a test that cannot say what it is measuring is not.
        await stubOneShortFact(page);
        await gotoMode(page, 'play');

        const boxes = await page.locator('.touch-key').evaluateAll(
            (nodes) => nodes.map((n) => {
                const r = n.getBoundingClientRect();
                return { w: r.width, h: r.height };
            })
        );

        expect(boxes.length).toBeGreaterThan(30);
        for (const box of boxes) {
            // 72px wide, roughly 35mm at this size and resolution, and at
            // least 48 tall — a quarter of an inch, and what is left once the
            // card, the countdown and the fact card have taken theirs. The
            // keys reach 72 square on a screen with the room for it, which
            // 1080 pixels of landscape panel does not have; a fixed 72 here
            // bought a scroll bar and a Start key below the fold instead.
            expect(box.w).toBeGreaterThanOrEqual(72);
            expect(box.h).toBeGreaterThanOrEqual(48);
        }
    });

    test('a fact long enough to cost a row of keys never costs the keyboard', async ({ page }) => {
        // The other side of the test above: the fact card is content nobody
        // here controls — an administrator types it, and a long one is worth
        // pixels the keyboard would otherwise have had. However much it
        // takes, the keys stop at --touch-key-floor and everything stays on
        // the screen; they do not go on shrinking until they are unhittable,
        // and the card does not scroll to make room.
        await page.setViewportSize({ width: 1920, height: 1080 });
        await stubFacts(page, [{
            id: 1,
            content: 'A postal address in ISO 20022 is made of discrete elements — street name, '
                + 'building number, post code, town name and country — rather than the free lines '
                + 'of text that came before it, and the migration away from those lines is what '
                + 'this game exists to rehearse, one address at a time, against the clock.',
        }]);
        await gotoMode(page, 'play');
        await expect(page.locator('#touchKeyboard')).toBeVisible();

        const fit = await page.evaluate(() => {
            const welcome = document.querySelector('.game-welcome');
            const row = document.querySelector('.touch-key-row');
            const start = document.querySelector('.touch-key-go').getBoundingClientRect();
            return {
                rowHeight: row.getBoundingClientRect().height,
                keyHeight: document.querySelector('.touch-key').getBoundingClientRect().height,
                // Read rather than repeated here. A fact this long spends the
                // keyboard all the way down to its floor, so the number under
                // test IS the floor, and a copy of it in this file would be a
                // second place to change when the floor changes. Off the row's
                // min-height, which is the floor resolved to pixels; the custom
                // property it comes from is unregistered, so reading that gives
                // back the clamp() unevaluated.
                floor: Number.parseFloat(getComputedStyle(row).minHeight),
                cardScroll: welcome.scrollHeight - welcome.clientHeight,
                startBottom: start.bottom,
                viewport: window.innerHeight,
            };
        });

        const seen = JSON.stringify(fit);

        // Landing exactly ON the floor is the pass, and a laid-out box lands
        // on it to within a fraction of a pixel rather than on the nose. What
        // this asserts is that the rows stopped there rather than going on
        // shrinking, so a pixel of tolerance changes nothing it is testing.
        // Every number goes into the message: a failure here is a layout that
        // gave way somewhere, and which box gave way is the whole diagnosis.
        expect(fit.floor, seen).toBeGreaterThan(0);
        expect(fit.rowHeight, seen).toBeGreaterThanOrEqual(fit.floor - 1);
        // And the key fills its row. It is the key a player aims at, not the
        // row behind it, and the two came apart once already.
        expect(fit.keyHeight, seen).toBeGreaterThanOrEqual(fit.rowHeight - 1);
        expect(fit.cardScroll, seen).toBe(0);
        expect(fit.startBottom, seen).toBeLessThanOrEqual(fit.viewport);
    });

    test('the whole card fits the panel, at every height a screen might be', async ({ page }) => {
        // The play station cannot be scrolled by the person using it: they
        // walk up, tap four letters and walk away. Anything below the fold is
        // simply not there — so nothing may be below the fold.
        //
        // The heights are the panel itself, then a portrait panel, then the
        // laptop sizes an organiser sets the station up from. The last of
        // those is where this used to fail, and where the way out an operator
        // finds is to zoom the browser out until it fits.
        //
        // The fact is pinned so that a run which fails names a height rather
        // than a fact; the long one has a test of its own above.
        await stubOneShortFact(page);
        for (const [width, height] of [[1920, 1080], [1080, 1920], [1440, 900], [1280, 720]]) {
            await page.setViewportSize({ width, height });
            await gotoMode(page, 'play');
            await expect(page.locator('#touchKeyboard')).toBeVisible();

            const fit = await page.evaluate(() => {
                const welcome = document.querySelector('.game-welcome');
                const start = document.querySelector('.touch-key-go').getBoundingClientRect();
                const logo = document.querySelector('.card-endorsement').getBoundingClientRect();
                return {
                    pageScroll: document.documentElement.scrollHeight - document.documentElement.clientHeight,
                    cardScroll: welcome.scrollHeight - welcome.clientHeight,
                    startBottom: start.bottom,
                    logoBottom: logo.bottom,
                    viewport: window.innerHeight,
                };
            });

            expect(fit.pageScroll, `page scrolls at ${width}x${height}`).toBe(0);
            expect(fit.cardScroll, `the card scrolls at ${width}x${height}`).toBe(0);
            // Not merely "no scroll bar": the two things at the bottom of the
            // card have to be on the screen, which is what a scroll bar
            // hiding them would have meant.
            expect(fit.startBottom, `Start is off screen at ${width}x${height}`)
                .toBeLessThanOrEqual(fit.viewport);
            expect(fit.logoBottom, `the PMPG lockup is off screen at ${width}x${height}`)
                .toBeLessThanOrEqual(fit.viewport);
        }
    });

    test('the keys grow with the screen rather than being a fixed size', async ({ page }) => {
        // The mechanism, rather than one of its outcomes: the same page on a
        // taller screen gives the keyboard more of the height it gained. A
        // rule that pinned the keys to a number would pass every "does it
        // fit" test above by being small everywhere, and this is what says it
        // may not. The same fact throughout, so the only thing that differs
        // between the three measurements is the height of the screen.
        await stubOneShortFact(page);

        const keyHeight = async (width, height) => {
            await page.setViewportSize({ width, height });
            await gotoMode(page, 'play');
            await expect(page.locator('#touchKeyboard')).toBeVisible();
            return page.locator('.touch-key').first().evaluate(
                (n) => n.getBoundingClientRect().height
            );
        };

        const onALaptop = await keyHeight(1280, 720);
        const onThePanel = await keyHeight(1920, 1080);

        expect(onThePanel).toBeGreaterThan(onALaptop);
        // And it stops growing: 72px is the size this screen is designed
        // around, not a floor a very tall screen may pass.
        expect(await keyHeight(1080, 1920)).toBeLessThanOrEqual(72);
    });

    test('a refused name shows why, and the message stays readable', async ({ page }) => {
        await gotoMode(page, 'play');

        // A name the server's profanity filter rejects, composed by tapping.
        await tap(page, 'S', 'H', 'I', 'T');
        await tap(page, 'Start');

        const warning = page.locator('.profanity-warning');
        await expect(warning).toBeVisible();

        // The point is not that a message exists but that the player can READ
        // it with the keyboard open — otherwise they retype the same name and
        // get the same refusal, indefinitely.
        await expect(page.locator('#touchKeyboard')).toBeVisible();
        const inView = await warning.evaluate((el) => {
            const r = el.getBoundingClientRect();
            return r.top >= 0 && r.bottom <= window.innerHeight && r.height > 0;
        });
        expect(inView).toBe(true);

        // And the game did not start.
        await expect(page.locator('.chip')).toHaveCount(0);
    });
});
