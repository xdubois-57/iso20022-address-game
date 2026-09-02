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
// The display mode token, over real HTTP.
//
// public/index.php cannot be included from a PHPUnit test — it starts a
// session and dispatches on the request method — so the gate itself is only
// observable here: what a right token buys, and what a wrong one, a missing
// one and a stale one all fall back to.
//
// The fallback is the assertion that matters. A wall must never show an error
// page to a room, so an unusable token has to serve the ORDINARY GAME, menus
// and all, exactly as ?mode=nimportequoi already did. Getting that wrong would
// be indistinguishable from getting it right, right up until the evening.

import { expect, test } from '@playwright/test';
import { displayModeToken } from '../support/display-mode.js';

const ADMIN_PIN = '1234';

async function enterPin(page, pin) {
    for (const digit of pin) {
        await page.click(`.pin-key[data-digit="${digit}"]`);
    }
    await page.click('.pin-key-submit');
}

async function csrfToken(page) {
    await page.goto('/');
    const csrf = await page.evaluate(
        () => document.querySelector('meta[name="csrf-token"]')?.content || ''
    );
    expect(csrf).toMatch(/^[0-9a-f]{64}$/);
    return csrf;
}

/** The shell a dedicated screen gets: no nav, no hamburger, data-mode set. */
async function expectDedicatedShell(page, mode) {
    expect(await page.evaluate(() => document.body.dataset.mode)).toBe(mode);
    await expect(page.locator('#headerNav')).toHaveCount(0);
    await expect(page.locator('#hamburgerBtn')).toHaveCount(0);
    await expect(page.locator('.nav-btn')).toHaveCount(0);
}

/** The shell everything else gets: the ordinary game, with its menus. */
async function expectOrdinaryShell(page) {
    expect(await page.evaluate(() => document.body.dataset.mode)).toBeUndefined();
    await expect(page.locator('#headerNav')).toBeVisible();
    await expect(page.locator('.nav-btn')).toHaveCount(4);
    await expect(page.locator('#hamburgerBtn')).toHaveCount(1);
}

