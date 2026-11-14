<?php

namespace Saan\Modules\Complaints;

use App\Support\Saan\HashKey;
use App\Support\Saan\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Constants and pure helpers of the complaints module: labels, receipt numbers, tracking codes and their hash, SLA, Thai fiscal year and the annual report. */
final class Complaints
{
    public const CATEGORIES = [
        'general' => 'เรื่องร้องเรียนทั่วไป',
        'corruption' => 'ทุจริตและประพฤติมิชอบ',
        'suggestion' => 'ข้อเสนอแนะ',
        'info_request' => 'ขอข้อมูลข่าวสาร',
    ];
    public const STATUSES = [
        'received' => 'รับเรื่องแล้ว',
        'in_progress' => 'อยู่ระหว่างดำเนินการ',
        'replied' => 'ตอบกลับแล้ว',
        'closed' => 'ปิดเรื่อง',
    ];
    /** Unambiguous: no 0/O, 1/I. 32 symbols = 5 bits each, so a 12 character tracking code carries 60 bits. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const RECEIPT = '/^C\d{2}-[A-HJ-NP-Z2-9]{8}$/D';
    public const CODE = '/^[A-HJ-NP-Z2-9]{10,24}$/D';
    public const CODE_LENGTH = 12;
    public const SLA_DEFAULT = ['general' => 15, 'corruption' => 30, 'suggestion' => 15, 'info_request' => 15];
    public const MAX_DETAIL = 4000;

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    public static function today(): string
    {
        return CarbonImmutable::now('Asia/Bangkok')->toDateString();
    }

    // ---------------------------------------------------------------- receipt number and tracking code (made by the public form)

    private static function random(int $n): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $n; $i++) $out .= self::ALPHABET[random_int(0, $max)];
        return $out;
    }

    public static function newReceipt(): string
    {
        return 'C'.substr((string) (CarbonImmutable::now('Asia/Bangkok')->year + 543), -2).'-'.self::random(8);
    }

    public static function newTrackingCode(): string
    {
        return self::random(self::CODE_LENGTH);
    }

    /**
     * Receipt number and tracking code of a form that carries a nonce: derived (HMAC with the unlock key, over the nonce and the digest of what was typed), so a double click or a
     * refresh-and-resend of the SAME form gets the same receipt and code and the internal side recognises it as the same case, while changed text gets new ones.
     * @return array{0:string,1:string} receipt, code
     */
    public static function deriveIds(string $nonce, string $content): array
    {
        $b = hash_hmac('sha256', 'saan-complaint-ids-v1|'.$nonce.'|'.hash('sha256', $content), HashKey::publicUnlock(), true);
        $pick = function (int $from, int $n) use ($b): string {
            $o = '';
            for ($i = 0; $i < $n; $i++) $o .= self::ALPHABET[ord($b[$from + $i]) & 31];
            return $o;
        };
        return ['C'.substr((string) (CarbonImmutable::now('Asia/Bangkok')->year + 543), -2).'-'.$pick(0, 8), $pick(8, self::CODE_LENGTH)];
    }

    /** Upper case, only letters and digits: people type the code with spaces or dashes. */
    public static function normalizeCode(mixed $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', is_scalar($code) ? (string) $code : ''));
    }

    public static function normalizeReceipt(mixed $receipt): string
    {
        return strtoupper(trim(is_scalar($receipt) ? (string) $receipt : ''));
    }

    public static function formatCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    /** HMAC of receipt + tracking code with the key that both editions share for unlock codes. The code itself is never stored anywhere. */
    public static function trackHash(string $receipt, string $code, ?string $key = null): string
    {
        return hash_hmac('sha256', 'saan-complaint-track-v1|'.$receipt.'|'.self::normalizeCode($code), $key ?? HashKey::publicUnlock());
    }

    /**
     * True when $code matches $stored under the unlock key in force or one retired by a rotation (the plain code is never kept, so old
     * hashes cannot be re-keyed). Every candidate is always computed and compared: the time does not depend on which key matched.
     */
    public static function trackMatches(string $stored, string $receipt, string $code): bool
    {
        $ok = false;
        foreach (HashKey::publicUnlockHistory() as $k) $ok = hash_equals($stored, self::trackHash($receipt, $code, $k)) || $ok;
        return $ok;
    }

