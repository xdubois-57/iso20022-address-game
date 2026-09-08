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
# tests/release/run.sh - the suite for scripts/release-lib.sh.
#
# WHY THIS SUITE EXISTS
# ---------------------------------------------------------------------------
# The release script published the production FTP password three times and
# every other gate stayed green, because every other gate reads the source and
# the artifact is not source. This is the gate that opens the archive.
#
# WHY IT IS PLAIN BASH
# ---------------------------------------------------------------------------
# bats-core would be the obvious choice and would add a production-adjacent
# dependency, a licence to audit and a lockfile entry, to run perhaps thirty
# assertions against one file. The runner below is about forty lines. When this
# suite is big enough for that trade to flip, flipping it is easy.
#
# WHY EACH CASE BUILDS A THROWAWAY REPOSITORY
# ---------------------------------------------------------------------------
# The interesting behaviour is "what does git track here", so the fixture has
# to be a real repository with real tracked, untracked and ignored files. A
# mocked `git ls-files` would test the mock. Each case gets its own repository
# under a temporary directory, removed on exit, and nothing touches the
# checkout the suite is running from.

set -uo pipefail

SUITE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SUITE_DIR/../.." && pwd)"

# shellcheck source=../../scripts/release-lib.sh
. "$REPO_ROOT/scripts/release-lib.sh"

PASSED=0
FAILED=0
CURRENT=""

WORK_ROOT="$(mktemp -d)"
trap 'rm -rf "$WORK_ROOT"' EXIT

it() {
    CURRENT="$1"
}

pass() {
    PASSED=$((PASSED + 1))
    printf '  \033[32m✓\033[0m %s\n' "$CURRENT"
}

fail() {
    FAILED=$((FAILED + 1))
    printf '  \033[31m✗\033[0m %s\n' "$CURRENT"
    printf '      %s\n' "$1"
}

assert_eq() {
    if [[ "$1" == "$2" ]]; then pass; else fail "expected '$2', got '$1'"; fi
}

assert_contains() {
    if [[ "$1" == *"$2"* ]]; then pass; else fail "expected to find '$2' in: $1"; fi
}

assert_ok() {
    if [[ "$1" -eq 0 ]]; then pass; else fail "expected success, got exit $1"; fi
}

assert_fails() {
    if [[ "$1" -ne 0 ]]; then pass; else fail "expected a failure, but it succeeded"; fi
}

# A repository shaped like this one: the four tracked files under config/, and
# whatever local secrets the case asks for. Echoes the path.
#
# `use_repo` is how a case should call it. make_repo runs inside a command
# substitution, which is a subshell, so its own `cd` dies with that subshell and
# the caller is left standing in the real checkout — which is not a hypothetical
# tidiness point: it made the first three exclusion cases read this repository's
# genuine config/deploy.conf and pass or fail on that.
make_repo() {
    local name="$1"; shift
    local dir="$WORK_ROOT/$name"

    mkdir -p "$dir/config" "$dir/vendor" "$dir/scripts" "$dir/tests"
    cd "$dir" || return 1

    git init --quiet
    git config user.email "suite@example.invalid"
    git config user.name "Suite"

    printf 'Deny from all\n'                  > config/.htaccess
    printf 'FTP_HOST=\nFTP_USER=\nFTP_PASS=\n' > config/deploy.conf.example
    printf '<?php return [];\n'               > config/credentials.php.example
    printf '<?php return [];\n'               > config/version.php
    printf '<?php // autoload\n'              > vendor/autoload.php
    printf '<?php // a test\n'                > tests/ExampleTest.php
    printf 'config/deploy.conf\nconfig/credentials.php\n' > .gitignore

    git add -A
    git commit --quiet -m "fixture"

    printf '%s\n' "$dir"
}

use_repo() {
    local dir
    dir="$(make_repo "$1")" || return 1
    cd "$dir" || return 1
    printf '%s\n' "$dir"
}

