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

// A dedicated screen is addressed by ?mode= AND a matching &t=; the helper
// knows both halves so this file does not have to.
import { displayModeToken, gotoMode } from '../support/display-mode.js';

const ADMIN_PIN = '1234';

async function enterPin(page, pin) {
    for (const digit of pin) {
        await page.click(`.pin-key[data-digit="${digit}"]`);
    }
    await page.click('.pin-key-submit');
}

/**
 * Click real screen coordinates inside an element.
 *
 * Used where the target has pointer-events:none: a locator click would be
 * refused as unactionable, and forcing it would prove nothing, since the whole
 * question is what an ordinary tap does.
 */
async function tapAt(page, locator, offsetX, offsetY) {
    const box = await locator.boundingBox();
    expect(box, 'the element must be laid out to be tapped').not.toBeNull();
    const x = offsetX === undefined ? box.x + box.width / 2 : box.x + offsetX;
    const y = offsetY === undefined ? box.y + box.height / 2 : box.y + offsetY;
    await page.mouse.click(x, y);
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

    test('the admin panel explains the modes rather than switching them', async ({ page }) => {
        // Read before the panel is opened, so the expectations below are the
        // instance's real token rather than whatever the panel printed.
        const token = await displayModeToken(page);

        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);

        const section = page.locator('.display-modes-section');
        await expect(section).toBeVisible();
        await expect(section.locator('h3')).toHaveText('Display modes');

        // Three blocks: the device toggle, the two URL-driven screens, and
        // the setting that belongs to one of them.
        await expect(section.locator('h4')).toHaveText([
            'Kiosk mode — this device',
            'Dedicated screens — by URL',
            'Wall window',
        ]);

        // Both URLs, absolute, built from the origin the organiser is on —
        // not from anything configured, so what they copy is reachable from
        // where they are standing.
        const origin = new URL(page.url()).origin;
        await expect(page.locator('.display-mode-url')).toHaveText([
            `${origin}/?mode=hof&t=${token}`,
            `${origin}/?mode=play&t=${token}`,
        ]);

        // A slot for a QR code each. Whether one is drawn into them is
        // asserted separately below, because it depends on a CDN script.
        await expect(page.locator('.display-mode-qr')).toHaveCount(2);

        // The launch command, ready to copy, and the reason it is preferred.
        await expect(page.locator('.display-mode-cmd pre')).toContainText(
            `chrome --kiosk --app="${origin}/?mode=hof&t=${token}"`
        );
        await expect(page.locator('.display-mode-note')).toContainText('reboot');

        // The panel is the instructions, not the switch. A control here that
        // put the wall into wall mode would go out at that PC's first reboot,
        // which is the exact problem the URL solves.
        await expect(section.locator('input[type="checkbox"]')).toHaveCount(1);
        await expect(section.locator('#kioskToggle')).toHaveCount(1);
    });

    test('each dedicated screen gets a QR code, from the library already loaded', async ({ page }) => {
        // qrcode-generator arrives from a CDN, so a stand-in is installed
        // before the page's own scripts run. The real library overwrites it
        // whenever it loads, which means this asserts the real thing on a
        // machine with a route to jsdelivr and asserts OUR half of it —
        // that both slots are filled with what the library returned —
        // everywhere else. Either way the test runs; it is never skipped over.
        await page.addInitScript(() => {
            window.qrcode = function () {
                return {
                    addData(text) { this._text = text; },
                    make() { /* nothing to compute for a stand-in */ },
                    createSvgTag: () => '<svg viewBox="0 0 1 1"></svg>',
                };
            };
        });

        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);

        await expect(page.locator('.display-mode-qr')).toHaveCount(2);
        await expect(page.locator('.display-mode-qr svg')).toHaveCount(2);

        // One library, not two: the same script tag the kiosk share code uses.
        await expect(page.locator('script[src*="qrcode"]')).toHaveCount(1);
    });

    test('the wall window setting lives in exactly one place and still saves', async ({ page }) => {
        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);

        // Moved, not copied. Two fields writing the same key is how an
        // organiser sets a value in one of them and watches the other undo it.
        await expect(page.locator('#boardWindowInput')).toHaveCount(1);
        await expect(page.locator('.admin-section', { hasText: 'Wall Window' })).toHaveCount(1);
        await expect(page.locator('.display-modes-section #boardWindowInput')).toHaveCount(1);

        await expect(page.locator('#boardWindowInput')).toHaveValue('24');
        await page.fill('#boardWindowInput', '6');
        await page.click('#saveBoardWindowBtn');
        await expect(page.locator('#boardWindowStatus')).toContainText('last 6 hours');

        // It reaches the wall's own data source, which is the only screen it
        // is meant to affect.
        expect((await (await page.request.get('/board/data')).json()).window_hours).toBe(6);

        // Put it back so the rest of the run sees a normal instance.
        await page.fill('#boardWindowInput', '24');
        await page.click('#saveBoardWindowBtn');
        await expect(page.locator('#boardWindowStatus')).toContainText('last 24 hours');
    });

    test('copying a URL puts it on the clipboard', async ({ page, context }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        const token = await displayModeToken(page);
        await page.goto('/');
        await page.click('[data-screen="admin"]');
        await enterPin(page, ADMIN_PIN);

        await page.locator('.display-mode-row .display-mode-copy').first().click();
        await expect(page.locator('.display-mode-row .display-mode-copy').first())
            .toHaveText('Copied');

        // What is copied is the WHOLE address, token included — an organiser
        // pasting it into a kiosk launcher must get a working screen, not one
        // that comes back with menus.
        const origin = new URL(page.url()).origin;
        expect(await page.evaluate(() => navigator.clipboard.readText()))
            .toBe(`${origin}/?mode=hof&t=${token}`);
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
            await gotoMode(page, mode);

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
        await gotoMode(page, 'hof');

        await expect(page.locator('.footer-endorsement img')).toHaveAttribute(
            'alt', 'Payments Market Practice Group'
        );
        await expect(page.locator('.game-footer [data-screen="privacy"]')).toHaveCount(0);
        await expect(page.locator('#footerGithubLink')).toHaveCount(0);
    });

    test('the play station keeps its footer intact', async ({ page }) => {
        await gotoMode(page, 'play');

        await expect(page.locator('.game-footer [data-screen="privacy"]')).toHaveCount(1);
        await expect(page.locator('#footerGithubLink')).toHaveCount(1);
        await expect(page.locator('.footer-endorsement')).toHaveCount(1);
    });
});

