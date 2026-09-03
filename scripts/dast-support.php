<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/**
 * The PHP half of scripts/dast.sh — everything the harness needs that would be
 * more than a line of shell.
 *
 * The split exists for the same reason scripts/e2e.sh has its own PHP helpers:
 * this repository already requires one interpreter, so the security harness
 * gains no dependency of its own by using it.
 *
 * Every subcommand is FAIL-CLOSED. A scan that reports success because a step
 * quietly did nothing is worse than a scan that did not run, because everybody
 * believes the first one. Each of these says what went wrong and exits non-zero
 * rather than letting the run continue on an assumption.
 *
 * Subcommands (scripts/dast.sh calls these; a human never does):
 *   free-port
 *   generate-cert <pem-path> <hostname>
 *   wait-url <url> <timeout-seconds>
 *   assert-https <base-url>
 *   zap-plan-start <zap-base-url> <api-key> <container-plan-path>
 *   zap-plan-await-delay <zap-base-url> <api-key> <plan-id> <timeout-seconds>
 *   zap-plan-wait <zap-base-url> <api-key> <plan-id> <timeout-seconds>
 *   assert-sitemap <zap-base-url> <api-key> <site-url> <expectations-file>
 *   gate-alerts <zap-base-url> <api-key> <site-url> <threshold> [summary-path]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "dast-support.php is a CLI script.\n");
    exit(1);
}

/**
 * The risk names ZAP reports, weakest first.
 *
 * Informational and Low are recorded and printed but never fail a run; the gate
 * is Medium and above. There is deliberately NO baseline of accepted findings:
 * a finding is either fixed, or filtered as a false positive with the reason
 * written into tests/dast/zap-passive.yaml where a reviewer will see it. That
 * is the opposite choice from the PHPStan and tsc baselines, and the difference
 * is the point — those record thousands of pre-existing type findings nobody
 * introduced today, this records live security findings against a running
 * instance, and a growing list of "accepted" ones is how a scan stops meaning
 * anything.
 */
const DAST_RISK_ORDER = ['Informational', 'Low', 'Medium', 'High'];

function dastFail(string $message): never
{
    fwrite(STDERR, "DAST: {$message}\n");
    exit(1);
}

/**
 * A plain HTTP GET: no proxy, no redirects followed, a short timeout.
 *
 * Everything this script talks to is on loopback. Routing a ZAP API call
 * through an ambient HTTPS_PROXY — which some environments set, this one
 * included — would simply hang, so the proxy is disabled explicitly rather than
 * left to chance.
 *
 * @return array{status: int, body: string}|null null on a transport failure
 */
function dastHttpGet(string $url, int $timeoutSeconds = 30): ?array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'proxy' => null,
            'request_fulluri' => false,
        ],
        'ssl' => [
            // The instance serves a certificate generated for this run and
            // trusted by nothing. Verifying it would mean shipping a trust
            // store for a key that lives as long as one scan; the connection is
            // to loopback either way.
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    $status = 0;
    $headers = $http_response_header ?? [];
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers];
}

/**
 * Call a ZAP API endpoint, returning null on any failure rather than exiting.
 *
 * Separate from dastZapApi() because one caller — polling a plan's progress —
 * has to survive a transient refusal the others should die on:
 * `automation/action/runPlan` hands back a planId before the plan object is
 * registered, so a `planProgress` call landing in that window answers
 * `internal_error`. A real answer, and not a real problem.
 *
 * @param  array<string, string> $parameters
 * @return array<string, mixed>|null
 */
function dastZapApiSoft(string $baseUrl, string $apiKey, string $path, array $parameters = []): ?array
{
    $query = http_build_query(['apikey' => $apiKey] + $parameters);
    $url = rtrim($baseUrl, '/') . '/JSON/' . trim($path, '/') . '/?' . $query;

    $response = dastHttpGet($url, 120);
    if ($response === null) {
        return null;
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded) || isset($decoded['code'])) {
        return null;
    }

    return $decoded;
}

/**
 * Call a ZAP API endpoint, failing the run if it does not answer or refuses.
 *
 * @param  array<string, string> $parameters
 * @return array<string, mixed>
 */
