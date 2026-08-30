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

namespace App\Controllers;

use App\Models\Database;
use App\Models\GitHubWebhook;
use App\Models\SettingsModel;
use App\Models\Updater;

/**
 * POST /webhook/github — the one public, session-free, CSRF-free route in
 * the app: GitHub is a machine caller with no cookie to carry a CSRF token,
 * and CSRF protection is not the right tool for it anyway — the HMAC-SHA256
 * signature (verified against the secret generated on the admin panel's
 * Automatic Updates section) is what authenticates the request instead. See
 * the early-exit branch in public/index.php, which routes here before
 * session_start() for exactly this reason.
 *
 * Always responds 200, even for an ignored/unhandled event, except for an
 * invalid or missing signature (403): GitHub retries any non-2xx status, and
 * there is nothing to retry for an event this install simply does not act on
 * (a push to a branch it does not track, a release action other than
 * "published", ...).
 */
class WebhookController
{
    /**
     * Runs on GitHub's request/response cycle, so the actual install (which
     * can take tens of seconds — a multi-megabyte download with retries) is
     * deferred until after the response is flushed. GitHub is not left
     * waiting on it, and a slow or dropped connection to GitHub can't abort
     * a download that has already started.
     */
    public function github(): void
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

        $pdo = Database::getInstance()->getPdo();
        $settings = new SettingsModel($pdo);
        $webhook = new GitHubWebhook($settings);

        $secret = $settings->get('update_webhook_secret') ?? '';
        if (!$webhook->verifySignature($rawBody, $signature, $secret)) {
            error_log('SECURITY: Invalid GitHub webhook signature from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $this->respond(['status' => 'forbidden'], 403);
            return;
        }

        $payload = json_decode($rawBody, true);
        $result = ['status' => 'ignored', 'reason' => 'invalid_payload'];
        if (is_array($payload)) {
            $result = match ($event) {
                'release' => $webhook->handleReleaseEvent($payload),
                'push' => $webhook->handlePushEvent($payload),
                'ping' => ['status' => 'ok'],
                default => ['status' => 'ignored', 'reason' => 'unhandled_event'],
            };
        }

        $this->respond($result, 200);

        if ($result['status'] === 'ok' && $event !== 'ping') {
            $this->runInstallAfterResponse();
        }
    }

    /**
     * Flushes the HTTP response to GitHub, then keeps running to perform
     * the actual install. `fastcgi_finish_request()` (PHP-FPM) is the clean
     * path; the flush-based fallback below is best-effort on other SAPIs
     * (mod_php, `php -S`) — the connection may stay open a little longer
     * there, but the install itself is identical either way, and its own
     * time budget (Updater's ~60s download retry window) keeps a slow
     * network from turning that into a hang.
     */
    private function runInstallAfterResponse(): void
    {
        ignore_user_abort(true);
        set_time_limit(180);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }

        $pdo = Database::getInstance()->getPdo();
        $basePath = dirname(__DIR__, 2);
        (new Updater($basePath, new SettingsModel($pdo)))->run();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(array $data, int $code): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen(json_encode($data) ?: '{}'));
        echo json_encode($data);
    }
}
