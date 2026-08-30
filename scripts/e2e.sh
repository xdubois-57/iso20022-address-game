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

ISO20022_CONFIG_DIR="$CONFIG_DIR" php -S "127.0.0.1:${PORT}" -t public scripts/e2e-router.php \
    > "$SERVER_LOG" 2>&1 &
SERVER_PID=$!

TIMEOUT="${E2E_SERVER_TIMEOUT:-30}"
DEADLINE=$((SECONDS + TIMEOUT))
until php -r "exit(@file_get_contents('http://127.0.0.1:${PORT}/') === false ? 1 : 0);" 2>/dev/null; do
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
