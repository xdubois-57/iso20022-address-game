// Vitest configuration for this app's first-party browser JavaScript unit
// tests (development/test tooling only — production ships plain, unbundled
// JavaScript and needs neither Node nor a build step; see package.json and
// README.md § Running Tests). Tests run against the real files under
// public/assets/js/ in a jsdom-simulated DOM, with no PHP server, no
// database, and no network — fetch is mocked in the tests that need it.
import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'lcov'],
            reportsDirectory: 'coverage/js',
            include: ['public/assets/js/**/*.js'],
            exclude: [
                'public/assets/js/vendor/**',
                'tests/**',
                'node_modules/**',
            ],
        },
    },
});