function dastZapApi(string $baseUrl, string $apiKey, string $path, array $parameters = []): array
{
    $query = http_build_query(['apikey' => $apiKey] + $parameters);
    $url = rtrim($baseUrl, '/') . '/JSON/' . trim($path, '/') . '/?' . $query;

    $response = dastHttpGet($url, 120);
    if ($response === null) {
        dastFail("the ZAP API did not answer at {$baseUrl} (path {$path}).");
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        dastFail("the ZAP API returned something that is not JSON for {$path}: " . substr($response['body'], 0, 200));
    }

    if (isset($decoded['code'])) {
        dastFail("the ZAP API refused {$path}: {$decoded['code']} - " . (string) ($decoded['message'] ?? ''));
    }

    return $decoded;
}

/**
 * A free TCP port on loopback, chosen the same way scripts/e2e.sh chooses one:
 * bind to port 0 and read back what the kernel gave.
 */
function dastFreePort(): void
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
    if ($socket === false) {
        dastFail("could not find a free port: {$errorString}");
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    echo substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * Write a self-signed certificate and its key into one PEM file — the shape
 * stream_socket_server()'s `local_cert` wants.
 *
 * PHP's own openssl extension rather than the `openssl` binary, for the same
 * "one interpreter" reason the rest of this harness follows. The key never
 * leaves the run's temporary directory and the certificate is valid for a day,
 * because a throwaway TLS identity outliving the run it was made for is litter
 * with a private key attached.
 */
function dastGenerateCertificate(string $pemPath, string $hostname): void
{
    if (!extension_loaded('openssl')) {
        dastFail("the 'openssl' PHP extension is required to generate the scan's certificate.");
    }

    $subject = [
        'countryName' => 'BE',
        'organizationName' => 'ISO 20022 Address Game DAST harness',
        'commonName' => $hostname,
    ];

    // The subjectAltName is not decoration: every current browser ignores
    // commonName entirely, and Chromium rejects a certificate without a SAN
    // outright — including under ignoreHTTPSErrors, which suppresses the
    // interstitial but not a malformed certificate.
    $configFile = tempnam(sys_get_temp_dir(), 'dast-openssl-');
    if ($configFile === false) {
        dastFail('could not create a temporary OpenSSL configuration file.');
    }
    file_put_contents(
        $configFile,
        "[req]\ndistinguished_name = dn\n[dn]\n[v3_req]\n"
        . "basicConstraints = CA:FALSE\n"
        . "keyUsage = digitalSignature, keyEncipherment\n"
        . "extendedKeyUsage = serverAuth\n"
        . "subjectAltName = DNS:{$hostname}, DNS:localhost, IP:127.0.0.1\n"
    );

    $config = [
        'config' => $configFile,
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'x509_extensions' => 'v3_req',
    ];

    $privateKey = openssl_pkey_new($config);
    if ($privateKey === false) {
        unlink($configFile);
        dastFail('could not generate a private key: ' . (string) openssl_error_string());
    }

    $csr = openssl_csr_new($subject, $privateKey, $config);
    if ($csr === false) {
        unlink($configFile);
        dastFail('could not generate a certificate request: ' . (string) openssl_error_string());
    }

    $certificate = openssl_csr_sign($csr, null, $privateKey, 1, $config);
    if ($certificate === false) {
        unlink($configFile);
        dastFail('could not self-sign the certificate: ' . (string) openssl_error_string());
    }

    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($privateKey, $keyPem, null, $config);
    unlink($configFile);

    // 0600 before anything is written: the file holds a private key, and a
    // default-umask window is a window.
    touch($pemPath);
    chmod($pemPath, 0600);
    file_put_contents($pemPath, $certificatePem . $keyPem);
}

/** Poll a URL until it answers or the deadline passes. Never a fixed sleep. */
function dastWaitUrl(string $url, int $timeoutSeconds): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $response = dastHttpGet($url, 5);
        if ($response !== null && $response['status'] > 0 && $response['status'] < 500) {
            return true;
        }
        usleep(200_000);
    }

    return false;
}

/**
 * Prove, before spending twenty minutes scanning, that the instance really
 * believes it is on HTTPS.
 *
 * This is the check that stops the whole run from being wasted. If
 * scripts/dast-https-prepend.php is not doing its job the application emits no
 * HSTS and no Secure cookie, and the scan spends its time rediscovering a
 * defect in the harness and reporting it as the application's. Better to fail
 * here, loudly, in ten seconds.
 */
