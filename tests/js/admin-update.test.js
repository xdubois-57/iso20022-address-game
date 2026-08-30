/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    formatEventLine,
    formatInstallLine,
    formatTimestamp,
    initAutoUpdateSection,
    isValidChannel,
    renderAutoUpdateSection,
    summarizeInstallResult,
} from '../../public/assets/js/admin-update.js';

function baseState(overrides = {}) {
    return Object.assign({
        enabled: false,
        channel: 'release',
        has_secret: false,
        github_owner: 'xdubois-57',
        github_repo: 'iso20022-address-game',
        webhook_path: '/webhook/github',
        last_event_at: null,
        last_event_result: null,
        last_install_at: null,
        last_install_status: null,
        last_install_error: null,
        dependencies_changed: false,
        install_pending: false,
        version: { tag: 'v0.1.11', commit: 'e5e14ff' },
    }, overrides);
}

describe('isValidChannel', () => {
    it('accepts exactly release and main', () => {
        expect(isValidChannel('release')).toBe(true);
        expect(isValidChannel('main')).toBe(true);
    });

    it('rejects anything else', () => {
        expect(isValidChannel('dev')).toBe(false);
        expect(isValidChannel('')).toBe(false);
        expect(isValidChannel('Release')).toBe(false);
    });
});

describe('formatTimestamp', () => {
    it('returns null for falsy input', () => {
        expect(formatTimestamp(null)).toBeNull();
        expect(formatTimestamp(0)).toBeNull();
    });

    it('formats an epoch-seconds value as UTC', () => {
        expect(formatTimestamp(1740000000)).toBe('2025-02-19 21:20 UTC');
    });
});

describe('formatEventLine', () => {
    it('returns null when no delivery has arrived', () => {
        expect(formatEventLine(null, null)).toBeNull();
    });

    it('reports a successful delivery as installed', () => {
        expect(formatEventLine(1740000000, 'ok')).toBe('2025-02-19 21:20 UTC — installed');
    });

    it('humanizes a known ignore reason', () => {
        expect(formatEventLine(1740000000, 'ignored:repository_mismatch'))
            .toBe('2025-02-19 21:20 UTC — ignored — payload was for a different repository');
    });

    it('falls back to a generic message for an unknown reason', () => {
        expect(formatEventLine(1740000000, 'ignored:something_new')).toBe('2025-02-19 21:20 UTC — ignored (something_new)');
    });
});

describe('formatInstallLine', () => {
    it('returns null when no install has run', () => {
        expect(formatInstallLine(null, null, null)).toBeNull();
    });

    it('reports a completed install', () => {
        expect(formatInstallLine(1740000000, 'completed', null)).toBe('2025-02-19 21:20 UTC — Installed successfully');
    });

    it('includes the error message for a rolled-back install', () => {
        expect(formatInstallLine(1740000000, 'rolled_back', 'disk full'))
            .toBe('2025-02-19 21:20 UTC — Failed — rolled back (disk full)');
    });
});

describe('summarizeInstallResult', () => {
    it('handles a network failure (null response)', () => {
        expect(summarizeInstallResult(null)).toMatch(/check your connection/);
    });

    it('reports the installed version on success', () => {
        expect(summarizeInstallResult({ success: true, result: { status: 'completed', version: 'v1.2.3' } }))
            .toBe('Installed v1.2.3.');
    });

    it('reports why nothing was queued', () => {
        expect(summarizeInstallResult({ success: false, reason: 'no_release_found' }))
            .toBe('Nothing to install (no release found).');
    });

    it('reports an in-progress install', () => {
        expect(summarizeInstallResult({ success: false, result: { status: 'in_progress' } }))
            .toMatch(/already installing/);
    });

    it('reports the install error', () => {
        expect(summarizeInstallResult({ success: false, result: { status: 'failed', error: 'boom' } }))
            .toBe('Install failed: boom');
    });
});

describe('renderAutoUpdateSection', () => {
    it('renders the current version, disabled state, and both channel options', () => {
        const html = renderAutoUpdateSection(baseState());

        expect(html).toContain('v0.1.11');
        expect(html).toContain('e5e14ff');
        expect(html).toContain('Formal releases only');
        expect(html).toContain('Every commit on main');
        expect(html).not.toMatch(/id="autoUpdateEnabledToggle"[^>]*checked/);
    });

    it('checks the enabled toggle and the configured channel radio', () => {
        const html = renderAutoUpdateSection(baseState({ enabled: true, channel: 'main' }));

        expect(html).toMatch(/id="autoUpdateEnabledToggle"\s+checked/);
        expect(html).toMatch(/value="main"\s+checked/);
        expect(html).not.toMatch(/value="release"\s+checked/);
    });

    it('escapes owner/repo values (XSS guard)', () => {
        const html = renderAutoUpdateSection(baseState({ github_owner: '"><script>alert(1)</script>' }));
        expect(html).not.toContain('<script>alert(1)</script>');
    });

    it('shows the dependencies-changed warning only when set', () => {
        expect(renderAutoUpdateSection(baseState())).not.toContain('composer.lock changed');
        expect(renderAutoUpdateSection(baseState({ dependencies_changed: true }))).toContain('composer.lock changed');
    });

    it('shows the install-pending notice only when set', () => {
        expect(renderAutoUpdateSection(baseState())).not.toContain('is queued');
        expect(renderAutoUpdateSection(baseState({ install_pending: true }))).toContain('is queued');
    });

    it('labels the secret button Generate vs Regenerate based on has_secret', () => {
        expect(renderAutoUpdateSection(baseState({ has_secret: false }))).toContain('>Generate Secret<');
        expect(renderAutoUpdateSection(baseState({ has_secret: true }))).toContain('>Regenerate Secret<');
    });
});

