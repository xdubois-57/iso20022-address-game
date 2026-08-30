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

import { escapeHtml } from './lib/format.js';

/**
 * Admin dashboard's "Automatic Updates" section — a GitHub webhook that can
 * install either a formally published release or every commit pushed to
 * main, backed by App\Controllers\AdminController's admin/get-update-settings,
 * admin/save-update-settings, admin/generate-webhook-secret and
 * admin/install-update-now actions (see app/Models/GitHubWebhook.php and
 * app/Models/Updater.php for what those do server-side).
 *
 * Kept as its own module — unlike the rest of the admin dashboard, which is
 * built inline in app.js's renderAdminDashboard() — specifically so its
 * render/format logic is importable by tests/js/admin-update.test.js without
 * a build step: app.js loads it as `import * as AdminUpdate from
 * './admin-update.js'` and calls AdminUpdate.initAutoUpdateSection() once
 * the section's markup (renderAutoUpdateSection()) is in the DOM.
 */

const CHANNELS = ['release', 'main'];

export function isValidChannel(channel) {
    return CHANNELS.includes(channel);
}

/**
 * "2026-03-05 14:32 UTC", or a fallback when there is nothing to show yet.
 *
 * @param {number|null} epochSeconds
 */
export function formatTimestamp(epochSeconds) {
    if (!epochSeconds) return null;
    var d = new Date(epochSeconds * 1000);
    return d.toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
}

/**
 * One-line summary of the most recent webhook delivery, or null when none
 * has ever arrived — renderAutoUpdateSection() shows "No delivery received
 * yet" in that case.
 *
 * @param {number|null} atEpochSeconds
 * @param {string|null} result 'ok' or 'ignored:<reason>'
 */
export function formatEventLine(atEpochSeconds, result) {
    var when = formatTimestamp(atEpochSeconds);
    if (!when || !result) return null;
    return when + ' — ' + (result === 'ok' ? 'installed' : humanizeIgnoreReason(result));
}

/**
 * @param {number|null} atEpochSeconds
 * @param {string|null} status 'completed' | 'failed' | 'rolled_back'
 * @param {string|null} error
 */
export function formatInstallLine(atEpochSeconds, status, error) {
    var when = formatTimestamp(atEpochSeconds);
    if (!when || !status) return null;
    var label = { completed: 'Installed successfully', failed: 'Failed', rolled_back: 'Failed — rolled back' }[status] || status;
    var line = when + ' — ' + label;
    if (error) line += ' (' + error + ')';
    return line;
}

function humanizeIgnoreReason(result) {
    var reason = result.startsWith('ignored:') ? result.slice('ignored:'.length) : result;
    var known = {
        action_not_published: 'release event ignored (not a publish)',
        auto_update_disabled: 'ignored — automatic updates are disabled',
        channel_not_release: 'ignored — channel is set to "every commit on main"',
        channel_not_main: 'ignored — channel is set to "formal releases only"',
        repository_mismatch: 'ignored — payload was for a different repository',
        invalid_payload: 'ignored — payload was missing required fields',
        no_download_url: 'ignored — no downloadable artifact found',
        download_url_refused: 'ignored — download URL was not a GitHub host',
    };
    return known[reason] || ('ignored (' + reason + ')');
}

/**
 * @param {{
 *   enabled: boolean, channel: string, has_secret: boolean,
 *   github_owner: string, github_repo: string, webhook_path: string,
 *   last_event_at: number|null, last_event_result: string|null,
 *   last_install_at: number|null, last_install_status: string|null, last_install_error: string|null,
 *   dependencies_changed: boolean, install_pending: boolean,
 *   version: {tag: string, commit: string},
 * }} state
 */
export function renderAutoUpdateSection(state) {
    var eventLine = formatEventLine(state.last_event_at, state.last_event_result);
    var installLine = formatInstallLine(state.last_install_at, state.last_install_status, state.last_install_error);
    var webhookUrl = (typeof window !== 'undefined' ? window.location.origin : '') + state.webhook_path;

    var html = '<div class="admin-section" id="autoUpdateSection"><h3>Automatic Updates</h3>';
    html += '<p>Current version: <strong>' + escapeHtml(state.version.tag) + ' (' + escapeHtml(state.version.commit) + ')</strong></p>';
    html += '<p>Installs automatically from a GitHub webhook — either a formally published release, or every commit pushed to <code>main</code>.</p>';

    html += '<label class="kiosk-toggle">';
    html += '<input type="checkbox" id="autoUpdateEnabledToggle"' + (state.enabled ? ' checked' : '') + '>';
    html += '<span class="kiosk-slider"></span>';
    html += '<span class="kiosk-label">' + (state.enabled ? 'Enabled' : 'Disabled') + '</span>';
    html += '</label>';

    html += '<div class="auto-update-channel">';
    html += '<label><input type="radio" name="autoUpdateChannel" value="release"'
        + (state.channel === 'release' ? ' checked' : '') + '> Formal releases only</label>';
    html += '<label><input type="radio" name="autoUpdateChannel" value="main"'
        + (state.channel === 'main' ? ' checked' : '') + '> Every commit on main</label>';
    html += '</div>';

    html += '<div class="pin-change-form">';
    html += '<input type="text" id="autoUpdateOwnerInput" placeholder="GitHub owner" value="' + escapeHtml(state.github_owner) + '" maxlength="100">';
    html += '<input type="text" id="autoUpdateRepoInput" placeholder="GitHub repository" value="' + escapeHtml(state.github_repo) + '" maxlength="100">';
    html += '</div>';
    html += '<button class="btn-primary" id="saveAutoUpdateBtn">Save Settings</button>';
    html += '<p class="deadline-status hidden" id="autoUpdateSaveStatus"></p>';

    html += '<div class="auto-update-webhook">';
    html += '<p>Webhook URL: <code>' + escapeHtml(webhookUrl) + '</code></p>';
    html += '<p>' + (state.has_secret ? 'A webhook secret is set.' : 'No webhook secret set yet — generate one and add it to GitHub before this will do anything.') + '</p>';
    html += '<button class="btn-secondary" id="generateWebhookSecretBtn">' + (state.has_secret ? 'Regenerate Secret' : 'Generate Secret') + '</button>';
    html += '<div class="webhook-secret-reveal hidden" id="webhookSecretReveal"></div>';
    html += '</div>';

    html += '<div class="auto-update-status">';
    html += '<p>Last delivery: ' + (eventLine ? escapeHtml(eventLine) : 'No delivery received yet') + '</p>';
    html += '<p>Last install: ' + (installLine ? escapeHtml(installLine) : 'No install has run yet') + '</p>';
    if (state.dependencies_changed) {
        html += '<p class="auto-update-warning">composer.lock changed in the last installed commit — dependencies may be out of date until <code>composer install</code> runs.</p>';
    }
    if (state.install_pending) {
        html += '<p class="auto-update-warning">An update is queued and will install shortly.</p>';
    }
    html += '</div>';

    html += '<button class="btn-secondary" id="installUpdateNowBtn">Install now</button>';
    html += '<p class="deadline-status hidden" id="installNowStatus"></p>';
    html += '</div>';

    return html;
}

