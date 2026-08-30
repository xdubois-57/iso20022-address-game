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
const TIMEOUT_SCALE = process.env.E2E_COVERAGE === '1' ? 5 : 1;

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
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
