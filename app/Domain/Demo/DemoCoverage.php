<?php

namespace App\Domain\Demo;

use App\Support\Saan\ClassroomMoves;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trial school, part 6: the screens that stay empty when a module only gets its main rows - corrections with reasons, score history, report layouts,
 * voided and cancelled items, the other statuses of every workflow, import batches, second and third rows of every list. Everything goes through the
 * module's own service where one exists (so audit rows, counters and derived tables are right); each block has the same module guard as the main builders.
 */
final class DemoCoverage
{
    public function __construct(private DemoContext $c) {}

    private function user(int $teacherIndex): ?\App\Models\User
    {
        $id = $this->c->teachers[$teacherIndex]['user_id'] ?? null;
        return $id ? \App\Models\User::find($id) : null;
    }

    // ================================================================ after the learning builders

    /** Attendance corrections, score history, special results, report layouts, old grade rows, voided behaviour records, two cancelled lessons. */
    public function learning(): void
    {
        $c = $this->c;
        $days = $c->lastSchoolDays(15);

        if ($c->has('attendance', 'att_corrections', 'att_class_days') && count($days) > 9) {
            $att = app(\Saan\Modules\Attendance\Attendance::class);
            $why = ['ผู้ปกครองนำใบลามาส่งภายหลัง แก้จากขาดเรียนเป็นลา (ข้อมูลสมมติ)', 'ครูบันทึกผิดคน แก้ตามรายงานของครูที่ปรึกษา (ข้อมูลสมมติ)', 'นักเรียนมาถึงหลังเข้าแถว แก้เป็นมาสาย (ข้อมูลสมมติ)',
                'ผู้ปกครองแจ้งว่านักเรียนไม่สบาย บันทึกเพิ่มภายหลัง (ข้อมูลสมมติ)', 'ตรวจสอบกับใบรับรองแพทย์แล้ว แก้เป็นลาป่วย (ข้อมูลสมมติ)', 'ตรวจเช็คชื่อซ้ำช่วงพักกลางวัน แก้ตามจริง (ข้อมูลสมมติ)'];
            $next = ['absent' => 'leave', 'late' => 'present', 'sick' => 'absent', 'leave' => 'sick'];
            foreach ([[0, 9], [1, 8], [2, 7], [3, 6], [4, 5], [5, 4]] as $n => [$r, $di]) {
                $room = $c->classrooms[$r];
                $date = $days[$di]->toDateString();
                $day = $att->dayRow($room['id'], $date);
                if (!$day) continue;
                $marks = DB::table('attendances')->where('school_id', $c->school)->where('date', $date)->where('classroom_id', $room['id'])->orderBy('id')->get()->keyBy('student_id');
                $rows = [];
                if ($first = $marks->first()) $rows[] = ['student_id' => (int) $first->student_id, 'status' => $next[$first->status] ?? 'present', 'version' => (int) $first->version, 'note' => null];
                foreach ($c->studentsOf($r) as $s) if (!$marks->has($s['id'])) { $rows[] = ['student_id' => $s['id'], 'status' => 'sick', 'version' => 0, 'note' => 'ไม่สบาย มีไข้ (ข้อมูลสมมติ)']; break; }
                if ($rows) $att->save($room['id'], $date, (int) $day->version, $rows, false, $why[$n], false);
            }
        }

        if ($c->has('assessment', 'asm_score_revisions')) {
            $asm = app(\Saan\Modules\Assessment\Assessment::class);
            $courses = DB::table('asm_courses')->where('school_id', $c->school)->where('term_id', $c->term['id'])->where('classroom_id', $c->classrooms[0]['id'])->orderBy('id')->limit(4)->pluck('id');
            $score = fn (int $course, int $student) => DB::table('asm_scores')->where('course_id', $course)->where('student_id', $student)->where('component_key', 'continuous')->first();
            foreach ($courses as $n => $course) {
                $cells = [];
                foreach (array_slice(array_values($c->studentsOf(0)), 3 + $n, 3) as $i => $s) {
                    $row = $score((int) $course, $s['id']);
                    $old = intdiv((int) $row->score_centi, 100);
                    $cells[] = ['student_id' => $s['id'], 'component_key' => 'continuous', 'score' => $old >= 66 ? $old - 3 - $i : $old + 2 + 2 * $i, 'version' => (int) $row->version];
                }
                $asm->saveScores((int) $course, $cells, 'ตรวจชิ้นงานที่ส่งซ่อมแล้ว ปรับคะแนนเก็บ (ข้อมูลสมมติ)');
                $row = $score((int) $course, $cells[0]['student_id']);
                $asm->saveScores((int) $course, [['student_id' => $cells[0]['student_id'], 'component_key' => 'continuous', 'score' => intdiv((int) $row->score_centi, 100) + 1, 'version' => (int) $row->version]], 'แก้ตัวเลขที่คีย์ผิด (ข้อมูลสมมติ)');
            }
        }

        if ($c->has('reports-pp', 'rpt_special_results', 'rpt_templates') && $c->has('assessment', 'asm_courses')) {
            $rpt = app(\Saan\Modules\ReportsPp\ReportsPp::class);
            $courseOf = fn (array $term, int $room, int $i) => (int) DB::table('asm_courses')->where('school_id', $c->school)->where('term_id', $term['id'])->where('classroom_id', $c->classrooms[$room]['id'])->orderBy('id')->skip($i)->value('id');
            $pick = fn (int $room, int $i) => array_values($c->studentsOf($room))[$i]['id'];
            $rpt->saveSpecial($courseOf($c->term, 0, 1), $pick(0, 6), 'ร', 'ยังส่งโครงงานไม่ครบ รอส่งภายในสัปดาห์หน้า (ข้อมูลสมมติ)', 0);
            $rpt->saveSpecial($courseOf($c->term, 0, 3), $pick(0, 9), 'มผ', 'ไม่ผ่านเกณฑ์การปฏิบัติกิจกรรม (ข้อมูลสมมติ)', 0);
            $rpt->saveSpecial($courseOf($c->prev, 1, 0), $pick(1, 4), 'มส', 'ขาดเรียนเกินร้อยละ 20 ของเวลาเรียนทั้งหมด (ข้อมูลสมมติ)', 0, 'บันทึกผลพิเศษตามมติที่ประชุมฝ่ายวิชาการ (ข้อมูลสมมติ)');
            $rpt->saveSpecial($courseOf($c->prev, 2, 2), $pick(2, 7), 'ร', 'รอสอบแก้ตัว (ข้อมูลสมมติ)', 0, 'บันทึกผลพิเศษตามมติที่ประชุมฝ่ายวิชาการ (ข้อมูลสมมติ)');
            $year = (int) $c->term['year'];
            $rpt->saveTemplate('pp5', $year - 1, ['title' => 'แบบบันทึกผลการเรียนประจำรายวิชา (ปพ.5) ฉบับปีการศึกษาก่อน', 'footer_note' => 'ใช้สำหรับเก็บเป็นหลักฐานของโรงเรียน (ข้อมูลสมมติ)'], 0);
            $rpt->saveTemplate('pp5', $year, ['title' => 'แบบบันทึกผลการเรียนประจำรายวิชา (ปพ.5)', 'show_attributes' => false, 'footer_note' => 'พิมพ์จากระบบสาน (ข้อมูลสมมติ)'], 0);
            $rpt->saveTemplate('pp6', $year, ['title' => 'แบบรายงานผลการพัฒนาคุณภาพผู้เรียนรายบุคคล (ปพ.6)', 'font_size' => 13, 'footer_note' => 'แจ้งผู้ปกครองทราบและลงชื่อรับทราบ (ข้อมูลสมมติ)'], 0);
            $rpt->saveTemplate('summary', $year, ['title' => 'รายงานสรุปผลการเรียนรายห้อง', 'font_size' => 14, 'show_components' => false], 0);
        }

        // the old prototype grade table: the read-only school overview (/operations) still lists it for the selected room
        // asked quietly: the overview is an experimental module that a production install keeps off, and that is not a "skipped module" to report
        if (app(\App\Support\Modules\ModuleRegistry::class)->active('operations') && Schema::hasTable('grade_entries')) {
            $rows = [];
            foreach ([$c->term, $c->prev] as $term) {
                foreach ($c->studentsOf(0) as $k => $s) {
                    foreach (['ภาษาไทย', 'คณิตศาสตร์', 'วิทยาศาสตร์และเทคโนโลยี'] as $j => $subject) {
                        $rows[] = ['term_id' => $term['id'], 'student_id' => $s['id'], 'subject' => $subject, 'continuous_centi' => (45 + DemoNames::pick('ge'.$k.$j.$term['id'], 25)) * 100,
                            'exam_centi' => (14 + DemoNames::pick('gx'.$k.$j.$term['id'], 16)) * 100, 'policy_version' => 'เกณฑ์ 70/30 (ข้อมูลสมมติ)', 'recorded_by' => $c->admin->id];
                    }
                }
            }
            $c->insert('grade_entries', $rows);
        }

        if ($c->has('behavior', 'bhv_records')) {
            $beh = app(\Saan\Modules\Behavior\BehaviorRepository::class);
            $shown = array_map(fn ($k) => $c->students[$k]['id'], [0, 21, 60, 81]);
            $reasons = ['บันทึกผิดคน ครูแจ้งขอยกเลิกรายการ (ข้อมูลสมมติ)', 'บันทึกซ้ำกับรายการเดิมของวันเดียวกัน (ข้อมูลสมมติ)', 'ครูที่ปรึกษาตรวจสอบแล้วไม่ตรงกับข้อเท็จจริง (ข้อมูลสมมติ)'];
            foreach (DB::table('bhv_records')->where('school_id', $c->school)->where('status', 'active')->whereNotIn('student_id', $shown)->orderBy('id')->limit(3)->get() as $n => $r) {
                $beh->void((int) $r->id, (int) $r->version, $reasons[$n], $c->admin);
            }
        }

        // two cancelled activity periods of the current term: the lesson list keeps them in its recycle bin
        if ($c->has('timetable')) {
            $tt = app(\Saan\Modules\Timetable\Timetable::class);
            $request = Request::create('/', 'POST');
            foreach (DB::table('lessons')->where('school_id', $c->school)->where('term_id', $c->term['id'])->whereNull('deleted_at')->where('period_id', $c->periods[7])->whereIn('weekday', [4])
                ->whereIn('classroom_id', [$c->classrooms[4]['id'], $c->classrooms[5]['id']])->get() as $l) {
                $tt->delete((int) $l->id, (int) $c->term['id'], (int) $l->version, $request);
            }
        }
    }

    // ================================================================ after the office builders

    public function office(): void
    {
        $this->dutySwaps();
        $this->payslip();
        $this->finance();
        $this->documents();
        $this->projects();
        $this->portfolio();
    }

