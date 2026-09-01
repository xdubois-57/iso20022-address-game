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

import { expect, test } from '@playwright/test';

test.describe('boot', () => {
    test('home page renders the welcome card with no console errors', async ({ page }) => {
        const consoleErrors = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') consoleErrors.push(msg.text());
        });
        page.on('pageerror', (err) => consoleErrors.push(String(err)));

        await page.goto('/');

        await expect(page.locator('h1.logo')).toHaveText('ISO 20022 Address Game');
        await expect(page.locator('#welcomeNameInput')).toBeVisible();
        await expect(page.locator('#startGameBtn')).toBeVisible();

        expect(consoleErrors, `console errors: ${consoleErrors.join('\n')}`).toEqual([]);
    });

    test('the bundled address formatter loads and drives country-specific layouts', async ({ page }) => {
        await page.goto('/');

        // Not merely "the file 200s": this is what the bundling is FOR. Hybrid
        // mode grades against country-specific field order, and without this
        // library lib/address.js falls back to one hardcoded layout for every
        // country — which would mark a correct German address wrong, since
        // German addresses put the house number after the street name.
        await expect
            .poll(() => page.evaluate(() => typeof window.addressFormatter))
            .toBe('object');

        const formatted = await page.evaluate(() => {
            const addr = { houseNumber: '123', road: 'Main St', city: 'Springfield', postcode: '10001' };
            const out = {};
            for (const cc of ['US', 'DE']) {
                out[cc] = window.addressFormatter.format({ ...addr, countryCode: cc }, { output: 'array' });
            }
            return out;
        });

        expect(formatted.US[0]).toBe('123 Main St');
        expect(formatted.DE[0]).toBe('Main St 123');
    });

    test('the welcome card carries the PMPG logo with a meaningful alt', async ({ page }) => {
        await page.goto('/');

        const logo = page.locator('.welcome-card .card-endorsement img');
        await expect(logo).toBeVisible();

        // The alt is asserted rather than merely checked non-empty, and it
        // matters more since the visible "Supported by" label was dropped:
        // the lockup alone now carries the endorsement, so a screen reader
        // has to announce it. An empty alt would hide the support from
        // exactly the users who cannot see the image.
        await expect(logo).toHaveAttribute('alt', 'Payments Market Practice Group');

        // The label is gone on purpose. Asserted rather than merely deleted,
        // so it cannot creep back in unnoticed.
        await expect(page.locator('.welcome-card .endorsement-label')).toHaveCount(0);
        await expect(page.locator('.welcome-card .card-endorsement')).not.toContainText('Supported by');

        // Not a link: a kiosk in Guided Access cannot come back from an
        // outbound navigation.
        await expect(page.locator('.welcome-card .card-endorsement a')).toHaveCount(0);

        // The asset actually resolves. toBeVisible() passes on a broken image,
        // so decode the natural width instead — 0 means it never loaded.
        expect(await logo.evaluate((img) => img.naturalWidth)).toBeGreaterThan(0);
    });

    test('nav switches between Play, Hall of Fame and Admin screens', async ({ page }) => {
        await page.goto('/');

        await page.click('[data-screen="leaderboard"]');
        await expect(page.locator('.leaderboard-screen h2')).toHaveText('Hall of Fame');

        await page.click('[data-screen="admin"]');
        await expect(page.locator('.pin-panel')).toBeVisible();

        await page.click('[data-screen="game"]');
        await expect(page.locator('#welcomeNameInput')).toBeVisible();
    });
});
