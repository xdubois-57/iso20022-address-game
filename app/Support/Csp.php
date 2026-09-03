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

namespace App\Support;

/**
 * The Content-Security-Policy nonce for this request.
 *
 * ## Why a nonce exists at all
 *
 * The policy used to carry `'unsafe-inline'` in both `script-src` and
 * `style-src`, which is very close to having no script policy: it permits ANY
 * inline script the page ends up containing, including one an injection put
 * there. The passive security scan reports it, and is right to.
 *
 * A nonce replaces that blanket permission with a per-request secret. Only the
 * handful of inline blocks this application actually serves — the import map,
 * the theme's `<style>`, and the three small standalone pages' scripts — carry
 * it, so an injected `<script>` no longer runs even though inline script is
 * still used by the page.
 *
 * ## Why it is generated once and memoised
 *
 * The value in the header and the value in the markup have to be the same
 * string, or every inline block is blocked. Generating it lazily on first use
 * and keeping it for the rest of the request is what guarantees that, no
 * matter which of the two asks first.
 *
 * It is NOT reused across requests, deliberately: a nonce a page could predict
 * is a nonce an injection can quote.
 *
 * ## What it does not cover
 *
 * A nonce authorises `<script>` and `<style>` ELEMENTS. It does nothing for
 * `style="…"` ATTRIBUTES, which `style-src` blocks outright once
 * `'unsafe-inline'` is gone. Those were converted to CSS classes instead —
 * see public/assets/css/app.css. Adding `'unsafe-hashes'` to bring them back
 * would have undone most of the benefit.
 */
final class Csp
{
    private static ?string $nonce = null;

    /**
     * The nonce for this request, minted on first use.
     *
     * 16 random bytes, base64-encoded, which is what the CSP specification
     * recommends: enough entropy that guessing it is not a strategy.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }

        return self::$nonce;
    }

    /**
     * The nonce as a ready-to-echo HTML attribute — NO leading space.
     *
     * Views use this rather than building the attribute themselves, so the
     * escaping happens in one place. Base64 can contain `+` and `/` but never
     * a quote, so htmlspecialchars() is belt and braces rather than a fix for
     * a known problem — and belt and braces is the right posture for a value
     * that gates script execution.
     *
     * The absent leading space is load-bearing, and callers must write one
     * themselves: `<script <?= Csp::nonceAttribute() ?>>`. When the space
     * lived in here, views read `<script<?= … ?>>` — the PHP tag glued
     * straight onto the element name. Every HTML parser that is not PHP then
     * fails to see an opening `script` tag at all, and reports the matching
     * `</script>` as unbalanced; SonarCloud raised three such bugs (Web:S4645)
     * and held the project's reliability rating at C over markup that was
     * perfectly correct once PHP had run. Keeping the space outside the helper
     * makes the tag name readable to a plain parser again.
     */
    public static function nonceAttribute(): string
    {
        return 'nonce="' . htmlspecialchars(self::nonce(), ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Forget the current nonce. Tests only — a request never needs this, and
     * calling it mid-request would break every inline block on the page.
     */
    public static function reset(): void
    {
        self::$nonce = null;
    }
}
