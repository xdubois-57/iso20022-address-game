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
// Exercises POST /webhook/github (App\Controllers\WebhookController) as
// GitHub itself would call it: no session, no CSRF token, just a raw JSON
// body and an X-Hub-Signature-256 HMAC header. Deliberately stops short of a
// real install — that would mean this suite downloading a real GitHub
// artifact — so only outcomes reachable without ever calling
// App\Models\Updater::run() are covered here: signature verification and the
// decision logic's fast "ignored" paths (see tests/GitHubWebhookTest.php for
// the rest of that decision logic, covered without a network in PHPUnit).
//
// Every test configures enabled/channel/owner/repo explicitly before
// touching the webhook — auto-update.spec.js runs against this same server
// and database (one php -S instance serves the whole Playwright run; see
// scripts/e2e.sh), and update_enabled defaults to OFF on a fresh install
// regardless, so nothing here may assume a particular starting state.

import crypto from 'node:crypto';
import { expect, test } from '@playwright/test';

const ADMIN_PIN = '1234';
const OWNER = 'xdubois-57';
const REPO = 'iso20022-address-game';

/**
 * See auto-update.spec.js's own copy of this helper for why a native
 * .click() rather than Playwright's check()/check({force:true}) is needed
 * here: the checkbox is CSS `display: none` (it reuses the .kiosk-toggle
 * skin), which survives even `force`.
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

/**
 * Logs in, sets enabled/channel/owner/repo to known values, generates a
 * fresh webhook secret, and returns it.
 */
async function configureWebhook(page, channel) {
    await loginAsAdmin(page);
    const section = page.locator('#autoUpdateSection');
    await expect(section).toBeVisible();

    await setEnabledToggle(section, true);
    await section.locator(`input[name="autoUpdateChannel"][value="${channel}"]`).check();
    await section.locator('#autoUpdateOwnerInput').fill(OWNER);
    await section.locator('#autoUpdateRepoInput').fill(REPO);
    await section.locator('#saveAutoUpdateBtn').click();
    await expect(section.locator('#autoUpdateSaveStatus')).toHaveText('Saved.');

    await section.locator('#generateWebhookSecretBtn').click();
    // The click handler awaits the generate call AND a reload() before
    // writing the secret into the DOM (see admin-update.js) — an immediate
    // textContent() read races that; toContainText() polls until it lands.
    const reveal = section.locator('#webhookSecretReveal');
    await expect(reveal).toContainText(/[0-9a-f]{64}/);
    const revealText = await reveal.textContent();
    const match = /([0-9a-f]{64})/.exec(revealText || '');
    if (!match) throw new Error(`No secret found in reveal text: ${revealText}`);
    return match[1];
}

function sign(body, secret) {
    return 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
}

test.describe('webhook', () => {
    test('a ping with a correct signature is accepted', async ({ page }) => {
        const secret = await configureWebhook(page, 'release');
        const body = JSON.stringify({ zen: 'Speak like a human.' });

        const resp = await page.request.post('/webhook/github', {
            headers: {
                'Content-Type': 'application/json',
                'X-GitHub-Event': 'ping',
                'X-Hub-Signature-256': sign(body, secret),
            },
            data: body,
        });

        expect(resp.status()).toBe(200);
        expect(await resp.json()).toEqual({ status: 'ok' });
    });

    test('a wrong signature is refused with 403, regardless of event or payload', async ({ page }) => {
        await configureWebhook(page, 'release');
        const body = JSON.stringify({ zen: 'Speak like a human.' });

        const resp = await page.request.post('/webhook/github', {
            headers: {
                'Content-Type': 'application/json',
                'X-GitHub-Event': 'ping',
                'X-Hub-Signature-256': 'sha256=' + '0'.repeat(64),
            },
            data: body,
        });

        expect(resp.status()).toBe(403);
    });

    test('a missing signature is refused with 403', async ({ page }) => {
        await configureWebhook(page, 'release'); // ensures a secret IS configured, so absence is the only variable
        const resp = await page.request.post('/webhook/github', {
            headers: { 'Content-Type': 'application/json', 'X-GitHub-Event': 'ping' },
            data: JSON.stringify({ zen: 'x' }),
        });

        expect(resp.status()).toBe(403);
    });

    test('on the main channel, a push to a non-main branch is accepted but ignored, and installs nothing', async ({ page }) => {
        const secret = await configureWebhook(page, 'main');
        const body = JSON.stringify({
            ref: 'refs/heads/some-feature-branch',
            after: 'a'.repeat(40),
            repository: { full_name: `${OWNER}/${REPO}` },
        });

        const resp = await page.request.post('/webhook/github', {
            headers: {
                'Content-Type': 'application/json',
                'X-GitHub-Event': 'push',
                'X-Hub-Signature-256': sign(body, secret),
            },
            data: body,
        });

        expect(resp.status()).toBe(200);
        expect(await resp.json()).toEqual({ status: 'ignored', reason: 'branch_mismatch' });
    });

    test('on the release channel, a push to main is accepted but ignored (wrong channel)', async ({ page }) => {
        const secret = await configureWebhook(page, 'release');
        const body = JSON.stringify({
            ref: 'refs/heads/main',
            after: 'a'.repeat(40),
            repository: { full_name: `${OWNER}/${REPO}` },
        });

        const resp = await page.request.post('/webhook/github', {
            headers: {
                'Content-Type': 'application/json',
                'X-GitHub-Event': 'push',
                'X-Hub-Signature-256': sign(body, secret),
            },
            data: body,
        });

        expect(resp.status()).toBe(200);
        expect(await resp.json()).toEqual({ status: 'ignored', reason: 'channel_not_main' });
    });

    test('a release event for a different repository is accepted but ignored', async ({ page }) => {
        const secret = await configureWebhook(page, 'release');
        const body = JSON.stringify({
            action: 'published',
            release: { tag_name: 'v9.9.9', zipball_url: 'https://api.github.com/repos/someone-else/other-repo/zipball/v9.9.9' },
            repository: { full_name: 'someone-else/other-repo' },
        });

        const resp = await page.request.post('/webhook/github', {
            headers: {
                'Content-Type': 'application/json',
                'X-GitHub-Event': 'release',
                'X-Hub-Signature-256': sign(body, secret),
            },
            data: body,
        });

        expect(resp.status()).toBe(200);
        expect(await resp.json()).toEqual({ status: 'ignored', reason: 'repository_mismatch' });
    });
});
