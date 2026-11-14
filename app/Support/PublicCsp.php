<?php

namespace App\Support;

use App\Domain\Cms\SiteExtras;

/**
 * Per-request Content-Security-Policy additions for the public site (F5a).
 *
 * The site sends no blanket CSP of its own (staff pages and the Turnstile pages of the complaint and notice forms load their own scripts), so this
 * adds a header only when a page has something to restrict: a page that carries the map block gets exactly
 *     frame-src <the one provider source chosen in the settings>
 * and nothing else: no script-src, no default-src. Pages without such a block get no header from here, so their policy is unchanged.
 * Only the sources listed in SiteExtras::FRAME_SOURCES can be named; anything else is ignored.
 *
 * A singleton (AppServiceProvider); SchoolBoundary resets it before each request and writes the header after the page was rendered.
 */
final class PublicCsp
{
    /** Frame sources of module blocks that build an iframe only after a click (web-social: the Facebook page plugin, same path as SocialFeed::pluginUrl). */
    public const BLOCK_FRAMES = ['facebook' => 'https://www.facebook.com/plugins/page.php'];

    /** @var array<string,true> */
    private array $frames = [];

    public function reset(): void
    {
        $this->frames = [];
    }

    public function allowFrame(string $source): void
    {
        if (in_array($source, SiteExtras::FRAME_SOURCES, true) || in_array($source, self::BLOCK_FRAMES, true)) $this->frames[$source] = true;
    }

    public function value(): ?string
    {
        return $this->frames ? 'frame-src '.implode(' ', array_keys($this->frames)) : null;
    }
}
