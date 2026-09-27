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
        await expect(page.locator('.admin-section', { hasText: 'Theme Colors' })).toBeVisible();
        // Both removed on purpose: the game is open to everyone, and
        // deployment is the deploy script's job alone. Either section
        // reappearing would mean the feature grew back.
        await expect(page.locator('.admin-section', { hasText: 'Event Code' })).toHaveCount(0);
        await expect(page.locator('.admin-section', { hasText: 'Automatic Updates' })).toHaveCount(0);
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

    test('the countdown shows only while a deadline is set, and Clear switches it off', async ({ page }) => {
        // Resolves once the welcome screen has asked for the deadline, so the
        // "no countdown" assertion cannot pass merely because the answer has
        // not arrived yet.
        const deadlineFetched = () => page.waitForResponse(
            (resp) => resp.request().headers()['x-action'] === 'game/deadline'
        );

        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);

        await page.fill('#deadlineInput', '2030-01-01T09:00');
        await page.click('#setDeadlineBtn');
        await page.click('#modalOkBtn');

        let fetched = deadlineFetched();
        await page.click('#adminLogoutBtn');
        await fetched;
        await expect(page.locator('#countdownBanner')).toHaveClass('countdown-banner');
        await expect(page.locator('#countdownBanner')).toContainText('Unstructured address support ends in');

        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);
        await page.click('#clearDeadlineBtn');
        await expect(page.locator('.overlay-message')).toContainText('no longer shown to players');
        await page.click('#modalOkBtn');
        await expect(page.locator('#deadlineStatus')).toHaveText('No deadline set. The countdown is hidden from players.');

        // Leaves the instance as it found it: no deadline saved.
        fetched = deadlineFetched();
        await page.click('#adminLogoutBtn');
        expect((await (await fetched).json()).deadline).toBeNull();
        await expect(page.locator('#welcomeNameInput')).toBeVisible();
        await expect(page.locator('#countdownBanner')).toBeEmpty();
        await expect(page.locator('#countdownBanner')).not.toHaveClass('countdown-banner');
    });
});
