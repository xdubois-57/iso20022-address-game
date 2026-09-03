#!/usr/bin/env bash
#
# ISO 20022 Address Structuring Game
# Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program. If not, see <https://www.gnu.org/licenses/>.
#
# scripts/dast.sh - passive dynamic application security testing.
#
# Usage: ./scripts/dast.sh [<extra playwright arguments>...]
#        npm run dast      (canonical command - see README.md)
#
# One command, one complete run, the same shape as scripts/e2e.sh: provision a
# throwaway instance, serve it over HTTPS through the application's REAL entry
# point (public/index.php), drive it with the existing Playwright suite through
# an OWASP ZAP proxy, produce a report, gate on the findings, and tear
# everything back down - on success, on failure, and on Ctrl-C.
#
# THE BROWSER SUITE IS THE ATTACK SURFACE, NOT ZAP'S SPIDER
# ---------------------------------------------------------------------------
# The Playwright suite already drives the admin screen from behind its PIN pad,
# both dedicated display modes with their token, a full five-round game, the
# end-of-game screen and the share flow. A crawler pointed at this application
# would see the welcome card and stop. Replaying the suite through a proxy is
# the most faithful picture of the real surface that exists.
#
# WHAT IS REUSED, AND WHY THERE IS NO SECOND PROVISIONING
# ---------------------------------------------------------------------------
# scripts/e2e.sh already knows how to make a throwaway instance: a scratch
# config directory, a SQLite database, the documented default PIN, and the
# router that stands in for public/.htaccess. This script uses the SAME two
# scripts for that - scripts/e2e-seed-config.php and scripts/e2e-router.php -
# rather than a parallel copy that would drift. What is left here is what the
# scan genuinely adds: TLS, ZAP, and the verdict.
#
# WHAT DOES NOT SIMPLIFY: IT HAS TO BE HTTPS
# ---------------------------------------------------------------------------
# scripts/e2e.sh serves plain HTTP, and two of the application's protections
# are conditional on HTTPS - the HSTS header (public/index.php ~l.97) and
# session.cookie_secure (~l.155). A scan in the clear would report "no HSTS"
# and "cookie without Secure": two findings that are FALSE, about code that is
# correct. The tempting fix is an alert filter silencing both rules, and that
# is precisely how a report stops being read. So the harness is fixed instead
# (scripts/dast-tls-proxy.php + scripts/dast-https-prepend.php), both rules
# stay armed, and the wiring is PROVEN LIVE before the scan starts.
#
# IT CANNOT COLLIDE WITH `npm run e2e`
# ---------------------------------------------------------------------------
# Its own temporary directory, its own SQLite file, its own ports (all chosen
# at run time), its own report directory. Nothing here touches anything
# scripts/e2e.sh owns, so the two can run at the same time.
#
# Configuration, all optional:
#   DAST_PORT / DAST_BACKEND_PORT / DAST_ZAP_PORT
#       Fixed ports for the HTTPS front door, the `php -S` backend behind the
#       TLS terminator, and ZAP's proxy/API listener. Default: free ports
#       chosen at run time.
#   DAST_ZAP_IMAGE       Default 'ghcr.io/zaproxy/zaproxy:stable'.
#   DAST_REPORT_DIR      Where the report and the severity summary go.
#                        Default 'dast-report/' (gitignored).
#   DAST_THRESHOLD       Lowest risk that fails the run. Default 'Medium'.
#   DAST_SERVER_TIMEOUT / DAST_ZAP_TIMEOUT / DAST_PLAN_TIMEOUT
#       Seconds to wait for the instance (60), for ZAP's API (180), and for
#       the plan to finish (3600).
#   DAST_TIMEOUT_FACTOR
#       Multiplies every Playwright timeout. Default 4: the same scenarios do
#       the same work, but each request now crosses a TLS handshake and a
#       proxy, so the wall clock is several times a plain `npm run e2e`.
#       Scaling the ceilings is what stops the harness's own latency from
#       being reported as application failures. `npm run e2e` never sets it.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

PLAYWRIGHT_ARGS=("$@")

