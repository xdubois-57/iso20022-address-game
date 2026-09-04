#!/usr/bin/env bash
#
# release.sh — Create a semver tag and GitHub release.
#
# Usage:
#   ./release.sh          # patch bump (default)
#   ./release.sh patch    # patch bump  e.g. 1.2.3 → 1.2.4
#   ./release.sh minor    # minor bump  e.g. 1.2.3 → 1.3.0
#   ./release.sh major    # major bump  e.g. 1.2.3 → 2.0.0
#
# Requirements: git, gh (GitHub CLI, authenticated), composer, zip, php
#
# Release note (RELEASE_NOTES_FILE):
#   Set RELEASE_NOTES_FILE to a Markdown file and its contents become the head
#   of the published Release. Claude Code MUST always set it — a generated
#   commit list tells a reader what was touched, never what it means for them.
#   The note has to cover four things, in this order:
#
#     1. What changed, in the language of somebody using the game, not of the
#        diff.
#     2. Bugs fixed, each one said as the symptom that went away.
#     3. Backward compatibility: what an existing installation has to do, or an
#        explicit "nothing" — silence is not an answer, it is an oversight.
#     4. The tests that ran, with their counts, and any gate that did not run.
#
#   The dependency inventory is appended automatically by
#   scripts/dependency-inventory.php — every version read from the lock files,
#   every licence beside it, and the compatibility reasoning — so do not write
#   one by hand.
#
#   CLAUDE.md carries the full contract, including what belongs at the top of
#   a note and why. Read it before writing one.
#
#   Without the variable the script warns and keeps the notes the release
#   workflow generated. That is fine for a human cutting a quick patch and is
#   not fine for an agent, which has the context to write the note and no
#   excuse for a Release nobody can read.
#
# Also builds a deploy-ready release-vX.Y.Z.zip artifact (vendor/ included,
# --no-dev) — the zip to upload when deploying from a release rather than
# from a checkout. See the artifact-building block below for what is excluded
# and why.
#
# This script does NOT create the GitHub Release. Pushing the tag starts
# .github/workflows/release.yml, which runs every gate in checks.yml and then
# creates the Release as a DRAFT carrying the evidence pack. Creating one here
# as well would collide: both target the same tag, the workflow lands last, and
# it would quietly turn a published Release back into a draft.
#
# So the script waits for that workflow, attaches the deployable zip to the
# draft it produced, and publishes it. Nothing reaches the Releases page unless
# every gate went green — that condition is enforced by the workflow, which
# creates no draft at all when one fails.

set -euo pipefail

BUMP="${1:-patch}"

if [[ "$BUMP" != "patch" && "$BUMP" != "minor" && "$BUMP" != "major" ]]; then
    echo "Usage: $0 [patch|minor|major]" >&2
    exit 1
fi

# Ensure working directory is clean
if [[ -n "$(git status --porcelain)" ]]; then
    echo "Error: Working directory is not clean. Commit or stash changes first." >&2
    exit 1
fi

# Ensure we are on main branch
BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$BRANCH" != "main" ]]; then
    echo "Error: Must be on 'main' branch (currently on '$BRANCH')." >&2
    exit 1
fi

# Pull latest
git pull --rebase

# Get the latest semver tag, default to v0.0.0 if none exist
LATEST_TAG=$(git tag -l 'v*' --sort=-v:refname | head -n1)
if [[ -z "$LATEST_TAG" ]]; then
    LATEST_TAG="v0.0.0"
    echo "No existing tags found. Starting from $LATEST_TAG"
fi

# Parse major.minor.patch
VERSION="${LATEST_TAG#v}"
IFS='.' read -r MAJOR MINOR PATCH <<< "$VERSION"

# Bump
case "$BUMP" in
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    patch) PATCH=$((PATCH + 1)) ;;
    # Unreachable: $BUMP is validated above. Present so that a future edit
    # which adds a bump type without updating the guard fails loudly here
    # rather than silently tagging the version it started from.
    *) echo "Internal error: unhandled bump type '$BUMP'." >&2; exit 1 ;;
esac

NEW_VERSION="v${MAJOR}.${MINOR}.${PATCH}"

echo ""
echo "  Current version : $LATEST_TAG"
echo "  Bump type       : $BUMP"
echo "  New version     : $NEW_VERSION"
echo ""

# The release body is written by release.yml — `generate_release_notes` for the
# commit list, plus its own description of the evidence pack. Composing notes
# here too would only give the workflow something to overwrite.

