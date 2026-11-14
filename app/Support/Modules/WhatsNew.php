<?php

namespace App\Support\Modules;

/** Reads the section of CHANGELOG.md that belongs to a version. Output is plain data; the view escapes it. */
class WhatsNew
{
    /** @return array{title:string,date:?string,groups:array<int,array{heading:?string,items:array<int,string>}>}|null */
    public static function section(string $file, string $version): ?array
    {
        if (!is_file($file) || filesize($file) > 2_000_000) return null;
        $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];
        $sections = []; $cur = null;
        foreach ($lines as $line) {
            if (preg_match('/^##\s+\[?([^\]\s]+)\]?\s*(?:[-—–]\s*(\S.*))?$/u', $line, $m)) { $cur = count($sections); $sections[] = ['version' => $m[1], 'date' => $m[2] ?? null, 'lines' => []]; continue; }
            if ($cur !== null) $sections[$cur]['lines'][] = $line;
        }
        if (!$sections) return null;
        $pick = null;
        foreach ($sections as $s) if ($s['version'] === $version) { $pick = $s; break; }
        // dev builds (0.6.0-dev) and unreleased: fall back to the newest section so the page is never empty
        $pick ??= $sections[0];
        $groups = [['heading' => null, 'items' => []]];
        foreach ($pick['lines'] as $line) {
            if (preg_match('/^###\s+(.+)$/u', $line, $m)) { $groups[] = ['heading' => trim($m[1]), 'items' => []]; continue; }
            if (preg_match('/^\s*[-*]\s+(.+)$/u', $line, $m)) { $groups[array_key_last($groups)]['items'][] = trim($m[1]); continue; }
            if (trim($line) !== '' && $groups[array_key_last($groups)]['items']) $groups[array_key_last($groups)]['items'][count($groups[array_key_last($groups)]['items']) - 1] .= ' '.trim($line);
        }
        $groups = array_values(array_filter($groups, fn ($g) => $g['items']));
        return ['title' => $pick['version'], 'date' => $pick['date'] !== null ? trim($pick['date']) : null, 'groups' => $groups, 'exact' => $pick['version'] === $version];
    }

    /** Escape then turn `code` spans into <code>. Safe for {!! !!}. */
    public static function inline(string $text): string
    {
        return preg_replace('/`([^`]+)`/u', '<code>$1</code>', e($text)) ?? e($text);
    }
}