echo ""
echo "release-lib"
echo ""

# ── Version arithmetic ──────────────────────────────────────────────────────
echo "  version arithmetic"

it "bumps a patch"
assert_eq "$(release_next_version patch v1.2.3)" "v1.2.4"

it "bumps a minor and zeroes the patch"
assert_eq "$(release_next_version minor v1.2.3)" "v1.3.0"

it "bumps a major and zeroes both"
assert_eq "$(release_next_version major v1.2.3)" "v2.0.0"

it "carries past nine rather than treating it as a digit"
assert_eq "$(release_next_version patch v0.3.9)" "v0.3.10"

it "does not read a leading zero as octal"
assert_eq "$(release_next_version patch v0.0.08)" "v0.0.9"

it "starts a repository with no tags at v0.0.1"
assert_eq "$(release_next_version patch "")" "v0.0.1"

it "refuses a tag that is not three numbers"
release_next_version patch "v1.2" >/dev/null 2>&1
assert_fails $?

it "refuses an unknown bump"
release_next_version sideways "v1.2.3" >/dev/null 2>&1
assert_fails $?

# ── The version stamp ───────────────────────────────────────────────────────
echo ""
echo "  the version stamp"

STAMP="$WORK_ROOT/version.php"
release_write_version_file "$STAMP" "v9.9.9" "abc1234"

it "writes PHP that parses"
php -l "$STAMP" >/dev/null 2>&1
assert_ok $?

it "returns an array carrying the tag"
assert_eq "$(php -r '$v = require $argv[1]; echo $v["tag"];' "$STAMP")" "v9.9.9"

it "returns an array carrying the commit"
assert_eq "$(php -r '$v = require $argv[1]; echo $v["commit"];' "$STAMP")" "abc1234"

it "is the shape config/version.php in the repository already has"
assert_eq "$(php -r '$v = require $argv[1]; echo implode(",", array_keys($v));' "$STAMP")" "tag,commit"

# ── Deriving the exclusions ─────────────────────────────────────────────────
echo ""
echo "  deriving the config/ exclusions"

REPO="$(use_repo excludes)"; cd "$REPO" || exit 1

it "lists nothing when config/ holds only tracked files"
assert_eq "$(release_config_excludes)" ""

printf 'FTP_PASS=hunter2\n' > "$REPO/config/deploy.conf"

it "lists a gitignored secret"
assert_eq "$(release_config_excludes)" "config/deploy.conf"

printf '<?php return ["dsn" => "..."];\n' > "$REPO/config/credentials.php"

it "lists every gitignored secret"
assert_eq "$(release_config_excludes | sort | tr '\n' ' ')" "config/credentials.php config/deploy.conf "

# The case the blocklist could never cover: a local file nobody has thought of
# yet. It is untracked, so it is excluded, without anyone editing a list.
printf 'SECRET=1\n' > "$REPO/config/whatever-comes-next.conf"

it "lists a secret nobody has thought of yet, without being told about it"
assert_contains "$(release_config_excludes)" "config/whatever-comes-next.conf"

# ── Building the artifact ───────────────────────────────────────────────────
echo ""
echo "  building the artifact"

REPO="$(use_repo artifact)"; cd "$REPO" || exit 1
printf 'FTP_HOST=ftp.example.invalid\nFTP_USER=deploy\nFTP_PASS=hunter2\n' > "$REPO/config/deploy.conf"
printf '<?php return ["dsn" => "sqlite:x"];\n' > "$REPO/config/credentials.php"
printf 'secret\n' > "$REPO/config/db_config.json"

release_build_artifact "$REPO/out.zip" >/dev/null 2>&1
SHIPPED="$(unzip -Z1 "$REPO/out.zip" | grep '^config/' | grep -v '/$' | sort | tr '\n' ' ')"

it "ships the four tracked files under config/"
assert_eq "$SHIPPED" "config/.htaccess config/credentials.php.example config/deploy.conf.example config/version.php "

