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
// The two dedicated screens (?mode=hof, ?mode=play) and — carrying more
// weight than either — proof that the three contexts which predate them are
// untouched.
//
// The regression half is deliberately first and deliberately blunt. The wall
// and the play station are new and nobody depends on them yet; the mobile
// link, the desktop browser and the iPad kiosk are in production, and the one
// way this work can actually do damage is by changing them.

import { expect, test } from '@playwright/test';

const ADMIN_PIN = '1234';

async function enterPin(page, pin) {
    for (const digit of pin) {
        await page.click(`.pin-key[data-digit="${digit}"]`);
    }
    await page.click('.pin-key-submit');
}

test.describe('display modes — the existing contexts are unchanged', () => {
    test('the bare URL still shows all four nav buttons', async ({ page }) => {
        await page.goto('/');

        await expect(page.locator('#headerNav')).toBeVisible();
        await expect(page.locator('.nav-btn')).toHaveCount(4);
        await expect(page.locator('[data-screen="game"]')).toBeVisible();
        await expect(page.locator('[data-screen="leaderboard"]')).toBeVisible();
        await expect(page.locator('[data-screen="admin"]')).toBeVisible();
        await expect(page.locator('#stopBtn')).toBeVisible();

        // No data-mode at all, rather than an empty one: the default context
        // must remain indistinguishable from what it was before ?mode existed.
        expect(await page.evaluate(() => document.body.dataset.mode)).toBeUndefined();
    });

    test('the hamburger still opens and closes the nav below 768px', async ({ page }) => {
        await page.setViewportSize({ width: 400, height: 800 });
        await page.goto('/');

        const nav = page.locator('#headerNav');
        const burger = page.locator('#hamburgerBtn');

        await expect(burger).toBeVisible();
        await expect(burger).toHaveAttribute('aria-expanded', 'false');

        await burger.click();
        await expect(nav).toHaveClass(/open/);
        await expect(burger).toHaveAttribute('aria-expanded', 'true');

        // Clicking a nav entry closes it again — the path a player on a phone
        // actually takes.
        await page.click('[data-screen="leaderboard"]');
        await expect(nav).not.toHaveClass(/open/);
        await expect(burger).toHaveAttribute('aria-expanded', 'false');
    });

    test('the admin kiosk toggle still does exactly what it did', async ({ page }) => {
        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);
        await expect(page.locator('.admin-dashboard')).toBeVisible();

        // The checkbox itself is display:none — it is styled as a slider, and
        // what a person taps is the label. Driven the same way here, so this
        // exercises the path an organiser actually takes.
        const toggle = page.locator('#kioskToggle');
        const slider = page.locator('.kiosk-toggle .kiosk-slider');
        await expect(slider).toBeVisible();
        await expect(toggle).not.toBeChecked();
        await expect(page.locator('.kiosk-label')).toHaveText('Disabled');

        await slider.click();
        await expect(toggle).toBeChecked();
        await expect(page.locator('.kiosk-label')).toHaveText('Enabled');
        // The GitHub link steps aside in kiosk mode: an outbound navigation
        // strands a locked-down device.
        await expect(page.locator('#footerGithubLink')).toBeHidden();

        await slider.click();
        await expect(toggle).not.toBeChecked();
        await expect(page.locator('.kiosk-label')).toHaveText('Disabled');
        await expect(page.locator('#footerGithubLink')).toBeVisible();
    });

    test('an unknown mode behaves exactly like the bare URL', async ({ page }) => {
        await page.goto('/?mode=nimportequoi');

        await expect(page.locator('.nav-btn')).toHaveCount(4);
        expect(await page.evaluate(() => document.body.dataset.mode)).toBeUndefined();
    });
});

test.describe('display modes — the dedicated screens', () => {
    for (const mode of ['hof', 'play']) {
        test(`?mode=${mode} serves a shell with no nav and no hamburger`, async ({ page }) => {
            await page.goto(`/?mode=${mode}`);

            await expect(page.locator('h1.logo')).toHaveText('ISO 20022 Address Game');
            expect(await page.evaluate(() => document.body.dataset.mode)).toBe(mode);

            // Counted rather than checked for visibility: the requirement is
            // that the markup is absent, and a hidden element is still
            // reachable by keyboard.
            await expect(page.locator('#headerNav')).toHaveCount(0);
            await expect(page.locator('#hamburgerBtn')).toHaveCount(0);
            await expect(page.locator('.nav-btn')).toHaveCount(0);
            await expect(page.locator('#stopBtn')).toHaveCount(0);
        });
    }

    test('the wall keeps the endorsement but drops Privacy and GitHub', async ({ page }) => {
        await page.goto('/?mode=hof');

        await expect(page.locator('.footer-endorsement img')).toHaveAttribute(
            'alt', 'Payments Market Practice Group'
        );
        await expect(page.locator('.game-footer [data-screen="privacy"]')).toHaveCount(0);
        await expect(page.locator('#footerGithubLink')).toHaveCount(0);
    });

    test('the play station keeps its footer intact', async ({ page }) => {
        await page.goto('/?mode=play');

        await expect(page.locator('.game-footer [data-screen="privacy"]')).toHaveCount(1);
        await expect(page.locator('#footerGithubLink')).toHaveCount(1);
        await expect(page.locator('.footer-endorsement')).toHaveCount(1);
    });
});