# Confirm
# ── SonarCloud: nothing at LOW or above ─────────────────────────────────────
#
# Runs BEFORE the tag is pushed, which is the whole point. The release workflow
# checks the same thing again, but by then the tag exists — and a tag pointing
# at a version that was refused is a mess somebody has to clean up by hand.
#
# Stricter than SonarCloud's own quality gate on purpose. That gate judges NEW
# code, so a project can sit indefinitely on findings it inherited and still
# show green. This asks a different question: is anything open that is more than
# informational?
#
# The project is public, so the API answers without a token. Nothing here needs
# a secret, which means it works from a clone as well as from CI.
SONAR_PROJECT="${SONAR_PROJECT:-xdubois-57_iso20022-address-game}"
SONAR_API="https://sonarcloud.io/api"

echo ""
echo "Checking SonarCloud for open findings..."

sonar_get() {
    curl --silent --show-error --fail-with-body --max-time 30 "$SONAR_API/$1"
}

# The analysis has to be OF the commit being released. Checking a stale one
# would be worse than not checking: it reports on code that is not what is
# about to ship, and it reports it as a pass.
HEAD_SHA="$(git rev-parse HEAD)"
ANALYSED_SHA="$(sonar_get "project_analyses/search?project=${SONAR_PROJECT}&ps=1" \
    | php -r '$d = json_decode(stream_get_contents(STDIN), true);
              echo $d["analyses"][0]["revision"] ?? "";' 2>/dev/null || true)"

if [[ -z "$ANALYSED_SHA" ]]; then
    echo "ERROR: could not read SonarCloud's latest analysis." >&2
    echo "Refusing to release on an unknown quality state. Check the project at" >&2
    echo "  https://sonarcloud.io/project/overview?id=${SONAR_PROJECT}" >&2
    exit 1
fi

if [[ "$ANALYSED_SHA" != "$HEAD_SHA" ]]; then
    echo "ERROR: SonarCloud's latest analysis is not of this commit." >&2
    echo "  analysed: ${ANALYSED_SHA}" >&2
    echo "  releasing: ${HEAD_SHA}" >&2
    echo "" >&2
    echo "Wait for the analysis of this commit to finish, then run this again." >&2
    echo "A pass read off an older analysis is not a pass." >&2
    exit 1
fi

# Every open finding of any type — bug, vulnerability or code smell — EXCEPT
# purely informational ones. INFO is acceptable by project policy; LOW and
# above are not.
#
# Filtered here rather than by the API, because SonarCloud reports two severity
# scales at once and an issue is only acceptable if it is informational on
# BOTH. The classic scale runs INFO/MINOR/MAJOR/CRITICAL/BLOCKER; the Clean
# Code scale reports per-impact severities of INFO/LOW/MEDIUM/HIGH/BLOCKER. A
# classic MINOR carries an impact of LOW, so trusting either scale alone would
# let one through that the other calls a defect.
#
# Issues marked won't fix or false positive in SonarCloud are resolved and
# therefore not returned: declining a finding is a decision somebody made, and
# this gate honours it rather than second-guessing it.
SONAR_ISSUES="$(sonar_get "issues/search?componentKeys=${SONAR_PROJECT}&statuses=OPEN,CONFIRMED,REOPENED&ps=500")"

SONAR_BLOCKING="$(printf '%s' "$SONAR_ISSUES" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($d) || !isset($d["issues"])) { echo "-1"; exit; }

    $blocking = 0;
    foreach ($d["issues"] as $i) {
        $informational = ($i["severity"] ?? "") === "INFO";
        foreach (($i["impacts"] ?? []) as $impact) {
            if (($impact["severity"] ?? "") !== "INFO") {
                $informational = false;
            }
        }
        if (!$informational) {
            $blocking++;
        }
    }
    echo $blocking;
')"

if [[ "$SONAR_BLOCKING" -lt 0 ]]; then
    echo "ERROR: could not read SonarCloud findings." >&2
    exit 1
fi

