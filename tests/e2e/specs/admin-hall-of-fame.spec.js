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
// Admin → Hall of Fame Management.
//
// The property under test is the one that failed in production: an organiser
// asked to take a name off the public board must be able to FIND it here.
// The endpoint used to answer with the leading 200 rows while the public Hall
// of Fame paged through every row there is, so on a busy installation the
// entries people actually complain about — the low-scoring ones — were on the
// public wall and absent from the only screen that can delete them.
//
// It is tested from both ends. The first block plays against real rows
// submitted through the real endpoint, which is what proves an entry survives
// encryption, ordering and rendering all the way onto the screen. The second
// serves a two-page listing through page.route, because crossing a page
// boundary for real needs fifty-one submissions and leaderboard/submit allows
// ten per five minutes per address — a budget shared with every other spec in
// the run.

import { expect, test } from '@playwright/test';

const ADMIN_PIN = '1234';

async function csrfFrom(page) {
    return page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
}

function post(page, csrf, action, body) {
    return page.request.post('/index.php', {
        headers: {
            'Content-Type': 'application/json',
            'X-Action': action,
            'X-CSRF-Token': csrf,
        },
        data: JSON.stringify(body || {}),
    });
}

/** Open the dashboard through the PIN pad, the way an organiser does. */
async function openDashboard(page) {
    await page.goto('/');
    await page.click('[data-screen="admin"]');
    for (const digit of ADMIN_PIN) {
        await page.click(`.pin-key[data-digit="${digit}"]`);
    }
    await page.click('.pin-key-submit');
    await expect(page.locator('.admin-dashboard')).toBeVisible();
    await expect(page.locator('#adminLeaderboard table')).toBeVisible();
}

test.describe.serial('admin Hall of Fame management', () => {
    // Single characters on purpose: this is the shape of name that was
    // reported missing, and "0" is the one a truthiness test drops entirely.
    const NAMES = ['N', 'W', '0'];

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        try {
            await page.goto('/');
            const csrf = await csrfFrom(page);
            // Deliberately low scores: these are the entries that sink to the
            // bottom of the board, which is exactly where the old listing
            // stopped looking.
            for (const [i, name] of NAMES.entries()) {
                const resp = await post(page, csrf, 'leaderboard/submit', {
                    player_name: name,
                    score: 12 + i,
                    time_seconds: 600 + i,
                });
                expect((await resp.json()).success, `seeding "${name}"`).toBe(true);
            }
        } finally {
            await page.close();
        }
    });

    test('lists every entry the public Hall of Fame holds', async ({ page }) => {
        await page.goto('/');
        const csrf = await csrfFrom(page);
        // The public listing's own all-time count, not /board/data's — that
        // one is narrowed to the wall's time window and would be comparing
        // two different questions.
        const totalCount = (await (await post(page, csrf, 'leaderboard/top', { page: 1 })).json()).total_count;

        await openDashboard(page);
        const listed = await page.locator('#adminLeaderboard tbody tr').count();

        // A page holds fifty rows, and a whole suite run submits nowhere near
        // that many — so anything less than everything means the dashboard is
        // hiding rows again.
        expect(totalCount).toBeLessThanOrEqual(50);
        expect(listed).toBe(totalCount);
    });

    test('shows one-character names rather than empty cells', async ({ page }) => {
        await openDashboard(page);

        for (const name of NAMES) {
            await expect(
                page.locator('#adminLeaderboard tbody tr td:nth-child(2)').filter({ hasText: new RegExp(`^${name}$`) }),
            ).toHaveCount(1);
        }
    });

    test('a one-character name reaches the public Hall of Fame too', async ({ page }) => {
        await page.goto('/');
        await page.click('[data-screen="leaderboard"]');
        await expect(page.locator('.leaderboard-table')).toBeVisible();

        // The seeded runs are the worst on the board, so they are on its last
        // page wherever that happens to be.
        const lastPage = page.locator('.btn-page[title="Last"]');
        if (await lastPage.count()) {
            await lastPage.click();
        }

        for (const name of NAMES) {
            await expect(
                page.locator('.leaderboard-table tbody tr td:nth-child(2)').filter({ hasText: new RegExp(`^${name}$`) }),
            ).toHaveCount(1);
        }
    });

    test('deleting an entry removes it and renumbers what is left', async ({ page }) => {
        await openDashboard(page);

        const before = await page.locator('#adminLeaderboard tbody tr').count();
        const target = page.locator('#adminLeaderboard tbody tr').last();
        const targetName = (await target.locator('td:nth-child(2)').textContent()) ?? '';

        await target.locator('.btn-delete-entry').click();
        await page.click('.confirm-overlay #confirmOkBtn');

        await expect(page.locator('#adminLeaderboard tbody tr')).toHaveCount(before - 1);
        await expect(
            page.locator('#adminLeaderboard tbody tr td:nth-child(2)')
                .filter({ hasText: new RegExp(`^${targetName}$`) }),
        ).toHaveCount(0);

        // Ranks are a contiguous 1..n after the removal — the old code dropped
        // the row from the DOM and left every number below it one too high.
        const ranks = await page.locator('#adminLeaderboard tbody tr td:first-child')
            .evaluateAll((cells) => cells.map((c) => Number(c.textContent)));
        expect(ranks).toEqual(ranks.map((_, i) => i + 1));
    });
});

