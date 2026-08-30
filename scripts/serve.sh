#!/usr/bin/env bash
#
# scripts/serve.sh — start a local development server.
#
# Usage: composer serve            # http://localhost:8000
#        composer serve -- 8080    # a specific port
#        PORT=8080 composer serve
#
# Gets a working instance up from a fresh clone with nothing else installed:
# checks prerequisites, provisions a SQLite-backed config if the install has
# none, and serves the real entry point (public/index.php).
#
# This is a DEVELOPMENT server. PHP's built-in server handles one request at
# a time and is not hardened — never expose it beyond localhost.

set -euo pipefail

cd "$(dirname "$0")/.."

command -v php >/dev/null 2>&1 || { echo "ERROR: php is not installed or not on PATH." >&2; exit 1; }

if [[ ! -f vendor/autoload.php ]]; then
    echo "ERROR: dependencies are not installed. Run 'composer install' first." >&2
    exit 1
fi

PORT="${1:-${PORT:-8000}}"
if ! [[ "$PORT" =~ ^[0-9]+$ ]] || (( PORT < 1 || PORT > 65535 )); then
    echo "ERROR: '$PORT' is not a valid port." >&2
    exit 1
fi

# Refuse rather than let php -S fail with a bare "Address already in use",
# which usually means a forgotten server from an earlier run is still holding
# the port and quietly serving stale code.
if lsof -i ":$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "ERROR: port $PORT is already in use." >&2
    echo "       Stop the process using it, or pick another: composer serve -- 8080" >&2
    exit 1
fi

# A fresh clone has no database configured, which otherwise just lands the
# visitor on the setup page with no explanation. Provision a throwaway SQLite
# instance instead, so 'composer serve' works with nothing else installed.
# An install that IS configured is never touched.
SEEDED=0
if [[ ! -f config/credentials.php && ! -f config/db_config.json ]]; then
    echo "No database configured - provisioning a local SQLite instance..."
    mkdir -p storage
    php scripts/e2e-seed-config.php "$(pwd)/storage/dev.sqlite" config/credentials.php >/dev/null
    SEEDED=1
fi

echo
echo "  ISO 20022 Address Game - development server"
echo "  ------------------------------------------"
echo "  URL     http://localhost:${PORT}"
if [[ "$SEEDED" == "1" ]]; then
    echo "  Data    storage/dev.sqlite (new, empty - upload scenarios via Admin)"
    echo "  Admin   PIN 1234 (stored in config/credentials.php; change it there)"
else
    echo "  Config  config/credentials.php takes precedence over db_config.json"
fi
echo "  Stop    Ctrl-C"
echo

exec php -S "localhost:${PORT}" -t public