if [[ "$SONAR_BLOCKING" -gt 0 ]]; then
    echo "" >&2
    echo "ERROR: SonarCloud reports ${SONAR_BLOCKING} finding(s) at LOW or above. Not releasing." >&2
    echo "" >&2
    printf '%s' "$SONAR_ISSUES" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        foreach (($d["issues"] ?? []) as $i) {
            $informational = ($i["severity"] ?? "") === "INFO";
            foreach (($i["impacts"] ?? []) as $impact) {
                if (($impact["severity"] ?? "") !== "INFO") { $informational = false; }
            }
            if ($informational) { continue; }

            $impacts = [];
            foreach (($i["impacts"] ?? []) as $impact) {
                $impacts[] = ($impact["softwareQuality"] ?? "?") . " " . ($impact["severity"] ?? "?");
            }
            printf("  %-9s %-22s %s:%s\n    %s\n",
                $i["severity"] ?? "?",
                $impacts ? implode(", ", $impacts) : ($i["type"] ?? "?"),
                explode(":", $i["component"] ?? "?", 2)[1] ?? "?",
                $i["line"] ?? "-", $i["message"] ?? "");
        }' >&2
    echo "" >&2
    echo "INFO is acceptable; LOW and above are not. If a finding is genuinely not" >&2
    echo "worth fixing, mark it won't fix in SonarCloud — that is a decision with a" >&2
    echo "name against it, and this gate honours it. Silencing it here would not be." >&2
    exit 1
fi

# Security hotspots are a separate endpoint. A pack that checks issues alone
# looks thorough and misses the category a security reviewer opens first.
SONAR_HOTSPOTS="$(sonar_get "hotspots/search?projectKey=${SONAR_PROJECT}&status=TO_REVIEW&ps=100" \
    | php -r '$d = json_decode(stream_get_contents(STDIN), true);
              echo count($d["hotspots"] ?? []);')"

if [[ "$SONAR_HOTSPOTS" -gt 0 ]]; then
    echo "ERROR: SonarCloud reports ${SONAR_HOTSPOTS} security hotspot(s) to review." >&2
    echo "Review them in SonarCloud before releasing." >&2
    exit 1
fi

echo "SonarCloud: nothing at LOW or above, 0 hotspots to review, analysed at ${HEAD_SHA:0:7}."

read -rp "Create tag $NEW_VERSION, run every gate and publish the release? [y/N] " CONFIRM
if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
    echo "Aborted."
    exit 0
fi

# Write version file (commit will be the release commit itself)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
COMMIT_SHORT=$(git rev-parse --short HEAD)
cat > "$SCRIPT_DIR/config/version.php" <<EOF
<?php
// Auto-generated by release.sh — do not edit manually
return [
    'tag' => '$NEW_VERSION',
    'commit' => '$COMMIT_SHORT',
];
EOF

git add config/version.php
git commit -m "release: $NEW_VERSION"
git push origin main

# Create annotated tag on the release commit
git tag -a "$NEW_VERSION" -m "Release $NEW_VERSION"
git push origin "$NEW_VERSION"

echo ""
echo "Tag $NEW_VERSION pushed."

# Build the release artifact: a ready-to-upload copy of the site, which is
# what makes a release useful to anyone deploying without a shell. vendor/
# must be present in it — shared hosting has no way to run
# `composer install`, and a vendor-less zip uploads perfectly happily but
# yields a dead site the moment a class it needs isn't there. GitHub's own
# auto-generated source zipball is NOT a substitute for this artifact for
# exactly that reason.
#
# `composer install --no-dev` strips PHPUnit and friends from THIS checkout's
# own vendor/ — every `vendor/bin/phpunit` call in this working tree breaks
# silently until dev dependencies come back. The trap restores them
# unconditionally on any exit from here on (success, a later failure,
# Ctrl-C), registered before the zip is even built so a failure there still
# triggers it.
echo ""
echo "Building release artifact..."
composer install --no-dev --optimize-autoloader --no-interaction --quiet
# One trap, extended rather than joined by a second: bash keeps only the last
# handler registered for a signal, so `trap ... EXIT` further down would
# silently replace this one and leave the checkout without its dev
# dependencies. NOTES_FILE is declared empty here so the handler can refer to
# it before the release note is composed.
NOTES_FILE=""
trap 'echo "Restoring dev dependencies (composer install)..."; composer install --no-interaction --quiet || echo "WARNING: failed to restore dev dependencies — run \`composer install\` manually." >&2; rm -f "${NOTES_FILE:-}"' EXIT