    /** Swap requests in every state (asked, accepted, declined, approved, rejected, cancelled) and two cancelled duties, one with a cover teacher. */
    private function dutySwaps(): void
    {
        $c = $this->c;
        if (!$c->has('leave-duty', 'ld_duty_swaps', 'duties')) return;
        $duty = app(\Saan\Modules\LeaveDuty\Duty::class);
        $used = DB::table('ld_duty_swaps')->pluck('duty_id')->map('intval')->all();
        $future = DB::table('duties')->where('school_id', $c->school)->where('date', '>', $c->today->toDateString())->where('status', 'assigned')->orderBy('date')->orderBy('id')->get()->all();
        $staffOf = [];
        foreach ($c->teachers as $i => $t) $staffOf[(int) $t['id']] = $i;
        $actor = ['accept' => 'to', 'decline' => 'to', 'cancel' => 'from', 'approve' => 'admin', 'reject' => 'admin'];
        $note = ['ติดประชุมกลุ่มสาระ ขอแลกเวร (ข้อมูลสมมติ)', 'ต้องพาเด็กไปแข่งขัน ขอแลกเวร (ข้อมูลสมมติ)', 'ไปติดต่อราชการ ขอแลกเวร (ข้อมูลสมมติ)'];
        $at = 4;
        foreach ([['accept'], ['decline'], ['accept', 'approve'], ['accept', 'reject'], ['cancel'], ['accept', 'cancel']] as $n => $steps) {
            for (; $at < count($future); $at++) {
                $d = $future[$at];
                $from = $staffOf[(int) $d->teacher_id] ?? null;
                if ($from === null || in_array((int) $d->id, $used, true) || !($owner = $this->user($from))) continue;
                $swap = null;
                foreach (range(1, 12) as $off) {
                    $to = ($from - 1 + $off + $n) % 12 + 1;
                    if ($to === $from) continue;
                    try { $swap = $duty->swapRequest((int) $d->id, (int) $c->teachers[$to]['id'], $note[$n % 3], $owner); break; } catch (\Throwable) { /* that teacher is busy that day: try the next one */ }
                }
                if (!$swap) continue;
                $used[] = (int) $d->id;
                foreach ($steps as $step) {
                    $who = $actor[$step] === 'to' ? $this->user($to) : ($actor[$step] === 'from' ? $owner : $c->admin);
                    $swap = $duty->swapTransition((int) $swap['id'], $step, (int) $swap['version'], $step === 'reject' ? 'ผู้รับเวรมีคาบสอนชดเชยในวันเดียวกัน (ข้อมูลสมมติ)' : null, $who);
                }
                $at++;
                break;
            }
        }
        foreach ([true, false] as $cover) {
            for (; $at < count($future); $at++) {
                $d = $future[$at];
                if (in_array((int) $d->id, $used, true)) continue;
                $from = $staffOf[(int) $d->teacher_id] ?? 1;
                foreach ($cover ? range(1, 12) : [0] as $off) {
                    $to = $cover ? ($from - 1 + $off + 5) % 12 + 1 : null;
                    try { $duty->cancel((int) $d->id, (int) $d->version, $cover ? 'ครูเจ้าของเวรติดภารกิจราชการ มอบครูอีกท่านดูแลแทน (ข้อมูลสมมติ)' : 'งดเวรเพราะโรงเรียนปิดทำการ (ข้อมูลสมมติ)', $to === null || $to === $from ? null : (int) $c->teachers[$to]['id']); $used[] = (int) $d->id; break 2; } catch (\Throwable) { /* try the next candidate */ }
                }
            }
            $at++;
        }
    }

    /** More periods: one imported from a file (check screen and confirm; still a draft), one cancelled draft, one draft with an import still waiting; one slip voided. */
    private function payslip(): void
    {
        $c = $this->c;
        if (!$c->has('payslip', 'psl_payees', 'psl_periods', 'psl_slips', 'psl_imports')) return;
        $periods = \Saan\Modules\Payslip\Periods::class;
        $slips = \Saan\Modules\Payslip\Slips::class;
        $importer = \Saan\Modules\Payslip\Importer::class;
        $payees = DB::table('psl_payees')->where('school_id', $c->school)->orderBy('id')->get();
        $people = DemoOffice::payroll();
        $map = $importer::normaliseMapping(['key_type' => 'code', 'key_col' => 'รหัสผู้รับเงิน', 'earnings' => [['col' => 'เงินเดือน', 'name' => 'เงินเดือน (ตัวเลขสมมติ)']],
            'deductions' => [['col' => 'เงินสะสมกองทุน', 'name' => 'เงินสะสมกองทุน (ตัวเลขสมมติ)'], ['col' => 'สหกรณ์ออมทรัพย์', 'name' => 'สหกรณ์ออมทรัพย์ (ตัวเลขสมมติ)']], 'tax_col' => 'ภาษี', 'net_col' => 'ยอดสุทธิ']);
        Settings::put('payslip.import_map', $map);
        $money = fn (int $satang) => sprintf('%d.%02d', intdiv($satang, 100), $satang % 100);
        $csv = function (bool $withBadRow) use ($payees, $people, $money): string {
            $out = "รหัสผู้รับเงิน,เงินเดือน,เงินสะสมกองทุน,สหกรณ์ออมทรัพย์,ภาษี,ยอดสุทธิ\n";
            foreach ($payees as $n => $p) {
                [, $salary] = $people[$n] ?? ['', 1111100];
                $fund = intdiv($salary * 3, 100);
                $tax = intdiv($salary, 100);
                $out .= implode(',', [$p->payee_code, $money($salary), $money($fund), '1000.00', $money($tax), $money($salary - $fund - 100000 - $tax)])."\n";
            }
            return $withBadRow ? $out."P999,20000.00,600.00,1000.00,200.00,18200.00\n" : $out;
        };
        $month = $c->today->startOfMonth();
        $create = fn (CarbonImmutable $m) => $periods::create($m->year, $m->month, '', $m->day(25)->toDateString());
        $stage = fn (object $period, bool $bad) => $importer::stage($period, $c->upload($csv($bad), 'เงินเดือน-'.$period->year.'-'.sprintf('%02d', $period->month).'.csv', 'text/csv'), $map);

        $two = $create($month->subMonthsNoOverflow(2));
        $importer::confirm($importer::find($stage($two, true)['import_id']), 1); // slips of a draft period; the officer publishes it when the check is done
        $periods::cancel((int) $create($month->subMonthsNoOverflow(3))->id, 1, 'สร้างงวดผิดเดือน ยกเลิกทั้งงวดก่อนเผยแพร่ (ข้อมูลสมมติ)');
        $stage($create($month->addMonthNoOverflow()), true);

        $last = DB::table('psl_periods')->where('school_id', $c->school)->where('status', 'published')->orderByDesc('year')->orderByDesc('month')->skip(0)->first();
        // not the slip of a trial account: each of those must still find its own published slip when it signs in
        $trial = array_values(array_filter(array_map(fn ($k) => $c->users[$k] ?? null, ['principal', 'homeroom', 'subject', 'finance', 'payroll', 'clerk', 'editor'])));
        $slip = $last ? DB::table('psl_slips')->where('school_id', $c->school)->where('period_id', $last->id)->where('status', 'active')
            ->whereNotIn('payee_id', DB::table('psl_payees')->where('school_id', $c->school)->whereIn('user_id', $trial)->pluck('id'))->orderByDesc('id')->first() : null;
        if ($slip) $slips::void($slip, (int) $slip->version, 'ออกสลิปผิดผู้รับเงิน ยกเลิกแล้วออกใหม่ภายหลัง (ข้อมูลสมมติ)');
    }

    /** More fee items (one switched off), two unpaid invoices voided, a withdrawn slip, a void receipt, other income, closed months. */
    private function finance(): void
    {
        $c = $this->c;
        if (!$c->has('finance', 'fin_fee_items', 'fin_invoices', 'fin_payments', 'fin_payment_claims', 'fin_claim_slips', 'fin_periods')) return;
        $billing = \Saan\Modules\Finance\Billing::class;
        $officer = $c->user('finance');
        $item = [];
        foreach ([['FEE-TRIP', 'ค่าทัศนศึกษาแหล่งเรียนรู้', 35000, null, true], ['FEE-LAB', 'ค่าวัสดุปฏิบัติการวิทยาศาสตร์', 30000, ['ม.4', 'ม.5', 'ม.6'], true], ['FEE-BOOK', 'ค่าเอกสารประกอบการเรียน', 25000, ['ม.1', 'ม.2', 'ม.3'], true],
            ['FEE-CAMP', 'ค่าค่ายลูกเสือ-เนตรนารี', 60000, ['ม.1', 'ม.2'], true], ['FEE-UNI', 'ค่าชุดพลศึกษา (เลิกเก็บแล้ว)', 45000, null, false]] as [$code, $label, $amount, $levels, $active]) {
            $item[$code] = $c->insertId('fin_fee_items', ['code' => $code, 'code_key' => strtolower($code), 'label' => $label, 'default_amount_satang' => $amount, 'term_id' => null, 'grade_levels' => $levels, 'is_active' => $active, 'account_ids' => null]);
        }
        $issued = $c->daysAgo(25, $c->term['starts_on']);
        // students whose invoice is still untouched (no payment, no slip): two of them have their invoice voided, one guardian sent a slip and took it back
        $invoice = fn (int $k) => DB::table('fin_invoices')->where('school_id', $c->school)->where('student_id', $c->students[$k]['id'])->first();
        foreach ([[29, 'ออกใบแจ้งหนี้ซ้ำ ยกเลิกรายการนี้ (ข้อมูลสมมติ)'], [48, 'นักเรียนย้ายห้องก่อนออกใบแจ้งหนี้ ยกเลิกรายการ (ข้อมูลสมมติ)']] as [$k, $why]) {
            $row = $invoice($k);
            if ($row && $row->status === 'open' && (int) $row->paid_satang === 0) $billing::voidInvoice((int) $row->id, (int) $row->version, $why, $officer);
        }
        $k = 28;
        $open = $invoice($k);
        $png = DemoContext::png(['Invoice '.($open->invoice_no ?? ''), 'Amount 2200.00 THB', 'Withdrawn sample slip']);
        if ($png !== null && $open && $open->status === 'open' && isset($c->guardians[$k])) {
            $claim = $c->insertId('fin_payment_claims', ['invoice_id' => $open->id, 'student_id' => $c->students[$k]['id'], 'guardian_id' => $c->guardians[$k], 'entered_by' => null, 'source' => 'guardian', 'amount_satang' => (int) $open->total_satang,
                'approved_satang' => null, 'transferred_at' => $c->today->toDateString().' 07:20:00', 'account_id' => (int) DB::table('fin_payment_accounts')->orderBy('id')->value('id'), 'status' => 'pending']);
            $path = $c->put('finance/'.$c->school.'/slips/'.bin2hex(random_bytes(16)).'.png', $png);
            $c->insertId('fin_claim_slips', ['claim_id' => $claim, 'path' => $path, 'mime' => 'image/png', 'size' => strlen($png), 'sha256' => hash('sha256', $png), 'dup_key' => hash('sha256', $png)]);
            \Saan\Modules\Finance\Claims::withdraw($claim, (int) $c->guardians[$k], 1);
        }
        $cash = DB::table('fin_payments')->where('school_id', $c->school)->where('status', 'valid')->where('method', 'cash')->orderBy('id')->first();
        if ($cash) $billing::voidPayment((int) $cash->id, (int) $cash->version, 'ออกใบเสร็จผิดชื่อนักเรียน ยกเลิกแล้วออกใบใหม่ (ข้อมูลสมมติ)', $officer);
        foreach ([['ดอกเบี้ยเงินฝาก', 'ดอกเบี้ยเงินฝากธนาคารประจำเดือน (รายการสมมติ)', 31250], ['เงินบริจาค', 'ผู้ปกครองบริจาคสมทบทุนอาหารกลางวัน (รายการสมมติ)', 500000], ['รายได้สหกรณ์', 'ส่วนแบ่งกำไรสหกรณ์ร้านค้า (รายการสมมติ)', 184000]] as $n => [$category, $text, $amount]) {
            Mutation::insert('fin_ledger', ['entry_date' => $issued->addDays($n)->gt($c->today) ? $c->today->toDateString() : $issued->addDays($n)->toDateString(), 'kind' => 'income', 'category' => $category, 'amount_satang' => $amount,
                'description' => $text, 'document_no' => 'บจ.สมมติ '.(4 + $n), 'payment_id' => null, 'created_by' => $officer->id], 'finance.ledger_created');
        }
        // months before the first invoice are closed; one was closed and opened again
        $first = $c->daysAgo(25, $c->term['starts_on'])->startOfMonth();
        foreach ([1, 2, 3, 4] as $back) {
            $period = $first->subMonthsNoOverflow($back)->format('Y-m');
            $row = Mutation::insert('fin_periods', ['period' => $period, 'closed_at' => now(), 'closed_by' => $officer->id], 'finance.period_closed', ['period' => $period]);
            if ($back === 4) Mutation::update('fin_periods', (int) $row->id, (int) $row->version, ['closed_at' => null, 'closed_by' => null], 'finance.period_reopened', ['period' => $period, 'by' => $officer->id]);
        }
    }

