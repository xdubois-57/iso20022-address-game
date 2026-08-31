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
        await page.goto('/?mode=play');
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
        await page.goto('/?mode=play');

        // The roadmap's minimum, one key at a time. A standards forum fills a
        // room with Scandinavian, Irish, German and Hispanic names, and
        // without these they all go up on the wall misspelt.
        for (const accent of ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', 'Ü', 'Ç', 'Ø', 'Å']) {
            await expect(key(page, accent), accent).toBeVisible();
        }
    });

    test('backspace and clear behave as a keyboard should', async ({ page }) => {
        await page.goto('/?mode=play');

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
        await page.goto('/?mode=play');

        // maxlength constrains typing, not assignment — a keyboard that
        // writes into .value has to enforce it itself or a 60-character name
        // reaches a column that holds 50.
        await page.evaluate(() => {
            document.getElementById('welcomeNameInput').value = 'x'.repeat(50);
        });
        await tap(page, 'A');

        await expect(page.locator('#welcomeNameInput')).toHaveValue('x'.repeat(50));
    });

    test('keys are big enough to hit standing at a 42-inch panel', async ({ page }) => {
        await page.goto('/?mode=play');

        const boxes = await page.locator('.touch-key').evaluateAll(
            (nodes) => nodes.map((n) => {
                const r = n.getBoundingClientRect();
                return { w: r.width, h: r.height };
            })
        );

        expect(boxes.length).toBeGreaterThan(30);
        for (const box of boxes) {
            // 72px, roughly 35mm at this size and resolution.
            expect(box.h).toBeGreaterThanOrEqual(72);
            expect(box.w).toBeGreaterThanOrEqual(72);
        }
    });

    test('a refused name shows why, and the message stays readable', async ({ page }) => {
        await page.goto('/?mode=play');

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