describe('initAutoUpdateSection — DOM wiring', () => {
    let container;

    beforeEach(() => {
        container = document.createElement('div');
        document.body.appendChild(container);
    });

    it('loads settings on init and renders the section', async () => {
        const api = vi.fn().mockResolvedValue(baseState({ enabled: true }));
        await initAutoUpdateSection(container, api);

        expect(api).toHaveBeenCalledWith('admin/get-update-settings');
        expect(container.querySelector('#autoUpdateSection')).not.toBeNull();
        expect(container.querySelector('#autoUpdateEnabledToggle').checked).toBe(true);
    });

    it('does nothing (no crash) when the initial load fails', async () => {
        const api = vi.fn().mockResolvedValue(null);
        await expect(initAutoUpdateSection(container, api)).resolves.not.toThrow();
    });

    it('save button posts the form state and reloads on success', async () => {
        const api = vi.fn()
            .mockResolvedValueOnce(baseState({ enabled: false, channel: 'release' })) // initial load
            .mockResolvedValueOnce({ success: true }) // save
            .mockResolvedValueOnce(baseState({ enabled: true, channel: 'main' })); // reload

        await initAutoUpdateSection(container, api);

        container.querySelector('#autoUpdateEnabledToggle').checked = true;
        container.querySelector('input[name="autoUpdateChannel"][value="main"]').checked = true;
        container.querySelector('#autoUpdateOwnerInput').value = 'someone';
        container.querySelector('#autoUpdateRepoInput').value = 'somerepo';

        container.querySelector('#saveAutoUpdateBtn').dispatchEvent(new window.Event('click', { bubbles: true }));
        await flushPromises();

        expect(api).toHaveBeenCalledWith('admin/save-update-settings', {
            enabled: true, channel: 'main', github_owner: 'someone', github_repo: 'somerepo',
        });
        expect(api).toHaveBeenCalledTimes(3);
        expect(container.querySelector('#autoUpdateEnabledToggle').checked).toBe(true);
    });

    it('save button shows the server error and does not reload on failure', async () => {
        const api = vi.fn()
            .mockResolvedValueOnce(baseState())
            .mockResolvedValueOnce({ success: false, error: 'GitHub owner and repository are required' });

        await initAutoUpdateSection(container, api);
        container.querySelector('#saveAutoUpdateBtn').dispatchEvent(new window.Event('click', { bubbles: true }));
        await flushPromises();

        expect(api).toHaveBeenCalledTimes(2);
        expect(container.querySelector('#autoUpdateSaveStatus').textContent)
            .toBe('GitHub owner and repository are required');
    });

    it('generate-secret button reveals the secret exactly once and reloads so the label flips to Regenerate', async () => {
        const api = vi.fn()
            .mockResolvedValueOnce(baseState({ has_secret: false })) // initial load
            .mockResolvedValueOnce({ success: true, secret: 'abc123deadbeef' }) // generate
            .mockResolvedValueOnce(baseState({ has_secret: true })); // reload after generate

        await initAutoUpdateSection(container, api);
        container.querySelector('#generateWebhookSecretBtn').dispatchEvent(new window.Event('click', { bubbles: true }));
        await flushPromises();
        await flushPromises();

        const reveal = container.querySelector('#webhookSecretReveal');
        expect(reveal.classList.contains('hidden')).toBe(false);
        expect(reveal.textContent).toContain('abc123deadbeef');
        expect(container.querySelector('#generateWebhookSecretBtn').textContent).toBe('Regenerate Secret');
    });

    it('install-now button disables itself during the request and reloads after', async () => {
        let resolveInstall;
        const installPromise = new Promise((resolve) => { resolveInstall = resolve; });
        const api = vi.fn()
            .mockResolvedValueOnce(baseState())
            .mockReturnValueOnce(installPromise)
            .mockResolvedValueOnce(baseState({ last_install_status: 'completed', last_install_at: 1740000000 }));

        await initAutoUpdateSection(container, api);
        const installBtn = container.querySelector('#installUpdateNowBtn');
        installBtn.dispatchEvent(new window.Event('click', { bubbles: true }));
        await flushPromises();

        expect(installBtn.disabled).toBe(true);

        resolveInstall({ success: true, result: { status: 'completed', version: 'v1.2.3' } });
        await flushPromises();
        await flushPromises();

        expect(installBtn.disabled).toBe(false);
        expect(api).toHaveBeenCalledWith('admin/install-update-now', {});
    });
});

function flushPromises() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}