ARTIFACT="release-${NEW_VERSION}.zip"
rm -f "$ARTIFACT"
# The `* <digit>` patterns drop macOS conflict copies — "phpunit 2",
# "README 3.md", "deep-copy 4/". iCloud Drive creates them when a synced
# folder is rewritten mid-sync, which is exactly what the `composer install`
# above does to vendor/bin/* and autoload_files.php, so a checkout living
# under a synced ~/Documents grows them steadily: v0.2.3 shipped 56 of them,
# ~229 KB of dead weight including dev-only binaries this build is meant to
# strip. Excluding them here keeps the artifact correct on any machine
# rather than depending on where someone happens to have cloned the repo.
#
# The second block is the development toolchain. None of it is reachable over
# the web — `scripts/` and the dotfiles are denied by .htaccess — but none of
# it belongs on a web host either, and `scripts/dast*` in particular is a
# scanner harness that exists to be pointed at a running instance. Shipping
# it is not an exploit; it is handing someone the tools and saving them the
# download. It arrived with the v3 roadmap and nothing was excluding it.
zip -rq "$ARTIFACT" . \
    -x ".git/*" ".github/*" "tests/*" "storage/*" "uploads/*" "*.zip" \
       "config/credentials.php" "config/db_config.json" \
       "node_modules/*" "coverage/*" "test-results/*" "playwright-report/*" \
       ".claude/*" ".idea/*" ".vscode/*" "*.DS_Store" "deploy.sh" \
       "scripts/dast*" "scripts/e2e*" "scripts/serve.sh" \
       "scripts/merge-coverage.php" "scripts/js-typecheck.mjs" \
       "phpstan.neon" "phpstan-baseline.neon" "js-typecheck-baseline.json" \
       "tsconfig.json" "phpunit.xml" "vitest.config.js" \
       "package.json" "package-lock.json" "sonar-project.properties" \
       "DESIGN.md" "docs/*" "release.sh" \
       "* [0-9]" "* [0-9].*" "* [0-9]/" "* [0-9]/*" \
       "* [0-9][0-9]" "* [0-9][0-9].*" "* [0-9][0-9]/" "* [0-9][0-9]/*"

echo "Artifact built: $ARTIFACT ($(du -h "$ARTIFACT" | cut -f1))"

# Assert the exclusions actually held. This runs before anything is attached
# to a Release, so a surprise here costs a re-run rather than a published bad
# artifact — the whole point is that nobody has to remember to check by hand.
STRAYS=$(unzip -Z1 "$ARTIFACT" | grep -cE ' [0-9]+(/|\.|$)' || true)
if [[ "$STRAYS" -gt 0 ]]; then
    echo "ERROR: $STRAYS macOS conflict copies survived into $ARTIFACT:" >&2
    unzip -Z1 "$ARTIFACT" | grep -E ' [0-9]+(/|\.|$)' | head -10 >&2
    echo "Release NOT published. Tag $NEW_VERSION is already pushed; delete the" >&2
    echo "strays (or move the repo off iCloud Drive) and re-run this script." >&2
    exit 1
fi

# Counted rather than `grep -q`: -q exits on the first match, unzip then
# dies on SIGPIPE, and under `set -o pipefail` the pipeline reports 141 even
# though the file was found. That turned this guard into the opposite of a
# guard — it blocked v0.2.5 over an artifact that was perfectly fine.
AUTOLOAD=$(unzip -Z1 "$ARTIFACT" | grep -c '^vendor/autoload.php$' || true)
if [[ "$AUTOLOAD" -eq 0 ]]; then
    echo "ERROR: $ARTIFACT has no vendor/autoload.php — it would deploy as a dead site." >&2
    echo "Release NOT published. Tag $NEW_VERSION is already pushed." >&2
    exit 1
fi

# ── Wait for the gates ──────────────────────────────────────────────────────
# Pushing the tag started .github/workflows/release.yml. It runs every gate in
# checks.yml and, only if all of them are green, creates the Release as a draft
# carrying the evidence pack. Waiting here is what makes the whole chain one
# command: the alternative is a human remembering to come back ten minutes
# later to finish a release by hand, which is how a version ships with a red
# gate nobody looked at.
#
# The run is found by tag rather than by commit: a tag push sets head_branch to
# the tag name, and the release commit may also have a CI run against main.
echo ""
echo "Waiting for the Release workflow (every gate, then the evidence pack)..."

RUN_ID=""
for _ in $(seq 1 30); do
    RUN_ID=$(gh run list --workflow=release.yml --branch "$NEW_VERSION" \
        --limit 1 --json databaseId -q '.[0].databaseId' 2>/dev/null || true)
    [[ -n "$RUN_ID" && "$RUN_ID" != "null" ]] && break
    sleep 10
done

