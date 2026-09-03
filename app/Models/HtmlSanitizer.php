<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Models;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allowlist HTML sanitiser for the small amount of rich text the admin panel
 * accepts (the "Did you know?" facts).
 *
 * Facts are rendered with innerHTML on the public welcome screen and screen
 * saver, so whatever is stored executes in every visitor's browser. Validation
 * used to be length-only, which turned "knows the admin PIN" into "runs
 * JavaScript for every player". Parsing with DOM and rebuilding from an
 * allowlist — rather than pattern-matching for bad input — means anything not
 * explicitly permitted is dropped rather than merely looked for.
 */
class HtmlSanitizer
{
    /** Tags kept in the output. Everything else is unwrapped to its text. */
    private const ALLOWED_TAGS = ['a', 'b', 'strong', 'i', 'em', 'br'];

    /** Attributes kept, per tag. Everything else (onclick, style, ...) is dropped. */
    private const ALLOWED_ATTRIBUTES = ['a' => ['href']];

    /** URL schemes permitted in href. Blocks javascript:, data:, vbscript:, ... */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Attribute names whose value is a URL and must therefore be scheme-checked.
     *
     * Deliberately broader than ALLOWED_ATTRIBUTES: it states a fact about
     * these attribute names rather than about today's allowlist. Written as a
     * list for the same reason — comparing the attribute name against a single
     * literal made the check provably redundant while the allowlist held one
     * entry, which is a guard that disappears the moment somebody adds `src`.
     */
    private const URL_ATTRIBUTES = ['href', 'src'];

    /**
     * Elements dropped WITH their contents, rather than unwrapped to text.
     *
     * Unwrapping is right for an ordinary disallowed element — `<div>text</div>`
     * should keep "text" — but wrong for these, whose contents are code or data
     * rather than prose. Mirrors DROP_WITH_CONTENT in
     * public/assets/js/lib/sanitize.js, which both files' comments claim as an
     * invariant; it was not one. libxml discards the contents of <script> and
     * <style> for us, so those two agreed by accident, but `<iframe>text</iframe>`
     * came out of the server as "text" and out of the client as nothing at all,
     * and the same fact rendered differently depending on which sanitiser last
     * touched it. Listing them here makes the two ends agree by construction.
     *
     * Two tags a reader might expect here are deliberately absent, both
     * because the CLIENT's parser cannot produce what dropping them would
     * assume, so listing them would recreate the divergence the other way
     * round:
     *
     *   noscript — a browser's DOMParser has scripting disabled, so it never
     *     builds a <noscript> element at all. It parses the contents as
     *     ordinary markup and discards the wrapper, keeping that text
     *     whatever this list says.
     *   embed — a void element. `<embed>text</embed>` puts "text" AFTER the
     *     element in a browser (the closing tag is ignored), where libxml
     *     nests it inside. Unwrapping is identical to dropping for the
     *     well-formed `<embed src=x>` case, and matches the browser for the
     *     malformed one.
     *
     * Both are still removed as elements — they are simply not in
     * ALLOWED_TAGS — along with every attribute they carried.
     */
    private const DROPPED_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'template', 'title',
    ];

    /**
     * Return $html containing only allowed tags and attributes.
     *
     * Disallowed elements are replaced by their text content, so removing a tag
     * never silently deletes the words a reader was meant to see.
     */
    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');

        // Malformed fragments are expected — parse them quietly rather than
        // emitting warnings, and keep libxml's global error state untouched.
        $previousErrorState = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="sanitizer-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);

        $root = $doc->getElementById('sanitizer-root');
        if (!$root) {
            // Parsing failed outright: fall back to plain text, never raw HTML.
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }

        self::cleanChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /**
     * Strip every tag, returning readable plain text. Used where markup is
     * never appropriate.
     */
    public static function toPlainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    private static function cleanChildren(DOMNode $node): void
    {
        // Snapshot the list: the live NodeList shifts as nodes are replaced.
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                self::cleanElement($child);
                continue;
            }

            // Keep text, drop everything exotic (comments, CDATA, PIs).
            if ($child->nodeType !== XML_TEXT_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function cleanElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        // Checked before recursing: there is nothing inside these worth
        // cleaning, because none of it is kept.
        if (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
            $element->parentNode?->removeChild($element);
            return;
        }

        // Recurse first so that unwrapping a parent keeps already-cleaned children.
        self::cleanChildren($element);

        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            self::unwrap($element);
            return;
        }

        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        // Snapshot: removing attributes mutates the live map mid-iteration.
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);

            // URL check first, allowlist second. Either way the attribute
            // goes, so the order does not change behaviour — but checking the
            // allowlist first narrows the name to the single literal it
            // permits, which makes the URL guard provably redundant and hides
            // the moment a second attribute is allowed.
            if (in_array($name, self::URL_ATTRIBUTES, true)
                && !self::isSafeUrl($attribute->nodeValue ?? '')
            ) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // A link that lost its href is no longer a link.
        if ($tag === 'a') {
            if (!$element->hasAttribute('href')) {
                self::unwrap($element);
                return;
            }
            // Facts open in a new tab; never hand the opener to the target page.
            $element->setAttribute('rel', 'noopener noreferrer');
            $element->setAttribute('target', '_blank');
        }
    }

    /**
     * Replace an element with its children, preserving the text it wrapped.
     */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (!$parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // Strip characters browsers ignore but that hide a scheme
        // ("java\0script:", "java\tscript:").
        $normalised = strtolower(preg_replace('/[\x00-\x20]/', '', $url) ?? '');

        // Relative and anchor links carry no scheme and are safe.
        if (!preg_match('#^([a-z][a-z0-9+.-]*):#', $normalised, $m)) {
            return true;
        }

        return in_array($m[1], self::ALLOWED_SCHEMES, true);
    }
}