test.describe.serial('the display mode token', () => {
    test('the right token opens the wall', async ({ page }) => {
        const token = await displayModeToken(page);

        await page.goto(`/?mode=hof&t=${token}`);
        await expectDedicatedShell(page, 'hof');
    });

    test('a wrong token serves the ordinary game, with the navigation', async ({ page }) => {
        const token = await displayModeToken(page);

        // Same length and shape as the real thing, so what is being tested is
        // the comparison and not a length check somewhere upstream of it.
        const wrong = token.replace(/./, (c) => (c === 'a' ? 'b' : 'a'));
        expect(wrong).not.toBe(token);
        expect(wrong).toHaveLength(token.length);

        await page.goto(`/?mode=hof&t=${wrong}`);
        await expectOrdinaryShell(page);
    });

    test('an absent token serves the ordinary game too', async ({ page }) => {
        await page.goto('/?mode=hof');
        await expectOrdinaryShell(page);

        // And an empty one. hash_equals('', '') is true, so this is the case
        // that would silently open both screens to anyone if the gate ever
        // compared against a missing token as a plain string.
        await page.goto('/?mode=hof&t=');
        await expectOrdinaryShell(page);
    });

    test('no error is shown, and nothing about it changes the status code', async ({ page }) => {
        // The wall has nobody standing in front of it. A 403, a 404 or a page
        // saying "invalid token" would be worse than the game itself.
        const response = await page.goto('/?mode=hof&t=definitely-not-the-token');
        expect(response.status()).toBe(200);

        await expectOrdinaryShell(page);
        await expect(page.locator('body')).not.toContainText('token');
        await expect(page.locator('#welcomeNameInput')).toBeVisible();
    });

    test('both modes are gated, not just the wall', async ({ page }) => {
        const token = await displayModeToken(page);

        await page.goto(`/?mode=play&t=${token}`);
        await expectDedicatedShell(page, 'play');

        await page.goto('/?mode=play&t=nope');
        await expectOrdinaryShell(page);
    });

    test('regeneration refuses without an admin session', async ({ page }) => {
        const csrf = await csrfToken(page);

        const resp = await page.request.post('/index.php', {
            headers: {
                'Content-Type': 'application/json',
                'X-Action': 'admin/regenerate-display-token',
                'X-CSRF-Token': csrf,
            },
            data: '{}',
        });
        expect(resp.status()).toBe(401);
    });

    test('regeneration refuses without a CSRF token', async ({ page }) => {
        // Enforced by public/index.php rather than by the controller, so it is
        // only provable over real HTTP — a PHPUnit test calling the method
        // directly never reaches that check.
        const csrf = await csrfToken(page);
        const login = await page.request.post('/index.php', {
            headers: {
                'Content-Type': 'application/json',
                'X-Action': 'admin/login',
                'X-CSRF-Token': csrf,
            },
            data: JSON.stringify({ pin: ADMIN_PIN }),
        });
        expect((await login.json()).success).toBe(true);

        // Signed in, and still refused: the session alone is not enough.
        const noToken = await page.request.post('/index.php', {
            headers: { 'Content-Type': 'application/json', 'X-Action': 'admin/regenerate-display-token' },
            data: '{}',
        });
        expect(noToken.status()).toBe(403);

        const wrongToken = await page.request.post('/index.php', {
            headers: {
                'Content-Type': 'application/json',
                'X-Action': 'admin/regenerate-display-token',
                'X-CSRF-Token': 'not-the-token',
            },
            data: '{}',
        });
        expect(wrongToken.status()).toBe(403);
    });

    test('regenerating from the panel repaints the addresses and strands the old ones', async ({ page }) => {
        const before = await displayModeToken(page);

        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);
        await expect(page.locator('.admin-dashboard')).toBeVisible();

        const origin = new URL(page.url()).origin;
        await expect(page.locator('.display-mode-url').first())
            .toHaveText(`${origin}/?mode=hof&t=${before}`);

        // The confirmation has to say what this costs, because the failure it
        // causes is silent: both screens drop to the ordinary game with
        // nothing on their own displays to explain it.
        await page.click('#regenerateDisplayTokenBtn');
        const dialog = page.locator('.confirm-overlay .overlay-content');
        await expect(dialog).toContainText('WITHOUT ANY WARNING');
        await expect(dialog).toContainText('ordinary game');
        await expect(dialog).toContainText('reopen both');

        // Cancelling changes nothing at all.
        await page.click('#confirmCancelBtn');
        await expect(page.locator('.display-mode-url').first())
            .toHaveText(`${origin}/?mode=hof&t=${before}`);

        await page.click('#regenerateDisplayTokenBtn');
        await page.click('#confirmOkBtn');

        // Repainted in place, with no reload: putting two screens back on air
        // has to be thirty seconds of work, not a hunt for the new address.
        await expect(page.locator('.display-mode-url').first())
            .not.toHaveText(`${origin}/?mode=hof&t=${before}`);

        const after = await page.locator('.display-mode-url').first().innerText();
        const prefix = `${origin}/?mode=hof&t=`;
        expect(after.startsWith(prefix), after).toBe(true);
        expect(after.slice(prefix.length)).toMatch(/^[0-9a-f]{32}$/);

        // The launch commands and the QR codes moved with them, or an
        // organiser reopens the screens on the address that no longer works.
        await expect(page.locator('.display-mode-cmd pre')).toContainText(after);
        await expect(page.locator('.display-mode-cmd pre')).not.toContainText(before);
        await expect(page.locator('.display-mode-qr')).toHaveCount(2);

        // And the old address is now an ordinary page. Silently — which is the
        // whole hazard the confirmation exists to warn about.
        await page.goto(`/?mode=hof&t=${before}`);
        await expectOrdinaryShell(page);

        // While the new one works.
        await page.goto(after.replace(origin, ''));
        await expectDedicatedShell(page, 'hof');
    });
});
