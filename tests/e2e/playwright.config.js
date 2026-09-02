// Playwright configuration for this app's end-to-end tests.
//
// Test infrastructure only — the same "development/test tooling, never a
// frontend build step" footing as vitest.config.js (see package.json and
// README.md § Running Tests). Production still ships plain, unbundled
// browser JavaScript and needs neither Node nor a browser runtime to run.
//
// Deliberately narrow:
//   - Chromium only, headless. There is no graphical session in CI or in a
//     sandboxed Claude Code environment, and the goal here is a boot/admin
//     regression gate, not cross-browser coverage.
//   - One worker. scripts/e2e.sh serves the whole run off a single PHP
//     built-in server (php -S), which handles one request at a time — extra
//     workers would contend for it rather than finish sooner. The admin
//     dashboard also fires close to ten AJAX calls per load (one per
//     admin-section widget) that php -S serves strictly serially, so
//     expect/action timeouts below carry a bit more headroom than a
//     concurrent server would need — not a lot, this is still comfortably
//     fast in absolute terms, but a loaded CI runner deserves some margin.
//   - No retries. A retry budget on a handful of boot/admin assertions would
//     hide exactly the flakiness this suite exists to catch.
//
// The server is NOT started by Playwright's own `webServer` option: the full
// lifecycle (throwaway SQLite instance, free port, readiness polling,
// teardown) lives in scripts/e2e.sh so `npm run e2e` and a developer running
// it locally take the identical path. This config expects E2E_BASE_URL to
// already point at a running instance and fails loudly rather than silently
// testing something else.
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL;

// A coverage run instruments every request that reaches the front controller
// and writes a .cov file per request, which measurably slows each one. The
// budgets below are sized for an uninstrumented run, so they are scaled here
// rather than being permanently loosened — a normal run keeps its tight
// timings, and a coverage run stops failing for a reason that has nothing to
// do with the application.
const TIMEOUT_SCALE = process.env.E2E_COVERAGE === '1'
    ? 5
    // scripts/dast.sh sets this. Every request then crosses a TLS handshake
    // and a recording proxy, so the same scenarios take several times the wall
    // clock they take under `npm run e2e`. Scaling the ceilings is what stops
    // the harness's own latency from being reported as application failures.
    : Number(process.env.E2E_TIMEOUT_FACTOR || 1);

/**
 * Proxy and TLS settings, empty unless scripts/dast.sh is driving the run.
 *
 * --proxy-bypass-list=<-loopback> IS NOT OPTIONAL when a proxy is set, and
 * getting it wrong is silent rather than loud. Chromium bypasses any proxy for
 * loopback addresses by default; without this argument every test still
 * passes, the scanner records nothing, finds no problems in the nothing it was
 * given, and the run reports a clean bill of health.
 * scripts/dast-support.php's assert-sitemap exists to catch that, but the
 * argument is what prevents it.
 *
 * ignoreHTTPSErrors is scoped the same way: the instance serves a certificate
 * generated for that one run and trusted by nothing, and it is only ever
 * reached over loopback.
 */
const scanUse = {};
if (process.env.E2E_PROXY_SERVER) {
    scanUse.proxy = { server: process.env.E2E_PROXY_SERVER };
    scanUse.launchOptions = { args: ['--proxy-bypass-list=<-loopback>'] };
}
if (process.env.E2E_IGNORE_HTTPS_ERRORS === '1') {
    scanUse.ignoreHTTPSErrors = true;
}

if (!baseURL) {
    throw new Error(
        'E2E_BASE_URL is not set. Run the end-to-end tests via `npm run e2e` '
        + '(scripts/e2e.sh), which provisions the application instance and sets it.'
    );
}

export default defineConfig({
    testDir: './specs',
    outputDir: '../../test-results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000 * TIMEOUT_SCALE,
    expect: { timeout: 15_000 * TIMEOUT_SCALE },
    // Fails the run if a test is left focused with .only, which would
    // silently reduce a release gate to a subset of itself.
    forbidOnly: !!process.env.CI,
    reporter: [
        ['list'],
        ['html', { outputFolder: '../../playwright-report', open: 'never' }],
    ],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: 15_000 * TIMEOUT_SCALE,
        navigationTimeout: 30_000 * TIMEOUT_SCALE,
        // Last, so a security scan's proxy and TLS settings override the
        // defaults above rather than being overridden by them. Empty for an
        // ordinary run, which is therefore byte-for-byte unchanged.
        ...scanUse,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
