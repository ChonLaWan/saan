<?php

namespace Saan\Modules\WebCms;

use App\Support\Fiscal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Demo content for the group hub pages (opt-in: only php artisan cms:demo, which already requires school.demo and local mode): one association page
 * with a made-up committee and a member list PDF, and one monthly trial-balance page with three sample PDFs for the latest closed months.
 * Everything is fictional (demo school "โรงเรียนต้นธารวิทยา"). Existing pages are never touched: a slug that is taken is skipped. Call while an admin is signed in.
 */
final class HubDemo
{
    public const ALUMNI = 'demo-alumni';
    public const FINANCE = 'demo-finance';

    /** @return array{created:list<string>,skipped:list<string>} */
    public static function seed(): array
    {
        $out = ['created' => [], 'skipped' => []];
        $taken = fn (string $slug) => DB::table('cms_hubs')->where('school_id', Hubs::school())->where('slug', $slug)->exists();

        if ($taken(self::ALUMNI)) $out['skipped'][] = self::ALUMNI;
        else {
            $hub = HubAdmin::createFromTemplate(['template' => 'alumni', 'slug' => self::ALUMNI, 'title' => 'สมาคมศิษย์เก่าโรงเรียนต้นธารวิทยา (สมมติ)', 'status' => 'published',
                'description' => 'หน้าสมาคมตัวอย่างสำหรับทดลองระบบ ข้อมูลทั้งหมดเป็นข้อมูลสมมติ ไม่เกี่ยวข้องกับสมาคมจริง',
                'contact' => "โทร 000-000-0000 (สมมติ)\nอีเมล alumni@example.org (สมมติ)",
                'committee' => [['name' => 'นายสมชาย ตัวอย่างดี (สมมติ)', 'role' => 'นายกสมาคม'], ['name' => 'นางสาวสมหญิง ทดสอบ (สมมติ)', 'role' => 'เลขานุการ'], ['name' => 'นายธนา สาธิต (สมมติ)', 'role' => 'เหรัญญิก']]]);
            $list = DB::table('cms_hub_categories')->where('hub_id', $hub->id)->where('rule', 'pdf')->value('id');
            HubAdmin::addDocuments($hub, ['category_id' => $list, 'title' => 'รายชื่อคณะกรรมการสมาคม (ตัวอย่าง)'], [self::pdf('members-sample.pdf', 'Sample member list (fictional)')]);
            $out['created'][] = self::ALUMNI;
        }

        if ($taken(self::FINANCE)) $out['skipped'][] = self::FINANCE;
        else {
            $hub = HubAdmin::createFromTemplate(['template' => 'trial_balance', 'slug' => self::FINANCE, 'title' => 'รายงานการเงิน โรงเรียนต้นธารวิทยา (สมมติ)', 'status' => 'published',
                'description' => 'งบทดลองรายเดือนตัวอย่างสำหรับทดลองระบบ ตัวเลขในไฟล์เป็นข้อมูลสมมติ']);
            $cat = DB::table('cms_hub_categories')->where('hub_id', $hub->id)->where('rule', 'monthly')->value('id');
            [$y, $m] = Fiscal::today();
            for ($k = 1; $k <= 3; $k++) {
                $mm = $m - $k;
                $yy = $y;
                if ($mm < 1) { $mm += 12; $yy--; }
                HubAdmin::addDocuments($hub, ['category_id' => $cat, 'period_month' => $mm, 'period_year' => $yy],
                    [self::pdf("trial-balance-$yy-$mm.pdf", 'Sample trial balance '.$mm.'/'.$yy.' (fictional)')]);
            }
            $out['created'][] = self::FINANCE;
        }
        return $out;
    }

    /** A tiny valid one-page PDF with a line of ASCII text, as an upload. */
    private static function pdf(string $name, string $text): UploadedFile
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 18 Tf 72 720 Td ($text) Tj ET";
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)."  >>\nstream\n$stream\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf = "%PDF-1.4\n";
        $at = [];
        foreach ($objs as $i => $o) { $at[] = strlen($pdf); $pdf .= ($i + 1)." 0 obj\n$o\nendobj\n"; }
        $x = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($at as $off) $pdf .= sprintf("%010d 00000 n \n", $off);
        $pdf .= "trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
        $tmp = tempnam(sys_get_temp_dir(), 'hdm');
        file_put_contents($tmp, $pdf);
        register_shutdown_function(static fn () => @unlink($tmp)); // the stored copy lives in the media library; do not leave the temp file behind
        return new UploadedFile($tmp, $name, 'application/pdf', null, true);
    }
}
