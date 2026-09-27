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
// The scenario set every spec that plays a round needs on the server first.
// It was copied into five specs; the day the admin login or the upload
// contract changes, it now changes here.
//
// Uploaded through the real admin endpoint rather than seeded into the
// database, so the Excel parsing path is exercised too — that is the one route
// by which content ever enters a real install.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect } from '@playwright/test';
import { csrfToken } from './display-mode.js';

const ADMIN_PIN = '1234';
const here = path.dirname(fileURLToPath(import.meta.url));
const scenariosXlsx = path.resolve(here, '../../../public/assets/Scenarios.xlsx');

/**
 * Log in as an administrator and upload the bundled Scenarios.xlsx.
 *
 * Pass the CSRF token of a session the caller goes on to use; without one the
 * page is parked on about:blank and a token is read fresh, for the reason
 * displayModeToken() gives — logging in regenerates the session, and a
 * request the SPA has in flight at that moment would lose it.
 *
 * @returns {Promise<number>} how many scenarios were imported
 */
export async function seedScenarios(page, csrf) {
    if (!csrf) {
        await page.goto('about:blank');
        csrf = await csrfToken(page);
    }

    const login = await page.request.post('/index.php', {
        headers: { 'Content-Type': 'application/json', 'X-Action': 'admin/login', 'X-CSRF-Token': csrf },
        data: JSON.stringify({ pin: ADMIN_PIN }),
    });
    expect((await login.json()).success).toBe(true);

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
    const body = await upload.json();
    expect(body.success, `upload failed: ${JSON.stringify(body)}`).toBe(true);
    expect(body.imported.scenarios).toBeGreaterThan(0);
    return body.imported.scenarios;
}
