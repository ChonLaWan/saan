<?php

namespace App\Support;

final class UiIcon
{
    private static ?array $paths = null;

    private static function paths(): array
    {
        return self::$paths ??= json_decode(
            file_get_contents(public_path('assets/ui-icons.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public static function name(string $name): string
    {
        return array_key_exists($name, self::paths()) ? $name : 'arrow-right';
    }

    public static function path(string $name): string
    {
        return self::paths()[self::name($name)];
    }
}