function dastAssertHttps(string $baseUrl): void
{
    $response = dastHttpGet($baseUrl . '/', 20);
    if ($response === null) {
        dastFail("could not reach {$baseUrl} over HTTPS.");
    }

    $hasHsts = false;
    $hasSecureCookie = false;
    foreach ((array) ($response['headers'] ?? []) as $header) {
        $header = (string) $header;
        if (stripos($header, 'Strict-Transport-Security:') === 0) {
            $hasHsts = true;
        }
        if (stripos($header, 'Set-Cookie:') === 0 && stripos($header, 'secure') !== false) {
            $hasSecureCookie = true;
        }
    }

    if (!$hasHsts || !$hasSecureCookie) {
        dastFail(
            "the instance is not behaving as an HTTPS one"
            . ' (HSTS: ' . ($hasHsts ? 'yes' : 'NO')
            . ', Secure session cookie: ' . ($hasSecureCookie ? 'yes' : 'NO') . ").\n"
            . "      scripts/dast-https-prepend.php is not reaching public/index.php's two\n"
            . "      HTTPS-conditional protections. Scanning now would report the harness's\n"
            . '      own defect as two findings against correct application code.'
        );
    }

    echo "DAST: HSTS and a Secure session cookie confirmed - the HTTPS wiring is live.\n";
}

/**
 * Ask ZAP to load and start an Automation Framework plan; print the plan id.
 *
 * Starting and waiting are two subcommands because the plan is deliberately not
 * run to completion in one go: its `delay` job blocks until the browser suite
 * has finished producing traffic, which happens between the two calls. The plan
 * is loaded from inside the container, so the path is the container's.
 */
function dastStartPlan(string $baseUrl, string $apiKey, string $planPath): void
{
    $started = dastZapApi($baseUrl, $apiKey, 'automation/action/runPlan', ['filePath' => $planPath]);
    $planId = (string) ($started['planId'] ?? '');
    if ($planId === '') {
        dastFail('ZAP accepted the plan but returned no planId.');
    }

    echo $planId;
}

/**
 * Wait until the running plan has reached its `delay` job — that is, until
 * every job before it has actually run.
 *
 * Sending traffic earlier would scan responses with the default configuration
 * and no alert filters in place, and an alert raised before its filter exists
 * stays raised. Polled on the plan's own progress log rather than slept on, so
 * a slow container delays the run instead of corrupting it.
 */
function dastAwaitDelayJob(string $baseUrl, string $apiKey, string $planId, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    $reported = [];

    while (microtime(true) < $deadline) {
        $progress = dastZapApiSoft($baseUrl, $apiKey, 'automation/view/planProgress', ['planId' => $planId]);
        if ($progress === null) {
            usleep(250_000);
            continue;
        }

        foreach ((array) ($progress['error'] ?? []) as $line) {
            dastFail("the ZAP automation plan errored before the browser ran: {$line}");
        }

        foreach (['info', 'warn'] as $level) {
            foreach ((array) ($progress[$level] ?? []) as $line) {
                $key = $level . '|' . $line;
                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    echo "DAST: [zap {$level}] {$line}\n";
                }
                if (stripos((string) $line, 'delay') !== false) {
                    return;
                }
            }
        }

        usleep(250_000);
    }

    dastFail("ZAP did not reach the plan's delay job within {$timeoutSeconds} s.");
}

/**
 * Follow a running plan to completion, echoing what ZAP says as it says it, and
 * failing if the plan errors or never finishes.
 *
 * Polled rather than waited on blindly: a plan that hangs has to fail this
 * script rather than hold a CI job open for ever.
 */
function dastWaitPlan(string $baseUrl, string $apiKey, string $planId, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    $reported = [];

    while (microtime(true) < $deadline) {
        $progress = dastZapApiSoft($baseUrl, $apiKey, 'automation/view/planProgress', ['planId' => $planId]);
        if ($progress === null) {
            usleep(500_000);
            continue;
        }

        foreach (['info', 'warn', 'error'] as $level) {
            foreach ((array) ($progress[$level] ?? []) as $line) {
                $key = $level . '|' . $line;
                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    echo "DAST: [zap {$level}] {$line}\n";
                }
            }
        }

        $errors = (array) ($progress['error'] ?? []);
        if (count($errors) > 0) {
            dastFail('the ZAP automation plan reported an error: ' . implode('; ', $errors));
        }

        if (($progress['finished'] ?? '') !== '') {
            return;
        }

        usleep(500_000);
    }

    dastFail("the ZAP automation plan did not finish within {$timeoutSeconds} s.");
}

/**
 * Assert that ZAP's site map is non-empty and holds the pages the browser suite
 * is known to reach.
 *
 * THE MOST IMPORTANT CHECK IN THE HARNESS, because the failure it catches is
 * completely silent. Chromium bypasses an HTTP proxy for loopback addresses
 * unless told not to (`--proxy-bypass-list=<-loopback>`). When that argument
 * goes missing, every browser test still passes, ZAP records nothing, the
 * passive scanner finds no problems in the nothing it was given, and the run
 * reports a clean bill of health.
 *
 * The expectations file lists one path per line, so "ZAP saw the site" cannot
 * be satisfied by the home page alone.
 */
