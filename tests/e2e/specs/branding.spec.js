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

const PMPG_ALT = 'Payments Market Practice Group';

async function enterPin(page, pin) {
    for (const digit of pin) {
        await page.click(`.pin-key[data-digit="${digit}"]`);
    }
    await page.click('.pin-key-submit');
}

/** Log in on the admin screen and open the dashboard. */
async function loginAsAdmin(page) {
    await page.goto('/');
    await page.click('[data-screen="admin"]');
    await expect(page.locator('.pin-panel')).toBeVisible();
    await enterPin(page, ADMIN_PIN);
    await expect(page.locator('#eventCodeAdminInput')).toBeVisible();
}

/** Set the event code, or clear it by passing an empty string. */
async function setEventCode(page, code) {
    await page.fill('#eventCodeAdminInput', code);
    await page.click('#saveEventCodeBtn');
    await expect(page.locator('#eventCodeStatus')).toBeVisible();
}

// The welcome card's own logo is asserted in boot.spec.js, next to the rest of
// the first-paint checks. What lives here is everything that needs the
// instance reconfigured first.
test.describe('PMPG branding', () => {
    test('the event code gate carries the logo too', async ({ page, browser }) => {
        // This gate is the FIRST screen a player sees whenever an event code
        // is configured — which is the situation the game is actually run in
        // at a conference. A logo that only appeared on the post-gate welcome
        // card would be missing precisely then.
        await loginAsAdmin(page);
        await setEventCode(page, 'e2e-branding-code');

        try {
            // A separate context, because an admin session is exempt from the
            // gate and would sail straight past the screen under test.
            const visitor = await browser.newContext();
            const visitorPage = await visitor.newPage();
            await visitorPage.goto('/');

            await expect(visitorPage.locator('#eventCodeInput')).toBeVisible();

            const logo = visitorPage.locator('.welcome-card .card-endorsement img');
            await expect(logo).toBeVisible();
            await expect(logo).toHaveAttribute('alt', PMPG_ALT);

            await visitor.close();
        } finally {
            // Restore the instance for every spec that runs after this one:
            // a leftover event code would gate the whole suite.
            await setEventCode(page, '');
        }
    });

    test('clearing the event code leaves the game reachable again', async ({ browser }) => {
        // Guards the cleanup above. If the finally block ever stopped working,
        // the failure would surface here as a clear message rather than as a
        // baffling cascade in an unrelated spec.
        const visitor = await browser.newContext();
        const visitorPage = await visitor.newPage();
        await visitorPage.goto('/');

        await expect(visitorPage.locator('#welcomeNameInput')).toBeVisible();
        await visitor.close();
    });
});
