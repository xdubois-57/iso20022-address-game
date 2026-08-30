#!/usr/bin/env bash
#
# scripts/e2e.sh — end-to-end (real browser) test harness.
#
# Usage: ./scripts/e2e.sh [<extra playwright arguments>...]
#        npm run e2e        (canonical command — see README.md § Running Tests)
#
# One command, one complete run: provisions a throwaway SQLite-backed
# instance, serves it through the application's REAL entry point
# (public/index.php, via scripts/e2e-router.php standing in for
# public/.htaccess's mod_rewrite rules), drives it with headless Chromium via
# Playwright, and tears everything back down — on success, on failure, and on
# Ctrl-C.
#
# What it does, in order:
#   1. Check prerequisites (php, npx, Playwright installed).
#   2. Create a scratch instance directory: a config/ of its own
#      (ISO20022_CONFIG_DIR — see App\Models\Database::configDir()) holding a
#      db_config.json that points App\Models\Database::tryConnect() at a
#      throwaway SQLite file instead of MySQL. No developer config is ever
#      touched.
#   3. Start `php -S` on a free local port with that config directory
#      exported, so App\Models\Database::initSchema() creates the schema
#      fresh on the instance's first request (same idempotent path a real
#      first visit takes — nothing here is E2E-specific).
#   4. Poll (never sleep-and-hope) until the server answers.
#   5. Run the Playwright project in tests/e2e/.
#   6. Exit with Playwright's own exit code.
#
# Cleanup (kill the server, remove the instance directory) runs from an EXIT
# trap, so it happens on every path out — including a failed run or Ctrl-C.
#
# Configuration (all optional):
#   E2E_PORT               Fixed HTTP port. Default: a free port chosen at run time.
#   E2E_SERVER_TIMEOUT     Seconds to wait for the server to answer. Default 30.
#   E2E_COVERAGE           1 to record PHP line coverage of everything the
#                          browser makes the application execute, written as
#                          .cov files into E2E_COVERAGE_DIR for
#                          scripts/merge-coverage.php. Needs pcov loaded. Off
#                          by default: it slows every request down, which a
#                          developer running the suite for its result should
#                          not pay for.
#   E2E_COVERAGE_DIR       Where those files go. Default coverage/php/raw.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"

command -v php >/dev/null 2>&1 || { echo "ERROR: php is required." >&2; exit 1; }
command -v npx >/dev/null 2>&1 || { echo "ERROR: node/npx is required." >&2; exit 1; }
[[ -d node_modules/@playwright/test ]] || { echo "ERROR: dependencies not installed — run 'npm install' first." >&2; exit 1; }

INSTANCE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/iso20022-e2e.XXXXXX")"
CONFIG_DIR="$INSTANCE_DIR/config"
mkdir -p "$CONFIG_DIR"
DB_PATH="$INSTANCE_DIR/e2e.sqlite"

# A real config/credentials.php, not the legacy db_config.json format — see
# scripts/e2e-seed-config.php for why the encryption key requires this shape.
# Admin PIN is the documented default (1234).
php scripts/e2e-seed-config.php "$DB_PATH" "$CONFIG_DIR/credentials.php"

PORT="${E2E_PORT:-}"
if [[ -z "$PORT" ]]; then
    PORT="$(php -r '
        $s = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        if (!$s) { fwrite(STDERR, "could not find a free port: $errstr\n"); exit(1); }
        $name = stream_socket_get_name($s, false);
        echo substr($name, strrpos($name, ":") + 1);
        fclose($s);
    ')"
fi

SERVER_LOG="$INSTANCE_DIR/php-server.log"
SERVER_PID=""

cleanup() {
    local status=$?
    if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    rm -rf "$INSTANCE_DIR"
    exit $status
}
trap cleanup EXIT INT TERM