function dastAssertSitemap(string $baseUrl, string $apiKey, string $siteUrl, string $expectationsFile): void
{
    if (!is_file($expectationsFile)) {
        dastFail("the site-map expectations file {$expectationsFile} does not exist.");
    }

    $expected = array_values(array_filter(
        array_map(static fn (string $line): string => trim($line), (array) file($expectationsFile)),
        static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
    ));

    if (count($expected) === 0) {
        dastFail("the site-map expectations file {$expectationsFile} lists nothing to check.");
    }

    $response = dastZapApi($baseUrl, $apiKey, 'core/view/urls', ['baseurl' => $siteUrl]);
    $urls = array_map('strval', (array) ($response['urls'] ?? []));

    if (count($urls) === 0) {
        dastFail(
            "ZAP's site map for {$siteUrl} is EMPTY - the browser did not proxy through it.\n"
            . "      The usual cause is Chromium bypassing the proxy for loopback: check that\n"
            . "      --proxy-bypass-list=<-loopback> reached launchOptions.args in\n"
            . '      tests/e2e/playwright.config.js.'
        );
    }

    $paths = [];
    foreach ($urls as $url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path)) {
            $paths[$path] = true;
        }
    }

    $missing = [];
    foreach ($expected as $expectedPath) {
        $found = false;
        foreach (array_keys($paths) as $path) {
            if ($path === $expectedPath || str_starts_with($path, rtrim($expectedPath, '/') . '/')) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missing[] = $expectedPath;
        }
    }

    if (count($missing) > 0) {
        dastFail(
            'ZAP recorded ' . count($urls) . " URLs for {$siteUrl}, but not these paths the browser\n"
            . '      suite is known to visit: ' . implode(', ', $missing) . "\n"
            . '      A site map missing them means the scan never saw most of the application.'
        );
    }

    echo 'DAST: ZAP site map holds ' . count($urls) . " URLs, including every expected path.\n";
}

/**
 * Read the alerts ZAP raised, print them grouped by risk, optionally write a
 * severity-only summary, and decide the run's verdict.
 *
 * The summary file is what the release workflow publishes. It holds COUNTS BY
 * SEVERITY AND NOTHING ELSE — no rule names, no URLs, no evidence. This
 * repository is public, so its Release assets and its workflow artifacts are
 * public too, and a detailed DAST report on a public release is a map drawn for
 * whoever wants one. The full report stays in the job log, for whoever is
 * debugging a red build.
 */
