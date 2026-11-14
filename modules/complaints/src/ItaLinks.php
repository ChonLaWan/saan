<?php

namespace Saan\Modules\Complaints;

use App\Support\Modules\ModuleRegistry;

/**
 * ITA O13 (channel for reporting corruption and misconduct) and O14 (annual statistics of such complaints): the web-ita public page asks this class for
 * links to put under a topic whose code is O13 / O14, or that holds an item whose title starts with that code. Nothing is hard-coded to a school:
 * the targets are this site's own relative URLs and the newest PUBLISHED annual report.
 */
final class ItaLinks
{
    /** @return list<array{title:string,url:string}> */
    public static function forTopic(array $topic, ?int $fiscalYear = null): array
    {
        // With an ITA fiscal year the O14 report links point at THAT year's published report (none when it is not published yet); without one, the newest.
        if (!app(ModuleRegistry::class)->active('complaints')) return [];
        $codes = [self::code((string) ($topic['code'] ?? ''))];
        foreach ((array) ($topic['items'] ?? []) as $i) $codes[] = self::code((string) ($i['title'] ?? ''), true);
        $has = fn (string $c) => in_array($c, $codes, true);
        $out = [];
        if ($has('O13') || $has('O14')) {
            $out[] = ['title' => 'ช่องทางร้องเรียนและแจ้งเรื่อง (แบบฟอร์มออนไลน์)', 'url' => url('/portal/complaints')];
        }
        if ($has('O13')) $out[] = ['title' => 'ตรวจสอบสถานะเรื่องที่แจ้งไว้', 'url' => url('/portal/complaints/status')];
        if ($has('O14')) {
            $latest = $fiscalYear !== null ? Reports::find($fiscalYear) : Reports::latest();
            if ($latest) {
                $out[] = ['title' => 'รายงานสรุปเรื่องร้องเรียนปีงบประมาณ '.$latest['fiscal_year'], 'url' => url('/portal/complaints/reports/'.$latest['fiscal_year'])];
                $out[] = ['title' => 'ดาวน์โหลดรายงานปีงบประมาณ '.$latest['fiscal_year'].' (CSV)', 'url' => url('/portal/complaints/reports/'.$latest['fiscal_year'].'.csv')];
            }
        }
        return $out;
    }

    /** "O13", "o 13", "O-13 ช่องทาง..." -> O13 (item titles only count when they START with the code). */
    private static function code(string $text, bool $prefix = false): string
    {
        $t = trim($text);
        if ($prefix) return preg_match('/^O\s*-?\s*(13|14)(?!\d)/iu', $t, $m) ? 'O'.$m[1] : '';
        $n = strtoupper((string) preg_replace('/[\s-]+/', '', $t));
        return in_array($n, ['O13', 'O14'], true) ? $n : '';
    }
}
