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

const ADMIN_PIN = '1234';

/**
 * The checkbox reuses the .kiosk-toggle/.kiosk-slider skin (app.css), which
 * sets `display: none` on the raw input — CSS display:none survives even
 * Playwright's `force` option, so a real mouse click can never reach it.
 * Calling the native DOM .click() directly toggles it exactly like a click
 * on the visible slider would (the browser handles a real .click() call on
 * a checkbox identically either way, `checked` state and `change` event
 * included) — guarded to a no-op when already in the desired state, so
 * calling this twice in a row does not toggle it back off.
 */
async function setEnabledToggle(section, checked) {
    const input = section.locator('#autoUpdateEnabledToggle');
    if ((await input.isChecked()) !== checked) {
        await input.evaluate((el) => el.click());
    }
}

async function loginAsAdmin(page) {
    await page.goto('/');
    await page.click('[data-screen="admin"]');
    for (const digit of ADMIN_PIN) {
        await page.click(`.pin-key[data-digit="${digit}"]`);
    }
    await page.click('.pin-key-submit');
    await expect(page.locator('.admin-dashboard')).toBeVisible();
}

test.describe('admin > automatic updates', () => {
    test('save persists enabled state, channel and repository across a reload', async ({ page }) => {
        await loginAsAdmin(page);

        const section = page.locator('#autoUpdateSection');
        await expect(section).toBeVisible();

        await setEnabledToggle(section, true);
        await section.locator('input[name="autoUpdateChannel"][value="main"]').check();
        await section.locator('#autoUpdateOwnerInput').fill('example-owner');
        await section.locator('#autoUpdateRepoInput').fill('example-repo');
        await section.locator('#saveAutoUpdateBtn').click();

        await expect(section.locator('#autoUpdateSaveStatus')).toHaveText('Saved.');

        // Re-enter the admin dashboard from scratch to prove this round-tripped
        // through the server (settings table), not just local DOM state.
        await page.reload();
        await page.click('[data-screen="admin"]');
        for (const digit of ADMIN_PIN) {
            await page.click(`.pin-key[data-digit="${digit}"]`);
        }
        await page.click('.pin-key-submit');

        const reloadedSection = page.locator('#autoUpdateSection');
        await expect(reloadedSection).toBeVisible();
        await expect(reloadedSection.locator('#autoUpdateEnabledToggle')).toBeChecked();
        await expect(reloadedSection.locator('input[name="autoUpdateChannel"][value="main"]')).toBeChecked();
        await expect(reloadedSection.locator('#autoUpdateOwnerInput')).toHaveValue('example-owner');
        await expect(reloadedSection.locator('#autoUpdateRepoInput')).toHaveValue('example-repo');
    });

    test('save rejects an empty repository', async ({ page }) => {
        await loginAsAdmin(page);
        const section = page.locator('#autoUpdateSection');

        await section.locator('#autoUpdateRepoInput').fill('');
        await section.locator('#saveAutoUpdateBtn').click();

        await expect(section.locator('#autoUpdateSaveStatus')).not.toHaveText('Saved.');
    });

    test('generating a webhook secret shows it once, and it is gone after reload', async ({ page }) => {
        await loginAsAdmin(page);
        const section = page.locator('#autoUpdateSection');

        await expect(section.locator('#webhookSecretReveal')).toBeHidden();
        await section.locator('#generateWebhookSecretBtn').click();

        const reveal = section.locator('#webhookSecretReveal');
        await expect(reveal).toBeVisible();
        await expect(reveal).toContainText(/[0-9a-f]{64}/);
        await expect(section.locator('#generateWebhookSecretBtn')).toHaveText('Regenerate Secret');

        await page.reload();
        await page.click('[data-screen="admin"]');
        for (const digit of ADMIN_PIN) {
            await page.click(`.pin-key[data-digit="${digit}"]`);
        }
        await page.click('.pin-key-submit');

        const reloadedSection = page.locator('#autoUpdateSection');
        await expect(reloadedSection).toBeVisible();
        await expect(reloadedSection.locator('#webhookSecretReveal')).toBeHidden();
        await expect(reloadedSection.locator('#generateWebhookSecretBtn')).toHaveText('Regenerate Secret');
        await expect(reloadedSection).not.toContainText(/[0-9a-f]{64}/);
    });
});
