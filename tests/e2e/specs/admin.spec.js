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

// scripts/e2e-seed-config.php writes the documented default admin PIN.
const ADMIN_PIN = '1234';

async function enterPin(page, pin) {
    for (const digit of pin) {
        await page.click(`.pin-key[data-digit="${digit}"]`);
    }
    await page.click('.pin-key-submit');
}

test.describe('admin', () => {
    test('rejects a wrong PIN', async ({ page }) => {
        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await expect(page.locator('.pin-panel')).toBeVisible();

        await enterPin(page, '9999');

        await expect(page.locator('#pinError')).toBeVisible();
        await expect(page.locator('.pin-panel')).toBeVisible();
    });

    test('accepts the correct PIN and opens the dashboard', async ({ page }) => {
        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);

        await expect(page.locator('.admin-dashboard h2')).toHaveText('Admin Dashboard');
        await expect(page.locator('.admin-section', { hasText: 'Automatic Updates' })).toBeVisible();
        await expect(page.locator('.admin-section', { hasText: 'Event Code' })).toBeVisible();
        await expect(page.locator('.admin-section', { hasText: 'Theme Colors' })).toBeVisible();
    });

    test('logout returns to the game screen, and admin re-prompts for the PIN', async ({ page }) => {
        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);
        await expect(page.locator('.admin-dashboard')).toBeVisible();

        await page.click('#adminLogoutBtn');
        await expect(page.locator('#welcomeNameInput')).toBeVisible();

        // No session persistence: admin always re-prompts for the PIN.
        await page.click('[data-screen="admin"]');
        await expect(page.locator('.pin-panel')).toBeVisible();
    });
});