/**
 * Wires the controls inside a just-rendered renderAutoUpdateSection() —
 * mirrors app.js's initAdminActions()/loadAdminTheme() pattern: a load
 * function fetches state and re-renders the whole section, actions call the
 * matching admin/* action and reload.
 *
 * @param {HTMLElement} container element renderAutoUpdateSection()'s HTML was placed into (its parent, re-rendered on every reload)
 * @param {(action: string, body?: object) => Promise<any>} api
 */
export function initAutoUpdateSection(container, api) {
    async function reload() {
        var state = await api('admin/get-update-settings');
        if (!state) return;
        container.innerHTML = renderAutoUpdateSection(state);
        wire(state);
    }

    function wire(state) {
        var saveBtn = container.querySelector('#saveAutoUpdateBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', async function () {
                var enabled = container.querySelector('#autoUpdateEnabledToggle').checked;
                var channelInput = container.querySelector('input[name="autoUpdateChannel"]:checked');
                var channel = channelInput ? channelInput.value : state.channel;
                var owner = container.querySelector('#autoUpdateOwnerInput').value.trim();
                var repo = container.querySelector('#autoUpdateRepoInput').value.trim();

                var result = await api('admin/save-update-settings', {
                    enabled: enabled, channel: channel, github_owner: owner, github_repo: repo,
                });
                var message = result && result.success ? 'Saved.' : ((result && result.error) || 'Save failed.');

                // On success reload() replaces container's markup (so the
                // rest of the section — webhook URL, current values — picks
                // up what was just saved), which would otherwise instantly
                // erase the very "Saved." text set on the pre-reload node
                // before anyone ever saw it. Setting the message on whichever
                // #autoUpdateSaveStatus is CURRENT — after the reload, if one
                // happened — is what makes it actually visible.
                if (result && result.success) {
                    await reload();
                }
                var statusEl = container.querySelector('#autoUpdateSaveStatus');
                if (statusEl) {
                    statusEl.classList.remove('hidden');
                    statusEl.textContent = message;
                }
            });
        }

        var generateBtn = container.querySelector('#generateWebhookSecretBtn');
        if (generateBtn) {
            generateBtn.addEventListener('click', async function () {
                var result = await api('admin/generate-webhook-secret', {});
                if (!result || !result.success) return;

                // Reload so the rest of the section (button label, "has_secret"
                // text) reflects the new secret's existence — has_secret was
                // false a moment ago and the label/copy above still say so.
                // reload() replaces container's markup, so the reveal box is
                // re-queried afterwards rather than reusing the pre-reload node.
                await reload();
                var reveal = container.querySelector('#webhookSecretReveal');
                if (!reveal) return;
                reveal.classList.remove('hidden');
                reveal.textContent = 'New secret (shown once, copy it now): ' + result.secret;
            });
        }

        var installBtn = container.querySelector('#installUpdateNowBtn');
        var installStatus = container.querySelector('#installNowStatus');
        if (installBtn) {
            installBtn.addEventListener('click', async function () {
                installBtn.disabled = true;
                if (installStatus) {
                    installStatus.classList.remove('hidden');
                    installStatus.textContent = 'Checking for an update…';
                }

                var result = await api('admin/install-update-now', {});

                installBtn.disabled = false;
                if (installStatus) {
                    installStatus.textContent = summarizeInstallResult(result);
                }
                await reload();
            });
        }
    }

    return reload();
}

/**
 * @param {{success?: boolean, reason?: string, result?: {status: string, version?: string, error?: string}}|null} response
 */
export function summarizeInstallResult(response) {
    if (!response) return 'Request failed — check your connection and try again.';
    if (response.success) {
        return 'Installed ' + (response.result && response.result.version ? response.result.version : '') + '.';
    }
    if (response.reason) {
        return 'Nothing to install (' + response.reason.replace(/_/g, ' ') + ').';
    }
    if (response.result && response.result.status === 'in_progress') {
        return 'An update is already installing — try again shortly.';
    }
    if (response.result && response.result.error) {
        return 'Install failed: ' + response.result.error;
    }
    return 'Install failed.';
}