it "leaves the deploy password out — the bug that shipped in v0.3.1–v0.3.3"
if [[ "$SHIPPED" == *"config/deploy.conf "* ]]; then fail "config/deploy.conf is in the artifact"; else pass; fi

it "leaves the credentials file out"
if [[ "$SHIPPED" == *"config/credentials.php "* ]]; then fail "config/credentials.php is in the artifact"; else pass; fi

it "leaves the database settings out"
if [[ "$SHIPPED" == *"db_config.json"* ]]; then fail "config/db_config.json is in the artifact"; else pass; fi

it "keeps vendor/, without which the zip deploys as a dead site"
assert_contains "$(unzip -Z1 "$REPO/out.zip")" "vendor/autoload.php"

it "leaves the tests out"
LISTING="$(unzip -Z1 "$REPO/out.zip" | grep -v '/$')"
if [[ "$LISTING" == *"tests/"* ]]; then fail "a test file is in the artifact"; else pass; fi

# ── The guard on the built artifact ─────────────────────────────────────────
echo ""
echo "  the guard on the built artifact"

it "passes an artifact whose config/ is exactly what git tracks"
release_assert_no_local_secrets "$REPO/out.zip" >/dev/null 2>&1
assert_ok $?

# The regression, built deliberately: the artifact as it was for three
# releases, with the exclusions bypassed.
cd "$REPO" || exit 1
zip -qr "$REPO/poisoned.zip" config/

it "refuses an artifact carrying config/deploy.conf"
release_assert_no_local_secrets "$REPO/poisoned.zip" >/dev/null 2>&1
assert_fails $?

it "names the file it refused over, so the message is actionable"
assert_contains "$(release_assert_no_local_secrets "$REPO/poisoned.zip" 2>&1)" "config/deploy.conf"

it "refuses an artifact with no vendor/autoload.php"
release_assert_has_autoload "$REPO/poisoned.zip" >/dev/null 2>&1
assert_fails $?

it "passes an artifact that has one"
release_assert_has_autoload "$REPO/out.zip" >/dev/null 2>&1
assert_ok $?

# Regression for v0.2.5, which was blocked over an artifact that was fine:
# `grep -q` exits on the first match, unzip dies on SIGPIPE, and pipefail
# reported 141. The guard must pass a large listing that DOES contain the file.
it "does not choke on a large listing containing the autoloader"
cd "$REPO" || exit 1
mkdir -p vendor/filler
for i in $(seq 1 300); do printf 'x\n' > "vendor/filler/f$i.php"; done
zip -qr "$REPO/big.zip" vendor/
release_assert_has_autoload "$REPO/big.zip" >/dev/null 2>&1
assert_ok $?

echo ""
echo "  macOS conflict copies"

REPO="$(use_repo strays)"; cd "$REPO" || exit 1

it "passes a clean artifact"
zip -qr "$REPO/clean.zip" config/
release_assert_no_stray_copies "$REPO/clean.zip" >/dev/null 2>&1
assert_ok $?

it "refuses an artifact carrying an iCloud conflict copy"
cd "$REPO" || exit 1
printf 'dupe\n' > "config/version 2.php"
zip -qr "$REPO/dirty.zip" config/
release_assert_no_stray_copies "$REPO/dirty.zip" >/dev/null 2>&1
assert_fails $?

it "keeps them out of a real build"
release_build_artifact "$REPO/built.zip" >/dev/null 2>&1
release_assert_no_stray_copies "$REPO/built.zip" >/dev/null 2>&1
assert_ok $?

# ── Result ──────────────────────────────────────────────────────────────────
cd "$REPO_ROOT" || exit 1
echo ""
if [[ "$FAILED" -gt 0 ]]; then
    printf '\033[31m%d failed\033[0m, %d passed\n\n' "$FAILED" "$PASSED"
    exit 1
fi
printf '\033[32m%d passed\033[0m\n\n' "$PASSED"
