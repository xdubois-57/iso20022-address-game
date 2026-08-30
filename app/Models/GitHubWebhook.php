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

namespace App\Models;

/**
 * Decides what a GitHub webhook delivery means and, when it calls for an
 * install, records the target in the `update_pending` setting for
 * Updater to pick up. Never downloads or installs anything itself — that is
 * Updater's job, run by WebhookController after the HTTP response has
 * already been sent (see public/index.php's /webhook/github route).
 *
 * Two channels only (App\Controllers\AdminController::saveUpdateSettings()
 * validates the value): 'release' installs a formally published GitHub
 * release, 'main' installs every push to the main branch. Whichever channel
 * is NOT configured is a no-op for its matching event, and the other event
 * type is a no-op regardless of channel.
 */
class GitHubWebhook
{
    /** @var callable(string): (array<string, mixed>|null) */
    private $httpGet;

    /**
     * @param (callable(string): (array<string, mixed>|null))|null $httpGet
     *        Injectable seam for the one network call this class makes
     *        (resolveTagCommit()/checkAndQueueLatest()'s GitHub API reads) —
     *        defaults to a real HTTPS GET, overridden in tests with a fake
     *        that returns canned payloads instead of hitting the network.
     */
    public function __construct(private SettingsModel $settings, ?callable $httpGet = null)
    {
        $this->httpGet = $httpGet ?? $this->realHttpGet(...);
    }

