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
// The Privacy screen as a visitor actually reads it.
//
// tests/PrivacyNoticeContentTest.php asserts the same properties against
// app.js's source, which is fast and runs everywhere. This one proves the
// screen a person is served says what that source says — the two halves fail
// for different reasons, and the one that matters legally is this one.

import { expect, test } from '@playwright/test';

async function openPrivacy(page) {
    await page.goto('/');
    await page.click('.game-footer [data-screen="privacy"]');
    await expect(page.locator('.privacy-screen')).toBeVisible();
}

test.describe('the Privacy screen', () => {
    test('names no supporting organisation, and denies none either', async ({ page }) => {
        await openPrivacy(page);

        const text = await page.locator('.privacy-screen').innerText();

        expect(text).not.toContain('PMPG');
        expect(text).not.toContain('Payments Market Practice Group');
        // The half that is easy to lose while fixing the other: the retired
        // wording must not come back in its place. The page stays quiet.
        expect(text.toLowerCase()).not.toContain('not affiliated');
    });

    test('still names both authors as the data controllers', async ({ page }) => {
        await openPrivacy(page);

        // Untouched by the removal above. This paragraph is a GDPR
        // declaration, and it has always named only the two people who
        // actually process anything here.
        const section = page.locator('.privacy-screen', { hasText: '1. Data Controller' });
        await expect(section).toContainText(
            'The data controllers for this application are Xavier Dubois and Niel Buchan'
        );

        await expect(page.locator('.privacy-screen')).toContainText(
            'This game was created as an educational tool by Xavier Dubois and Niel Buchan'
        );
    });

    test('the footer lockup is still there while the prose is silent', async ({ page }) => {
        await openPrivacy(page);

        // Not a contradiction, and worth pinning: the mark stays, the page
        // simply stops speaking for whoever it belongs to. Removing the
        // sentence was never meant to remove the endorsement.
        await expect(page.locator('.game-footer .footer-endorsement img')).toHaveAttribute(
            'alt', 'Payments Market Practice Group'
        );
    });
});
