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

import { describe, expect, it } from 'vitest';
import { sanitizeToFragment, setSanitizedHtml } from '../../public/assets/js/lib/sanitize.js';

/** Render through the sanitiser and hand back the resulting HTML. */
function clean(html) {
    const host = document.createElement('div');
    setSanitizedHtml(host, html);
    return host.innerHTML;
}

describe('sanitizeToFragment — allowlisted markup survives', () => {
    it('keeps the inline tags the server also allows', () => {
        expect(clean('<b>bold</b> <strong>s</strong> <i>i</i> <em>e</em>'))
            .toBe('<b>bold</b> <strong>s</strong> <i>i</i> <em>e</em>');
    });

    it('keeps <br>', () => {
        expect(clean('one<br>two')).toBe('one<br>two');
    });

    it('keeps links with http, https and mailto hrefs, and hardens them', () => {
        // rel/target match what HtmlSanitizer adds server-side: facts open in
        // a new tab and must never hand window.opener to the target page.
        const rel = 'rel="noopener noreferrer" target="_blank"';
        expect(clean('<a href="https://example.com">x</a>')).toBe(`<a href="https://example.com" ${rel}>x</a>`);
        expect(clean('<a href="http://example.com">x</a>')).toBe(`<a href="http://example.com" ${rel}>x</a>`);
        expect(clean('<a href="mailto:a@b.com">x</a>')).toBe(`<a href="mailto:a@b.com" ${rel}>x</a>`);
    });

    it('keeps plain text unchanged', () => {
        expect(clean('November 2026 is the deadline')).toBe('November 2026 is the deadline');
    });

    it('escapes text rather than re-emitting raw characters', () => {
        expect(clean('5 &lt; 10 &amp; rising')).toBe('5 &lt; 10 &amp; rising');
    });
});

describe('sanitizeToFragment — script execution is what this exists to stop', () => {
    it('drops a script element entirely', () => {
        expect(clean('<script>alert(1)</script>')).toBe('');
    });

    it('keeps surrounding text when unwrapping a script', () => {
        expect(clean('before<script>alert(1)</script>after')).toBe('beforeafter');
    });

    it('drops inline event handlers', () => {
        expect(clean('<b onclick="alert(1)">hi</b>')).toBe('<b>hi</b>');
        expect(clean('<a href="https://e.com" onmouseover="alert(1)">x</a>'))
            .toBe('<a href="https://e.com" rel="noopener noreferrer" target="_blank">x</a>');
    });

    it('unwraps a link whose href was rejected, leaving only its text', () => {
        // Not merely stripping the href: a bare <a> still looks clickable, so
        // the element goes and the words stay — same as the server.
        expect(clean('<a href="javascript:alert(1)">click</a>')).toBe('click');
        expect(clean('<a href="data:text/html,<script>alert(1)</script>">click</a>')).toBe('click');
        expect(clean('<a>no href at all</a>')).toBe('no href at all');
    });

    it('is not fooled by case or whitespace in the scheme', () => {
        expect(clean('<a href="JaVaScRiPt:alert(1)">x</a>')).toBe('x');
        expect(clean('<a href="  javascript:alert(1)">x</a>')).toBe('x');
    });

    it('drops an img with an onerror payload', () => {
        // The classic fact-field payload: unwrapping must not leave the
        // element behind, because onerror fires without any interaction.
        expect(clean('<img src=x onerror="alert(1)">')).toBe('');
    });

    it('drops iframes, objects, embeds and forms', () => {
        expect(clean('<iframe src="https://evil.test"></iframe>')).toBe('');
        expect(clean('<object data="x"></object>')).toBe('');
        expect(clean('<embed src="x">')).toBe('');
        expect(clean('<form action="https://evil.test"><input name="a"></form>')).toBe('');
    });

    // Every case below carries CONTENT, unlike the empty elements above.
    // That distinction is the one these tests exist for: it separates
    // "dropped with its contents" from "unwrapped to its contents", and it
    // is where this sanitiser and App\Models\HtmlSanitizer had silently
    // drifted apart — the server unwrapped `<iframe>text</iframe>` to "text"
    // while this dropped it whole. The same list is asserted in
    // tests/HtmlSanitizerTest.php::droppedWithContentProvider; keep the two
    // in step, or the same fact renders differently on each end.
    it.each([
        ['script', '<script>alert(1)</script>'],
        ['style', '<style>body{display:none}</style>'],
        ['iframe', '<iframe src="https://evil.test">fallback text</iframe>'],
        ['object', '<object data="x">fallback text</object>'],
        ['template', '<template>inert markup</template>'],
        ['title', '<title>page title</title>'],
    ])('drops <%s> together with its contents', (_tag, html) => {
        expect(clean(html)).toBe('');
    });

    // NOSCRIPT and EMBED are not in that table, because this parser cannot
    // produce what dropping them would assume: DOMParser has scripting
    // disabled and never builds a <noscript> element, and <embed> is void so
    // text after it is a sibling rather than a child. App\Models\HtmlSanitizer
    // unwraps both for the same reason, so the two ends agree on these.
    it('keeps noscript text, which the parser has already unwrapped', () => {
        expect(clean('<noscript>no script here</noscript>')).toBe('no script here');
    });

    it('removes embed but keeps the text the parser left beside it', () => {
        expect(clean('<embed src="x">')).toBe('');
        expect(clean('<embed>text</embed>')).toBe('text');
    });

    it('does not take the dropped element\'s siblings with it', () => {
        expect(clean('before<iframe>swallowed</iframe>after')).toBe('beforeafter');
    });

    it('drops style elements and style attributes', () => {
        expect(clean('<style>body{display:none}</style>')).toBe('');
        expect(clean('<b style="position:fixed;inset:0">x</b>')).toBe('<b>x</b>');
    });

    it('unwraps disallowed tags but preserves their nested allowed markup', () => {
        expect(clean('<div><span>plain <b>bold</b></span></div>')).toBe('plain <b>bold</b>');
    });

    it('strips comments', () => {
        expect(clean('a<!-- sneaky -->b')).toBe('ab');
    });

    it('handles a nested script inside a disallowed wrapper', () => {
        expect(clean('<div><script>alert(1)</script>text</div>')).toBe('text');
    });
});

describe('sanitizeToFragment — edge cases', () => {
    it('returns an empty fragment for null, undefined and empty input', () => {
        expect(clean('')).toBe('');
        expect(clean(null)).toBe('');
        expect(clean(undefined)).toBe('');
    });

    it('returns a DocumentFragment', () => {
        expect(sanitizeToFragment('<b>x</b>')).toBeInstanceOf(DocumentFragment);
    });

    it('replaces prior content rather than appending to it', () => {
        const host = document.createElement('div');
        host.innerHTML = '<p>stale</p>';
        setSanitizedHtml(host, 'fresh');
        expect(host.innerHTML).toBe('fresh');
    });

    it('never executes script when rendered into a live document', () => {
        let fired = false;
        window.__sanitizeProbe = () => { fired = true; };
        const host = document.createElement('div');
        document.body.appendChild(host);
        setSanitizedHtml(host, '<img src=x onerror="window.__sanitizeProbe()"><script>window.__sanitizeProbe()<\/script>');
        expect(fired).toBe(false);
        expect(host.querySelector('script')).toBeNull();
        expect(host.querySelector('img')).toBeNull();
        host.remove();
        delete window.__sanitizeProbe;
    });
});