    /**
     * One or two attachments for the first ten registered letters (the first already has its own, made through the service). Written like the service writes
     * them but without it: every service upload re-adds up the storage of all modules (about 0.1 s each), which a trial build would pay a dozen times.
     */
    private function documents(): void
    {
        $c = $this->c;
        if (!$c->has('documents', 'doc_documents', 'doc_files')) return;
        $rows = [];
        foreach (DB::table('doc_documents')->where('school_id', $c->school)->orderBy('id')->limit(10)->get(['id']) as $n => $d) {
            if ($n === 0) continue;
            foreach (range(0, $n % 3 === 0 ? 1 : 0) as $j) {
                $bytes = DemoContext::pdf('Attachment '.($n + 1).'.'.($j + 1), ['Letter number '.($n + 1), 'Sample enclosure']);
                $rows[] = ['document_id' => (int) $d->id, 'path' => $c->put('documents/'.$c->school.'/'.$d->id.'/'.bin2hex(random_bytes(16)).'.pdf', $bytes), 'original_name' => ($j === 0 ? 'ไฟล์แนบหนังสือ' : 'เอกสารประกอบ').'-'.($n + 1).'.pdf',
                    'mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'uploaded_by' => $c->users['clerk']];
            }
        }
        $c->insert('doc_files', $rows);
    }

    /** Next year's plan, still a draft, with two proposed projects. */
    private function projects(): void
    {
        $c = $this->c;
        if (!$c->has('projects', 'prj_plans', 'prj_projects')) return;
        $fy = ($c->today->month >= 10 ? $c->today->year + 544 : $c->today->year + 543) + 1;
        $start = CarbonImmutable::create($fy - 544, 10, 1, 0, 0, 0, 'Asia/Bangkok');
        $plan = $c->insertId('prj_plans', ['fiscal_year_be' => $fy, 'title' => 'แผนปฏิบัติการประจำปีงบประมาณ '.$fy.' (ฉบับร่าง)', 'status' => 'draft']);
        foreach ([['วช.01', 'พัฒนาห้องสมุดและแหล่งเรียนรู้ในโรงเรียน', 3, 6500000, 'อุดหนุนรายหัว'], ['กน.01', 'ส่งเสริมกิจกรรมดนตรีไทยและศิลปวัฒนธรรม', 10, 2800000, 'รายได้สถานศึกษา']] as [$code, $title, $owner, $budget, $source]) {
            $c->insertId('prj_projects', ['plan_id' => $plan, 'code' => $code, 'code_key' => mb_strtolower($code), 'title' => $title.' (โครงการสมมติ)', 'owner_teacher_id' => $c->teachers[$owner]['id'],
                'department' => DemoNames::STAFF[$owner][6], 'strategy' => 'กลยุทธ์ที่ '.(1 + $owner % 4).' พัฒนาคุณภาพผู้เรียน', 'objective' => 'เพื่อเสนอเข้าแผนปฏิบัติการของปีถัดไป (ข้อมูลสมมติ)', 'target' => 'นักเรียนทุกระดับชั้น',
                'starts_on' => $start->toDateString(), 'ends_on' => $start->addYear()->subDay()->toDateString(), 'budget_satang' => $budget, 'budget_source' => $source, 'status' => 'proposed', 'approved_by' => null, 'approved_at' => null]);
        }
    }

    /** More portfolio files in the review states a staff member can meet (waiting, approved, asked to revise). */
    private function portfolio(): void
    {
        $c = $this->c;
        if (!$c->has('personnel', 'per_folders', 'per_files')) return;
        $folders = DB::table('per_folders')->where('school_id', $c->school)->orderBy('sort')->orderBy('id')->limit(4)->pluck('id')->all();
        if (!$folders) return;
        $rows = [];
        foreach ([[2, 'แผนการจัดการเรียนรู้ วิทยาศาสตร์ ม.1.pdf', 'approved', null], [3, 'รายงานผลการอ่านออกเขียนได้ ภาคเรียนที่ 1.pdf', 'pending', null], [4, 'บันทึกการเยี่ยมบ้านนักเรียน.pdf', 'revise', 'ขอให้ระบุวันที่เยี่ยมบ้านและลงชื่อผู้ปกครอง (ตัวอย่าง)'],
            [5, 'สื่อการสอนเรื่องสมการ.pdf', 'approved', null], [2, 'เกียรติบัตรการอบรมครูวิทยาศาสตร์.pdf', 'pending', null], [6, 'แผนการจัดการเรียนรู้ ภาษาอังกฤษ ม.2.pdf', 'revise', 'ขอให้เพิ่มวิธีวัดและประเมินผลท้ายหน่วย (ตัวอย่าง)']] as $n => [$staffIndex, $name, $review, $note]) {
            $teacher = $c->teachers[$staffIndex];
            $folder = $folders[($n + 1) % count($folders)];
            $bytes = DemoContext::pdf('Portfolio file '.($n + 6), ['Teacher index '.$staffIndex, 'File number '.($n + 6)]);
            $path = $c->put('personnel/'.$c->school.'/'.$teacher['id'].'/'.$folder.'/'.bin2hex(random_bytes(16)).'.pdf', $bytes);
            $rows[] = ['teacher_id' => $teacher['id'], 'folder_id' => $folder, 'fiscal_or_school_year' => (int) $c->term['year'], 'original_name' => $name, 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes), 'dedupe_key' => hash('sha256', $bytes), 'status' => 'active', 'review_status' => $review, 'uploaded_by' => $teacher['user_id'],
                'reviewed_by' => $review === 'pending' ? null : $c->users['principal'], 'reviewed_at' => $review === 'pending' ? null : now(), 'review_note' => $note];
        }
        $c->insert('per_files', $rows);
    }

    // ================================================================ after the student-life builders

    public function studentLife(): void
    {
        $this->clubs();
        $this->elections();
        $this->projectLearning();
        $this->exams();
        $this->learningLinks();
        $this->connectors();
        $this->gateway();
    }

    /** A club with a waiting list, a club that closed its registration, a club that has not met yet; one result set by hand with its reason. */
    private function clubs(): void
    {
        $c = $this->c;
        if (!$c->has('activities', 'act_clubs', 'act_members', 'act_sessions', 'act_attendance')) return;
        $clubs = app(\Saan\Modules\Activities\Clubs::class);
        $days = array_slice(array_values(array_filter($c->lastSchoolDays(40, CarbonImmutable::parse($c->term['starts_on'], 'Asia/Bangkok')), fn ($d) => $d->dayOfWeekIso === 3)), -4);
        foreach ([['ชุมนุมศิลปะและงานประดิษฐ์', 10, 8, 4, 2, 'ทำงานประดิษฐ์จากวัสดุเหลือใช้และวาดภาพระบายสี'], ['ชุมนุมภาษาอังกฤษเพื่อการสื่อสาร', 12, 30, 5, 3, 'ฝึกสนทนาและเล่นเกมภาษาอังกฤษง่ายๆ'], ['ชุมนุมอนุรักษ์สิ่งแวดล้อม', 2, 30, 6, 0, 'ปลูกต้นไม้และคัดแยกขยะในโรงเรียน']] as $n => [$name, $advisor, $capacity, $mod, $sessions, $about]) {
            $club = $clubs->create(['name' => $name, 'term_id' => $c->term['id'], 'advisor_teacher_id' => $c->teachers[$advisor]['id'], 'capacity' => $capacity, 'grade_levels' => [], 'description' => $about.' (ข้อมูลสมมติ)', 'is_open' => true]);
            $result = $clubs->enroll((int) $club->id, array_column(array_filter($c->students, fn ($s, $k) => $k % 8 === $mod, ARRAY_FILTER_USE_BOTH), 'id'));
            if ($result['rejected']) throw new \RuntimeException('ลงทะเบียนชุมนุมทดลองไม่สำเร็จ: '.$result['rejected'][0]['reason']);
            $enrolled = DB::table('act_members')->where('club_id', $club->id)->where('status', 'enrolled')->orderBy('id')->pluck('student_id')->all();
            foreach (array_slice($days, 0, $sessions) as $i => $day) {
                $session = $clubs->sessionCreate((int) $club->id, $day->toDateString(), 'กิจกรรมครั้งที่ '.($i + 1));
                $clubs->saveAttendance((int) $session->id, (int) $session->version, array_map(fn ($sid) => ['student_id' => $sid, 'present' => DemoNames::pick('club2|'.$n.'|'.$sid.'|'.$i, 6) !== 0], $enrolled));
            }
            if ($sessions) $clubs->computeResults((int) $club->id);
            if ($n === 0) {
                $m = DB::table('act_members')->where('club_id', $club->id)->where('status', 'enrolled')->where('result', 'มผ')->orderBy('id')->first() ?? DB::table('act_members')->where('club_id', $club->id)->where('status', 'enrolled')->orderBy('id')->first();
                if ($m) $clubs->overrideResult((int) $m->id, (int) $m->version, 'ผ', 'ครูที่ปรึกษาพิจารณาจากชิ้นงานที่ส่งชดเชยครบแล้ว (ข้อมูลสมมติ)');
            }
            if ($n === 1) $clubs->update((int) $club->id, (int) DB::table('act_clubs')->where('id', $club->id)->value('version'), ['is_open' => false]);
        }
    }

