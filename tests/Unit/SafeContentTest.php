<?php

namespace Tests\Unit;

use App\Domain\Cms\SafeContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the CMS content sanitizer lets through, byte for byte.
 *
 * The expected values were recorded on symfony/html-sanitizer 7.4.23 and are identical on 8.1.11 (upgrade of 2026-11-14,
 * docs/upgrades/2026-11-14-symfony-html-sanitizer-8.md). A future version that changes any of them must fail here, not in a school's website.
 * Inputs use the harmless word "marker" only.
 */
class SafeContentTest extends TestCase
{
    public static function cases(): array
    {
        $rel = ' rel="noopener noreferrer"';

        return [
            // allowed structure
            'inline' => ['<p><strong>a</strong> <b>b</b> <em>c</em> <i>d</i> <u>e</u><br>f</p>', '<p><strong>a</strong> <b>b</b> <em>c</em> <i>d</i> <u>e</u><br />f</p>'],
            'only h2 and h3 survive' => ['<h1>one</h1><h2>two</h2><h3>three</h3><h4>four</h4>', '<h2>two</h2><h3>three</h3>'],
            'lists' => ['<ul><li>a</li><li>b<ol><li>c</li></ol></li></ul>', '<ul><li>a</li><li>b<ol><li>c</li></ol></li></ul>'],
            'blockquote and hr' => ['<blockquote><p>q</p></blockquote><hr>', '<blockquote><p>q</p></blockquote><hr />'],
            'table' => ['<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>d</td></tr></tbody></table>', '<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>d</td></tr></tbody></table>'],
            'table loses every attribute' => ['<table border="1" style="width:100%" class="x"><tr><td colspan="2" align="center">d</td></tr></table>', '<table><tbody><tr><td>d</td></tr></tbody></table>'],
            'thai text is untouched' => ['<p>น้ำ ผู้ ที่ ป้าย กำ เก้าอี้ ฤๅ ๆ ฯลฯ ๒๕๖๙</p>', '<p>น้ำ ผู้ ที่ ป้าย กำ เก้าอี้ ฤๅ ๆ ฯลฯ ๒๕๖๙</p>'],
            'bare text is escaped' => ["a & b < c > d \"e\" 'f'", 'a &amp; b &lt; c &gt; d &#34;e&#34; &#039;f&#039;'],
            'unclosed tags are closed' => ['<p>a<strong>b<em>c', '<p>a<strong>b<em>c</em></strong></p>'],
            'uppercase tags' => ['<P><STRONG>X</STRONG><A HREF="https://example.go.th/">Y</A></P>', '<p><strong>X</strong><a href="https://example.go.th/"'.$rel.'>Y</a></p>'],

            // links: https, mailto, tel and relative only; rel is always forced; nothing else is kept on the element
            'https link' => ['<a href="https://example.go.th/a?b=1&c=2" title="t">x</a>', '<a href="https://example.go.th/a?b&#61;1&amp;c&#61;2" title="t"'.$rel.'>x</a>'],
            'http link loses href' => ['<a href="http://example.go.th/">x</a>', '<a'.$rel.'>x</a>'],
            'relative link' => ['<a href="/portal/news">x</a>', '<a href="/portal/news"'.$rel.'>x</a>'],
            'anchor link' => ['<a href="#top">x</a>', '<a href="#top"'.$rel.'>x</a>'],
            'mailto link' => ['<a href="mailto:office@example.go.th">x</a>', '<a href="mailto:office&#64;example.go.th"'.$rel.'>x</a>'],
            'tel link' => ['<a href="tel:+6621234567">x</a>', '<a href="tel:&#43;6621234567"'.$rel.'>x</a>'],
            'script scheme' => ['<a href="javascript:marker">x</a>', '<a'.$rel.'>x</a>'],
            'script scheme, mixed case' => ['<a href="JaVaScRiPt:marker">x</a>', '<a'.$rel.'>x</a>'],
            'script scheme, whitespace inside' => ["<a href=\" java\tscript:marker\">x</a>", '<a'.$rel.'>x</a>'],
            'script scheme, entity inside' => ['<a href="&#106;avascript:marker">x</a>', '<a'.$rel.'>x</a>'],
            'data scheme' => ['<a href="data:text/html,marker">x</a>', '<a'.$rel.'>x</a>'],
            'vbscript scheme' => ['<a href="vbscript:marker">x</a>', '<a'.$rel.'>x</a>'],
            'own rel, target, handler, class and id are removed' => ['<a href="https://example.go.th/" rel="opener" target="_blank" onclick="marker" class="c" id="i">x</a>', '<a href="https://example.go.th/"'.$rel.'>x</a>'],
            'first of two href wins' => ['<a href="https://example.go.th/1" href="https://example.go.th/2">x</a>', '<a href="https://example.go.th/1"'.$rel.'>x</a>'],
            'quotes inside attributes are escaped' => ['<a href=\'https://example.go.th/?q="1"\' title=\'a"b\'>x</a>', '<a href="https://example.go.th/?q&#61;&#34;1&#34;" title="a&#34;b"'.$rel.'>x</a>'],

            // dropped with their content
            'script' => ['<p>a</p><script>marker</script><p>b</p>', '<p>a</p><p>b</p>'],
            'script with src' => ['<script src="https://example.org/marker.js"></script>x', 'x'],
            'style element and style attribute' => ['<style>p{color:red}</style><p style="color:red">x</p>', '<p>x</p>'],
            'iframe' => ['<iframe src="https://example.org/"></iframe>x', 'x'],
            'object and embed' => ['<object data="marker"></object><embed src="marker">x', 'x'],
            'img with handler' => ['<img src="https://example.org/a.png" alt="a" onerror="marker">x', 'x'],
            'svg with handler' => ['<svg onload="marker"><circle r="1"/></svg>x', 'x'],
            'math' => ['<math><mi>x</mi></math>y', 'y'],
            'form' => ['<form action="/x"><input name="a" value="marker"><button>go</button></form>', ''],
            'noscript' => ['<noscript><p>marker</p></noscript>x', 'x'],
            'template' => ['<template><p>marker</p></template>x', 'x'],
            'textarea and title' => ['<textarea><p>marker</p></textarea><title>marker</title>x', 'x'],
            'xmp' => ['<xmp><p>marker</p></xmp>x', 'x'],
            'unknown elements go with their content' => ['<div><span>a</span><section>b</section></div>', ''],
            'script nested in a paragraph' => ['<p>a<script>b<p>c</p></script>d</p>', '<p>ad</p>'],
            'handlers and other attributes on allowed elements' => ['<p onclick="marker" onmouseover="marker" id="p1" class="c" data-x="1">x</p>', '<p>x</p>'],
            'comment' => ['a<!-- marker -->b', 'ab'],
            'cdata' => ['a<![CDATA[marker]]>b', 'ab'],
            'full document keeps only the body content' => ['<!DOCTYPE html><html><head><title>t</title><meta charset="utf-8"></head><body><p>x</p></body></html>', '<p>x</p>'],
            'invalid UTF-8 gives nothing' => ["<p>a\xC3\x28b</p>", ''],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('cases')]
    public function test_output_is_exactly_as_recorded(string $input, string $expected): void
    {
        $this->assertSame($expected, SafeContent::html($input));
    }

    public function test_sanitizing_twice_changes_nothing_more(): void
    {
        foreach (self::cases() as $name => [$input]) {
            $once = SafeContent::html($input);
            $this->assertSame($once, SafeContent::html($once), $name);
        }
    }

    public function test_input_at_the_limit_passes_whole_and_one_byte_more_is_refused(): void
    {
        $this->assertSame(SafeContent::MAX_INPUT_BYTES, strlen(SafeContent::html(str_repeat('a', SafeContent::MAX_INPUT_BYTES))));
        $this->expectException(\LengthException::class);
        SafeContent::html(str_repeat('a', SafeContent::MAX_INPUT_BYTES + 1));
    }

    public function test_the_old_html5_library_is_gone_and_the_native_parser_is_used(): void
    {
        $this->assertFalse(class_exists(\Masterminds\HTML5::class), 'masterminds/html5 came back into vendor');
        $this->assertTrue(class_exists(\Symfony\Component\HtmlSanitizer\Parser\NativeParser::class));
        $this->assertTrue(class_exists(\Dom\HTMLDocument::class), 'PHP 8.4 native HTML5 parser is required');
    }
}
