<?php

namespace Saan\Modules\WebIta;

/**
 * The O1..O18 open-data items this module works with. A topic whose code is "O4" (or an item whose title starts with "O4") IS item O4.
 * The titles are neutral working names: the school renames topics to the wording of the ITA announcement of its year, the code is what matters.
 * 'source' is the automatic source an item of that O gets in the ready-made set (null = the school attaches a link, a file or a text itself).
 */
final class ItaCatalog
{
    public const ITEMS = [
        'O1' => ['title' => 'โครงสร้างหน่วยงาน', 'source' => null],
        'O2' => ['title' => 'ข้อมูลผู้บริหาร', 'source' => 'directory_executives'],
        'O3' => ['title' => 'อำนาจหน้าที่และภารกิจ', 'source' => null],
        'O4' => ['title' => 'ข่าวประชาสัมพันธ์', 'source' => 'news'],
        'O5' => ['title' => 'ข้อมูลการติดต่อ', 'source' => 'contact'],
        'O6' => ['title' => 'กฎหมายและระเบียบที่เกี่ยวข้อง', 'source' => null],
        'O7' => ['title' => 'ถาม-ตอบ (Q&A)', 'source' => null],
        'O8' => ['title' => 'ช่องทางสื่อสังคมออนไลน์', 'source' => null],
        // O9 (annual plan) has no stage in the procurement posts yet: the school attaches the plan itself. O10 is the announced outcome (award and contract).
        'O9' => ['title' => 'แผนการจัดซื้อจัดจ้างประจำปี', 'source' => null],
        'O10' => ['title' => 'สรุปผลการจัดซื้อจัดจ้าง', 'source' => 'procurement', 'source_opts' => ['stage' => 'results']],
        'O11' => ['title' => 'แผนปฏิบัติการประจำปี', 'source' => null],
        'O12' => ['title' => 'รายงานผลการดำเนินงานประจำปี', 'source' => null],
        'O13' => ['title' => 'คู่มือและช่องทางการรับเรื่องร้องเรียน', 'source' => 'complaints_form'],
        'O14' => ['title' => 'ช่องทางรับเรื่องร้องเรียนและรายงานสรุป', 'source' => 'complaints_channel'],
        'O15' => ['title' => 'มาตรการป้องกันการทุจริต', 'source' => null],
        'O16' => ['title' => 'ข้อมูลการบริหารงบประมาณ', 'source' => 'finance_report'], // monthly trial balances of the fiscal year; without such a page it falls back to the "งบประมาณ" group page (source hub)
        'O17' => ['title' => 'การบริหารและพัฒนาบุคลากร', 'source' => null],
        'O18' => ['title' => 'ผลการประเมินคุณธรรมและความโปร่งใส', 'source' => null],
    ];

    /** "O4", "o 4", "O-04" -> "O4"; anything else (including O19, O130) -> ''. */
    public static function code(string $text): string
    {
        return preg_match('/^O\s*-?\s*0*(\d{1,2})$/iu', trim($text), $m) && isset(self::ITEMS['O'.(int) $m[1]]) ? 'O'.(int) $m[1] : '';
    }

    /** Same, for an item title: only counts when the title STARTS with the code ("O13 แบบฟอร์ม..."). */
    public static function codeOfTitle(string $title): string
    {
        return preg_match('/^O\s*-?\s*0*(\d{1,2})(?!\d)/iu', trim($title), $m) && isset(self::ITEMS['O'.(int) $m[1]]) ? 'O'.(int) $m[1] : '';
    }

    /** Topics (as arrays with 'code', 'items') of one year grouped by the O code they stand for: a topic without an O code lends its items to the code their own title starts with. @return array{items:array<string,list<array>>,seen:array<string,bool>} items per code, and whether a topic (or titled item) for the code exists */
    public static function group(array $topics): array
    {
        $out = array_fill_keys(array_keys(self::ITEMS), []);
        $seen = array_fill_keys(array_keys(self::ITEMS), false);
        foreach ($topics as $t) {
            $topicCode = self::code((string) ($t['code'] ?? ''));
            if ($topicCode !== '') $seen[$topicCode] = true;
            foreach ((array) ($t['items'] ?? []) as $i) {
                $c = $topicCode !== '' ? $topicCode : self::codeOfTitle((string) ($i['title'] ?? ''));
                if ($c !== '') { $out[$c][] = $i; $seen[$c] = true; }
            }
        }
        return ['items' => $out, 'seen' => $seen];
    }

    /** Topics of the ready-made set: one topic per O code, one item each, with the default automatic source. */
    public static function preset(): array
    {
        $out = [];
        foreach (self::ITEMS as $code => $d) {
            $out[] = ['code' => $code, 'title' => $d['title'], 'items' => [['title' => $d['title'], 'url' => null, 'media_id' => null, 'note' => '', 'source' => $d['source'], 'source_opts' => $d['source_opts'] ?? null]]];
        }
        return $out;
    }
}