SUPPORT="${REPO_ROOT}/scripts/dast-support.php"
PLAN_FILE="${REPO_ROOT}/tests/dast/zap-passive.yaml"
SITEMAP_EXPECTATIONS="${REPO_ROOT}/tests/dast/expected-paths.txt"

DAST_ZAP_IMAGE="${DAST_ZAP_IMAGE:-ghcr.io/zaproxy/zaproxy:stable}"
DAST_REPORT_DIR="${DAST_REPORT_DIR:-${REPO_ROOT}/dast-report}"
DAST_THRESHOLD="${DAST_THRESHOLD:-Medium}"
DAST_SERVER_TIMEOUT="${DAST_SERVER_TIMEOUT:-60}"
DAST_ZAP_TIMEOUT="${DAST_ZAP_TIMEOUT:-180}"
DAST_PLAN_TIMEOUT="${DAST_PLAN_TIMEOUT:-3600}"
DAST_TIMEOUT_FACTOR="${DAST_TIMEOUT_FACTOR:-4}"

# ---------------------------------------------------------------
# 1. Prerequisites. Fail closed with the exact command to run - never install
# anything on the caller's behalf, the same philosophy as scripts/e2e.sh.
# ---------------------------------------------------------------
command -v php >/dev/null 2>&1 || { echo "ERROR: php is required." >&2; exit 1; }
command -v npx >/dev/null 2>&1 || { echo "ERROR: node/npx is required." >&2; exit 1; }
[[ -f "${REPO_ROOT}/vendor/autoload.php" ]] || {
    echo "ERROR: vendor/autoload.php not found - run 'composer install' first." >&2; exit 1; }
[[ -d "${REPO_ROOT}/node_modules/@playwright/test" ]] || {
    echo "ERROR: dependencies not installed - run 'npm install' first." >&2; exit 1; }
[[ -f "${PLAN_FILE}" ]] || { echo "ERROR: no ZAP plan at ${PLAN_FILE}." >&2; exit 1; }