    /** A draft, one that is open for voting right now and an older closed one (with its secret ballots). */
    private function elections(): void
    {
        $c = $this->c;
        if (!$c->has('activities', 'act_elections', 'act_candidates', 'act_voters', 'act_ballots')) return;
        $el = app(\Saan\Modules\Activities\Elections::class);
        $year = (int) $c->term['year'];
        $local = fn (CarbonImmutable $d, string $t) => $d->toDateString().' '.$t;
        $draft = $el->create(['title' => 'เลือกตั้งกรรมการนักเรียน ฝ่ายกีฬาและสุขภาพ ปีการศึกษา '.$year, 'opens_at' => $local($c->today->addDays(7), '08:30'), 'closes_at' => $local($c->today->addDays(7), '15:00'), 'eligible_grade_levels' => ['ม.4', 'ม.5', 'ม.6']]);
        foreach ([[61, 'ออกกำลังกายทุกวันพุธ'], [101, 'จัดแข่งขันกีฬาระหว่างห้อง']] as $n => [$k, $slogan]) $el->addCandidate((int) $draft->id, $n + 1, $c->students[$k]['id'], $slogan.' (ข้อมูลสมมติ)');
        $open = $el->create(['title' => 'เลือกตั้งหัวหน้าชั้น ม.1 ปีการศึกษา '.$year, 'opens_at' => now('Asia/Bangkok')->subHour()->format('Y-m-d H:i'), 'closes_at' => $local($c->today->addDays(2), '16:00'), 'eligible_grade_levels' => ['ม.1']]);
        foreach ([[3, 'ทุกเสียงมีความหมาย'], [9, 'ห้องเรียนสะอาดและเป็นระเบียบ'], [14, 'เรียนรู้ร่วมกัน ช่วยเหลือกัน']] as $n => [$k, $slogan]) $el->addCandidate((int) $open->id, $n + 1, $c->students[$k]['id'], $slogan.' (ข้อมูลสมมติ)');
        $el->transition((int) $open->id, (int) DB::table('act_elections')->where('id', $open->id)->value('version'), 'open');
        // the older, closed one (written in its closed state, like the main election)
        $day = $c->lastSchoolDays(30)[0];
        $old = $c->insertId('act_elections', ['title' => 'เลือกตั้งประธานชมรมนักเรียน ภาคเรียนที่ผ่านมา', 'opens_at' => $c->at($day, '08:30'), 'closes_at' => $c->at($day, '15:00'), 'eligible_grade_levels' => ['ม.4', 'ม.5', 'ม.6'], 'status' => 'closed', 'key_secret' => null]);
        $cand = [];
        foreach ([[62, 'ชมรมที่เปิดกว้างสำหรับทุกคน'], [102, 'กิจกรรมใหม่ทุกเดือน']] as $n => [$k, $slogan]) $cand[] = $c->insertId('act_candidates', ['election_id' => $old, 'number' => $n + 1, 'student_id' => $c->students[$k]['id'], 'slogan' => $slogan.' (ข้อมูลสมมติ)']);
        $voters = [];
        $ballots = [];
        foreach ($c->students as $k => $s) {
            if ($s['room'] < 3 || DemoNames::pick('voted2|'.$k, 100) >= 80) continue;
            $voters[] = ['election_id' => $old, 'student_id' => $s['id'], 'classroom_id' => $c->classrooms[$s['room']]['id'], 'voted_at' => $c->at($day, sprintf('%02d:%02d', 9 + $k % 5, $k % 60))];
            $p = DemoNames::pick('ballot2|'.$k, 100);
            $ballots[bin2hex(random_bytes(16))] = ['election_id' => $old, 'voter_key' => bin2hex(random_bytes(32)), 'candidate_id' => $p < 58 ? $cand[0] : ($p < 92 ? $cand[1] : null), 'cast_at' => $day->toDateString()];
        }
        ksort($ballots);
        $c->insert('act_voters', $voters);
        $c->insert('act_ballots', array_map(fn ($id, $b) => ['id' => $id] + $b, array_keys($ballots), $ballots));
    }

    /** Projects of the other three stages (planning, doing, done) with members, logs, linked work and reflections. */
    private function projectLearning(): void
    {
        $c = $this->c;
        if (!$c->has('constructionism', 'con_projects', 'con_members', 'con_logs', 'con_artifacts', 'con_reflections')) return;
        $pbl = \Saan\Modules\Constructionism\Pbl::class;
        $days = $c->lastSchoolDays(12);
        foreach ([[2, 6, 'หนังสือเล่มเล็กเล่าเรื่องชุมชนต้นธาร', 'เราจะเก็บเรื่องเล่าของคนในชุมชนไว้ให้คนรุ่นต่อไปได้อย่างไร', 'planning', 'community-book', 1],
            [1, 12, 'สวนผักปลอดสารพิษหลังโรงเรียน', 'เราจะปลูกผักให้พอสำหรับโรงอาหารโดยไม่ใช้สารเคมีได้อย่างไร', 'done', 'school-garden', 3],
            [5, 8, 'เครื่องวัดคุณภาพน้ำอย่างง่าย', 'นักเรียนจะวัดคุณภาพน้ำในคลองหน้าโรงเรียนด้วยอุปกรณ์ราคาถูกได้อย่างไร', 'active', 'water-sensor', 2]] as [$room, $advisor, $title, $question, $status, $slug, $logs]) {
            $detail = $pbl::create(['classroom_id' => $c->classrooms[$room]['id'], 'title' => $title, 'driving_question' => $question.' (ข้อมูลสมมติ)', 'advisor_teacher_id' => $c->teachers[$advisor]['id']], $c->admin);
            $project = (int) $detail['project']['id'];
            $members = array_slice(array_values($c->studentsOf($room)), 6, 4);
            foreach ($members as $n => $s) $pbl::addMember($project, ['student_id' => $s['id'], 'role' => ['หัวหน้าทีม', 'ออกแบบ', 'บันทึกข้อมูล', 'นำเสนอ'][$n]], $c->admin);
            foreach (array_slice(['ลงพื้นที่สำรวจและสัมภาษณ์ผู้รู้', 'ลงมือทำและบันทึกผลทุกสัปดาห์', 'สรุปผลและเตรียมนำเสนอ'], 0, $logs) as $n => $entry) {
                $pbl::addLog($project, ['date' => $days[min(count($days) - 1, 2 + $n * 4)]->toDateString(), 'entry' => $entry.' (ข้อมูลสมมติ)', 'by_teacher' => $n === 1], $c->admin);
            }
            if ($status !== 'planning') {
                \Saan\Modules\Constructionism\Files::add($project, ['title' => 'ภาพถ่ายความก้าวหน้า', 'kind' => 'doc', 'url' => 'https://example.org/saan-demo/'.$slug.'/photos'], null, $c->admin);
                foreach (array_slice($members, 0, 2) as $n => $s) {
                    \Saan\Modules\Constructionism\Reflections::add($project, ['student_id' => $s['id'], 'text' => 'ได้เรียนรู้การทำงานเป็นทีมและการแก้ปัญหาร่วมกัน (ข้อมูลสมมติ)',
                        'rubric' => ['inquiry' => 3, 'making' => 3 + $n, 'teamwork' => 4, 'iteration' => 3, 'sharing' => 2 + $n]], $c->admin);
                }
            }
            DB::table('con_projects')->where('id', $project)->update(['status' => $status]);
        }
    }

    /** Four more exam sets (every kind and status): answers, item analysis and score lists for the ones that were checked. */
    private function exams(): void
    {
        $c = $this->c;
        if (!$c->has('exam-checker', 'exm_exams', 'exm_sheets')) return;
        $sets = [['midterm', 'สอบกลางภาคภาษาไทย ม.1 (ข้อสอบสมมติ)', 'ภาษาไทย', 'ม.1', 30, 'closed', 0, 1], ['final', 'สอบปลายภาควิทยาศาสตร์ ม.4 (ข้อสอบสมมติ)', 'วิทยาศาสตร์และเทคโนโลยี', 'ม.4', 40, 'open', 3, 1],
            ['onet_practice', 'ฝึกทำข้อสอบ O-NET คณิตศาสตร์ ม.6 (ข้อสอบสมมติ)', 'คณิตศาสตร์', 'ม.6', 20, 'draft', 5, 0], ['quiz', 'แบบทดสอบย่อยภาษาอังกฤษ ม.2 (ข้อสอบสมมติ)', 'ภาษาอังกฤษ', 'ม.2', 15, 'closed', 1, 1],
            ['other', 'แบบทดสอบก่อนเรียน การงานอาชีพ ม.3 (ข้อสอบสมมติ)', 'การงานอาชีพ', 'ม.3', 10, 'open', 2, 2]];
        foreach ($sets as $n => [$kind, $title, $subject, $grade, $count, $final, $room, $sheets]) {
            $key = [];
            for ($q = 0; $q < $count; $q++) $key[] = $n === 0 && $q === 7 ? 'cancelled' : 1 + DemoNames::pick('key2|'.$n.'|'.$q, 4);
            $half = intdiv($count, 2);
            $exam = Mutation::insert('exm_exams', ['term_id' => $c->term['id'], 'title' => $title, 'kind' => $kind, 'subject_label' => $subject, 'grade_level' => $grade, 'question_count' => $count, 'choices_per_question' => 4,
                'points_per_question' => $kind === 'midterm' || $kind === 'final' ? 2 : 1, 'answer_key' => $key, 'sections' => $half > 4 ? [['label' => 'ตอนที่ 1', 'from' => 1, 'to' => $half], ['label' => 'ตอนที่ 2', 'from' => $half + 1, 'to' => $count]] : null,
                'status' => $final === 'draft' ? 'draft' : 'open', 'created_by' => $c->teachers[[5, 8, 7, 6, 11][$n]]['user_id']], 'exam_checker.exam_created', ['title' => 'demo']);
            if ($sheets) {
                $rows = [];
                foreach (array_slice($c->studentsOf($room), 0, $sheets === 2 ? 11 : 20, true) as $k => $s) {
                    $answers = '';
                    foreach ($key as $q => $right) {
                        $right = is_int($right) ? $right : 1;
                        $p = DemoNames::pick('exam2|'.$n.'|'.$k.'|'.$q, 100);
                        $answers .= $p < 5 ? '-' : ($p < 45 + DemoNames::pick('ability'.$k, 50) ? $right : $right % 4 + 1);
                    }
                    $rows[] = ['student_code' => $s['code'], 'answers' => $answers];
                }
                $saved = \Saan\Modules\ExamChecker\SheetService::save($exam, $rows, ['source' => 'keyed']);
                if ($saved['errors']) throw new \RuntimeException('บันทึกใบตอบทดลองไม่สำเร็จ: '.$saved['errors'][0]['message']);
            }
            if ($final === 'closed') Mutation::update('exm_exams', (int) $exam->id, (int) DB::table('exm_exams')->where('id', $exam->id)->value('version'), ['status' => 'closed'], 'exam_checker.exam_updated', ['fields' => ['status']]);
        }
    }

