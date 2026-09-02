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
// The sharing switch, and the line it must not cross.
//
// Two halves, and the second one carries more weight than the first. That the
// buttons disappear is easy and would be easy to get right by accident; that
// /share keeps answering while they are gone is the whole design, and the only
// place it is observable is over real HTTP against a real instance.
//
// SERIAL, and it puts the setting back. The setting is global to the throwaway
// instance every spec in the run shares, and leaving it off would silently
// change what a later spec is testing.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const ADMIN_PIN = '1234';
const here = path.dirname(fileURLToPath(import.meta.url));
const scenariosXlsx = path.resolve(here, '../../../public/assets/Scenarios.xlsx');

/** A session cookie plus its CSRF token. */
async function session(page) {
    await page.goto('/');
    const csrf = await page.evaluate(
        () => document.querySelector('meta[name="csrf-token"]')?.content || ''
    );
    expect(csrf).toMatch(/^[0-9a-f]{64}$/);
    return csrf;
}

function api(page, csrf, action, body) {
    return page.request.post('/index.php', {
        headers: {
            'Content-Type': 'application/json',
            'X-Action': action,
            'X-CSRF-Token': csrf,
        },
        data: JSON.stringify(body ?? {}),
    });
}

async function loginAsAdmin(page, csrf) {
    const login = await api(page, csrf, 'admin/login', { pin: ADMIN_PIN });
    expect((await login.json()).success).toBe(true);
}

async function setSharing(page, enabled) {
    const csrf = await session(page);
    await loginAsAdmin(page, csrf);
    const resp = await api(page, csrf, 'admin/set-sharing', { sharing_enabled: enabled });
    const body = await resp.json();
    expect(body.success, `set-sharing failed: ${JSON.stringify(body)}`).toBe(true);
    expect(body.sharing_enabled).toBe(enabled);
    return csrf;
}

async function seedScenarios(page) {
    const csrf = await session(page);
    await loginAsAdmin(page, csrf);

    const upload = await page.request.post('/index.php', {
        headers: { 'X-Action': 'admin/upload', 'X-CSRF-Token': csrf },
        multipart: {
            file: {
                name: 'Scenarios.xlsx',
                mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                buffer: readFileSync(scenariosXlsx),
            },
        },
    });
    expect((await upload.json()).imported.scenarios).toBeGreaterThan(0);
}

/**
 * Place one chip in one slot by dispatching the drag events the page listens
 * for — the same synthesis play-station.spec.js uses, and for the same reason:
 * what matters here is reaching the end of a game, and the drag mechanics
 * themselves are covered by the unit tests over lib/.
 */
async function placeOneChip(page) {
    await page.evaluate(() => {
        const chip = document.querySelector('.chip');
        const slot = document.querySelector('.slot');
        const dataTransfer = new DataTransfer();
        chip.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer }));
        slot.dispatchEvent(new DragEvent('dragover', { bubbles: true, dataTransfer }));
        slot.dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer }));
    });
}

/**
 * Keep this spec's games off the leaderboard.
 *
 * leaderboard/submit is rate-limited to ten per five minutes per address and
 * that budget is shared with every other spec in the run. Nothing here is
 * about whether a row was written.
 */
async function stubSubmission(page) {
    await page.route('**/index.php', (route) => {
        if (route.request().headers()['x-action'] !== 'leaderboard/submit') {
            return route.fallback();
        }
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, entry_id: 1, rank: 1, page: 1 }),
        });
    });
}

/** Play five rounds from whatever screen currently shows the welcome card. */
async function playFromWelcome(page, playerName) {
    await page.fill('#welcomeNameInput', playerName);
    await page.click('#startGameBtn');

    for (let round = 1; round <= 5; round++) {
        await expect(page.locator('.chip').first()).toBeVisible();
        await placeOneChip(page);

        const validate = page.locator('#validateBtn');
        await expect(validate).toBeEnabled();
        await validate.click();

        await page.click('#nextRoundBtn');
    }
}

