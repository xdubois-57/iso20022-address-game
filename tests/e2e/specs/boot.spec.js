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

// The one third party the page fetches from. PicoCSS, Dropzone, canvas-confetti,
// Chart.js and the QR library all come from here; the address formatter, which
// grading depends on, is bundled locally precisely so it does not.
const CDN_ORIGIN = 'https://cdn.jsdelivr.net/';

test.describe('boot', () => {
    test('home page renders the welcome card with no console errors of its own', async ({ page }) => {
        // Partitioned by origin rather than collected into one list, and the
        // reason is worth stating because it looks like a weakening and is the
        // opposite.
        //
        // This test exists to catch the application's own boot failures: an
        // import map that resolves to nothing, a syntax error in app.js, a
        // module that 404s. It used to fail on ANY console error, which
        // included "Failed to load resource" for each of the five CDN files —
        // so a jsdelivr outage, or a runner without a route to it, turned this
        // red for a reason that has nothing to do with the code. README
        // § Requirements says in as many words that the game is expected to
        // keep working on a restricted network, and display-modes.spec.js
        // already installs a stand-in for the CDN's QR library rather than let
        // its availability decide whether a test runs. This follows both.
        //
        // Nothing is exempted except a third-party file failing to arrive:
        //   - every uncaught exception fails, wherever it came from;
        //   - every console error from OUR origin fails;
        //   - a CDN script that loads and then misbehaves fails, because that
        //     is not a "Failed to load resource" message;
        //   - and a first-party request that fails is caught separately below,
        //     which the old single-list version never checked explicitly.
        const appErrors = [];
        const cdnResourceFailures = [];
        const firstPartyRequestFailures = [];

        page.on('console', (msg) => {
            if (msg.type() !== 'error') return;
            const from = msg.location().url || '';
            if (from.startsWith(CDN_ORIGIN) && msg.text().includes('Failed to load resource')) {
                cdnResourceFailures.push(from);
                return;
            }
            appErrors.push(`${msg.text()}  [${from || 'no source'}]`);
        });
        page.on('pageerror', (err) => appErrors.push(String(err)));
        page.on('requestfailed', (request) => {
            if (!request.url().startsWith(CDN_ORIGIN)) {
                firstPartyRequestFailures.push(`${request.url()} — ${request.failure()?.errorText}`);
            }
        });

        await page.goto('/');

        await expect(page.locator('h1.logo')).toHaveText('ISO 20022 Address Game');
        await expect(page.locator('#welcomeNameInput')).toBeVisible();
        await expect(page.locator('#startGameBtn')).toBeVisible();

        expect(appErrors, `console errors from the application: ${appErrors.join('\n')}`).toEqual([]);
        expect(
            firstPartyRequestFailures,
            `first-party requests that failed: ${firstPartyRequestFailures.join('\n')}`
        ).toEqual([]);

        // Reported, never failed on. It is genuinely useful to see in the log
        // that a run happened without the CDN — the assertions above then say
        // the welcome card came up anyway, which is the documented behaviour.
        if (cdnResourceFailures.length > 0) {
            console.log(
                `note: ${cdnResourceFailures.length} CDN resource(s) did not load on this runner; `
                + 'the page booted regardless, which is what README § Requirements promises.'
            );
        }
    });

    test('the security headers name the directives that do not fall back', async ({ page }) => {
        // frame-ancestors, form-action and base-uri are NOT covered by
        // default-src. A CSP that omits them leaves them unset, not
        // restricted — which is what the passive ZAP scan reports as
        // "Failure to Define Directive with No Fallback", and it is right to.
        //
        // Asserted over real HTTP because these are response headers: no unit
        // test can see them, and public/index.php cannot be included from one.
        const response = await page.goto('/');
        const csp = response.headers()['content-security-policy'];

        expect(csp, 'the shell must carry a CSP at all').toBeTruthy();
        expect(csp).toContain("frame-ancestors 'none'");
        expect(csp).toContain("form-action 'self'");
        expect(csp).toContain("base-uri 'self'");

        // No blanket permission for inline script or style. 'unsafe-inline'
        // allows ANY inline block the page ends up containing, an injected one
        // included, which is close to having no script policy at all.
        expect(csp, "'unsafe-inline' must not come back").not.toContain('unsafe-inline');
        // …and 'unsafe-hashes' is the tempting way to bring style attributes
        // back, which would undo most of the benefit.
        expect(csp).not.toContain('unsafe-hashes');

        // A per-request nonce instead, in both directives.
        const nonce = /'nonce-([A-Za-z0-9+/=]+)'/.exec(csp);
        expect(nonce, 'the policy must carry a nonce').not.toBeNull();
        expect(csp.match(/'nonce-[A-Za-z0-9+/=]+'/g)).toHaveLength(2);

        // The same nonce reaches the markup — if the header and the page
        // disagreed, every inline block would be blocked and the page would
        // arrive unstyled with no import map.
        //
        // Read through the .nonce IDL PROPERTY, not getAttribute('nonce').
        // Browsers deliberately blank the content attribute once the element
        // is parsed — "nonce hiding", which stops a page from being made to
        // leak its own nonce through a CSS attribute selector — while keeping
        // the real value on the element. getAttribute() therefore returns ''
        // on a page whose nonces are perfectly correct.
        const inlineNonces = await page.evaluate(
            () => [...document.querySelectorAll('script, style')]
                .map((el) => el.nonce)
                .filter((value) => value)
        );
        expect(inlineNonces.length, 'the shell has inline blocks to authorise').toBeGreaterThan(0);
        for (const value of inlineNonces) {
            expect(value).toBe(nonce[1]);
        }

        // And the page still works under it: a violation surfaces as a
        // first-party console error, which the test above fails on.
        await expect(page.locator('#startGameBtn')).toBeVisible();
    });

    test('the nonce is different on every request', async ({ page }) => {
        // A nonce a page could predict is a nonce an injection can quote.
        const read = async () => {
            const response = await page.goto('/');
            return /'nonce-([A-Za-z0-9+/=]+)'/.exec(
                response.headers()['content-security-policy']
            )[1];
        };

        const first = await read();
        const second = await read();

        expect(first).not.toBe(second);
        expect(first.length).toBeGreaterThanOrEqual(20);
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