    /** Access codes for four more rooms (one already expired) and links for them. */
    private function learningLinks(): void
    {
        $c = $this->c;
        if (!$c->has('learning-links', 'lrn_links', 'lrn_access')) return;
        $links = \Saan\Modules\LearningLinks\Links::class;
        foreach ([[1, 'ห้องเรียนออนไลน์ภาษาอังกฤษ ม.2', 'm2-english', 'lms'], [1, 'แบบฝึกหัดคำศัพท์ ม.2', 'm2-vocab', 'app'], [2, 'วิดีโอสอนซ่อมคณิตศาสตร์ ม.3', 'm3-math-video', 'video'], [2, 'ใบงานวิทยาศาสตร์ ม.3', 'm3-science-sheets', 'doc'],
            [4, 'ห้องเรียนออนไลน์เคมี ม.5', 'm5-chem', 'lms'], [5, 'ตัวอย่างข้อสอบ O-NET ม.6', 'm6-onet', 'doc'], [null, 'ระบบยืมหนังสือห้องสมุดออนไลน์', 'library-loan', 'app']] as $n => [$room, $title, $slug, $kind]) {
            $links::create(['title' => $title, 'url' => 'https://classroom.example.org/saan-demo/'.$slug, 'kind' => $kind, 'sort' => 10 + $n] + ($room === null ? [] : ['classroom_id' => $c->classrooms[$room]['id']]), $c->admin);
        }
        foreach ([1, 2, 4, 5] as $room) $links::issueCode($c->classrooms[$room]['id'], 90, $c->admin);
        DB::table('lrn_access')->where('school_id', $c->school)->where('classroom_id', $c->classrooms[5]['id'])->update(['expires_at' => now()->subDays(3)]);
    }

    /** A second archive (an older year, students and grades) and two file-conversion runs: one imported into the registry, one still waiting with a problem row. */
    private function connectors(): void
    {
        $c = $this->c;
        if (!$c->has('connectors', 'con_archives', 'con_archive_files', 'con_rows', 'con_mapping_runs')) return;
        $service = app(\Saan\Modules\Connectors\ArchiveService::class);
        $year = (int) $c->term['year'] - 3;
        $archive = $service->create('ปีการศึกษา '.$year.' (ข้อมูลสมมติ)', $year, 'ไฟล์ตัวอย่างของชุดทดลอง');
        $students = "เลขประจำตัวนักเรียน,คำนำหน้า,ชื่อ,นามสกุล,ชั้น,ห้อง,เลขที่,เพศ\n";
        $grades = "เลขประจำตัวนักเรียน,ชื่อ-สกุล,ปีการศึกษา,ภาคเรียน,รหัสวิชา,ชื่อวิชา,คะแนน,เกรด,หน่วยกิต\n";
        foreach (array_slice(array_values($c->studentsOf(4)), 0, 6) as $n => $s) {
            $students .= implode(',', [$s['code'], $s['prefix'], $s['first'], $s['last'], 'ม.1', 1, $n + 1, $s['sex'] === 'M' ? 'ชาย' : 'หญิง'])."\n";
            foreach ([['ว21101', 'วิทยาศาสตร์ 1', 1.5], ['อ21101', 'ภาษาอังกฤษ 1', 1.5]] as $j => [$code, $name, $credit]) {
                $score = 50 + DemoNames::pick('older'.$n.$j, 45);
                $grades .= implode(',', [$s['code'], $s['first'].' '.$s['last'], $year, 1, $code, $name, $score, $score >= 80 ? 4 : ($score >= 70 ? 3 : ($score >= 60 ? 2 : 1)), $credit])."\n";
            }
        }
        $service->importFile((int) $archive['id'], 'student', 'generic_th', [], 'students-'.$year.'.csv', $students);
        $service->importFile((int) $archive['id'], 'grade', 'generic_th', [], 'grades-'.$year.'.csv', $grades);

        if (!\Saan\Modules\Connectors\MappingService::registryAvailable()) return;
        $map = app(\Saan\Modules\Connectors\MappingService::class);
        // the old register has the right birth day of two children: those rows are the "update" rows of the import
        $file = "เลขประจำตัวนักเรียน,คำนำหน้า,ชื่อ,นามสกุล,ชั้น,ห้อง,เลขที่,เพศ,วันเกิด\n";
        foreach (array_slice(array_values($c->studentsOf(0)), 0, 5) as $n => $s) {
            $birth = CarbonImmutable::parse((string) DB::table('students')->where('id', $s['id'])->value('birth_date'));
            if ($n < 2) $birth = $birth->day($birth->day === 15 ? 14 : 15);
            $file .= implode(',', [$s['code'], $s['prefix'], $s['first'], $s['last'], 'ม.1', 1, $s['roll'], $s['sex'] === 'M' ? 'ชาย' : 'หญิง', $birth->toDateString()])."\n";
        }
        $run = $map->stage($file, 'ทะเบียนนักเรียนระบบเดิม.csv', 'generic_th', []);
        $map->commit((int) $run['run']['id'], (int) $run['run']['version']);
        $waiting = $file."99001,เด็กชาย,ตัวอย่าง,ผิดห้อง,ม.9,9,1,ชาย\n";
        $map->stage($waiting, 'นักเรียนใหม่-รอตรวจ.csv', 'generic_th', []);
    }

    /** Three more clients of the data gateway (a switched-off one, one with an address allow-list, one for student sign-in) and their request log. */
    private function gateway(): void
    {
        $c = $this->c;
        if (!$c->has('api-gateway', 'api_clients', 'api_request_log')) return;
        $rows = [];
        foreach ([['ระบบเช็คชื่อด้วยบัตรสมมติ', ['students:read:basic', 'classrooms:read', 'attendance:read:summary'], ['203.0.113.10', '203.0.113.11'], null, 60, true, 2],
            ['แอปห้องเรียนออนไลน์สมมติ (เข้าสู่ระบบนักเรียน)', ['sso:student', 'classrooms:read'], null, ['https://example.org/saan-demo/app/callback'], 120, true, 1],
            ['ระบบสำรวจความพึงพอใจเดิม (ปิดใช้งาน)', ['teachers:read:basic'], null, null, 30, false, null]] as [$name, $scopes, $ips, $uris, $rate, $active, $used]) {
            $client = $c->insertId('api_clients', ['name' => $name, 'name_key' => \Saan\Modules\ApiGateway\Net::key($name), 'client_id' => 'cl_'.bin2hex(random_bytes(10)), 'secret_hash' => $c->lockedHash(),
                'scopes' => $scopes, 'allowed_ips' => $ips, 'redirect_uris' => $uris, 'rate_per_minute' => $rate, 'is_active' => $active, 'last_used_at' => $used === null ? null : $c->at($c->today->subDays($used), '09:40')]);
            if ($used !== null) for ($i = 0; $i < 8; $i++) {
                $rows[] = ['client_id' => $client, 'route' => ['api/v1/students', 'api/v1/classrooms', 'api/v1/attendance/summary'][$i % 3], 'status' => $i % 5 === 4 ? 429 : 200, 'ms' => 25 + DemoNames::pick('ms2'.$client.$i, 70),
                    'at' => $c->at($c->today->subDays($used + intdiv($i, 3)), sprintf('09:%02d', 10 + $i * 5))];
            }
        }
        $c->insert('api_request_log', $rows);
    }

    // ================================================================ after the portal accounts