php -r 'exit(extension_loaded("openssl") && extension_loaded("pcntl") ? 0 : 1);' || {
    echo "ERROR: the scan needs PHP's 'openssl' and 'pcntl' extensions." >&2
    echo "       openssl generates the run's certificate; pcntl runs the TLS terminator." >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || {
    echo "ERROR: Docker is required - OWASP ZAP runs as a container." >&2
    exit 1
}
docker info >/dev/null 2>&1 || {
    echo "ERROR: the Docker daemon is not reachable. Start Docker, then re-run." >&2
    exit 1
}
docker image inspect "${DAST_ZAP_IMAGE}" >/dev/null 2>&1 || {
    echo "ERROR: the ZAP image is not present locally. Pull it once with:" >&2
    echo "           docker pull ${DAST_ZAP_IMAGE}" >&2
    echo "       (about 1.2 GB; nothing here downloads it for you.)" >&2
    exit 1
}

# ---------------------------------------------------------------
# 2. State the cleanup trap acts on. Each stays empty until the matching
# resource actually exists, so cleanup is safe at any point.
# ---------------------------------------------------------------
INSTANCE_DIR=""
SERVER_PID=""
TLS_PID=""
PLAYWRIGHT_PID=""
ZAP_CONTAINER=""

stop_pid() {
    local pid="$1" name="$2"
    [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null || return 0

    echo "DAST: stopping ${name} (pid ${pid})."
    kill "${pid}" 2>/dev/null || true
    local waited=0
    while kill -0 "${pid}" 2>/dev/null && [[ "${waited}" -lt 50 ]]; do
        waited=$((waited + 1))
        sleep 0.1
    done
    kill -9 "${pid}" 2>/dev/null || true

    return 0
}

cleanup() {
    # Never let a cleanup step's own failure replace the run's exit code, and
    # never let one failing step skip the others.
    local exit_code=$?
    set +e

    # Playwright first: it is the only child still driving the stack, and
    # killing the server under it turns a cancelled run into a wall of
    # connection errors.
    stop_pid "${PLAYWRIGHT_PID}" "Playwright"
    stop_pid "${TLS_PID}" "the TLS terminator"
    stop_pid "${SERVER_PID}" "the application server"

    # The TLS terminator FORKS one child per connection. Killing the parent
    # leaves those children holding their sockets. Matched on the run's own
    # temporary directory, which appears in the terminator's command line
    # (--cert=<dir>/server.pem) and is a mktemp name no other process on this
    # machine can carry. This script's own command line does not contain it,
    # so cleanup cannot kill itself.
    if [[ -n "${INSTANCE_DIR}" ]]; then
        pkill -f "${INSTANCE_DIR}" >/dev/null 2>&1
    fi

    if [[ -n "${ZAP_CONTAINER}" ]]; then
        echo "DAST: removing the ZAP container."
        docker rm -f "${ZAP_CONTAINER}" >/dev/null 2>&1
    fi

    if [[ -n "${INSTANCE_DIR}" && -d "${INSTANCE_DIR}" ]]; then
        rm -rf "${INSTANCE_DIR}"
    fi

    exit "${exit_code}"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------
# 3. The throwaway instance. Provisioned by the SAME script the browser suite
# uses (scripts/e2e-seed-config.php), so there is one implementation of "what
# a scratch install looks like" rather than two that drift apart.
# ---------------------------------------------------------------
INSTANCE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/iso20022-dast.XXXXXX")"
CONFIG_DIR="${INSTANCE_DIR}/config"
mkdir -p "${CONFIG_DIR}"
SERVER_LOG="${INSTANCE_DIR}/php-server.log"
TLS_LOG="${INSTANCE_DIR}/tls-proxy.log"
CERT_FILE="${INSTANCE_DIR}/server.pem"

php "${REPO_ROOT}/scripts/e2e-seed-config.php" \
    "${INSTANCE_DIR}/dast.sqlite" "${CONFIG_DIR}/credentials.php"

PORT="${DAST_PORT:-$(php "${SUPPORT}" free-port)}"
BACKEND_PORT="${DAST_BACKEND_PORT:-$(php "${SUPPORT}" free-port)}"
ZAP_PORT="${DAST_ZAP_PORT:-$(php "${SUPPORT}" free-port)}"

BASE_URL="https://localhost:${PORT}"

echo "DAST: generating a self-signed certificate for this run."
php "${SUPPORT}" generate-cert "${CERT_FILE}" localhost

echo "DAST: starting the application server on 127.0.0.1:${BACKEND_PORT}."
# auto_prepend_file translates the terminator's X-Forwarded-Proto into
# $_SERVER['HTTPS'] for this process only. The application itself is untouched
# and knows nothing about the scan - see scripts/dast-https-prepend.php.
ISO20022_CONFIG_DIR="${CONFIG_DIR}" php \
    -d display_errors=0 \
    -d log_errors=1 \
    -d "error_log=${INSTANCE_DIR}/php-error.log" \
    -d "auto_prepend_file=${REPO_ROOT}/scripts/dast-https-prepend.php" \
    -S "127.0.0.1:${BACKEND_PORT}" \
    -t public "${REPO_ROOT}/scripts/e2e-router.php" \
    > "${SERVER_LOG}" 2>&1 &
SERVER_PID=$!

echo "DAST: terminating TLS on 127.0.0.1:${PORT} in front of it."
php "${REPO_ROOT}/scripts/dast-tls-proxy.php" \
    --listen="127.0.0.1:${PORT}" \
    --backend="127.0.0.1:${BACKEND_PORT}" \
    --cert="${CERT_FILE}" \
    > "${TLS_LOG}" 2>&1 &
TLS_PID=$!

if ! php "${SUPPORT}" wait-url "${BASE_URL}/" "${DAST_SERVER_TIMEOUT}"; then
    echo "ERROR: the application did not answer on ${BASE_URL} within ${DAST_SERVER_TIMEOUT} s." >&2
    echo "--- php -S output ---" >&2; tail -40 "${SERVER_LOG}" >&2 || true
    echo "--- TLS terminator output ---" >&2; tail -40 "${TLS_LOG}" >&2 || true
    echo "--- PHP error log ---" >&2; tail -40 "${INSTANCE_DIR}/php-error.log" >&2 || true
    exit 1
fi

# Before spending twenty minutes scanning, prove the instance really believes
# it is on HTTPS. Without this the scan would rediscover a broken harness and
# report it as two findings against correct application code.
php "${SUPPORT}" assert-https "${BASE_URL}"

# ---------------------------------------------------------------
# 4. ZAP, in daemon mode, so the plan can configure the passive scanner and
# then block while the browser drives traffic through it.
# ---------------------------------------------------------------
ZAP_WORK_DIR="${INSTANCE_DIR}/zap"
mkdir -p "${ZAP_WORK_DIR}/reports"
cp "${PLAN_FILE}" "${ZAP_WORK_DIR}/plan.yaml"

# The container runs as the `zap` user, not as whoever started this script.
# Only the reports directory needs to be writable by that other uid; the plan
# is read, and the directory itself only traversed.
chmod 0755 "${ZAP_WORK_DIR}"
chmod 0777 "${ZAP_WORK_DIR}/reports"
chmod 0644 "${ZAP_WORK_DIR}/plan.yaml"

ZAP_API_KEY="$(php -r 'echo bin2hex(random_bytes(16));')"
ZAP_CONTAINER="iso20022-dast-zap-$$"
RELEASE_FILE_HOST="${ZAP_WORK_DIR}/browser-finished"

# Reaching a server on the host from inside a container is platform-dependent,
# and getting it wrong produces an EMPTY SITE MAP rather than an error - a scan
# that "passes" having seen nothing. On Linux the container shares the host's
# network namespace, so `localhost` is the same loopback on both sides.
# Elsewhere (Docker Desktop) the port is published and the host is reachable by
# name, and the browser has to be handed that same name: every request it makes
# is resolved by ZAP, not by the browser, so a hostname meaning "this machine"
# inside the container is the only one that works.
if [[ "$(uname -s)" == "Linux" ]]; then
    ZAP_NETWORK_ARGS=(--network=host)
    ZAP_TARGET="${BASE_URL}"
    ZAP_LISTEN_HOST="127.0.0.1"
else
    ZAP_NETWORK_ARGS=(--publish "127.0.0.1:${ZAP_PORT}:${ZAP_PORT}" --add-host "host.docker.internal:host-gateway")
    ZAP_TARGET="https://host.docker.internal:${PORT}"
    ZAP_LISTEN_HOST="0.0.0.0"
    echo "DAST: not Linux - ZAP will reach the instance as ${ZAP_TARGET}."
fi
ZAP_PROXY="http://127.0.0.1:${ZAP_PORT}"
BROWSER_URL="${ZAP_TARGET}"

echo "DAST: starting OWASP ZAP (${DAST_ZAP_IMAGE}) on ${ZAP_PROXY}."
docker run --detach --rm \
    --name "${ZAP_CONTAINER}" \
    "${ZAP_NETWORK_ARGS[@]}" \
    --volume "${ZAP_WORK_DIR}:/dast" \
    --env "DAST_TARGET=${ZAP_TARGET}" \
    --env "DAST_REPORT_DIR=/dast/reports" \
    --env "DAST_RELEASE_GATE_FILE=/dast/browser-finished" \
    "${DAST_ZAP_IMAGE}" \
    zap.sh -daemon -silent \
        -host "${ZAP_LISTEN_HOST}" -port "${ZAP_PORT}" \
        -config api.key="${ZAP_API_KEY}" \
        -config api.addrs.addr.name=.* \
        -config api.addrs.addr.regex=true \
    > /dev/null || {
        echo "ERROR: could not start the ZAP container." >&2
        exit 1
    }

if ! php "${SUPPORT}" wait-url "${ZAP_PROXY}/JSON/core/view/version/?apikey=${ZAP_API_KEY}" "${DAST_ZAP_TIMEOUT}"; then
    echo "ERROR: ZAP did not answer its API within ${DAST_ZAP_TIMEOUT} s." >&2
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi
echo "DAST: ZAP is up."

# The plan starts now and blocks on its own `delay` job until the browser has
# finished. Starting it BEFORE the traffic is the whole point: passive-scan
# configuration has to be in place before the first response is scanned.
PLAN_ID="$(php "${SUPPORT}" zap-plan-start "${ZAP_PROXY}" "${ZAP_API_KEY}" /dast/plan.yaml)"
echo "DAST: ZAP automation plan ${PLAN_ID} started (waiting on the browser)."

# Do not send traffic until the configuration jobs have actually run. Polled,
# never slept: the delay job's appearance in the progress log is the proof that
# the job before it is done.
if ! php "${SUPPORT}" zap-plan-await-delay "${ZAP_PROXY}" "${ZAP_API_KEY}" "${PLAN_ID}" 120; then
    echo "ERROR: ZAP never reached the plan's delay job - the passive scanner is not configured." >&2
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi

# ---------------------------------------------------------------
# 5. The browser, proxied through ZAP.
# ---------------------------------------------------------------
echo "DAST: running the Playwright suite through ZAP..."
set +e
# ${PLAYWRIGHT_ARGS[@]+"${PLAYWRIGHT_ARGS[@]}"} rather than the plain
# "${PLAYWRIGHT_ARGS[@]}": under `set -u`, bash 3.2 - which is what macOS still
# ships - treats an EMPTY array's expansion as an unbound variable and aborts.
# The array is empty on exactly the path that matters, a bare ./scripts/dast.sh.
E2E_BASE_URL="${BROWSER_URL}" \
E2E_PROXY_SERVER="${ZAP_PROXY}" \
E2E_IGNORE_HTTPS_ERRORS="1" \
E2E_TIMEOUT_FACTOR="${DAST_TIMEOUT_FACTOR}" \
    npx playwright test --config="${REPO_ROOT}/tests/e2e/playwright.config.js" \
        ${PLAYWRIGHT_ARGS[@]+"${PLAYWRIGHT_ARGS[@]}"} &
PLAYWRIGHT_PID=$!
wait "${PLAYWRIGHT_PID}"
PLAYWRIGHT_EXIT=$?
PLAYWRIGHT_PID=""
set -e

# ---------------------------------------------------------------
# 6. Release the plan, whatever the browser's verdict: a failed scenario still
# produced traffic worth scanning, and the browser's exit code is reported
# separately below rather than swallowing the findings.
# ---------------------------------------------------------------
if [[ "${PLAYWRIGHT_EXIT}" -ne 0 ]]; then
    echo "DAST: the browser suite exited ${PLAYWRIGHT_EXIT} - scanning the traffic it did produce." >&2
fi

touch "${RELEASE_FILE_HOST}"
echo "DAST: waiting for ZAP to finish passive scanning and write its report."
if ! php "${SUPPORT}" zap-plan-wait "${ZAP_PROXY}" "${ZAP_API_KEY}" "${PLAN_ID}" "${DAST_PLAN_TIMEOUT}"; then
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi

# ---------------------------------------------------------------
# 7. Verdict.
# ---------------------------------------------------------------
mkdir -p "${DAST_REPORT_DIR}"
cp "${ZAP_WORK_DIR}"/reports/* "${DAST_REPORT_DIR}/" 2>/dev/null || {
    echo "ERROR: ZAP produced no report in ${ZAP_WORK_DIR}/reports." >&2
    exit 1
}
echo "DAST: report written to ${DAST_REPORT_DIR}/"

# Prove ZAP actually saw the site before believing anything it says about it.
php "${SUPPORT}" assert-sitemap "${ZAP_PROXY}" "${ZAP_API_KEY}" "${ZAP_TARGET}" "${SITEMAP_EXPECTATIONS}"

set +e
php "${SUPPORT}" gate-alerts \
    "${ZAP_PROXY}" "${ZAP_API_KEY}" "${ZAP_TARGET}" "${DAST_THRESHOLD}" \
    "${DAST_REPORT_DIR}/dast-severity-summary.json"
GATE_EXIT=$?
set -e

if [[ "${GATE_EXIT}" -ne 0 ]]; then
    echo "DAST FAILED. Report: ${DAST_REPORT_DIR}/dast-passive.html" >&2
    exit "${GATE_EXIT}"
fi

if [[ "${PLAYWRIGHT_EXIT}" -ne 0 ]]; then
    echo "DAST: no security finding, but the browser suite failed (exit ${PLAYWRIGHT_EXIT})." >&2
    echo "      A scan is only as complete as the traffic it was given - treat this as a failed run." >&2
    exit "${PLAYWRIGHT_EXIT}"
fi

echo "DAST OK."