test.describe('admin Hall of Fame pagination', () => {
    /**
     * A listing too big for one page, served rather than submitted.
     *
     * Row 51 is the whole point: under the old top-200-in-one-response
     * endpoint there was no second page to reach at all, and the only entries
     * an organiser could delete were the ones the first response happened to
     * carry.
     */
    const PER_PAGE = 50;
    const TOTAL = 51;

    function pageOf(n) {
        const start = (n - 1) * PER_PAGE;
        const count = Math.min(PER_PAGE, TOTAL - start);
        return {
            entries: Array.from({ length: count }, (_, i) => ({
                id: start + i + 1,
                score: 100,
                time_seconds: 60,
                created_at: '2026-05-01 20:00:00',
                game_score: 10_000 - (start + i),
                player_name: `Player ${start + i + 1}`,
            })),
            page: n,
            total_pages: Math.ceil(TOTAL / PER_PAGE),
            total_count: TOTAL,
            per_page: PER_PAGE,
        };
    }

    test('reaches the entry past the first page, numbered from the page offset', async ({ page }) => {
        const asked = [];
        await page.route('**/index.php', async (route) => {
            if (route.request().headers()['x-action'] !== 'admin/leaderboard-entries') {
                await route.fallback();
                return;
            }
            const requested = JSON.parse(route.request().postData() || '{}').page || 1;
            asked.push(requested);
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(pageOf(requested)),
            });
        });

        await openDashboard(page);

        await expect(page.locator('#adminLeaderboard tbody tr')).toHaveCount(PER_PAGE);
        await expect(page.locator('#adminLeaderboard tbody tr').first().locator('td').first()).toHaveText('1');
        await expect(page.locator('#adminLeaderboard .page-info')).toHaveText(`${TOTAL} entries`);

        // The numbered button, not the "Next"/"Last" arrows that carry the
        // same data-page on a two-page listing.
        await page.locator('#adminLeaderboard .btn-page[data-page="2"]:not([title])').click();

        await expect(page.locator('#adminLeaderboard tbody tr')).toHaveCount(1);
        const row = page.locator('#adminLeaderboard tbody tr').first();
        // Rank 51, not rank 1: the number comes from the page offset the
        // server reported, not from this row's position in the array.
        await expect(row.locator('td').first()).toHaveText('51');
        await expect(row.locator('td').nth(1)).toHaveText('Player 51');
        await expect(row.locator('.btn-delete-entry')).toBeVisible();

        expect(asked).toEqual([1, 2]);
    });
});