    /**
     * Guardian portal: accounts in every state (active, switched off), invitation links in every state (open, used, expired, revoked), verified and
     * waiting e-mail contacts, and "acknowledged" marks that a guardian pressed. Accounts added here have a password nobody knows (an administrator sends a link).
     */
    public function guardians(): void
    {
        $c = $this->c;
        if (!$c->has('guardian-portal', 'gp_accounts', 'gp_links', 'gp_acks', 'gp_contacts') || !$c->guardians) return;
        $links = \Saan\Modules\GuardianPortal\Links::class;
        $locked = $c->lockedHash();
        foreach ([[1, 'active'], [2, 'active'], [3, 'disabled'], [4, 'invite'], [5, 'expired'], [6, 'revoked']] as [$k, $state]) {
            $guardian = $c->guardians[$k];
            $student = $c->students[$k]['id'];
            if ($state === 'active' || $state === 'disabled') {
                $email = 'parent'.$k.'@'.DemoNames::MAIL_DOMAIN;
                $c->insertId('gp_accounts', ['guardian_id' => $guardian, 'email' => $email, 'email_key' => $email, 'password' => $locked, 'is_active' => $state === 'active', 'must_change' => false, 'auth_version' => 1,
                    'last_login_at' => $state === 'active' ? $c->at($c->today->subDays($k), '19:30') : null]);
                $c->insertId('gp_links', ['guardian_id' => $guardian, 'student_id' => $student, 'token_hash' => hash('sha256', random_bytes(24)), 'purpose' => 'invite', 'expires_at' => now(), 'used_at' => now(), 'created_by' => $c->admin->id]);
            } elseif ($state === 'invite') {
                $links::create($c->admin, $student, $guardian, 'invite', 14);
            } elseif ($state === 'expired') {
                $c->insertId('gp_links', ['guardian_id' => $guardian, 'student_id' => $student, 'token_hash' => hash('sha256', random_bytes(24)), 'purpose' => 'invite', 'expires_at' => now()->subDays(3),
                    'created_by' => $c->admin->id, 'created_at' => now()->subDays(17), 'updated_at' => now()->subDays(17)]);
            } else {
                $made = $links::create($c->admin, $student, $guardian, 'view', 14);
                $links::revoke((int) $made['link']->id, (int) $made['link']->version);
            }
        }

        // e-mail addresses for password reset: two verified, one still waiting for the link to be pressed
        foreach ([['guardian', $c->guardians[0], 'guardian1', true], ['guardian', $c->guardians[21], 'guardian2', true], ['guardian', $c->guardians[1], 'parent1', false], ['student', $c->students[0]['id'], 'student0', true]] as [$kind, $subject, $name, $verified]) {
            $email = $name.'@'.DemoNames::MAIL_DOMAIN;
            $c->insertId('gp_contacts', ['kind' => $kind, 'subject_id' => $subject, 'email' => $email, 'email_key' => $email, 'verify_hash' => $verified ? null : hash('sha256', random_bytes(24)),
                'verify_expires_at' => $verified ? null : now()->addMinutes(\Saan\Modules\GuardianPortal\Contacts::VERIFY_MINUTES), 'verified_at' => $verified ? now() : null]);
        }

        // acknowledgements, written by the module itself (they stamp the behaviour record and leave a portal event)
        $request = Request::create('/guardian/api/acknowledge', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
        $acks = \Saan\Modules\GuardianPortal\Acks::class;
        // never for the two trial guardian accounts: whoever tries the portal must still find the "รับทราบ" buttons to press
        $trial = array_map(fn ($k) => $c->guardians[$k] ?? 0, DemoAcademic::GUARDIAN_ACCOUNTS);
        foreach ([1, 2] as $n => $k) {
            $acks::acknowledge($request, $c->guardians[$k], $c->students[$k]['id'], 'attendance_month', null, $c->today->format('Y-m'), null);
            if ($n === 0) $acks::acknowledge($request, $c->guardians[$k], $c->students[$k]['id'], 'term_result', (int) $c->prev['id'], null, null);
        }
        if ($c->has('behavior', 'bhv_records')) {
            $done = 0;
            foreach ($c->students as $k => $s) {
                if ($done >= 2) break;
                if (!isset($c->guardians[$k]) || in_array($c->guardians[$k], $trial, true)) continue;
                $record = DB::table('bhv_records')->where('school_id', $c->school)->where('student_id', $s['id'])->where('status', 'active')->where('guardian_visible', true)->whereNull('guardian_ack_at')->orderBy('id')->first();
                if (!$record) continue;
                $acks::acknowledge($request, $c->guardians[$k], $s['id'], 'behavior', (int) $record->id, null, $done === 0 ? 'รับทราบแล้ว ขอบคุณครูที่แจ้ง (ข้อมูลสมมติ)' : null);
                $done++;
            }
        }

        // notices of the school in the portal: some already read
        foreach ([[0, 'ใบแจ้งหนี้ค่าธรรมเนียมภาคเรียนใหม่ออกแล้ว', 'โปรดชำระภายในกำหนด หรือแจ้งชำระผ่านหน้านี้ (ข้อความสมมติ)', true], [0, 'ประชุมผู้ปกครองต้นภาคเรียน', 'ครูประจำชั้นนัดพบผู้ปกครองตามกำหนดการของโรงเรียน (ข้อความสมมติ)', true],
            [0, 'โรงเรียนได้รับสลิปการโอนเงินของท่านแล้ว', 'เจ้าหน้าที่การเงินกำลังตรวจสอบ ผลจะแจ้งในหน้านี้ (ข้อความสมมติ)', false], [21, 'แจ้งกำหนดการสอบกลางภาค', 'ขอให้นักเรียนเตรียมอุปกรณ์การสอบและมาถึงโรงเรียนก่อนเวลา (ข้อความสมมติ)', false],
            [21, 'ใบเสร็จรับเงินพร้อมให้ดาวน์โหลด', 'ท่านดาวน์โหลดใบเสร็จได้ที่หน้าค่าธรรมเนียม (ข้อความสมมติ)', true]] as [$k, $title, $body, $read]) {
            \Saan\Modules\GuardianPortal\Notices::push($c->guardians[$k], $c->students[$k]['id'], 'fee_invoice', null, $title, $body);
            if ($read) DB::table('gp_notices')->where('id', DB::table('gp_notices')->max('id'))->update(['read_at' => now()]);
        }
    }

    /** The student portal: accounts a teacher issued a PIN for (some must still change it, one is locked after wrong tries). The PINs themselves are nobody's. */
    public function studentPins(): void
    {
        $c = $this->c;
        if (!$c->has('student-portal', 'api_student_pins')) return;
        foreach ([[1, 'issued'], [2, 'used'], [3, 'locked'], [4, 'used'], [5, 'issued']] as [$k, $state]) {
            $c->insertId('api_student_pins', ['student_id' => $c->students[$k]['id'], 'pin_hash' => $c->lockedHash(), 'failed_count' => $state === 'locked' ? 5 : 0, 'locked_until' => $state === 'locked' ? now()->addMinutes(20) : null,
                'issued_by' => $c->admin->id, 'issued_at' => now()->subDays(6 - $k), 'lock_count' => $state === 'locked' ? 1 : 0, 'must_change' => $state === 'issued', 'auth_version' => 1, 'secret_kind' => 'pin',
                'last_login_at' => $state === 'used' ? $c->at($c->today->subDays($k), '07:50') : null, 'changed_at' => $state === 'used' ? $c->at($c->today->subDays($k + 1), '19:10') : null]);
        }
    }

    // ================================================================ website

    /** Surveys of every kind and state: a draft, a roster survey (some codes used), a closed anonymous one, an e-mail one with answers waiting for their link. */
    public function surveys(): void
    {
        $c = $this->c;
        if (!$c->has('surveys', 'srv_surveys', 'srv_responses', 'srv_roster', 'srv_pending')) return;
        $svc = app(\Saan\Modules\Surveys\SurveyService::class);
        $year = (int) $c->term['year'];
        $make = fn (string $slug, string $title, string $intro, string $mode, array $questions, string $status = 'draft') => Mutation::insert('srv_surveys', ['slug' => $slug.'-'.$year, 'title' => $title, 'intro' => $intro,
            'identity_mode' => $mode, 'status' => $status, 'certificate' => null, 'questions' => $svc->normaliseQuestions($questions)], 'survey.created', ['slug' => $slug.'-'.$year]);
        $scale = fn (string $key, string $label) => ['key' => $key, 'type' => 'scale', 'label' => $label, 'required' => true, 'max' => 5];
        $text = ['key' => 'comment', 'type' => 'text', 'label' => 'ข้อเสนอแนะเพิ่มเติม', 'required' => false];
        $hash = fn (object $s, string $ip) => $svc->ipHash($ip, (int) $s->id);
        $answers = fn (object $s, array $raw) => $svc->validateAnswers($svc->questions($s), $raw);

        $make('club-wish', 'แบบสำรวจความสนใจชุมนุมใหม่ ปีการศึกษา '.$year, 'ฉบับร่างของชุดทดลอง ยังไม่เปิดให้ตอบ', 'anonymous', [['key' => 'wish', 'type' => 'multi', 'label' => 'ชุมนุมที่อยากให้เปิดเพิ่ม', 'required' => true, 'options' => ['ทำอาหาร', 'ถ่ายภาพ', 'ฟุตซอล', 'ดาราศาสตร์']], $text]);

        $roster = $make('sports-day', 'แบบประเมินกิจกรรมกีฬาสีภายใน ปีการศึกษา '.$year, 'ตอบด้วยรหัสรายชื่อที่ครูประจำชั้นแจกให้ (รหัสสมมติ)', 'roster_code', [$scale('fun', 'ความสนุกสนานของกิจกรรม'), $scale('safe', 'ความปลอดภัยในการแข่งขัน'), $text], 'open');
        $codes = [];
        $rows = [];
        foreach (array_values($c->studentsOf(1)) as $i => $s) {
            $codes[$i] = 'SP'.strtoupper(bin2hex(random_bytes(4)));
            $rows[] = ['survey_id' => $roster->id, 'code_hash' => $svc->key((int) $roster->id, 'roster_code', $codes[$i]), 'display_name' => $s['prefix'].$s['first'].' '.$s['last'], 'used_at' => null];
        }
        DB::table('srv_roster')->insert($rows);
        $survey = $svc->find((int) $roster->id);
        foreach (DB::table('srv_roster')->where('survey_id', $roster->id)->orderBy('id')->limit(11)->get() as $i => $r) {
            $svc->finalize($survey, (string) $r->code_hash, (string) $r->display_name, $answers($survey, ['fun' => 3 + DemoNames::pick('sp-f'.$i, 3), 'safe' => 3 + DemoNames::pick('sp-s'.$i, 3)] + ($i % 4 === 0 ? ['comment' => 'อยากให้มีน้ำดื่มเพิ่มตามจุดแข่ง (คำตอบสมมติ)'] : [])), '', (int) $r->id);
        }
        DB::table('srv_responses')->where('survey_id', $roster->id)->update(['ip_hash' => '']);
        $c->accounts[] = ['role' => 'รหัสรายชื่อตัวอย่างของแบบประเมินกิจกรรมกีฬาสี (หน้าแบบสำรวจ)', 'login' => '-', 'password' => $codes[19], 'url' => DemoAcademic::publicBase($c).'/portal/surveys/sports-day-'.$year,
            'note' => 'ไม่ใช่บัญชี: รหัสของนักเรียนที่ยังไม่ได้ตอบ ใช้ได้ครั้งเดียว'];

        $closed = $make('safety', 'แบบสำรวจความปลอดภัยในโรงเรียน ปีการศึกษา '.$year, 'แบบสำรวจตัวอย่างที่ปิดรับคำตอบแล้ว', 'anonymous', [$scale('overall', 'ความรู้สึกปลอดภัยเมื่ออยู่ในโรงเรียน'), $text], 'open');
        $open = $svc->find((int) $closed->id);
        for ($i = 0; $i < 8; $i++) $svc->submit($open, ['a' => ['overall' => 3 + DemoNames::pick('sf-o'.$i, 3)] + ($i % 3 === 0 ? ['comment' => 'อยากให้มีไฟส่องสว่างทางเดินหลังอาคาร 2 (คำตอบสมมติ)'] : [])], '203.0.113.'.(100 + $i), null);
        DB::table('srv_responses')->where('survey_id', $closed->id)->update(['ip_hash' => '']);
        Mutation::update('srv_surveys', (int) $closed->id, (int) DB::table('srv_surveys')->where('id', $closed->id)->value('version'), ['status' => 'closed'], 'survey.close');

        $camp = $make('camp', 'แบบประเมินค่ายวิชาการ ปีการศึกษา '.$year, 'ตอบด้วยชื่อและอีเมล ระบบส่งลิงก์ยืนยันให้ทางอีเมล', 'name_email', [$scale('content', 'เนื้อหากิจกรรมเหมาะสม'), $text], 'open');
        $survey = $svc->find((int) $camp->id);
        $people = array_slice(array_values($c->studentsOf(3)), 0, 7);
        foreach ($people as $i => $s) {
            $email = 'camp'.($i + 1).'@'.DemoNames::MAIL_DOMAIN;
            $key = $svc->key((int) $camp->id, 'name_email', \Saan\Modules\Surveys\SurveyService::emailIdentity($email));
            $raw = $answers($survey, ['content' => 3 + DemoNames::pick('camp'.$i, 3)]);
            $name = $s['prefix'].$s['first'].' '.$s['last'];
            if ($i < 4) $svc->finalize($survey, $key, $name, $raw, '');
            else DB::table('srv_pending')->insert(['survey_id' => $camp->id, 'respondent_key' => $key, 'display_name' => $name, 'answers' => json_encode($raw, JSON_UNESCAPED_UNICODE),
                'response_id' => null, 'expires_at' => now()->addHours(\Saan\Modules\Surveys\SurveyService::VERIFY_HOURS), 'created_at' => now(), 'nonce' => bin2hex(random_bytes(16))]);
        }
        DB::table('srv_responses')->where('survey_id', $camp->id)->update(['ip_hash' => '']);
    }

    /** A draft list, a list that is published, and last term's list that was archived. */
    public function notices(): void
    {
        $c = $this->c;
        if (!$c->has('web-notices', 'notice_lists', 'notice_rows')) return;
        $repo = app(\Saan\Modules\WebNotices\NoticeRepository::class);
        $year = (int) $c->term['year'];
        $make = function (string $slug, string $title, string $intro, array $columns, string $mode, int $from, int $to, int $offset, int $count, callable $cells, ?string $then) use ($c, $repo) {
            $header = ['order_no' => 'ลำดับ', 'display_name' => 'ชื่อ-สกุล', 'classroom' => 'ห้องเรียน', 'group' => 'กลุ่ม', 'note' => 'หมายเหตุ'];
            $text = implode("\t", array_map(fn ($k) => $header[$k], $columns))."\n";
            for ($i = 0; $i < $count; $i++) {
                [, $prefix, $first, $last] = DemoNames::student($offset + $i * 5, 1 + $i % 6);
                $text .= implode("\t", array_map(fn ($k) => $cells($k, $i, $prefix.' '.$first.' '.$last), $columns))."\n";
            }
            $list = $repo->create(['title' => $title, 'slug' => $slug, 'intro' => $intro, 'columns' => $columns, 'name_mode' => $mode, 'publish_from' => $c->today->subDays($from)->toDateString(), 'expires_on' => $c->today->addDays($to)->toDateString()])['list'];
            $list = $repo->replaceRows((int) $list['id'], (int) $list['version'], $text)['list'];
            foreach ($then ? explode(',', $then) : [] as $action) $list = $repo->setStatus((int) $list['id'], (int) DB::table('notice_lists')->where('id', $list['id'])->value('version'), $action)['list'];
        };
        $cells = fn (string $k, int $i, string $name) => match ($k) {
            'order_no' => (string) ($i + 1), 'display_name' => $name, 'classroom' => 'ม.'.(1 + $i % 6).'/1', 'group' => 'กลุ่ม '.chr(65 + $i % 4), default => $i < 8 ? 'ได้รับทุน' : 'สำรองรับทุน',
        };
        $make('scholarship-'.$year, 'ประกาศรายชื่อนักเรียนที่ได้รับทุนการศึกษา ปีการศึกษา '.$year.' (รายชื่อสมมติ)', 'ฉบับร่างของชุดทดลอง ทุกชื่อเป็นชื่อสมมติ ยังไม่เผยแพร่', ['order_no', 'display_name', 'classroom', 'note'], 'first_only', 0, 30, 220, 12, $cells, null);
        $make('science-camp-'.$year,'ประกาศรายชื่อนักเรียนที่เข้าร่วมค่ายวิชาการ ปีการศึกษา '.$year.' (รายชื่อสมมติ)', 'รายชื่อตัวอย่างของชุดทดลอง แบ่งตามกลุ่มกิจกรรม', ['order_no', 'display_name', 'classroom', 'group'], 'first_initial', 3, 40, 300, 16, $cells, 'publish');
        $make('m4-admission-'.($year - 1), 'ประกาศผลการคัดเลือกเข้าเรียนชั้น ม.4 ปีการศึกษา '.($year - 1).' (รายชื่อสมมติ)', 'ประกาศของปีที่ผ่านมา เก็บถาวรแล้ว', ['order_no', 'display_name', 'note'], 'first_initial', 10, 20, 380, 10, $cells, 'publish,archive');
    }

    /** More complaint cases in every stage (worked, overdue, answered, closed, last year's), with notes, an accused person, attached files and the yearly summaries. */
    public function complaints(): void
    {
        $c = $this->c;
        if (!$c->has('complaints', 'cmp_cases', 'cmp_actions', 'cmp_notes', 'cmp_files', 'cmp_accused')) return;
        $rules = \Saan\Modules\Complaints\Complaints::class;
        $cases = \Saan\Modules\Complaints\Cases::class;
        $contact = fn (string $name, string $mail) => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['name' => $name, 'email' => $mail, 'phone' => ''], JSON_UNESCAPED_UNICODE));
        $file = function (int $id, string $name, string $mime, string $bytes, string $at) use ($c) {
            $path = $c->put('complaints/'.$c->school.'/'.bin2hex(random_bytes(16)).'.'.($mime === 'application/pdf' ? 'pdf' : 'png'), $bytes);
            $c->insertId('cmp_files', ['case_id' => $id, 'name' => $name, 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'created_at' => $at]);
        };
        $png = DemoContext::png(['Complaint attachment', 'Sample photo']) ?? DemoContext::pdf('Complaint attachment');
        $isPng = str_starts_with($png, "\x89PNG");
        $add = function (string $category, ?string $who, string $subject, string $detail, int $ago, string $status = 'received', ?string $reply = null, ?int $closedAfter = null) use ($c, $rules) {
            $received = CarbonImmutable::parse($c->today->subDays($ago)->toDateString().' 10:20', 'Asia/Bangkok');
            $at = $c->at($received->toDateString(), '10:20');
            $answered = $reply ? $c->at($received->addDays(3)->toDateString(), '14:00') : null;
            $closed = $closedAfter !== null ? $c->at($received->addDays($closedAfter)->toDateString(), '16:00') : null;
            $receipt = $rules::newReceipt();
            $id = $c->insertId('cmp_cases', ['receipt_no' => $receipt, 'track_hash' => $rules::trackHash($receipt, $rules::newTrackingCode()), 'envelope_hash' => null, 'category' => $category, 'anonymous' => $who === null, 'contact' => $who,
                'subject' => $subject, 'detail' => $detail, 'status' => $closed ? 'closed' : ($reply ? 'replied' : 'received'), 'assignee_id' => $closed || $reply ? $c->admin->id : null, 'due_on' => $rules::dueDate($category, $received),
                'received_at' => $at, 'status_changed_at' => $closed ?? $answered ?? $at, 'public_reply' => $reply, 'public_reply_at' => $answered, 'closed_at' => $closed, 'created_at' => $at, 'updated_at' => $closed ?? $answered ?? $at]);
            $actions = [['case_id' => $id, 'actor_id' => null, 'action' => 'received', 'detail' => json_encode(['files' => 0]), 'created_at' => $at]];
            if ($reply) $actions[] = ['case_id' => $id, 'actor_id' => $c->admin->id, 'action' => 'replied', 'detail' => null, 'created_at' => $answered];
            if ($closed) $actions[] = ['case_id' => $id, 'actor_id' => $c->admin->id, 'action' => 'closed', 'detail' => null, 'created_at' => $closed];
            $c->insert('cmp_actions', $actions);
            return [$id, $at];
        };
        $parent = fn (int $n) => $contact('ผู้ปกครองตัวอย่าง '.$n.' (สมมติ)', 'parent'.$n.'@'.DemoNames::MAIL_DOMAIN);