    // ---------------------------------------------------------------- SLA

    public static function slaDays(string $category): int
    {
        $d = self::SLA_DEFAULT[$category] ?? 15;
        $v = Settings::get('complaints.sla_'.$category, $d);
        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 365 ? (int) $v : $d;
    }

    public static function dueDate(string $category, ?CarbonImmutable $from = null): string
    {
        return ($from ?? CarbonImmutable::now('Asia/Bangkok'))->setTimezone('Asia/Bangkok')->addDays(self::slaDays($category))->toDateString();
    }

    /** Overdue = still open, not yet answered to the complainant (public_reply_at) and past the due date: a case answered in time is not late for staying open. */
    public static function isOverdue(object|array $case): bool
    {
        $c = (object) $case;
        return ($c->status ?? '') !== 'closed' && empty($c->public_reply_at) && !empty($c->due_on) && substr((string) $c->due_on, 0, 10) < self::today();
    }

    // ---------------------------------------------------------------- Thai fiscal year (1 Oct - 30 Sep, Buddhist era label)

    public static function fiscalYear(\DateTimeInterface $d): int
    {
        $d = CarbonImmutable::instance($d)->setTimezone('Asia/Bangkok');
        return (int) $d->year + ((int) $d->month >= 10 ? 1 : 0) + 543;
    }

    public static function currentFiscalYear(): int
    {
        return self::fiscalYear(CarbonImmutable::now('Asia/Bangkok'));
    }