// Serial, and seeded exactly once with as few entries as the assertions need.
//
// leaderboard/submit is rate-limited to ten successful submissions per five
// minutes per address, and that budget is shared with every other spec in the
// run — board-data.spec.js and gameplay.spec.js both spend from it. Seeding
// generously here does not fail this file, it fails gameplay.spec.js several
// minutes later, which is a miserable thing to debug. Four is what a podium
// plus a row costs; the tests that need a full board serve one through
// page.route instead.
test.describe.serial('the wall (?mode=hof)', () => {
    const SEEDED = 4;

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        try {
            await page.goto('/');
            const csrf = await page.evaluate(
                () => document.querySelector('meta[name="csrf-token"]')?.content || ''
            );

            // Top up to SEEDED rather than adding SEEDED. Other specs in the
            // run have already put entries on the board and spent from the
            // same rate-limit budget; submitting a fixed number regardless is
            // how that budget gets exhausted for whoever runs last.
            const existing = (await (await page.request.get('/board/data')).json()).total_count;

            for (let i = existing; i < SEEDED; i++) {
                const resp = await page.request.post('/index.php', {
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Action': 'leaderboard/submit',
                        'X-CSRF-Token': csrf,
                    },
                    data: JSON.stringify({
                        player_name: `Wall Player ${i}`,
                        score: 95 - i,
                        time_seconds: 40 + i,
                    }),
                });
                expect((await resp.json()).success, `seeding entry ${i}`).toBe(true);
            }
        } finally {
            await page.close();
        }
    });

    test('shows a podium and a ranked list, and nothing to click', async ({ page }) => {
        await gotoMode(page, 'hof');

        await expect(page.locator('.wall-title')).toHaveText('Hall of Fame');
        await expect(page.locator('.wall-pod')).toHaveCount(3);
        await expect(page.locator('.wall-list tbody tr').first()).toBeVisible();

        // The winner stands in the middle of the podium, not on the left.
        const podiumOrder = await page.locator('.wall-pod').evaluateAll(
            (nodes) => nodes.map((n) => n.className)
        );
        expect(podiumOrder[1]).toContain('wall-pod-1');

        // No pagination and no navigation: this screen renders one thing.
        await expect(page.locator('.pagination')).toHaveCount(0);
        await expect(page.locator('.btn-page')).toHaveCount(0);
        await expect(page.locator('.nav-btn')).toHaveCount(0);
        // Scoped to the board itself: the layout always carries the
        // inactivity overlay's hidden button, which is not part of this
        // screen and is not what "nothing to click" is about.
        await expect(page.locator('#appContainer button')).toHaveCount(0);
        await expect(page.locator('#appContainer a')).toHaveCount(0);
    });

    test('an accidental touch anywhere on the board does nothing', async ({ page }) => {
        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-pod').first()).toBeVisible();

        const before = await page.locator('#appContainer').innerHTML();

        // Dispatched through page.mouse rather than locator.click(), and NOT
        // with `force: true`. pointer-events:none means Playwright's
        // actionability check would never let a locator click through, so a
        // forced click was the old way to get one out — but forcing is exactly
        // what a real finger cannot do. Clicking coordinates is what an
        // accidental tap actually is, and the assertion is unchanged: nothing
        // on the board reacts.
        await tapAt(page, page.locator('.wall-screen'), 40, 40);
        await tapAt(page, page.locator('.wall-title'));
        await tapAt(page, page.locator('.wall-list tbody tr').first());

        await expect(page.locator('.wall-title')).toHaveText('Hall of Fame');
        expect(await page.locator('#appContainer').innerHTML()).toBe(before);
    });

    test('the row count comes from the viewport, not from a constant', async ({ page }) => {
        // A full board, served rather than submitted: leaderboard/submit is
        // rate-limited to ten per five minutes, and what is under test here is
        // how many rows the page decides to draw, not how they got there.
        await page.route('**/board/data*', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                window_hours: 24,
                total_count: 50,
                server_time: '2026-05-01T20:00:00+00:00',
                entries: Array.from({ length: 50 }, (_, i) => ({
                    id: i + 1,
                    rank: i + 1,
                    player_name: `Filler ${i + 1}`,
                    game_score: 1000 - i,
                    time_seconds: 60,
                    created_at: '2026-05-01 20:00:00',
                })),
                recent: [],
            }),
        }));

        // A 42-inch portrait panel is 1080x1920 on one machine and 2160x3840
        // on the next, so a hard-coded row count would leave either a gaping
        // hole or a list running off the bottom edge.
        await page.setViewportSize({ width: 1080, height: 1920 });
        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-list tbody tr').first()).toBeVisible();
        const tall = await page.locator('.wall-list tbody tr').count();

        await page.setViewportSize({ width: 1080, height: 700 });
        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-list tbody tr').first()).toBeVisible();
        const short = await page.locator('.wall-list tbody tr').count();

        expect(short).toBeGreaterThan(0);
        expect(tall).toBeGreaterThan(short);
    });

    test('a failed poll leaves the last good board on screen', async ({ page }) => {
        // This test asks for more time than the suite's default budget allows
        // it, and used to get away with it only because the waits below
        // resolved early. It contains TWO waits of up to 60 s — one for the
        // board to notice it is stale, one for it to recover — inside a 60 s
        // test timeout, so a wall that legitimately took forty seconds to go
        // stale would have been reported as a harness timeout rather than as
        // the behaviour it actually is.
        //
        // test.slow() rather than a hard-coded number: it triples whatever the
        // configured timeout is, so this keeps scaling with a coverage or
        // security-scan run the way every other budget in the config does.
        test.slow();

        await gotoMode(page, 'hof');
        await expect(page.locator('.wall-pod')).toHaveCount(3);

        const shown = await page.locator('.wall-list, .wall-podium').first().innerHTML();

        // Every subsequent poll fails, exactly as a dropped venue network
        // would look from inside the page.
        await page.route('**/board/data*', (route) => route.abort('failed'));

        // Long enough for several attempts (5s, then a growing backoff) — the
        // board must still be there afterwards, never a blank screen and never
        // an error page.
        await expect(page.locator('#wallStaleDot')).toBeVisible({ timeout: 60_000 });
        await expect(page.locator('.wall-pod')).toHaveCount(3);
        expect(await page.locator('.wall-list, .wall-podium').first().innerHTML()).toBe(shown);

        // …and it recovers on its own once the network returns, with no
        // intervention, which is the property the evening depends on.
        await page.unroute('**/board/data*');
        await expect(page.locator('#wallStaleDot')).toBeHidden({ timeout: 60_000 });
    });

    test('an empty board says so rather than showing an error', async ({ page }) => {
        // Served an empty board rather than emptied for real: purging the
        // leaderboard would destroy the fixture the serial tests above share,
        // and what is under test here is the rendering of an empty response.
        await page.route('**/board/data*', (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                window_hours: 24,
                total_count: 0,
                server_time: '2026-05-01T20:00:00+00:00',
                entries: [],
                recent: [],
            }),
        }));

        await gotoMode(page, 'hof');

        await expect(page.locator('.wall-empty')).toBeVisible();
        await expect(page.locator('.wall-title')).toHaveText('Hall of Fame');
        await expect(page.locator('.wall-pod')).toHaveCount(0);
    });
});