        // being worked: assigned, an accused teacher, two internal notes, a file; late on purpose (received 24 days ago, due after 15)
        [$id, $at] = $add('general', $parent(1), 'ร้องเรียนเรื่องการมาสายของครูผู้สอน', 'ผู้ปกครองแจ้งว่าครูผู้สอนวิชาหนึ่งเข้าสอนช้ากว่าเวลาหลายครั้งในเดือนที่ผ่านมา ขอให้โรงเรียนตรวจสอบ (ข้อมูลสมมติ)', 24);
        $file($id, 'บันทึกวันเวลาที่เข้าสอนช้า.pdf', 'application/pdf', DemoContext::pdf('Complaint attachment', ['Sample enclosure']), $at);
        $v = $cases::assign($c->admin, $id, 1, (int) $c->admin->id)['case']['version'];
        $cases::setAccused($c->admin, $id, (int) $v, [(int) $c->users['t11']]);
        $cases::addNote($c->admin, $id, 'สอบถามครูผู้ถูกร้องเบื้องต้น ให้ส่งคำชี้แจงเป็นลายลักษณ์อักษร (บันทึกสมมติ)');
        $cases::addNote($c->admin, $id, 'ตรวจสมุดลงเวลาสอนย้อนหลัง 1 เดือนแล้ว รอสรุปเสนอผู้อำนวยการ (บันทึกสมมติ)');

        // answered information request, a closed suggestion, a case from last fiscal year (closed), and one that arrived today with a photo
        $add('info_request', $parent(2), 'ขอสำเนาแผนปฏิบัติการประจำปี', 'ขอข้อมูลแผนปฏิบัติการและงบประมาณของโรงเรียนเพื่อประกอบการประชุมผู้ปกครอง (ข้อมูลสมมติ)', 5, 'replied',
            'โรงเรียนเผยแพร่แผนปฏิบัติการไว้ในหน้า ITA ของเว็บไซต์ และจัดเตรียมสำเนาไว้ที่ห้องธุรการ (ข้อความตอบตัวอย่าง)');
        $add('suggestion', null, 'ข้อเสนอแนะเรื่องร่มเงาที่สนามกีฬา', 'อยากให้มีหลังคาหรือที่นั่งพักใต้ร่มเงาบริเวณสนามกีฬา (ข้อมูลสมมติ)', 20, 'closed', 'ขอบคุณสำหรับข้อเสนอ โรงเรียนบรรจุไว้ในแผนปรับปรุงภูมิทัศน์แล้ว (ข้อความตอบตัวอย่าง)', 7);
        $add('general', $parent(3), 'ขอให้ตรวจสอบค่าธรรมเนียมที่เรียกเก็บซ้ำ', 'ผู้ปกครองได้รับใบแจ้งหนี้ซ้ำสองใบสำหรับรายการเดียวกัน (ข้อมูลสมมติ)', 430, 'closed', 'ตรวจสอบแล้ว ยกเลิกใบแจ้งหนี้ที่ซ้ำและแจ้งผู้ปกครองทางโทรศัพท์ (ข้อความตอบตัวอย่าง)', 9);
        [$id, $at] = $add('general', null, 'แจ้งปัญหาน้ำขังหน้าอาคารเรียน 2', 'หลังฝนตกมีน้ำขังบริเวณทางเดินหน้าอาคาร 2 นักเรียนลื่นล้มบ่อย (ข้อมูลสมมติ)', 0);
        if ($isPng) $file($id, 'ภาพน้ำขัง.png', 'image/png', $png, $at);

