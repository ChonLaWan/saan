<?php

namespace Saan\Modules\Complaints\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Saan\Modules\Complaints\Access;
use Saan\Modules\Complaints\Cases;
use Saan\Modules\Complaints\Complaints;
use Saan\Modules\Complaints\Reports;

/** /api/complaints/*: staff side. Row access is decided in Saan\Modules\Complaints\Access (officers, executives, accused); the route only needs a signed-in staff account. */
class CaseController
{
    private function user(Request $r): object
    {
        $u = $r->user() ?? abort(401);
        abort_unless(Access::hasAny($u), 403, 'บัญชีนี้ไม่ได้รับสิทธิ์ดูแลเรื่องร้องเรียน');
        return $u;
    }

    public function meta(Request $r)
    {
        $u = $r->user() ?? abort(401);
        abort_unless(Access::hasAny($u), 403, 'บัญชีนี้ไม่ได้รับสิทธิ์ดูแลเรื่องร้องเรียน');
        return response()->json([
            'categories' => Complaints::CATEGORIES, 'statuses' => Complaints::STATUSES,
            'is_officer' => Access::isOfficer($u), 'is_executive' => Access::isExecutive($u),
            'sla' => array_combine(array_keys(Complaints::CATEGORIES), array_map(fn ($c) => Complaints::slaDays($c), array_keys(Complaints::CATEGORIES))),
            'warnings' => Access::executiveIds() ? [] : ['ยังไม่ได้ตั้งรายชื่อผู้บริหารที่เห็นเรื่องทุจริตและประพฤติมิชอบ (ตั้งค่าโมดูล) ตอนนี้ไม่มีใครเห็นเรื่องกลุ่มนี้'],
            'today' => Complaints::today(), 'fiscal_year' => Complaints::currentFiscalYear(),
        ]);
    }

    private function filters(Request $r): array
    {
        return $r->validate(['category' => 'nullable|string|max:20', 'status' => 'nullable|string|max:20', 'overdue' => 'nullable|boolean', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:10000']);
    }

    public function index(Request $r)
    {
        return response()->json(Cases::list($this->user($r), $this->filters($r)))->header('Cache-Control', 'no-store');
    }

    public function export(Request $r)
    {
        return response(Cases::exportCsv($this->user($r), $this->filters($r)), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="complaints-list.csv"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function show(Request $r, int $id)
    {
        $u = $this->user($r);
        $out = Cases::show($u, $id);
        Cases::viewed($u, $id);
        return response()->json($out)->header('Cache-Control', 'no-store');
    }

    public function assign(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'assignee_id' => 'nullable|integer|min:1']);
        return response()->json(Cases::assign($this->user($r), $id, (int) $d['version'], isset($d['assignee_id']) ? (int) $d['assignee_id'] : null));
    }

    public function due(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'due_on' => 'required|string|max:10']);
        return response()->json(Cases::setDue($this->user($r), $id, (int) $d['version'], $d['due_on']));
    }

    public function accused(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'user_ids' => 'present|array|max:20', 'user_ids.*' => 'integer|min:1']);
        return response()->json(Cases::setAccused($this->user($r), $id, (int) $d['version'], $d['user_ids']));
    }

    public function reply(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'text' => 'required|string|max:'.Complaints::MAX_DETAIL]);
        return response()->json(Cases::reply($this->user($r), $id, (int) $d['version'], $d['text']));
    }

    public function reissue(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        [$case, $code] = Cases::reissueCode($this->user($r), $id, (int) $d['version']);
        return response()->json($case + ['new_tracking_code' => $code])->header('Cache-Control', 'no-store');
    }

    public function close(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        return response()->json(Cases::close($this->user($r), $id, (int) $d['version']));
    }

    public function reopen(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        return response()->json(Cases::reopen($this->user($r), $id, (int) $d['version']));
    }

    public function note(Request $r, int $id)
    {
        $d = $r->validate(['body' => 'required|string|max:'.Complaints::MAX_DETAIL]);
        $u = $this->user($r);
        // the answer carries the case with the complainant's contact: only status and request hash are cached, a replay re-reads the case through Access (a user named accused since gets 404)
        return Idempotency::run($r, fn () => response()->json(Cases::addNote($u, $id, $d['body']), 201), fn () => Cases::show($u, $id));
    }

    public function file(Request $r, int $id, int $fileId)
    {
        return Cases::download($this->user($r), $id, $fileId);
    }

    // ---------------------------------------------------------------- annual report

    public function reports(Request $r)
    {
        return response()->json(['data' => Reports::staffList($this->user($r))]);
    }

    private function year(int $year): int
    {
        abort_if($year < 2560 || $year > 2700, 404);
        return $year;
    }

    public function report(Request $r, int $year)
    {
        $this->user($r);
        return response()->json(Complaints::report($this->year($year), $this->user($r)))->header('Cache-Control', 'no-store');
    }

    public function reportCsv(Request $r, int $year)
    {
        return response(Complaints::reportCsv(Complaints::report($this->year($year), $this->user($r))), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="complaints-report-'.$year.'.csv"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function publish(Request $r, int $year)
    {
        $u = $this->user($r);
        $d = $r->validate(['action' => 'required|in:publish,unpublish']);
        return response()->json(Reports::publish($this->year($year), $d['action'], $u));
    }
}