async function playAGame(page, url, playerName) {
    await page.goto(url);
    await playFromWelcome(page, playerName);
}

/** The four surfaces the switch governs, by the ids the roadmap names. */
const SHARE_SURFACES = ['#shareScoreBtn', '#linkedinShareBtn', '#copyLinkBtn', '#kioskQrContainer'];

test.describe.serial('the sharing switch', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        try {
            await seedScenarios(page);
        } finally {
            await page.close();
        }
    });

    test.afterAll(async ({ browser }) => {
        // Whatever happened above, the instance goes back to its default so
        // the specs that follow test the application rather than this file's
        // leftovers.
        const page = await browser.newPage();
        try {
            await setSharing(page, true);
        } finally {
            await page.close();
        }
    });

    test('off: none of the four surfaces reaches the DOM', async ({ page }) => {
        await setSharing(page, false);
        await stubSubmission(page);
        await playAGame(page, '/', 'No Sharing');

        await expect(page.locator('.final-score-screen')).toBeVisible();
        expect(await page.evaluate(() => document.body.dataset.sharing)).toBe('off');

        // Counted, not checked for visibility. A display:none button is still
        // in the DOM, still reachable by keyboard and still there for anyone
        // reading the markup — which is not what "not offered" means.
        for (const selector of SHARE_SURFACES) {
            await expect(page.locator(selector), selector).toHaveCount(0);
        }
        await expect(page.locator('#desktopShareRow')).toHaveCount(0);
        await expect(page.locator('#copyLinkStatus')).toHaveCount(0);

        // The screen is otherwise the screen it always was.
        await expect(page.locator('#submitFinalScoreBtn')).toBeVisible();
        await expect(page.locator('#playAgainFinalBtn')).toBeVisible();
        await expect(page.locator('.final-score-value')).toBeVisible();
    });

    test('off: kiosk mode renders no QR block either', async ({ page }) => {
        await setSharing(page, false);
        await stubSubmission(page);

        // Kiosk mode is a session flag that a reload clears, so the game has
        // to be reached from the admin screen WITHOUT navigating — which is
        // exactly how an organiser reaches it too.
        await page.goto('/');
        await page.click('[data-screen="admin"]');
        for (const digit of ADMIN_PIN) {
            await page.click(`.pin-key[data-digit="${digit}"]`);
        }
        await page.click('.pin-key-submit');
        await expect(page.locator('.admin-dashboard')).toBeVisible();

        await page.locator('.kiosk-toggle .kiosk-slider').click();
        await expect(page.locator('#kioskToggle')).toBeChecked();

        await page.click('[data-screen="game"]');
        await playFromWelcome(page, 'Kiosk No Sharing');

        await expect(page.locator('.final-score-screen')).toBeVisible();
        await expect(page.locator('#kioskQrContainer')).toHaveCount(0);
        await expect(page.locator('#kioskQrCode')).toHaveCount(0);
    });

    test('off: every share route still answers, which is the point', async ({ page }) => {
        const csrf = await setSharing(page, false);

        // A token is still mintable. Nothing in the UI asks for one with
        // sharing off, but the route does not change shape under an existing
        // client.
        const minted = await api(page, csrf, 'share/token', { score: 4200, name: 'Already Posted' });
        expect(minted.status()).toBe(200);
        const { token } = await minted.json();
        expect(token).toBeTruthy();

        // The link somebody has already posted. This is the assertion that
        // locks the intention: switching sharing off must not reach into
        // anybody's feed and break a link that is already there.
        const share = await page.request.get(`/share?d=${encodeURIComponent(token)}`);
        expect(share.status(), '/share must keep answering with sharing off').toBe(200);
        expect(await share.text()).toContain('Already Posted');

        // The QR code somebody has already photographed.
        const shareGo = await page.request.get(`/share/go?d=${encodeURIComponent(token)}`);
        expect(shareGo.status()).toBe(200);

        // The preview image both of those point at.
        const shareImage = await page.request.get(`/share/image?d=${encodeURIComponent(token)}`);
        expect(shareImage.status()).toBe(200);
        expect(shareImage.headers()['content-type']).toContain('image/png');

        // And the site's own OpenGraph image, which is not score sharing at
        // all: closing it would degrade the preview of every link to the game.
        const homeImage = await page.request.get('/share/home-image');
        expect(homeImage.status()).toBe(200);
        expect(homeImage.headers()['content-type']).toContain('image/png');
    });

    test('on: the end of a game is exactly what it was', async ({ page }) => {
        await setSharing(page, true);
        await stubSubmission(page);
        await playAGame(page, '/', 'Sharing Back On');

        await expect(page.locator('.final-score-screen')).toBeVisible();
        // Default installation, default markup: no attribute at all.
        expect(await page.evaluate(() => document.body.dataset.sharing)).toBeUndefined();

        // Headless Chromium on a desktop viewport has no navigator.share, so
        // this is setupShareButtons()'s desktop half — LinkedIn plus Copy Link.
        await expect(page.locator('#desktopShareRow')).toBeVisible();
        await expect(page.locator('#linkedinShareBtn')).toBeVisible();
        await expect(page.locator('#copyLinkBtn')).toBeVisible();
        await expect(page.locator('#linkedinShareBtn')).toHaveAttribute(
            'href', /linkedin\.com\/sharing\/share-offsite/
        );
    });

    test('the play station shares under neither setting', async ({ page }) => {
        // Two orthogonal mechanisms, and both have to hold. ?mode=play refuses
        // for a reason of its own — navigator.share opens an OS sheet on top
        // of a locked kiosk that the next player then has to dismiss — so
        // switching sharing back ON must not hand it share buttons.
        for (const enabled of [true, false]) {
            await setSharing(page, enabled);
            await stubSubmission(page);
            await playAGame(page, '/?mode=play', `Play ${enabled}`);

            await expect(page.locator('.play-result')).toBeVisible();
            await expect(page.locator('.final-score-screen')).toHaveCount(0);
            for (const selector of SHARE_SURFACES) {
                await expect(
                    page.locator(selector),
                    `${selector} with sharing_enabled=${enabled}`
                ).toHaveCount(0);
            }
            await page.unrouteAll({ behavior: 'ignoreErrors' });
        }
    });

    test('the admin panel says what the switch does not do', async ({ page }) => {
        // Set explicitly rather than inherited: the test above deliberately
        // leaves the instance switched off, and the panel is asserted here to
        // report what is STORED rather than whatever the last test happened
        // to leave behind.
        await setSharing(page, true);

        await page.goto('/');
        await page.click('[data-screen="admin"]');
        for (const digit of ADMIN_PIN) {
            await page.click(`.pin-key[data-digit="${digit}"]`);
        }
        await page.click('.pin-key-submit');
        await expect(page.locator('.admin-dashboard')).toBeVisible();

        const section = page.locator('.sharing-section');
        await expect(section).toBeVisible();
        await expect(section.locator('h3')).toHaveText('Sharing');

        // Without this sentence an administrator switching sharing off will
        // believe they have revoked something. They have not.
        await expect(page.locator('#sharingNote')).toContainText('Links already shared keep working');

        // It reflects the stored value rather than assuming one.
        await expect(page.locator('#sharingToggle')).toBeChecked();
        await expect(page.locator('#sharingLabel')).toHaveText('Enabled');

        // Two switches that look alike, and two class families. Reusing
        // .kiosk-toggle here made that selector match both, which is how a
        // test asserting on "the kiosk toggle" started failing for a reason
        // that had nothing to do with kiosk mode.
        await expect(page.locator('.kiosk-toggle')).toHaveCount(1);
        await expect(page.locator('.admin-switch')).toHaveCount(1);
        await expect(page.locator('.sharing-section .kiosk-toggle')).toHaveCount(0);
    });
});