    /** @return array{0:string,1:string} start and end of the fiscal year as Y-m-d H:i:s in the application time zone */
    public static function fiscalRange(int $fy): array
    {
        $ce = $fy - 543;
        $from = CarbonImmutable::create($ce - 1, 10, 1, 0, 0, 0, 'Asia/Bangkok')->setTimezone(config('app.timezone'));
        $to = CarbonImmutable::create($ce, 9, 30, 23, 59, 59, 'Asia/Bangkok')->setTimezone(config('app.timezone'));
        return [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
    }

    public static function fiscalLabel(int $fy): string
    {
        return 'ปีงบประมาณ พ.ศ. '.$fy.' (1 ตุลาคม '.($fy - 1).' ถึง 30 กันยายน '.$fy.')';
    }

    // ---------------------------------------------------------------- annual report (counts only)

    /** Fiscal years that have cases, plus the current one, newest first. @return list<int> */
    public static function fiscalYears(?object $user = null): array
    {
        $years = [self::currentFiscalYear()];
        foreach (($user ? Access::cases($user) : DB::table('cmp_cases')->where('school_id', self::school()))->pluck('received_at') as $at) $years[] = self::fiscalYear(CarbonImmutable::parse((string) $at, config('app.timezone')));
        foreach (DB::table('cmp_reports')->where('school_id', self::school())->pluck('fiscal_year') as $y) $years[] = (int) $y;
        $years = array_values(array_unique($years));
        rsort($years);
        return $years;
    }

    /**
     * Counts by category and status and the average handling time of closed cases for one fiscal year (cases counted in the year they were received).
     * Aggregates only: the result holds no name, contact, subject, detail, assignee or accused. It counts every case on purpose (it is a statistic of the school,
     * not a view of a case).
     */
    public static function report(int $fy, ?object $user = null): array
    {
        [$from, $to] = self::fiscalRange($fy);
        // a signed-in user gets counts of the cases he may see only (Access::scope: category, executive list, accused); null = the whole school, used for the frozen snapshot only
        $base = $user ? Access::cases($user) : DB::table('cmp_cases')->where('school_id', self::school());
        $rows = $base->whereBetween('cmp_cases.received_at', [$from, $to])->get(['category', 'status', 'received_at', 'closed_at']);
        $zero = array_fill_keys(array_keys(self::STATUSES), 0);
        $out = [];
        foreach (self::CATEGORIES as $key => $label) $out[$key] = ['label' => $label, 'total' => 0, 'by_status' => $zero, 'closed' => 0, 'avg_days' => null, '_sum' => 0.0];
        foreach ($rows as $r) {
            if (!isset($out[$r->category])) continue;
            $o = &$out[$r->category];
            $o['total']++;
            if (isset($o['by_status'][$r->status])) $o['by_status'][$r->status]++;
            if ($r->status === 'closed' && $r->closed_at) {
                $o['closed']++;
                $o['_sum'] += max(0, (strtotime((string) $r->closed_at) - strtotime((string) $r->received_at)) / 86400);
            }
            unset($o);
        }
        $tot = ['label' => 'รวมทุกประเภท', 'total' => 0, 'by_status' => $zero, 'closed' => 0, 'avg_days' => null];
        $sumAll = 0.0;
        foreach ($out as $k => $o) {
            $out[$k]['avg_days'] = $o['closed'] > 0 ? round($o['_sum'] / $o['closed'], 1) : null;
            $tot['total'] += $o['total'];
            $tot['closed'] += $o['closed'];
            $sumAll += $o['_sum'];
            foreach ($o['by_status'] as $s => $n) $tot['by_status'][$s] += $n;
            unset($out[$k]['_sum']);
        }
        $tot['avg_days'] = $tot['closed'] > 0 ? round($sumAll / $tot['closed'], 1) : null;
        return ['fiscal_year' => $fy, 'label' => self::fiscalLabel($fy), 'rows' => $out, 'totals' => $tot, 'generated_at' => now()->toDateTimeString()];
    }

    /**
     * Rebuilds a snapshot from whitelisted keys and numbers only (labels come from the constants, never from the stored text), so that a stored or received
     * snapshot can never carry a stray string to the public page.
     */
    public static function cleanSnapshot(array|string|null $snapshot): ?array
    {
        $s = is_string($snapshot) ? json_decode($snapshot, true, 16) : $snapshot;
        if (!is_array($s) || !isset($s['fiscal_year']) || !is_numeric($s['fiscal_year'])) return null;
        $fy = (int) $s['fiscal_year'];
        $clean = function ($row, string $label): array {
            $row = is_array($row) ? $row : [];
            $by = [];
            foreach (self::STATUSES as $st => $_) $by[$st] = max(0, (int) ($row['by_status'][$st] ?? 0));
            $avg = $row['avg_days'] ?? null;
            return ['label' => $label, 'total' => max(0, (int) ($row['total'] ?? 0)), 'by_status' => $by, 'closed' => max(0, (int) ($row['closed'] ?? 0)),
                'avg_days' => is_numeric($avg) ? round((float) $avg, 1) : null];
        };
        $rows = [];
        foreach (self::CATEGORIES as $k => $label) $rows[$k] = $clean($s['rows'][$k] ?? [], $label);
        $generated = is_string($s['generated_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $s['generated_at']) ? substr($s['generated_at'], 0, 19) : null;
        return ['fiscal_year' => $fy, 'label' => self::fiscalLabel($fy), 'rows' => $rows, 'totals' => $clean($s['totals'] ?? [], 'รวมทุกประเภท'), 'generated_at' => $generated];
    }

    /** UTF-8 CSV with a BOM (Excel). Only labels from the constants and numbers: nothing from a complaint can reach a cell. */
    public static function reportCsv(array $report): string
    {
        $lines = [['รายงานสรุปเรื่องร้องเรียนและรับเรื่อง'], [$report['label']], []];
        $head = ['ประเภท', 'จำนวนทั้งหมด'];
        foreach (self::STATUSES as $label) $head[] = $label;
        $head[] = 'ปิดเรื่องแล้ว';
        $head[] = 'เวลาดำเนินการเฉลี่ย (วัน)';
        $lines[] = $head;
        foreach (array_merge(array_values($report['rows']), [$report['totals']]) as $r) {
            $line = [$r['label'], $r['total']];
            foreach (array_keys(self::STATUSES) as $s) $line[] = $r['by_status'][$s];
            $line[] = $r['closed'];
            $line[] = $r['avg_days'] === null ? '' : number_format((float) $r['avg_days'], 1, '.', '');
            $lines[] = $line;
        }
        $fh = fopen('php://temp', 'r+');
        foreach ($lines as $l) fputcsv($fh, $l, ',', '"', '\\', "\r\n");
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF".$csv;
    }
}
