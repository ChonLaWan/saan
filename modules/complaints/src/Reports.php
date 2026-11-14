<?php

namespace Saan\Modules\Complaints;

use App\Support\Saan\Edition;
use App\Support\Saan\Mutation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Annual report snapshots: the staff list, publish / unpublish, and what the public side shows (only published, counts only). */
final class Reports
{
    /** Staff list: every fiscal year with its live counts and whether a snapshot is published. */
    public static function staffList(?object $user = null): array
    {
        $published = DB::table('cmp_reports')->where('school_id', Complaints::school())->get()->keyBy('fiscal_year');
        $out = [];
        foreach (Complaints::fiscalYears($user) as $fy) {
            $r = Complaints::report($fy, $user);
            $p = $published[$fy] ?? null;
            $out[] = ['fiscal_year' => $fy, 'label' => Complaints::fiscalLabel($fy), 'total' => $r['totals']['total'], 'status' => $p->status ?? 'none', 'published_at' => $p->published_at ?? null, 'version' => $p ? (int) $p->version : null];
        }
        return $out;
    }

    public static function publish(int $fy, string $action, ?object $user = null): array
    {
        if ($fy < 2560 || $fy > 2700) throw ValidationException::withMessages(['fiscal_year' => ['ปีงบประมาณไม่ถูกต้อง']]);
        $row = DB::table('cmp_reports')->where('school_id', Complaints::school())->where('fiscal_year', $fy)->first();
        if ($action === 'unpublish') {
            if (!$row) abort(404, 'ยังไม่มีรายงานปีนี้');
            Mutation::update('cmp_reports', (int) $row->id, (int) $row->version, ['status' => 'draft', 'published_at' => null], 'complaints.report_unpublished', ['fiscal_year' => $fy]);
        } else {
            // who may publish never depends on cmp_accused: a refusal only for the accused would itself tell them a case names them. The answer they get back is their own
            // scoped row; the whole-school figures become public on the report page anyway (that is what publishing is), and every publish is an audited action.
            // the snapshot is frozen at the moment of publishing: later cases never change a report that was shown as the year's figures
            $snap = json_encode(Complaints::report($fy), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($row) Mutation::update('cmp_reports', (int) $row->id, (int) $row->version, ['snapshot' => $snap, 'status' => 'published', 'published_at' => now()], 'complaints.report_published', ['fiscal_year' => $fy]);
            else Mutation::insert('cmp_reports', ['fiscal_year' => $fy, 'snapshot' => $snap, 'status' => 'published', 'published_at' => now()], 'complaints.report_published', ['fiscal_year' => $fy]);
        }
        return collect(self::staffList($user))->firstWhere('fiscal_year', $fy) ?? [];
    }

    /** Rows for the publisher (pub_complaint_reports): published reports, snapshot rebuilt from whitelisted numbers. */
    public static function projectedRows(): array
    {
        $out = [];
        foreach (DB::table('cmp_reports')->where('school_id', Complaints::school())->where('status', 'published')->orderBy('fiscal_year')->get() as $r) {
            $clean = Complaints::cleanSnapshot((string) $r->snapshot);
            if ($clean === null || (int) $clean['fiscal_year'] !== (int) $r->fiscal_year) continue;
            $out[] = ['fiscal_year' => (string) $r->fiscal_year, 'snapshot' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'published_at' => $r->published_at];
        }
        return $out;
    }

    /** Published reports as seen by the page of THIS edition (public edition: the projected table, otherwise the internal one). @return list<array{fiscal_year:int,report:array,published_at:?string}> newest first */
    public static function published(): array
    {
        if (Edition::isPublic()) {
            $rows = DB::table('pub_complaint_reports')->get(['fiscal_year', 'snapshot', 'published_at'])->map(fn ($r) => (array) $r)->all();
        } else {
            $rows = self::projectedRows();
        }
        $out = [];
        foreach ($rows as $r) {
            $clean = Complaints::cleanSnapshot((string) $r['snapshot']);
            if ($clean !== null && (int) $clean['fiscal_year'] === (int) $r['fiscal_year']) $out[] = ['fiscal_year' => (int) $r['fiscal_year'], 'report' => $clean, 'published_at' => $r['published_at']];
        }
        usort($out, fn ($a, $b) => $b['fiscal_year'] <=> $a['fiscal_year']);
        return $out;
    }

    public static function latest(): ?array
    {
        return self::published()[0] ?? null;
    }

    public static function find(int $fy): ?array
    {
        foreach (self::published() as $p) if ($p['fiscal_year'] === $fy) return $p;
        return null;
    }
}
