#!/usr/bin/env bash
# ============================================================================
# FTP Deploy Script — ISO 20022 Address Structuring Game
# ============================================================================
# This file IS in Git. It holds no secret.
#
# It used to carry the production host, user and password in plain text, which
# is why it was gitignored — and that had a cost nobody had counted: the
# exclusion list below, which decides what reaches a web server, existed only
# on one laptop. A second machine deploying from a clone would have shipped the
# development toolchain to production, and there was no way to review a change
# to any of it.
#
# ── Credentials ─────────────────────────────────────────────────────────────
#
# Read from config/deploy.conf, which IS gitignored. Create it once:
#
#   cp config/deploy.conf.example config/deploy.conf
#   chmod 600 config/deploy.conf
#   $EDITOR config/deploy.conf
#
# Or export FTP_HOST, FTP_USER and FTP_PASS in the environment, which is what
# a CI job would do.
#
# ── Why the password never reaches lftp's command line ──────────────────────
#
# It goes into a ~/.netrc-style file created for the run under a private
# temporary directory and deleted on exit, and lftp is pointed at it. Two
# reasons, and the second is the one that actually bit:
#
#   1. A command line is visible to every other process on the machine
#      (`ps aux`), and lftp -c would have put the password there.
#   2. `--verbose=1` makes lftp echo each transfer as a full URL —
#      ftp://user:password@host/path — so the password was printed dozens of
#      times per deploy, into a terminal, a log file, or a pasted bug report.
#      The verbosity is kept because the file list is genuinely useful; the
#      output is filtered instead, and the filter is belt to the netrc braces.
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOCAL_DIR="$SCRIPT_DIR"
CONFIG_FILE="${DEPLOY_CONFIG:-$SCRIPT_DIR/config/deploy.conf}"