# Coverage is opt-in and instruments the server through auto_prepend_file, so
# the application itself needs no knowledge of it (see
# scripts/e2e-coverage-prepend.php).
SERVER_PHP_ARGS=()
if [[ "${E2E_COVERAGE:-0}" == "1" ]]; then
    # E2E_PCOV_EXTENSION is for machines where pcov is built but not enabled in
    # php.ini. On CI it is unset: setup-php's `coverage: pcov` loads it globally.
    if [[ -n "${E2E_PCOV_EXTENSION:-}" ]]; then
        SERVER_PHP_ARGS+=(-d "extension=$E2E_PCOV_EXTENSION")
    fi

    # Fail loudly. Recording coverage that silently captures nothing is worse
    # than not asking for it: the merge step still succeeds on the unit data
    # alone and the resulting number looks like a real measurement.
    if ! php ${SERVER_PHP_ARGS[@]+"${SERVER_PHP_ARGS[@]}"} -r 'exit(extension_loaded("pcov") ? 0 : 1);' 2>/dev/null; then
        echo "ERROR: E2E_COVERAGE=1 but the pcov extension is not loaded." >&2
        echo "       Install it (pecl install pcov) or point E2E_PCOV_EXTENSION at pcov.so." >&2
        exit 1
    fi

    E2E_COVERAGE_DIR="${E2E_COVERAGE_DIR:-$PROJECT_ROOT/coverage/php/raw}"
    mkdir -p "$E2E_COVERAGE_DIR"
    export E2E_COVERAGE_DIR
    SERVER_PHP_ARGS+=(-d pcov.enabled=1 -d "pcov.directory=$PROJECT_ROOT/app")
    echo "Recording PHP coverage into $E2E_COVERAGE_DIR"
fi

ISO20022_CONFIG_DIR="$CONFIG_DIR" php ${SERVER_PHP_ARGS[@]+"${SERVER_PHP_ARGS[@]}"} -S "127.0.0.1:${PORT}" -t public scripts/e2e-router.php \
    > "$SERVER_LOG" 2>&1 &
SERVER_PID=$!

# Readiness probe. The status code matters, not merely "something answered":
# this request IS the instance's first visit, and the first visit runs work no
# later one does — schema creation and the once-a-day retention cleanup. A 500
# there used to be indistinguishable from "not listening yet", so the loop
# retried, the second request took the cheap path, and the run proceeded
# green over an instance whose first page load had failed. That is exactly
# the regression this suite exists to catch, so a response with a bad status
# now fails the run immediately and prints the server log.
#
#   exit 0 → 2xx, ready
#   exit 1 → could not connect; the server is still starting, so retry
#   exit 2 → the server answered with a non-2xx status; fail now, not later
probe_server() {
    php -r '
        $url = "http://127.0.0.1:" . $argv[1] . "/";
        $context = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 5]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) { exit(1); }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match("#^HTTP/\S+\s+(\d+)#", $header, $m)) { $status = (int) $m[1]; }
        }
        if ($status >= 200 && $status < 300) { exit(0); }
        fwrite(STDERR, "first request returned HTTP {$status}\n");
        exit(2);
    ' "$PORT" 2>&1
}

TIMEOUT="${E2E_SERVER_TIMEOUT:-30}"
DEADLINE=$((SECONDS + TIMEOUT))
while true; do
    # `|| PROBE_STATUS=$?` rather than a bare assignment: a non-zero exit from
    # a command substitution is fatal under `set -e`, and "not listening yet"
    # is the normal case here, not an error.
    PROBE_STATUS=0
    PROBE_OUTPUT="$(probe_server)" || PROBE_STATUS=$?

    if (( PROBE_STATUS == 0 )); then
        break
    fi

    if (( PROBE_STATUS == 2 )); then
        echo "ERROR: the instance's first page load failed — ${PROBE_OUTPUT}" >&2
        echo "Server log:" >&2
        cat "$SERVER_LOG" >&2
        exit 1
    fi

    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        echo "ERROR: PHP server exited early. Log:" >&2
        cat "$SERVER_LOG" >&2
        exit 1
    fi
    if (( SECONDS >= DEADLINE )); then
        echo "ERROR: server did not respond within ${TIMEOUT}s. Log:" >&2
        cat "$SERVER_LOG" >&2
        exit 1
    fi
    sleep 0.2
done

export E2E_BASE_URL="http://127.0.0.1:${PORT}"
echo "Serving throwaway instance at ${E2E_BASE_URL} (${INSTANCE_DIR})"

npx playwright test --config=tests/e2e/playwright.config.js "$@"
