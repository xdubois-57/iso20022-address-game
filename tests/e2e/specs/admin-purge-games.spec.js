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
// Admin → Game Counter → Delete All Games.
//
// The button next to "Reset from Hall of Fame" that empties both the counter
// and the Hall of Fame. What is checked here is the wiring an organiser
// depends on: a confirmation they have to answer, the right endpoint called
// once they do, nothing sent at all when they cancel, and both panels reread
// afterwards so the dashboard shows the emptied installation rather than the
// one it was showing a second ago.
//
// The purge itself is answered by page.route rather than performed. The whole
// suite runs against ONE throwaway instance, serially: a spec that really
// deleted every game would delete the rows the Hall of Fame specs seeded and
// the counter every other spec has been incrementing, and it would do so
// whenever Playwright happened to schedule it. What the server does with the
// request is covered where it can be covered honestly — Tests\
// AdminControllerEndpointsTest, against a database of its own.

import { expect, test } from '@playwright/test';

const ADMIN_PIN = '1234';

/** Open the dashboard through the PIN pad, the way an organiser does. */
async function openDashboard(page) {
    await page.goto('/');
    await page.click('[data-screen="admin"]');
    for (const digit of ADMIN_PIN) {
        await page.click(`.pin-key[data-digit="${digit}"]`);
    }
    await page.click('.pin-key-submit');
    await expect(page.locator('.admin-dashboard')).toBeVisible();
    await expect(page.locator('#purgeGamesBtn')).toBeVisible();
    // The listing has answered, whichever answer it gave. Waiting for a table
    // specifically would hang on an installation with nothing on the board —
    // which this spec neither needs nor should depend on the run order for.
    await expect(page.locator('#adminLeaderboard table, #adminLeaderboard .empty-state')).toHaveCount(1);
}

/**
 * Count the calls to each admin action, answering admin/purge-games from here
 * instead of letting it reach the database.
 */
async function interceptPurge(page, calls) {
    await page.route('**/index.php', async (route) => {
        const action = route.request().headers()['x-action'];
        if (action) {
            calls.push(action);
        }
        if (action !== 'admin/purge-games') {
            await route.fallback();
            return;
        }
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, total_games: 0 }),
        });
    });
}

test('deleting all games asks first, then empties the counter and the Hall of Fame', async ({ page }) => {
    await openDashboard(page);

    const calls = [];
    await interceptPurge(page, calls);

    await page.click('#purgeGamesBtn');

    // The confirmation names both consequences. An organiser who reads only
    // the button would expect the counter to go and the board to stay.
    const message = page.locator('.confirm-overlay .overlay-message');
    await expect(message).toContainText('Hall of Fame');
    await expect(message).toContainText('cannot be undone');

    await page.click('.confirm-overlay #confirmOkBtn');

    await expect(page.locator('.overlay-message')).toContainText('All games deleted');
    await page.click('#modalOkBtn');

    expect(calls.filter((a) => a === 'admin/purge-games')).toHaveLength(1);
    // Both panels are reread: the counter's own figures and the listing the
    // purge just emptied. Leaving either on screen would show an installation
    // that no longer exists.
    expect(calls).toContain('admin/game-stats');
    expect(calls).toContain('admin/leaderboard-entries');
});

test('cancelling the confirmation deletes nothing', async ({ page }) => {
    await openDashboard(page);

    const calls = [];
    await interceptPurge(page, calls);

    await page.click('#purgeGamesBtn');
    await page.click('.confirm-overlay #confirmCancelBtn');

    await expect(page.locator('.confirm-overlay')).toHaveCount(0);
    expect(calls).not.toContain('admin/purge-games');
    // And the dashboard is still the dashboard: no "deleted" modal behind the
    // dismissed confirmation, and the panel still on screen.
    await expect(page.locator('.overlay-message')).toHaveCount(0);
    await expect(page.locator('#purgeGamesBtn')).toBeVisible();
});