    /**
     * GitHub signs the raw request body with the shared secret
     * (HMAC-SHA256, hex-encoded, prefixed "sha256=") — constant-time
     * comparison so response timing can't leak how much of the signature
     * matched.
     */
    public function verifySignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, reason?: string}
     */
    public function handleReleaseEvent(array $payload): array
    {
        if (($payload['action'] ?? '') !== 'published') {
            // Every other release action (edited, prereleased, deleted, ...)
            // is noise here — not worth overwriting the diagnostics with.
            return ['status' => 'ignored', 'reason' => 'action_not_published'];
        }

        $result = $this->processReleaseEvent($payload);
        $this->recordEventOutcome($result);
        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, reason?: string}
     */
    private function processReleaseEvent(array $payload): array
    {
        if (!$this->isEnabled()) {
            return ['status' => 'ignored', 'reason' => 'auto_update_disabled'];
        }
        if ($this->channel() !== 'release') {
            return ['status' => 'ignored', 'reason' => 'channel_not_release'];
        }

        // The event must be for the repository this install is configured to
        // update from — without this, a signed event for any other repo
        // (one an attacker controls) would install that repo's code.
        if (!$this->isConfiguredRepository($payload)) {
            return ['status' => 'ignored', 'reason' => 'repository_mismatch'];
        }

        $release = $payload['release'] ?? null;
        if (!is_array($release) || empty($release['tag_name'])) {
            return ['status' => 'ignored', 'reason' => 'invalid_payload'];
        }

        $downloadUrl = $this->resolveReleaseDownloadUrl($release);
        if ($downloadUrl === null) {
            return ['status' => 'ignored', 'reason' => 'no_download_url'];
        }
        if (!GitHubUrlValidator::isAllowed($downloadUrl)) {
            return ['status' => 'ignored', 'reason' => 'download_url_refused'];
        }

        $tagName = (string) $release['tag_name'];
        $this->queuePendingInstall('release', $downloadUrl, $tagName, $this->resolveTagCommit($payload, $tagName));
        return ['status' => 'ok'];
    }

    /**
     * A release webhook payload's target_commitish is usually a branch name,
     * not a commit — so the short commit shown in the admin panel after a
     * release install is resolved with one extra, best-effort API call. A
     * failure here must never block the install itself: only the cosmetic
     * "commit" half of the version display is at stake, so it falls back to
     * a value derived from the tag rather than propagating the error.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveTagCommit(array $payload, string $tagName): string
    {
        $repository = $payload['repository'] ?? null;
        $fullName = is_array($repository) ? (string) ($repository['full_name'] ?? '') : '';
        if ($fullName !== '') {
            $commit = $this->githubApiGet('https://api.github.com/repos/' . $fullName . '/commits/' . rawurlencode($tagName));
            $sha = is_array($commit) ? (string) ($commit['sha'] ?? '') : '';
            if ($sha !== '') {
                return substr($sha, 0, 7);
            }
        }

        return substr(sha1($tagName), 0, 7);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, reason?: string}
     */
    public function handlePushEvent(array $payload): array
    {
        $result = $this->processPushEvent($payload);

        // A push fires for every commit on every branch and every tag —
        // recording each one would overwrite the diagnostics with noise
        // within minutes. Only a push that actually landed on the tracked
        // branch is worth remembering, whatever happened to it after that.
        if (($result['reason'] ?? '') !== 'branch_mismatch') {
            $this->recordEventOutcome($result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, reason?: string}
     */
    private function processPushEvent(array $payload): array
    {
        $ref = (string) ($payload['ref'] ?? '');
        $pushedBranch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : $ref;
        if ($pushedBranch !== 'main') {
            return ['status' => 'ignored', 'reason' => 'branch_mismatch'];
        }

        if (!$this->isEnabled()) {
            return ['status' => 'ignored', 'reason' => 'auto_update_disabled'];
        }
        if ($this->channel() !== 'main') {
            return ['status' => 'ignored', 'reason' => 'channel_not_main'];
        }

        if (!$this->isConfiguredRepository($payload)) {
            return ['status' => 'ignored', 'reason' => 'repository_mismatch'];
        }

        $sha = (string) ($payload['after'] ?? '');
        $repoFullName = (string) ($payload['repository']['full_name'] ?? '');
        // The all-zero SHA is GitHub's marker for a branch deletion — never
        // something to install from.
        if ($sha === '' || $repoFullName === '' || preg_match('/^0+$/', $sha) === 1) {
            return ['status' => 'ignored', 'reason' => 'invalid_payload'];
        }

        $downloadUrl = "https://api.github.com/repos/{$repoFullName}/zipball/{$sha}";
        if (!GitHubUrlValidator::isAllowed($downloadUrl)) {
            return ['status' => 'ignored', 'reason' => 'download_url_refused'];
        }

        $shortSha = substr($sha, 0, 7);
        $this->queuePendingInstall('branch', $downloadUrl, 'dev-' . $shortSha, $shortSha);
        return ['status' => 'ok'];
    }

    /**
     * Fetches the latest release or latest main commit from the GitHub REST
     * API (unauthenticated — subject to GitHub's 60 requests/hour/IP
     * anonymous rate limit) and queues it, mirroring whatever the matching
     * webhook event would have done. Used by the admin panel's manual
     * "Install now" button, which has no webhook payload to act on.
     *
     * @return array{status: string, reason?: string}
     */
    public function checkAndQueueLatest(): array
    {
        $owner = trim((string) ($this->settings->get('update_github_owner') ?: ''));
        $repo = trim((string) ($this->settings->get('update_github_repo') ?: ''));
        if ($owner === '' || $repo === '') {
            return ['status' => 'ignored', 'reason' => 'repository_not_configured'];
        }

        if ($this->channel() === 'release') {
            return $this->checkLatestRelease($owner, $repo);
        }

        return $this->checkLatestMainCommit($owner, $repo);
    }

    /**
     * @return array{status: string, reason?: string}
     */
    private function checkLatestRelease(string $owner, string $repo): array
    {
        $release = $this->githubApiGet("https://api.github.com/repos/{$owner}/{$repo}/releases/latest");
        if (!is_array($release) || empty($release['tag_name'])) {
            return ['status' => 'ignored', 'reason' => 'no_release_found'];
        }

        $downloadUrl = $this->resolveReleaseDownloadUrl($release);
        if ($downloadUrl === null || !GitHubUrlValidator::isAllowed($downloadUrl)) {
            return ['status' => 'ignored', 'reason' => 'no_download_url'];
        }

        $tagName = (string) $release['tag_name'];
        $commit = $this->githubApiGet("https://api.github.com/repos/{$owner}/{$repo}/commits/" . rawurlencode($tagName));
        $sha = is_array($commit) ? (string) ($commit['sha'] ?? '') : '';
        $this->queuePendingInstall('release', $downloadUrl, $tagName, $sha !== '' ? substr($sha, 0, 7) : substr(sha1($tagName), 0, 7));
        return ['status' => 'ok'];
    }

    /**
     * @return array{status: string, reason?: string}
     */
    private function checkLatestMainCommit(string $owner, string $repo): array
    {
        $commit = $this->githubApiGet("https://api.github.com/repos/{$owner}/{$repo}/commits/main");
        $sha = is_array($commit) ? (string) ($commit['sha'] ?? '') : '';
        if ($sha === '') {
            return ['status' => 'ignored', 'reason' => 'no_commit_found'];
        }

        $downloadUrl = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$sha}";
        if (!GitHubUrlValidator::isAllowed($downloadUrl)) {
            return ['status' => 'ignored', 'reason' => 'download_url_refused'];
        }

        $shortSha = substr($sha, 0, 7);
        $this->queuePendingInstall('branch', $downloadUrl, 'dev-' . $shortSha, $shortSha);
        return ['status' => 'ok'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function githubApiGet(string $url): ?array
    {
        return ($this->httpGet)($url);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function realHttpGet(string $url): ?array
    {
        if (!GitHubUrlValidator::isAllowed($url)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                // The GitHub API refuses unauthenticated requests with no
                // User-Agent header.
                'header' => "User-Agent: iso20022-address-game-updater\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Prefer a real .zip release asset over the first asset blindly (a
     * release can carry non-archive assets too), then fall back to the
     * source zipball.
     *
     * @param array<string, mixed> $release
     */
    private function resolveReleaseDownloadUrl(array $release): ?string
    {
        $assets = $release['assets'] ?? [];
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = (string) ($asset['name'] ?? '');
                $url = (string) ($asset['browser_download_url'] ?? '');
                if ($url !== '' && str_ends_with(strtolower($name), '.zip')) {
                    return $url;
                }
            }
        }

        return !empty($release['zipball_url']) ? (string) $release['zipball_url'] : null;
    }

    /**
     * Whether the webhook payload's repository is the one this install is
     * configured to update from (case-insensitive "owner/repo"). Both event
     * handlers gate on this so a validly-signed event for a different
     * repository can never trigger an install.
     *
     * @param array<string, mixed> $payload
     */
    private function isConfiguredRepository(array $payload): bool
    {
        $owner = strtolower(trim((string) ($this->settings->get('update_github_owner') ?: '')));
        $repo = strtolower(trim((string) ($this->settings->get('update_github_repo') ?: '')));
        if ($owner === '' || $repo === '') {
            return false;
        }

        $repository = $payload['repository'] ?? null;
        $fullName = is_array($repository) ? strtolower(trim((string) ($repository['full_name'] ?? ''))) : '';

        return $fullName === $owner . '/' . $repo;
    }

    private function isEnabled(): bool
    {
        return (bool) ((int) ($this->settings->get('update_enabled') ?: '0'));
    }

    private function channel(): string
    {
        return (string) ($this->settings->get('update_channel') ?: 'release');
    }

    private function queuePendingInstall(string $sourceType, string $downloadUrl, string $versionTo, string $commit): void
    {
        $this->settings->set('update_pending', json_encode([
            'source_type' => $sourceType,
            'download_url' => $downloadUrl,
            'version_to' => $versionTo,
            'commit' => $commit,
            'queued_at' => time(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{status: string, reason?: string} $result
     */
    private function recordEventOutcome(array $result): void
    {
        $this->settings->setMany([
            'update_last_event_at' => (string) time(),
            'update_last_event_result' => $result['status'] === 'ok'
                ? 'ok'
                : 'ignored:' . (string) ($result['reason'] ?? 'unknown'),
        ]);
    }
}