if [[ -z "$RUN_ID" || "$RUN_ID" == "null" ]]; then
    echo "ERROR: no Release workflow run appeared for $NEW_VERSION after 5 minutes." >&2
    echo "The tag is pushed and nothing is published. Check Actions, then re-run" >&2
    echo "the workflow for the tag rather than cutting another version." >&2
    exit 1
fi

# Watched for the live job list, but NOT trusted for the verdict: `gh run watch`
# refuses a run that has already completed, and a fast failure can finish before
# the poll above even finds it. The conclusion is read separately afterwards, so
# the decision is the same whether the run was watched or was already over.
if [[ "$(gh run view "$RUN_ID" --json status -q .status)" != "completed" ]]; then
    gh run watch "$RUN_ID" --interval 15 || true
fi

CONCLUSION=$(gh run view "$RUN_ID" --json conclusion -q .conclusion)
if [[ "$CONCLUSION" != "success" ]]; then
    echo "" >&2
    echo "ERROR: the Release workflow concluded '$CONCLUSION' — a gate is red." >&2
    echo "Nothing was published: the workflow creates no draft when a gate is red." >&2
    echo "Tag $NEW_VERSION exists and points at nothing. Fix the cause, then:" >&2
    echo "  git push --delete origin $NEW_VERSION && git tag -d $NEW_VERSION" >&2
    echo "and run this script again." >&2
    exit 1
fi

# ── Attach the deployable zip and publish ───────────────────────────────────
# The workflow's draft carries evidence.zip only. The artifact built above is
# the other half — the copy of the site somebody actually uploads — and the two
# belong on the same Release.
#
# --clobber so a re-run replaces the asset instead of failing on a name that is
# already there.
echo ""
echo "Attaching $ARTIFACT to the draft..."
gh release upload "$NEW_VERSION" "$ARTIFACT" --clobber

# ── Compose the release note ────────────────────────────────────────────────
# The workflow's own body describes the evidence pack and is worth keeping, so
# the human note goes ABOVE it rather than replacing it. The dependency
# inventory is generated: it is read from the lock files and from the CDN URLs
# pinned in the views, so it says what actually shipped rather than what a
# constraint allowed, and it cannot drift the way a hand-written list does.
NOTES_FILE="$(mktemp)"

if [[ -n "${RELEASE_NOTES_FILE:-}" ]]; then
    if [[ ! -r "$RELEASE_NOTES_FILE" ]]; then
        echo "ERROR: RELEASE_NOTES_FILE is set but '$RELEASE_NOTES_FILE' cannot be read." >&2
        echo "Release $NEW_VERSION is still a draft; nothing was published." >&2
        exit 1
    fi
    if [[ ! -s "$RELEASE_NOTES_FILE" ]]; then
        echo "ERROR: '$RELEASE_NOTES_FILE' is empty. See the contract in this script's header." >&2
        echo "Release $NEW_VERSION is still a draft; nothing was published." >&2
        exit 1
    fi

    echo ""
    echo "Composing the release note..."
    cat "$RELEASE_NOTES_FILE" > "$NOTES_FILE"
    printf '\n\n' >> "$NOTES_FILE"
    php "$SCRIPT_DIR/scripts/dependency-inventory.php" >> "$NOTES_FILE"
    printf '\n---\n\n' >> "$NOTES_FILE"
    gh release view "$NEW_VERSION" --json body -q .body >> "$NOTES_FILE"

    gh release edit "$NEW_VERSION" --notes-file "$NOTES_FILE"
else
    echo ""
    echo "WARNING: RELEASE_NOTES_FILE is not set. The Release keeps the notes the" >&2
    echo "workflow generated — a commit list, which says what was touched and not" >&2
    echo "what it means. See this script's header." >&2
fi

# Publishing here rather than leaving the draft for a human is deliberate, and
# it is not a loosening: the draft exists so that nothing is published before
# the gates have spoken, and by this line they have — a red run exited above,
# and a run that never produced a draft would have failed the upload. What is
# given up is a pair of eyes on the evidence BEFORE the Release is public; the
# pack stays attached to the published Release, so it is still read, just not
# as a blocking step. Cutting a version is the deliberate act; finishing it by
# hand ten minutes later is only an opportunity to forget.
gh release edit "$NEW_VERSION" --draft=false --latest

rm -f "$ARTIFACT"

echo ""
echo "GitHub release $NEW_VERSION published, with the evidence pack and the"
echo "deployable zip attached."
echo "  https://github.com/$(gh repo view --json nameWithOwner -q .nameWithOwner)/releases/tag/$NEW_VERSION"
