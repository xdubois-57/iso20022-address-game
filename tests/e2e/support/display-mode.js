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
// One place that knows how a dedicated screen is addressed.
//
// ?mode= alone stopped being enough when the display mode token arrived: a
// mode is honoured only alongside a matching &t=. Every spec that opens the
// wall or the play station comes through here, so the day that shape changes
// again it changes in one file rather than in five.
//
// NOTHING HERE NAVIGATES THE PAGE. The shell is fetched over HTTP through the
// context's own request object, which shares its cookie jar, and the CSRF
// token is read out of the returned markup. Doing this with page.goto('/')
// worked and cost about thirteen seconds a call — every asset on the shell is
// fetched for real — which was enough to push two 60-second tests over their
// budget, and it also dropped whatever page the caller was in the middle of.
//
// The token is read fresh on every call rather than cached for the run. It can
// be regenerated mid-run — display-token.spec.js does exactly that — and a
// cached value would then send whichever spec ran next to the ordinary game
// while it asserted on a wall, which is a confusing way to fail.

import { expect } from '@playwright/test';

const ADMIN_PIN = '1234';

/** The shell's CSRF token, without rendering the shell. */
async function csrfToken(page) {
    const shell = await page.request.get('/');
    expect(shell.status()).toBe(200);

    const match = /<meta name="csrf-token" content="([0-9a-f]{64})">/.exec(await shell.text());
    expect(match, 'the shell must carry a CSRF token').not.toBeNull();
    return match[1];
}

/** The token this installation currently holds, read as an administrator. */
export async function displayModeToken(page) {
    const csrf = await csrfToken(page);

    const post = (action, body) => page.request.post('/index.php', {
        headers: {
            'Content-Type': 'application/json',
            'X-Action': action,
            'X-CSRF-Token': csrf,
        },
        data: JSON.stringify(body ?? {}),
    });

    expect((await (await post('admin/login', { pin: ADMIN_PIN })).json()).success).toBe(true);

    const { token } = await (await post('admin/get-display-token')).json();
    expect(token, 'the instance must hand out a display mode token').toMatch(/^[0-9a-f]{32}$/);
    return token;
}

/** The full URL for one dedicated screen, token included. */
export async function modeUrl(page, mode) {
    return `/?mode=${mode}&t=${await displayModeToken(page)}`;
}

/** Open one dedicated screen. */
export async function gotoMode(page, mode) {
    await page.goto(await modeUrl(page, mode));
}
