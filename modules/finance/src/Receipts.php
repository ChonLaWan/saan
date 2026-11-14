<?php

namespace Saan\Modules\Finance;

use Illuminate\Support\Facades\DB;

/** The printable receipt (finance::receipt view, print to PDF from the browser). One builder for staff and for the guardian portal. */
final class Receipts
{
    /** $guardianView hides the name of the staff member who took the money (a guardian page never shows staff names). */
    public static function response(object $p, bool $guardianView = false)
    {
        $school = Support::school();
        $inv = DB::table('fin_invoices')->where('school_id', $school)->where('id', $p->invoice_id)->first();
        $s = DB::table('students')->leftJoin('classrooms', 'classrooms.id', '=', 'students.classroom_id')->where('students.school_id', $school)->where('students.id', $inv->student_id)
            ->first(['students.code', 'students.name', 'classrooms.name as classroom']);
        $lines = DB::table('fin_invoice_lines')->where('invoice_id', $inv->id)->orderBy('id')->get();
        $theme = app(\App\Domain\CmsPublishing::class)->theme($school)['published'];
        $schoolName = trim((string) ($theme['school_name'] ?? '')) ?: (string) config('saan.display');
        $address = trim((string) ($theme['address'] ?? ''));
        // snapshot taken at payment time wins; legacy rows (no snapshot) fall back to live data
        if (!empty($p->snap_student_name)) {
            $s = (object) ['code' => $p->snap_student_code, 'name' => $p->snap_student_name, 'classroom' => $p->snap_classroom];
            $schoolName = (string) ($p->snap_school_name ?: $schoolName);
            $address = (string) ($p->snap_address ?? '');
        }
        if ($guardianView) { $p = clone $p; $p->received_by_name = 'เจ้าหน้าที่การเงิน'; }
        return response()->view('finance::receipt', ['p' => $p, 'inv' => $inv, 's' => $s, 'lines' => $lines, 'school' => $schoolName, 'address' => $address])->header('Cache-Control', 'no-store, private');
    }
}