        // the only kind that the principal alone sees: assigned to the principal, an accused teacher and a note, written as the principal
        [$id] = $add('corruption', null, 'เรื่องตัวอย่างสำหรับทดลองการสืบสวนของผู้บริหาร', 'ข้อความสมมติสำหรับทดลองขั้นตอนของผู้บริหารที่ได้รับสิทธิ์ ไม่มีเหตุการณ์จริง', 8);
        $principal = $c->user('principal');
        $v = $cases::assign($principal, $id, 1, (int) $principal->id)['case']['version'];
        $cases::setAccused($principal, $id, (int) $v, [(int) $c->users['t5']]);
        $cases::addNote($principal, $id, 'นัดหมายคณะกรรมการสอบข้อเท็จจริงประชุมครั้งแรก (บันทึกสมมติ)');
    }

    /** The summaries of the two earlier fiscal years: last year's published, the year before kept as a draft. Runs after the cases above exist (they are counted). */
    public function complaintsReports(): void
    {
        $c = $this->c;
        if (!$c->has('complaints', 'cmp_cases', 'cmp_reports') || !class_exists(\Saan\Modules\Complaints\Reports::class)) return;
        $rules = \Saan\Modules\Complaints\Complaints::class;
        \Saan\Modules\Complaints\Reports::publish($rules::currentFiscalYear() - 1, 'publish', $c->admin);
        \Saan\Modules\Complaints\Reports::publish($rules::currentFiscalYear() - 2, 'publish', $c->admin);
        \Saan\Modules\Complaints\Reports::publish($rules::currentFiscalYear() - 2, 'unpublish', $c->admin);
    }

    /** Website: pages edited and published again (the revision list), a draft, a scheduled and a trashed article, old addresses that redirect, more messages. */
    public function site(): void
    {
        $c = $this->c;
        if (!$c->has('web-cms', 'cms_post_revisions', 'cms_redirects', 'cms_messages')) return;
        $content = app(\App\Domain\Cms\WebsiteContent::class);
        $edit = function (string $body, array $meta, string $extra): string {
            return ($meta['body_format'] ?? 'plain') === 'html' ? $body.'<p>'.$extra.'</p>' : $body."\n\n".$extra;
        };
        foreach (DB::table('posts')->where('school_id', $c->school)->where('status', 'published')->where('content_type', 'article')->whereNull('deleted_at')->orderByDesc('published_at')->limit(3)->pluck('id') as $n => $id) {
            $row = $content->read((int) $id);
            foreach (['เพิ่มรายละเอียดเวลาและสถานที่จัดกิจกรรม (แก้ไขตัวอย่าง)', 'ปรับถ้อยคำและเพิ่มช่องทางสอบถามจากโรงเรียน (แก้ไขตัวอย่าง)'] as $step => $extra) {
                $d = $row['draft'];
                $d['body'] = $edit((string) $d['body'], (array) ($d['metadata'] ?? []), $extra);
                if ($n === 2 && $step === 1) break;
                $row = $content->save($d + ['intent' => 'publish', 'version' => $row['version']], (int) $id);
            }
        }
        $base = ['content_type' => 'article', 'category' => 'news', 'metadata' => ['body_format' => 'plain', 'source_kind' => 'mock'], 'excerpt' => '', 'cover_media_id' => null, 'is_pinned' => false];
        $content->save($base + ['title' => 'ร่าง: ประกาศกำหนดการสอบปลายภาค', 'body' => 'ร่างข่าวตัวอย่าง รอผู้อำนวยการตรวจทานก่อนเผยแพร่ (ข้อมูลสมมติ)', 'intent' => 'draft']);
        $content->save($base + ['title' => 'นัดหมายประชุมผู้ปกครองปลายภาคเรียน', 'body' => 'ตั้งเวลาเผยแพร่ล่วงหน้า ประชุมผู้ปกครองที่ห้องประชุมโรงเรียน (ข่าวสมมติ)', 'intent' => 'schedule', 'scheduled_at' => $c->today->addDays(3)->toDateString().' 09:00']);
        $old = $content->save($base + ['title' => 'ข่าวเก่าที่ย้ายลงถังขยะ', 'body' => 'ข่าวตัวอย่างที่เจ้าหน้าที่ลบแล้ว กู้คืนได้จากถังขยะ (ข้อมูลสมมติ)', 'intent' => 'draft']);
        $content->action((int) $old['id'], (int) $old['version'], 'trash');

        foreach ([['/old-news', '/portal/news', 301, true], ['/admission-old', '/portal/pages', 301, true], ['/contact-us', '/portal/contact', 302, true], ['/old-calendar', '/portal/events', 301, false]] as [$from, $to, $code, $active]) {
            $c->insertId('cms_redirects', ['source_path' => \App\Domain\Cms\Redirects::normalize($from), 'target_path' => $to, 'status_code' => $code, 'is_active' => $active]);
        }
        $rows = [];
        foreach ([['ขอรับเอกสารรับรองการเป็นศิษย์เก่า', 'ศิษย์เก่าต้องการขอเอกสารรับรองเพื่อสมัครงาน ต้องทำอย่างไร', 'unread', 0], ['สอบถามกำหนดการเปิดภาคเรียน', 'ขอทราบวันเปิดภาคเรียนและรายการอุปกรณ์ที่ต้องเตรียม', 'unread', 1],
            ['ขอบคุณครูที่ดูแลนักเรียน', 'ขอขอบคุณครูประจำชั้นที่ช่วยดูแลบุตรหลานเป็นอย่างดี', 'read', 6], ['ขอยื่นข้อมูลบริจาคหนังสือ', 'ต้องการบริจาคหนังสืออ่านนอกเวลาให้ห้องสมุด ติดต่อที่ใด', 'archived', 20]] as $n => [$subject, $message, $status, $ago]) {
            $at = $c->at($c->today->subDays($ago), '14:10');
            $rows[] = ['name' => 'ผู้ติดต่อตัวอย่าง '.(6 + $n).' (สมมติ)', 'email' => 'visitor'.(6 + $n).'@'.DemoNames::MAIL_DOMAIN, 'subject' => $subject, 'message' => $message.' (ข้อความสมมติ)', 'status' => $status, 'created_at' => $at, 'updated_at' => $at];
        }
        $c->insert('cms_messages', $rows);
    }

    // ================================================================ registry, last (it changes a few students' status)

    /** Identity documents in every review state, import batches (committed, rolled back, waiting with problem rows), room moves and students who left. */
    public function registry(): void
    {
        $c = $this->c;
        if (!$c->has('registry', 'reg_documents', 'reg_import_batches', 'reg_import_rows')) return;
        // written like DocumentService::upload writes them, without its per-call storage count (see documents())
        $png = DemoContext::png(['Document sample', 'Fictional']);
        $rows = [];
        foreach ([[5, 'birth_certificate', 'สูติบัตร', 'accepted'], [6, 'photo', 'รูปถ่ายนักเรียน', 'accepted'], [7, 'birth_certificate', 'สูติบัตร', 'pending'], [8, 'house_registration', 'ทะเบียนบ้าน (สำเนา)', 'rejected'],
            [37, 'passport', 'หนังสือเดินทาง (ประเทศสมมติ)', 'accepted'], [58, 'house_registration', 'หนังสือรับรองการเกิด', 'pending'], [79, 'other', 'เอกสารรอผู้ปกครองนำมาส่ง', 'pending'], [99, 'transfer_record', 'ใบรับรองการย้ายโรงเรียน', 'rejected']] as [$k, $kind, $label, $review]) {
            $image = $png !== null && in_array($kind, ['photo', 'passport'], true);
            $bytes = $image ? $png : DemoContext::pdf('Identity document sample', ['Kind '.$kind, 'Fictional']);
            $rows[] = ['student_id' => $c->students[$k]['id'], 'kind' => $kind, 'label' => $label, 'path' => $c->put('registry/'.$c->school.'/'.$c->students[$k]['id'].'/'.bin2hex(random_bytes(16)).($image ? '.png' : '.pdf'), $bytes),
                'mime' => $image ? 'image/png' : 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'review_state' => $review, 'reviewed_by' => $review === 'pending' ? null : $c->admin->id, 'uploaded_by' => $c->admin->id];
        }
        $c->insert('reg_documents', $rows);

        $import = app(\Saan\Modules\Registry\ImportService::class);
        $guardians = fn (int $from, int $n) => "รหัสผู้ปกครอง,ชื่อ,นามสกุล,โทรศัพท์,อีเมล,อาชีพ\n".implode('', array_map(fn ($i) => implode(',', ['G'.($from + $i), DemoNames::MALE[$i], DemoNames::SURNAMES[10 + $i], DemoNames::phone(9000 + $from + $i),
            'g'.($from + $i).'@'.DemoNames::MAIL_DOMAIN, ['เกษตรกร', 'ค้าขาย', 'รับจ้างทั่วไป'][$i % 3]])."\n", range(0, $n - 1)));
        $import->commit((int) $import->preview('guardians', 'ผู้ปกครองใหม่ (ระบบเดิม).csv', $guardians(9001, 3))['batch']['id']);
        $second = $import->preview('guardians', 'ผู้ปกครองนำเข้าผิดไฟล์.csv', $guardians(9101, 2))['batch']['id'];
        $import->commit((int) $second);
        $import->rollback((int) $second);
        $new = "รหัสนักเรียน,คำนำหน้า,ชื่อ,นามสกุล,ห้องเรียน,เลขที่\n99101,เด็กชาย,ตัวอย่าง,นักเรียนใหม่,ม.1/1,21\n99102,เด็กหญิง,ตัวอย่าง,ห้องผิด,ม.9/9,22\n,เด็กชาย,ไม่มี,รหัส,ม.1/1,23\n";
        $import->preview('students', 'นักเรียนใหม่ รอตรวจ.csv', $new);

        // a child who went to another room and came back (the room history behind the attendance and report pages), two who left the school
        $days = $c->lastSchoolDays(15);
        foreach ([[5, 0, 1, 6, 11], [45, 2, 3, 4, 9]] as [$k, $a, $b, $out, $back]) {
            ClassroomMoves::record($c->school, $c->students[$k]['id'], $c->classrooms[$a]['id'], $c->classrooms[$b]['id'], $days[$out]->toDateString());
            ClassroomMoves::record($c->school, $c->students[$k]['id'], $c->classrooms[$b]['id'], $c->classrooms[$a]['id'], $days[$back]->toDateString());
        }
        foreach ([[17, 'transferred_out'], [57, 'left']] as [$k, $status]) {
            Mutation::update('students', $c->students[$k]['id'], (int) DB::table('students')->where('id', $c->students[$k]['id'])->value('version'), ['status' => $status], 'registry.student.updated', ['fields' => ['status']]);
        }
    }
}
