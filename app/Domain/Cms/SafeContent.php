<?php

namespace App\Domain\Cms;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class SafeContent
{
    // 50,000 validated characters can be up to 200,000 bytes (UTF-8, Thai is 3 bytes). Keep a margin and never truncate silently.
    public const MAX_INPUT_BYTES = 262144;

    public static function html(string $input): string
    {
        if (strlen($input) > self::MAX_INPUT_BYTES) throw new \LengthException('เนื้อหายาวเกินที่ระบบรองรับ');
        $config = (new HtmlSanitizerConfig())->withMaxInputLength(self::MAX_INPUT_BYTES)
            ->allowLinkSchemes(['https','mailto','tel'])->allowRelativeLinks()
            ->forceAttribute('a','rel','noopener noreferrer');
        foreach (['p','br','strong','b','em','i','u','ul','ol','li','h2','h3','blockquote','hr','table','thead','tbody','tr','th','td'] as $element) $config=$config->allowElement($element);
        $config=$config->allowElement('a',['href','title'])->dropElement('script')->dropElement('style')->dropElement('iframe')->dropElement('object');
        return (new HtmlSanitizer($config))->sanitize($input);
    }

    public static function url(string $url): bool
    {
        return (bool)preg_match('/^#[A-Za-z][\w-]*$/D',$url)
            || (bool)preg_match('~^/(?!/)[^\s\\\\]*$~D',$url)
            || (str_starts_with($url,'https://') && filter_var($url,FILTER_VALIDATE_URL));
    }
}
