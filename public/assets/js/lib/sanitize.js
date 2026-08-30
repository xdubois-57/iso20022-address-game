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

/**
 * Client-side counterpart of App\Models\HtmlSanitizer, kept deliberately in
 * step with it: same tags, same attributes, same URL schemes.
 *
 * "Did you know?" facts are authored in the admin panel and may carry a
 * little inline markup, so they cannot simply be escaped — they have to be
 * rendered as HTML. The server already sanitises them on write and on read,
 * but that made every render site a taint sink: `el.innerHTML = '...' +
 * fact.content` puts server data straight into the DOM parser, which is one
 * missed sanitiser call (or one row written by an older version) away from
 * executing script in every visitor's browser.
 *
 * Sanitising here as well closes that on the client, where the damage would
 * actually happen. It is defence in depth, not a replacement for the server
 * check: both ends now enforce the same allowlist independently.
 */

/** Mirrors HtmlSanitizer::ALLOWED_TAGS. */
const ALLOWED_TAGS = ['A', 'B', 'STRONG', 'I', 'EM', 'BR'];

/** Mirrors HtmlSanitizer::ALLOWED_ATTRIBUTES. */
const ALLOWED_ATTRIBUTES = { A: ['href'] };

/** Mirrors HtmlSanitizer::ALLOWED_SCHEMES. */
const ALLOWED_SCHEMES = ['http:', 'https:', 'mailto:'];

/**
 * Elements dropped WITH their contents, rather than unwrapped to their text.
 *
 * Unwrapping is right for an ordinary disallowed element — `<div>text</div>`
 * should keep "text" — but wrong for these, whose contents are code or data
 * rather than prose: unwrapping `<script>alert(1)</script>` would print
 * `alert(1)` on the page as visible text. Harmless, but wrong, and it is what
 * App\Models\HtmlSanitizer does too (verified: it returns '' for both
 * `<script>alert(1)</script>` and `<style>body{}</style>`). The two ends must
 * agree, or the same fact renders differently depending on which sanitiser
 * last touched it.
 */
const DROP_WITH_CONTENT = ['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'NOSCRIPT', 'TEMPLATE', 'TITLE'];

/**
 * Whether an href is safe to keep — anything that is not an allowlisted
 * absolute scheme, or a relative URL, is dropped. This is what keeps
 * `javascript:` and `data:` out.
 *
 * @param {string} href
 */
function isAllowedHref(href) {
    const value = String(href).trim();
    if (value === '') return false;

    try {
        // A relative URL resolves against the base and inherits the page's
        // scheme, so it is judged by the same allowlist rather than trusted.
        return ALLOWED_SCHEMES.includes(new URL(value, document.baseURI).protocol);
    } catch {
        return false;
    }
}

/**
 * Parse `html` and return a DocumentFragment containing only allowlisted
 * markup. Disallowed elements are replaced by their text content, exactly as
 * the server does, so removing a tag never removes the words inside it.
 *
 * DOMParser is used rather than innerHTML on a live element: it builds an
 * inert document that never runs scripts, loads images or fires handlers, so
 * nothing in the input executes even while it is being inspected.
 *
 * @param {string} html
 * @returns {DocumentFragment}
 */
export function sanitizeToFragment(html) {
    const parsed = new DOMParser().parseFromString(String(html ?? ''), 'text/html');
    return copyAllowedChildren(parsed.body, document.createDocumentFragment());
}

/**
 * @param {Node} source
 * @param {Node} target
 */
function copyAllowedChildren(source, target) {
    source.childNodes.forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            target.appendChild(document.createTextNode(node.nodeValue));
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            // Comments, CDATA and processing instructions carry nothing worth
            // keeping and are simply dropped.
            return;
        }

        if (DROP_WITH_CONTENT.includes(node.tagName)) {
            return;
        }

        if (!ALLOWED_TAGS.includes(node.tagName)) {
            // Unwrap: keep the words, discard the element.
            copyAllowedChildren(node, target);
            return;
        }

        const clean = document.createElement(node.tagName.toLowerCase());
        for (const name of ALLOWED_ATTRIBUTES[node.tagName] || []) {
            const value = node.getAttribute(name);
            if (name === 'href' && value !== null && isAllowedHref(value)) {
                clean.setAttribute('href', value);
            }
        }

        if (clean.tagName === 'A') {
            // Both rules below mirror HtmlSanitizer::cleanElement() exactly.
            // They were found to diverge by running the same inputs through
            // both implementations, which is the only way this stays honest:
            // a link whose href was rejected must be UNWRAPPED, not merely
            // stripped of its href, or a javascript: link would survive as a
            // clickable-looking element; and a surviving link must never hand
            // window.opener to the page it opens.
            if (!clean.hasAttribute('href')) {
                copyAllowedChildren(node, target);
                return;
            }
            clean.setAttribute('rel', 'noopener noreferrer');
            clean.setAttribute('target', '_blank');
        }

        copyAllowedChildren(node, clean);
        target.appendChild(clean);
    });

    return target;
}

/**
 * Replace everything in `el` with the sanitised rendering of `html`.
 *
 * @param {Element} el
 * @param {string} html
 */
export function setSanitizedHtml(el, html) {
    el.replaceChildren(sanitizeToFragment(html));
}
