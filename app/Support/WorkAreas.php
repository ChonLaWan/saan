<?php

namespace App\Support;

/**
 * Display grouping of the /work hub and the sidebar glyph of every work module.
 * Modules only declare one of twelve generic icons in module.json; the hub shows a more specific glyph from the shared icon family
 * (public/assets/ui-icons.json) and groups the tiles by area of school work. A module that is not listed lands in "system" with its generic icon.
 */
final class WorkAreas
{
    /** Areas in display order: id => Thai label. */
    public const AREAS = [
        'academic' => 'งานวิชาการ',
        'student' => 'กิจการนักเรียน',
        'personnel' => 'บุคลากร',
        'finance' => 'การเงินและพัสดุ',
        'documents' => 'สารบรรณและเว็บไซต์',
        'system' => 'ตั้งค่าและเชื่อมต่อ',
    ];

    /** Work module key => [area, glyph]. */
    private const MODULES = [
        'setup' => ['system', 'settings'], 'calendar' => ['academic', 'calendar'], 'timetable' => ['academic', 'calendar'],
        'courses' => ['academic', 'book'], 'scores' => ['academic', 'edit'], 'reports' => ['academic', 'document'],
        'exams' => ['academic', 'clipboard'], 'learning' => ['academic', 'globe'], 'pbl' => ['academic', 'palette'],
        'students' => ['student', 'graduation'], 'import' => ['student', 'upload'], 'attendance' => ['student', 'check'],
        'attendance-report' => ['student', 'chart'], 'behavior' => ['student', 'award'], 'health' => ['student', 'heart'],
        'clubs' => ['student', 'star'], 'elections' => ['student', 'flag'], 'guardians' => ['student', 'people'],
        'student-accounts' => ['student', 'lock'], 'student-pins' => ['student', 'lock'], 'complaints' => ['documents', 'shield'],
        'personnel' => ['personnel', 'person'], 'portfolio' => ['personnel', 'folder'], 'leave' => ['personnel', 'mail'],
        'leave-approve' => ['personnel', 'check'], 'duty' => ['personnel', 'clock'], 'payslip' => ['personnel', 'wallet'],
        'finance' => ['finance', 'wallet'], 'repairs' => ['finance', 'wrench'], 'assets' => ['finance', 'building'], 'projects' => ['finance', 'flag'],
        'documents' => ['documents', 'document'], 'inbox' => ['documents', 'inbox'], 'hubs' => ['documents', 'web'],
        'surveys' => ['documents', 'clipboard'], 'ita' => ['documents', 'shield'], 'notices' => ['documents', 'megaphone'], 'social' => ['documents', 'globe'],
        'api' => ['system', 'link'], 'archives' => ['system', 'folder'],
    ];

    /** Sidebar glyphs of the website (CMS) and account entries, by navigation key. */
    private const NAV = [
        'cms-home' => 'home', 'compose' => 'edit', 'posts' => 'megaphone', 'theme' => 'palette', 'branding' => 'palette', 'header' => 'web',
        'hero-design' => 'image', 'slides' => 'image', 'observance' => 'flag', 'pages' => 'document', 'jobs' => 'briefcase',
        'procurement' => 'clipboard', 'albums' => 'image', 'downloads' => 'download', 'videos' => 'video', 'newsletters' => 'mail',
        'website-people' => 'people', 'events' => 'calendar', 'banners' => 'megaphone', 'services' => 'link', 'profile' => 'building',
        'identity' => 'palette', 'mail' => 'mail', 'redirects' => 'link', 'homepage' => 'home', 'menus' => 'menu', 'media' => 'image',
        'categories' => 'folder', 'inbox' => 'inbox', 'audit' => 'history', 'trash' => 'trash', 'users' => 'people', 'deployment' => 'shield',
        'account' => 'person', 'work' => 'layers', 'overview' => 'grid', 'timetable' => 'calendar', 'duty' => 'clock', 'workload' => 'people',
        'approvals' => 'check', 'requests' => 'check', 'care' => 'heart', 'assessment' => 'chart', 'people' => 'calendar',
    ];

    /** Glyph of a sidebar item: work modules by module key, website and account entries by navigation key, else the item's own icon. */
    public static function navGlyph(string $navKey, string $fallback): string
    {
        if (str_starts_with($navKey, 'work-')) return self::glyph(substr($navKey, 5), $fallback);
        return self::NAV[$navKey] ?? $fallback;
    }

    public static function area(string $key): string
    {
        return self::MODULES[$key][0] ?? 'system';
    }

    /** Glyph name of the shared icon family for a module key; $fallback is the generic icon from module.json. */
    public static function glyph(string $key, string $fallback = 'grid'): string
    {
        return self::MODULES[$key][1] ?? $fallback;
    }
}
