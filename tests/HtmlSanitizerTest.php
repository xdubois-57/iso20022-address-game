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

namespace Tests;

use App\Models\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * The "Did you know?" facts are rendered with innerHTML on the public welcome
 * screen and screen saver, so anything that survives sanitisation runs in every
 * visitor's browser.
 */
class HtmlSanitizerTest extends TestCase
{
    /* =======================================================
       Markup that must survive
       ======================================================= */

    public function testPlainTextIsUnchanged(): void
    {
        $this->assertEquals('Just a fact.', HtmlSanitizer::sanitize('Just a fact.'));
    }

    public function testAllowedFormattingSurvives(): void
    {
        $html = '<b>bold</b> <strong>strong</strong> <i>italic</i> <em>em</em>';
        $this->assertEquals($html, HtmlSanitizer::sanitize($html));
    }

    public function testLineBreaksSurvive(): void
    {
        $this->assertStringContainsString('<br>', HtmlSanitizer::sanitize('one<br>two'));
    }

    public function testSafeLinkSurvivesAndIsHardened(): void
    {
        $out = HtmlSanitizer::sanitize('<a href="https://iso20022.org">ISO</a>');

        $this->assertStringContainsString('href="https://iso20022.org"', $out);
        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('ISO', $out);
    }

    public function testRelativeAndMailtoLinksAreAllowed(): void
    {
        $this->assertStringContainsString('href="/privacy"', HtmlSanitizer::sanitize('<a href="/privacy">p</a>'));
        $this->assertStringContainsString(
            'href="mailto:a@b.c"',
            HtmlSanitizer::sanitize('<a href="mailto:a@b.c">m</a>'),
        );
    }

    public function testEmptyInputStaysEmpty(): void
    {
        $this->assertEquals('', HtmlSanitizer::sanitize(''));
        $this->assertEquals('', HtmlSanitizer::sanitize('   '));
    }

    public function testUnicodeIsPreserved(): void
    {
        $this->assertEquals('Adresse à Zürich 東京', HtmlSanitizer::sanitize('Adresse à Zürich 東京'));
    }

    /* =======================================================
       Markup that must not survive
       ======================================================= */

    public function testScriptTagIsRemoved(): void
    {
        $out = HtmlSanitizer::sanitize('before<script>alert(1)</script>after');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('before', $out);
        $this->assertStringContainsString('after', $out);
    }

    public function testEventHandlerAttributesAreStripped(): void
    {
        $out = HtmlSanitizer::sanitize('<b onmouseover="steal()">hover</b>');

        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringContainsString('<b>hover</b>', $out);
    }

    public function testImageOnErrorPayloadIsRemoved(): void
    {
        $out = HtmlSanitizer::sanitize('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img', $out);
    }

    public function testJavascriptUrlIsRejected(): void
    {
        $out = HtmlSanitizer::sanitize('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringContainsString('click', $out, 'text must survive when the link is dropped');
    }

    public function testObfuscatedJavascriptUrlIsRejected(): void
    {
        // Browsers ignore embedded control characters, so the scheme check must too.
        $out = HtmlSanitizer::sanitize("<a href=\"java\tscript:alert(1)\">click</a>");

        $this->assertStringNotContainsString('<a', $out);
    }

    public function testDataUrlIsRejected(): void
    {
        $out = HtmlSanitizer::sanitize('<a href="data:text/html,<b>x</b>">d</a>');
        $this->assertStringNotContainsString('data:', $out);
    }

    public function testDisallowedTagIsUnwrappedNotDeleted(): void
    {
        // Dropping a tag must never silently swallow the words inside it.
        $out = HtmlSanitizer::sanitize('<div class="x">kept text</div>');

        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringContainsString('kept text', $out);
    }

    public function testIframeIsRemoved(): void
    {
        $out = HtmlSanitizer::sanitize('<iframe src="https://evil.example"></iframe>');
        $this->assertStringNotContainsString('<iframe', $out);
    }

    /**
     * Elements whose contents are code or data, not prose, go entirely —
     * unlike an ordinary disallowed tag, which is unwrapped to its text.
     *
     * Every case here carries CONTENT, which is the whole point: the older
     * tests on both sides used empty elements, so they could not tell
     * "dropped with its contents" from "unwrapped to its contents" and did
     * not notice that this sanitiser and its client-side counterpart had
     * drifted apart on exactly that question. The same list is asserted in
     * tests/js/sanitize.test.js; the two must not diverge, or the same fact
     * renders differently depending on which sanitiser last touched it.
     *
     * @dataProvider droppedWithContentProvider
     */
    public function testElementsDroppedWithTheirContents(string $input): void
    {
        $this->assertSame('', HtmlSanitizer::sanitize($input));
    }

    public static function droppedWithContentProvider(): array
    {
        return [
            'script'   => ['<script>alert(1)</script>'],
            'style'    => ['<style>body{display:none}</style>'],
            'iframe'   => ['<iframe src="https://evil.example">fallback text</iframe>'],
            'object'   => ['<object data="x">fallback text</object>'],
            'template' => ['<template>inert markup</template>'],
            'title'    => ['<title>page title</title>'],
        ];
    }

    /**
     * <noscript> and <embed> are absent from the list above, and this is why:
     * in each case the CLIENT's parser cannot produce what dropping them
     * would assume, so the server unwraps them to keep the two ends saying
     * the same thing. DOMParser has scripting disabled and never builds a
     * <noscript> element at all; <embed> is void, so text after it is a
     * sibling rather than a child. Both are still removed as elements.
     */
    public function testNoscriptIsUnwrappedToMatchTheClient(): void
    {
        $this->assertSame('no script here', HtmlSanitizer::sanitize('<noscript>no script here</noscript>'));
    }

    public function testEmbedIsRemovedButNeighbouringTextSurvives(): void
    {
        $this->assertSame('', HtmlSanitizer::sanitize('<embed src="x">'));
        $this->assertSame('text', HtmlSanitizer::sanitize('<embed>text</embed>'));
    }

    public function testDroppedElementDoesNotTakeItsSiblingsWithIt(): void
    {
        $out = HtmlSanitizer::sanitize('before<iframe>swallowed</iframe>after');

        $this->assertSame('beforeafter', $out);
    }

    public function testCommentsAreRemoved(): void
    {
        $out = HtmlSanitizer::sanitize('<!-- hidden -->visible');

        $this->assertStringNotContainsString('hidden', $out);
        $this->assertStringContainsString('visible', $out);
    }

    public function testStyleAttributeIsStripped(): void
    {
        $out = HtmlSanitizer::sanitize('<b style="position:fixed;top:0">x</b>');
        $this->assertStringNotContainsString('style', $out);
    }

    public function testNestedPayloadInsideAllowedTagIsRemoved(): void
    {
        $out = HtmlSanitizer::sanitize('<b><script>alert(1)</script>text</b>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('text', $out);
    }

    public function testSanitizingTwiceIsStable(): void
    {
        $once = HtmlSanitizer::sanitize('<b>x</b> <a href="https://a.b">l</a>');
        $this->assertEquals($once, HtmlSanitizer::sanitize($once));
    }

    /* =======================================================
       Plain-text helper
       ======================================================= */

    public function testToPlainTextStripsEverything(): void
    {
        $this->assertEquals('bold link', HtmlSanitizer::toPlainText('<b>bold</b> <a href="/x">link</a>'));
    }
}