function dastGateAlerts(
    string $baseUrl,
    string $apiKey,
    string $siteUrl,
    string $threshold,
    string $summaryPath = ''
): never {
    $thresholdIndex = array_search($threshold, DAST_RISK_ORDER, true);
    if ($thresholdIndex === false) {
        dastFail("unknown risk threshold '{$threshold}' (expected one of " . implode(', ', DAST_RISK_ORDER) . ').');
    }

    $alerts = [];
    $start = 0;
    $pageSize = 500;

    // Paged rather than fetched whole: a scan can raise thousands of
    // informational alerts, and ZAP streams the lot into one JSON document
    // otherwise.
    while (true) {
        $page = dastZapApi($baseUrl, $apiKey, 'alert/view/alerts', [
            'baseurl' => $siteUrl,
            'start' => (string) $start,
            'count' => (string) $pageSize,
        ]);
        $batch = (array) ($page['alerts'] ?? []);
        foreach ($batch as $alert) {
            if (is_array($alert)) {
                $alerts[] = $alert;
            }
        }
        if (count($batch) < $pageSize) {
            break;
        }
        $start += $pageSize;
    }

    /** @var array<string, array<string, array{count: int, urls: list<string>}>> $grouped */
    $grouped = [];
    /** @var array<string, int> $bySeverity */
    $bySeverity = array_fill_keys(DAST_RISK_ORDER, 0);

    foreach ($alerts as $alert) {
        // ZAP writes the risk as "Medium (High)" - risk, then confidence.
        $risk = trim(explode('(', (string) ($alert['risk'] ?? 'Informational'))[0]);
        $name = (string) ($alert['alert'] ?? $alert['name'] ?? 'unnamed');
        $url = (string) ($alert['url'] ?? '');

        $grouped[$risk][$name] ??= ['count' => 0, 'urls' => []];
        $grouped[$risk][$name]['count']++;
        if (count($grouped[$risk][$name]['urls']) < 3 && $url !== '') {
            $grouped[$risk][$name]['urls'][] = $url;
        }

        if (array_key_exists($risk, $bySeverity)) {
            $bySeverity[$risk]++;
        }
    }

    $failing = 0;
    echo "\nDAST: findings by risk\n";
    foreach (array_reverse(DAST_RISK_ORDER) as $risk) {
        $entries = $grouped[$risk] ?? [];
        if (count($entries) === 0) {
            continue;
        }

        $riskIndex = array_search($risk, DAST_RISK_ORDER, true);
        $blocking = is_int($riskIndex) && $riskIndex >= $thresholdIndex;

        echo '  ' . strtoupper($risk) . ($blocking ? ' (blocking)' : '') . "\n";
        foreach ($entries as $name => $entry) {
            $failing += $blocking ? $entry['count'] : 0;
            echo "    - {$name} x{$entry['count']}\n";
            foreach ($entry['urls'] as $url) {
                echo "        {$url}\n";
            }
        }
    }

    if (count($alerts) === 0) {
        echo "  (none)\n";
    }
    echo "\n";

    if ($summaryPath !== '') {
        // Counts only. Deliberately not the rule names: a list of which rules
        // fired on a public artifact still tells a reader where to start.
        $summary = [
            'threshold' => $threshold,
            'blocking' => $failing,
            'total' => count($alerts),
            'by_severity' => $bySeverity,
            'generated_at' => gmdate('c'),
            'note' => 'Counts by severity only. The full report is deliberately not published - '
                . 'see README.md, Dynamic application security testing.',
        ];
        @mkdir(dirname($summaryPath), 0755, true);
        file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT) . "\n");
        echo "DAST: severity summary written to {$summaryPath}\n";
    }

    if ($failing > 0) {
        fwrite(STDERR, "DAST: {$failing} finding(s) at or above '{$threshold}'. See the report above.\n");
        exit(1);
    }

    echo "DAST: no finding at or above '{$threshold}'.\n";
    exit(0);
}

$command = $argv[1] ?? '';

switch ($command) {
    case 'free-port':
        dastFreePort();
        break;

    case 'generate-cert':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '') {
            dastFail('usage: dast-support.php generate-cert <pem-path> <hostname>');
        }
        dastGenerateCertificate($argv[2], $argv[3]);
        break;

    case 'wait-url':
        if (($argv[2] ?? '') === '') {
            dastFail('usage: dast-support.php wait-url <url> <timeout-seconds>');
        }
        exit(dastWaitUrl($argv[2], (int) ($argv[3] ?? 30)) ? 0 : 1);

    case 'assert-https':
        if (($argv[2] ?? '') === '') {
            dastFail('usage: dast-support.php assert-https <base-url>');
        }
        dastAssertHttps($argv[2]);
        break;

    case 'zap-plan-start':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dastFail('usage: dast-support.php zap-plan-start <zap-base-url> <api-key> <plan-path>');
        }
        dastStartPlan($argv[2], $argv[3], $argv[4]);
        break;

    case 'zap-plan-await-delay':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dastFail('usage: dast-support.php zap-plan-await-delay <zap-base-url> <api-key> <plan-id> <timeout>');
        }
        dastAwaitDelayJob($argv[2], $argv[3], $argv[4], (int) ($argv[5] ?? 120));
        break;

    case 'zap-plan-wait':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dastFail('usage: dast-support.php zap-plan-wait <zap-base-url> <api-key> <plan-id> <timeout>');
        }
        dastWaitPlan($argv[2], $argv[3], $argv[4], (int) ($argv[5] ?? 1800));
        break;

    case 'assert-sitemap':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '' || ($argv[5] ?? '') === '') {
            dastFail('usage: dast-support.php assert-sitemap <zap-base-url> <api-key> <site-url> <expectations-file>');
        }
        dastAssertSitemap($argv[2], $argv[3], $argv[4], $argv[5]);
        break;

    case 'gate-alerts':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '' || ($argv[5] ?? '') === '') {
            dastFail('usage: dast-support.php gate-alerts <zap-base-url> <api-key> <site-url> <threshold> [summary]');
        }
        dastGateAlerts($argv[2], $argv[3], $argv[4], $argv[5], (string) ($argv[6] ?? ''));
        break;

    default:
        dastFail("unknown subcommand '{$command}'. See the docblock at the top of this file.");
}
