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
                // The SPA bootstrap: one 2,300-line IIFE that wires the whole
                // UI to the DOM on load. Importing it under jsdom would not
                // test it, it would execute the entire application against a
                // fake document. It is covered end-to-end by the Playwright
                // suite instead, which lcov cannot see.
                //
                // This is a scoping decision, not a claim that it is tested.
                // The logic worth asserting on has been progressively moved
                // out into lib/ (scoring, address, format, api, random,
                // sanitize), all of which ARE measured and
                // sit at 98-100%. Continuing that extraction is what will
                // genuinely cover this file.
                'public/assets/js/app.js',
            ],
        },
    },
});