# PARSED, never sourced.
#
# `source` would have been one line, and wrong. The file holds a password, and
# sourcing it means bash expands it: a $ in the password becomes a variable
# reference — which is how this first failed, with "V: unbound variable" — and
# a backtick or $(...) would EXECUTE. A credentials file is data; treating it
# as a program is a bug waiting for a password that happens to contain a
# punctuation mark.
#
# So: KEY=VALUE lines, one optional layer of matching quotes removed, comments
# and blanks skipped, nothing expanded and nothing run. Only the three keys
# this script uses are read, so a stray line in the file cannot set anything
# else.
#
# The config only fills in what the environment has not already set, so an
# exported value wins and a CI job needs no file at all.
read_config_value() {
    local key="$1" file="$2" line value
    [[ -f "$file" ]] || return 0

    # Last occurrence wins, matching how an editor's "add it at the bottom"
    # habit actually behaves.
    line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" | tail -n 1)" || true
    [[ -n "$line" ]] || return 0

    value="${line#*=}"
    # Trim surrounding whitespace.
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    # Remove ONE matching pair of quotes, if present. Anything inside is taken
    # literally, which is the entire point.
    if [[ "$value" == \"*\" && ${#value} -ge 2 ]]; then
        value="${value:1:${#value}-2}"
    elif [[ "$value" == \'*\' && ${#value} -ge 2 ]]; then
        value="${value:1:${#value}-2}"
    fi

    printf '%s' "$value"
}

if [[ -f "$CONFIG_FILE" ]]; then
    FTP_HOST="${FTP_HOST:-$(read_config_value FTP_HOST "$CONFIG_FILE")}"
    FTP_USER="${FTP_USER:-$(read_config_value FTP_USER "$CONFIG_FILE")}"
    FTP_PASS="${FTP_PASS:-$(read_config_value FTP_PASS "$CONFIG_FILE")}"
    FTP_REMOTE_DIR="${FTP_REMOTE_DIR:-$(read_config_value FTP_REMOTE_DIR "$CONFIG_FILE")}"
fi

# A world-readable file holding a production password is worth one line to
# catch. Warned rather than refused: it is the user's own machine, and a hard
# failure here would only teach people to delete the check.
if [[ -f "$CONFIG_FILE" ]]; then
    PERMS="$(stat -f '%Lp' "$CONFIG_FILE" 2>/dev/null || stat -c '%a' "$CONFIG_FILE" 2>/dev/null || echo '')"
    if [[ -n "$PERMS" && "$PERMS" != "600" && "$PERMS" != "400" ]]; then
        echo "WARNING: $CONFIG_FILE is mode $PERMS. Run: chmod 600 \"$CONFIG_FILE\"" >&2
    fi
fi

MISSING=()
[[ -z "${FTP_HOST:-}" ]] && MISSING+=("FTP_HOST")
[[ -z "${FTP_USER:-}" ]] && MISSING+=("FTP_USER")
[[ -z "${FTP_PASS:-}" ]] && MISSING+=("FTP_PASS")
if (( ${#MISSING[@]} > 0 )); then
    echo "ERROR: missing ${MISSING[*]}." >&2
    echo "" >&2
    echo "Create $CONFIG_FILE from config/deploy.conf.example, or export them:" >&2
    echo "  cp config/deploy.conf.example config/deploy.conf" >&2
    echo "  chmod 600 config/deploy.conf" >&2
    exit 1
fi

# Remote directory on the FTP server
REMOTE_DIR="${FTP_REMOTE_DIR:-/}"

# ── Pre-flight checks ──────────────────────────────────────────────────────
echo "=== ISO 20022 Address Game — FTP Deploy ==="
echo ""

# Check lftp is installed
if ! command -v lftp &>/dev/null; then
    echo "ERROR: lftp is required but not installed."
    echo "  macOS:  brew install lftp"
    echo "  Linux:  sudo apt install lftp"
    exit 1
fi

# Ensure vendor/ is populated (production only, no dev dependencies)
echo "→ Cleaning vendor/ and running composer install --no-dev ..."
rm -rf "$LOCAL_DIR/vendor"
composer install --no-dev --optimize-autoloader --working-dir="$LOCAL_DIR"
# --no-dev strips PHPUnit from this working tree, so `composer test` breaks
# until dev dependencies come back. Restore them on every exit, including a
# failed transfer or Ctrl-C. release.sh does the same for the same reason.
# ONE trap, doing both jobs. Bash keeps only the last handler registered for a
# signal, so a second `trap ... EXIT` further down would silently replace this
# one — and the netrc holding the production password would be the thing left
# behind. NETRC_DIR is declared empty here so the handler can name it before it
# exists.
NETRC_DIR=""
cleanup() {
    rm -rf "${NETRC_DIR:-}"
    echo "Restoring dev dependencies (composer install)..."
    composer install --no-interaction --quiet --working-dir="$LOCAL_DIR" \
        || echo "WARNING: restore failed — run \`composer install\` manually." >&2
}
trap cleanup EXIT
echo ""

# Set DRY_RUN=1 to preview exactly what would be uploaded and deleted
# without touching the server. Worth doing before any --delete mirror.
DRY_RUN_FLAG=""
[[ "${DRY_RUN:-0}" == "1" ]] && DRY_RUN_FLAG="--dry-run"

# ── The password, kept off the command line ────────────────────────────────
#
# Written to a netrc under a private temporary directory rather than passed to
# `open -u`, because a command line is readable by every other process on the
# machine. The trap removes the whole directory on any exit, successful or not.
# The file must be named .netrc and sit in HOME, because that is the only place
# lftp looks — there is no setting for an arbitrary path. So HOME is overridden
# for the lftp call alone, which also isolates it from any ~/.lftprc.
NETRC_DIR="$(mktemp -d)"
chmod 700 "$NETRC_DIR"

(umask 077; printf 'machine %s login %s password %s\n' \
    "$FTP_HOST" "$FTP_USER" "$FTP_PASS" > "$NETRC_DIR/.netrc")

# ── Deploy via lftp mirror ─────────────────────────────────────────────────
echo "→ Deploying to $FTP_HOST$REMOTE_DIR ..."
echo ""

# The output is filtered, and this is not belt-and-braces theatre: --verbose=1
# makes lftp echo every transfer as a full ftp://user:password@host/... URL, so
# without this the password is printed dozens of times per deploy — into a
# terminal, a log file, or a pasted bug report. The verbosity is worth keeping
# (the file list is how you see what a --delete mirror is about to do), so the
# credentials are stripped from the stream instead.
#
# PIPESTATUS, not $?, or the pipeline would report sed's exit status and a
# failed deploy would look like a successful one.
redact() {
    sed -E -e 's#(ftp|ftps|sftp)://[^@[:space:]]*@#\1://***@#g' \
           -e "s#${FTP_USER}#***#g" \
           -e "s#${FTP_PASS}#***#g"
}

set +e
HOME="$NETRC_DIR" lftp -c "
set ftp:ssl-allow no;
set net:timeout 30;
set net:max-retries 3;
set mirror:parallel-transfer-count 5;
open $FTP_HOST;
mirror --reverse --delete $DRY_RUN_FLAG --verbose=1 --parallel=5 \
    --exclude-glob .git/ \
    --exclude-glob .git/** \
    --exclude-glob .github/ \
    --exclude-glob .github/** \
    --exclude-glob .gitignore \
    --exclude-glob deploy.sh \
    --exclude-glob release.sh \
    --exclude-glob config/credentials.php \
    --exclude-glob config/db_config.json \
    --exclude-glob storage/** \
    --exclude-glob uploads/** \
    --exclude-glob tests/ \
    --exclude-glob tests/** \
    --exclude-glob node_modules/ \
    --exclude-glob node_modules/** \
    --exclude-glob coverage/ \
    --exclude-glob coverage/** \
    --exclude-glob test-results/ \
    --exclude-glob test-results/** \
    --exclude-glob playwright-report/ \
    --exclude-glob playwright-report/** \
    --exclude-glob docs/ \
    --exclude-glob docs/** \
    --exclude-glob scripts/e2e* \
    --exclude-glob scripts/serve.sh \
    --exclude-glob scripts/merge-coverage.php \
    --exclude-glob scripts/dast* \
    --exclude-glob scripts/js-typecheck.mjs \
    --exclude-glob phpstan.neon \
    --exclude-glob phpstan-baseline.neon \
    --exclude-glob js-typecheck-baseline.json \
    --exclude-glob tsconfig.json \
    --exclude-glob release.sh \
    --exclude-glob .phpunit.result.cache \
    --exclude-glob phpunit.xml \
    --exclude-glob vitest.config.js \
    --exclude-glob package.json \
    --exclude-glob package-lock.json \
    --exclude-glob sonar-project.properties \
    --exclude-glob DESIGN.md \
    --exclude-glob *.zip \
    --exclude-glob .DS_Store \
    --exclude '[^/]+ [0-9]+(/|\.|$)' \
    $LOCAL_DIR $REMOTE_DIR;
bye;
" 2>&1 | redact
LFTP_STATUS=${PIPESTATUS[0]}
set -e

if [[ "$LFTP_STATUS" -ne 0 ]]; then
    echo "" >&2
    echo "ERROR: lftp exited $LFTP_STATUS. Nothing further was done." >&2
    exit "$LFTP_STATUS"
fi

echo ""
echo "=== Deploy complete ==="
echo ""
echo "If this is a first deploy, visit the site in a browser."
echo "You will be redirected to the Database Setup page where you can"
echo "enter your DB credentials. The schema and encryption key will be"
echo "generated automatically."
