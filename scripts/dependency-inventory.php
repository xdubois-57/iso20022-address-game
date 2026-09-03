<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Print every dependency this project has, with its version, as Markdown.
 *
 *   php scripts/dependency-inventory.php
 *
 * Written for release.sh, which folds the output into the release note. The
 * point is that somebody reading a release can see exactly what shipped
 * without cloning the tag and running two package managers.
 *
 * Three surfaces, and all three matter for different reasons:
 *
 *  - **PHP, production.** These are IN the deployable zip. Their versions are
 *    what runs on the host.
 *  - **PHP, development.** Not deployed, but they are the tools whose verdict
 *    the release rests on. A release that says "PHPUnit passed" is worth
 *    knowing the PHPUnit version for.
 *  - **CDN.** The ones nothing installs and everybody forgets. They are not in
 *    any lock file: they are pinned by URL in the views, with an SRI hash.
 *    They reach the browser of every player, so leaving them out of an
 *    inventory would leave out the only dependencies a visitor actually
 *    executes.
 *
 * Versions are read from the LOCK files, never from composer.json or
 * package.json — a constraint like `^10.5` is not a version, and the whole
 * value of this list is that it says what shipped rather than what was
 * allowed.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Read and decode a JSON file, or fail loudly. */
function readJson(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "dependency-inventory: missing {$path}\n");
        exit(1);
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "dependency-inventory: {$path} is not valid JSON\n");
        exit(1);
    }

    return $data;
}

/**
 * The CDN dependencies, scraped from the views that load them.
 *
 * Scraped rather than listed here on purpose. A hand-maintained copy would be
 * wrong the first time somebody bumped a version in layout.php, and it would
 * be wrong silently — which is the failure mode an inventory exists to prevent.
 *
 * @return array<string, string> package => version
 */
function cdnDependencies(string $root): array
{
    $found = [];

    foreach (glob($root . '/app/Views/*.php') ?: [] as $view) {
        $source = (string) file_get_contents($view);
        // https://cdn.jsdelivr.net/npm/<name>@<version>/<path>
        // The name may be scoped (@picocss/pico), hence the optional leading @.
        preg_match_all(
            '#https://cdn\.jsdelivr\.net/npm/(@?[^/@"\']+(?:/[^/@"\']+)?)@([^/"\']+)/#',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $found[$match[1]] = $match[2];
        }
    }

    ksort($found);

    return $found;
}

/** One Markdown table of name/version rows. */
function table(array $rows, string $emptyNote): string
{
    if ($rows === []) {
        return $emptyNote . "\n";
    }

    $out = "| Package | Version |\n|---|---|\n";
    foreach ($rows as $name => $version) {
        $out .= '| `' . $name . '` | ' . $version . " |\n";
    }

    return $out;
}

/** @return array<string, string> */
function composerPackages(array $lock, string $key): array
{
    $rows = [];
    foreach ($lock[$key] ?? [] as $package) {
        if (isset($package['name'], $package['version'])) {
            $rows[(string) $package['name']] = (string) $package['version'];
        }
    }
    ksort($rows);

    return $rows;
}

/**
 * The JavaScript development dependencies, at their locked versions.
 *
 * package-lock.json v3 keys every installed tree under "packages", where the
 * direct dependencies appear as "node_modules/<name>". Only the ones
 * package.json actually asks for are listed: the full tree is several hundred
 * entries and nobody reads it.
 *
 * @return array<string, string>
 */
function npmPackages(array $manifest, array $lock): array
{
    $wanted = array_merge(
        array_keys($manifest['dependencies'] ?? []),
        array_keys($manifest['devDependencies'] ?? [])
    );

    $rows = [];
    foreach ($wanted as $name) {
        $entry = $lock['packages']['node_modules/' . $name] ?? null;
        $rows[$name] = is_array($entry) && isset($entry['version'])
            ? (string) $entry['version']
            : 'not installed';
    }
    ksort($rows);

    return $rows;
}

$composerLock = readJson($root . '/composer.lock');
$packageJson  = readJson($root . '/package.json');
$packageLock  = readJson($root . '/package-lock.json');

$php     = composerPackages($composerLock, 'packages');
$phpDev  = composerPackages($composerLock, 'packages-dev');
$node    = npmPackages($packageJson, $packageLock);
$cdn     = cdnDependencies($root);

$phpRequirement = $composerLock['platform']['php']
    ?? ($packageJson['engines']['php'] ?? 'see composer.json');

echo "### Dependencies\n\n";
echo "Read from the lock files, so these are the versions that shipped rather\n";
echo "than the constraints that were allowed.\n\n";

echo "**PHP runtime:** `" . $phpRequirement . "`\n\n";

echo "#### PHP — production (" . count($php) . ")\n\n";
echo "Shipped inside the deployable zip; this is what runs on the host.\n\n";
echo table($php, '_None._');

echo "\n#### PHP — development (" . count($phpDev) . ")\n\n";
echo "Not deployed. These are the tools whose verdict this release rests on.\n\n";
echo table($phpDev, '_None._');

echo "\n#### JavaScript — development (" . count($node) . ")\n\n";
echo "Test tooling only. Production JavaScript is plain, unbundled and\n";
echo "Node-free: nothing here reaches a browser.\n\n";
echo table($node, '_None._');

echo "\n#### CDN — loaded by the browser (" . count($cdn) . ")\n\n";
echo "In no lock file: pinned by URL in `app/Views/`, each with a Subresource\n";
echo "Integrity hash and an exact version, never a floating tag. These are the\n";
echo "only third-party dependencies a player's browser actually executes.\n\n";
echo table($cdn, '_None found — check the scrape in scripts/dependency-inventory.php._');

echo "\nThe address formatter is deliberately **not** on that list: it is\n";
echo "bundled at `public/assets/js/vendor/address-formatter.js` so that a kiosk\n";
echo "on a restricted network still grades hybrid mode against the right\n";
echo "country layouts.\n";
