<?php
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - ISO 20022 Address Game</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <main class="setup-screen">
        <article class="setup-card">
            <header>
                <h1>Database Setup</h1>
                <p>Could not connect to the database. Please provide valid connection details.</p>
            </header>

            <form id="setupForm">
                <label for="dbHost">
                    Host
                    <input type="text" id="dbHost" name="host" value="127.0.0.1" required>
                </label>

                <label for="dbPort">
                    Port
                    <input type="text" id="dbPort" name="port" value="3306" required>
                </label>

                <label for="dbName">
                    Database Name
                    <input type="text" id="dbName" name="name" placeholder="iso20022_game" required>
                </label>

                <label for="dbUsername">
                    Username
                    <input type="text" id="dbUsername" name="username" placeholder="root" required>
                </label>

                <label for="dbPassword">
                    Password
                    <input type="password" id="dbPassword" name="password" placeholder="Password">
                </label>

                <div class="setup-actions">
                    <button type="button" id="testConnectionBtn" class="btn-secondary">
                        Test Connection
                    </button>
                    <button type="submit" id="saveConfigBtn" class="btn-primary" disabled>
                        Save &amp; Initialize
                    </button>
                </div>
            </form>

            <div id="setupStatus" class="setup-status hidden"></div>
        </article>
    </main>

    <script>
    /**
     * ISO 20022 Address Structuring Game
     * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     */
    (function() {
        const form = document.getElementById('setupForm');
        const testBtn = document.getElementById('testConnectionBtn');
        const saveBtn = document.getElementById('saveConfigBtn');
        const status = document.getElementById('setupStatus');

        function getFormData() {
            return {
                host: document.getElementById('dbHost').value.trim(),
                port: document.getElementById('dbPort').value.trim(),
                name: document.getElementById('dbName').value.trim(),
                username: document.getElementById('dbUsername').value.trim(),
                password: document.getElementById('dbPassword').value,
            };
        }

        function showStatus(msg, isError) {
            status.textContent = msg;
            status.className = 'setup-status ' + (isError ? 'status-error' : 'status-success');
            status.classList.remove('hidden');
        }

        testBtn.addEventListener('click', async function() {
            testBtn.disabled = true;
            testBtn.textContent = 'Testing...';
            try {
                const resp = await fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-Action': 'setup/test'},
                    body: JSON.stringify(getFormData())
                });
                const data = await resp.json();
                if (data.success) {
                    showStatus('Connection successful!', false);
                    saveBtn.disabled = false;
                } else {
                    showStatus(data.error || 'Connection failed', true);
                    saveBtn.disabled = true;
                }
            } catch (e) {
                showStatus('Network error: ' + e.message, true);
            }
            testBtn.disabled = false;
            testBtn.textContent = 'Test Connection';
        });

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';
            try {
                const resp = await fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-Action': 'setup/save'},
                    body: JSON.stringify(getFormData())
                });
                const data = await resp.json();
                if (data.success) {
                    showStatus('Database configured! Redirecting...', false);
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    showStatus(data.error || 'Save failed', true);
                    saveBtn.disabled = false;
                }
            } catch (e) {
                showStatus('Network error: ' + e.message, true);
                saveBtn.disabled = false;
            }
            saveBtn.textContent = 'Save & Initialize';
        });
    })();
    </script>
</body>
</html>
