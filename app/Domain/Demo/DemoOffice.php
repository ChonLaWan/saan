<?php

namespace App\Domain\Demo;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Trial school, part 3: the office modules - personnel, leave and duty, payslips, fees, correspondence, maintenance, projects. */
final class DemoOffice
{
    public function __construct(private DemoContext $c) {}

    private static function money(int $satang): string
    {
        return sprintf('%d.%02d', intdiv($satang, 100), $satang % 100);
    }

    // ---------------------------------------------------------------- personnel

    /** A profile for every staff member (they form the public staff directory), portraits that already ship with the website sample, a few portfolio files. */
    public function personnel(): void
    {
        $c = $this->c;
        if (!$c->has('personnel', 'per_profiles', 'per_folders', 'per_files')) return;
        $staff = \Saan\Modules\Personnel\Staff::class;
        \Saan\Modules\Personnel\Folders::ensure();
        foreach (DemoNames::STAFF as $i => [, , , $group, $position, $standing, $work, , $portrait]) {
            $teacher = $c->teachers[$i]['id'];
            $profile = $staff::save($teacher, null, ['position' => $position, 'academic_standing' => $standing, 'department' => $i === 0 ? 'บริหารสถานศึกษา' : 'กลุ่มสาระการเรียนรู้'.$group,
                'work_group' => $work, 'start_date' => (2008 + DemoNames::pick('start'.$i, 14)).'-05-16',
                'education' => [['level' => 'ปริญญาตรี', 'degree' => $i === 0 ? 'ครุศาสตรบัณฑิต' : 'ศึกษาศาสตรบัณฑิต', 'major' => $group, 'institution' => 'มหาวิทยาลัยสมมติแห่งต้นธาร', 'year' => 2545 + DemoNames::pick('edu'.$i, 12)]],
                'show_in_directory' => true, 'directory_order' => $i]);
            $file = $portrait ? resource_path('install/demo/media/'.$portrait) : null;
            if ($file && is_file($file) && function_exists('imagecreatefromwebp')) {
                $staff::setPhoto($teacher, (int) $profile['version'], $c->upload((string) file_get_contents($file), 'portrait.webp', 'image/webp'));
                $c->track(DB::table('per_profiles')->where('school_id', $c->school)->where('teacher_id', $teacher)->value('photo_path'));
            }
        }
        $folders = DB::table('per_folders')->where('school_id', $c->school)->orderBy('sort')->orderBy('id')->limit(4)->pluck('id')->all();
        if (!$folders) return;
        $files = [[1, 'แผนการจัดการเรียนรู้ ภาษาไทย ม.1.pdf', 'approved', null], [1, 'รายงานผลการปฏิบัติงาน (SAR) รายบุคคล.pdf', 'pending', null], [1, 'บันทึกหลังสอน สัปดาห์ที่ 3.pdf', 'revise', 'ขอให้เพิ่มผลการประเมินผู้เรียนรายบุคคล (ตัวอย่าง)'],
            [7, 'แผนการจัดการเรียนรู้ คณิตศาสตร์ ม.4.pdf', 'pending', null], [7, 'วิจัยในชั้นเรียน เรื่องสมมติ.pdf', 'approved', null]];
        $rows = [];
        foreach ($files as $n => [$staffIndex, $name, $review, $note]) {
            $teacher = $c->teachers[$staffIndex];
            $folder = $folders[$n % count($folders)];
            $bytes = DemoContext::pdf('Portfolio file '.($n + 1), ['Teacher index '.$staffIndex, 'File number '.($n + 1)]);
            $path = $c->put('personnel/'.$c->school.'/'.$teacher['id'].'/'.$folder.'/'.bin2hex(random_bytes(16)).'.pdf', $bytes);
            $rows[] = ['teacher_id' => $teacher['id'], 'folder_id' => $folder, 'fiscal_or_school_year' => (int) $c->term['year'], 'original_name' => $name, 'path' => $path,
                'mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'dedupe_key' => hash('sha256', $bytes), 'status' => 'active',
                'review_status' => $review, 'uploaded_by' => $teacher['user_id'], 'reviewed_by' => $review === 'pending' ? null : $c->users['principal'],
                'reviewed_at' => $review === 'pending' ? null : now(), 'review_note' => $note];
        }
        $c->insert('per_files', $rows);
    }

    // ---------------------------------------------------------------- leave and duty

    /** Leave requests in every state, a duty roster for the last week and the next two, one open swap request. */
    public function leaveDuty(): void
    {
        $c = $this->c;
        if (!$c->has('leave-duty', 'ld_leave_types', 'ld_duty_swaps')) return;
        $leave = app(\Saan\Modules\LeaveDuty\Leave::class);
        $leave->ensureTypes();
        $past = $c->lastSchoolDays(16);
        $next = $c->nextSchoolDays(20);
        $back = fn (int $n) => $past[max(0, count($past) - 1 - $n)];
        $principal = $c->users['principal'];
        // staff index, type, first day, last day, state, note of the decision or the cancellation
        $plan = [
            [1, 'sick', $back(12), $back(12), 'approved', null], [1, 'personal', $next[5], $next[5], 'submitted', null],
            [7, 'vacation', $next[14], $next[14], 'draft', null], [7, 'personal', $back(8), $back(8), 'rejected', 'ตรงกับสัปดาห์สอบ ขอให้เลื่อนวันลา (ตัวอย่าง)'],
            [2, 'personal', $back(5), $back(5), 'approved', null], [3, 'sick', $back(3), $back(3), 'withdrawn', null],
            [4, 'personal', $next[8], $next[8], 'cancel_requested', 'ภารกิจเลื่อนออกไป ขอยกเลิกวันลา (ตัวอย่าง)'], [5, 'vacation', $next[11], $next[11], 'cancelled', 'ยกเลิกเพราะมีงานโรงเรียน (ตัวอย่าง)'],
            [6, 'sick', $next[1], $next[1], 'submitted', null], [12, 'vacation', $next[3], $next[4], 'approved', null],
        ];
        $reasons = ['sick' => 'ไม่สบาย มีไข้ พักรักษาตัว (ข้อมูลสมมติ)', 'personal' => 'ติดต่อราชการที่อำเภอธารทอง (ข้อมูลสมมติ)', 'vacation' => 'พักผ่อนประจำปีกับครอบครัว (ข้อมูลสมมติ)'];
        $away = [];
        $rows = [];
        foreach ($plan as [$staff, $type, $from, $to, $state, $note]) {
            // a leave may not cross the fiscal year (30 September / 1 October)
            if ($from->format('m-d') <= '09-30' && $to->format('m-d') >= '10-01' && $from->year === $to->year) $to = $from;
            $asked = $c->at($from->lt($c->today) ? $from->subDays(2) : $c->today->subDays(2), '09:30');
            $decided = in_array($state, ['approved', 'rejected', 'cancel_requested', 'cancelled'], true);
            if (in_array($state, ['approved', 'cancel_requested'], true)) for ($d = $from; $d->lte($to); $d = $d->addDay()) $away[$staff.'|'.$d->toDateString()] = true;
            $rows[] = ['teacher_id' => $c->teachers[$staff]['id'], 'starts_on' => $from->toDateString(), 'ends_on' => $to->toDateString(), 'type' => $type, 'reason' => $reasons[$type],
                'status' => $state, 'half_day' => null, 'days' => 0, 'fiscal_year' => null, 'submitted_at' => $state === 'draft' ? null : $asked,
                'decided_by' => $decided ? $principal : null, 'decided_at' => $decided ? $asked : null, 'decision_note' => $state === 'rejected' ? $note : null,
                'cancel_reason' => in_array($state, ['cancel_requested', 'cancelled'], true) ? $note : null, 'cancel_requested_at' => in_array($state, ['cancel_requested', 'cancelled'], true) ? $c->at($c->today, '08:10') : null,
                'cancelled_at' => $state === 'cancelled' ? $c->at($c->today, '09:00') : null, 'withdrawn_at' => $state === 'withdrawn' ? $asked : null, 'created_at' => $asked, 'updated_at' => $asked];
        }
        $c->insert('leave_requests', $rows);
        $leave->normalizeDerived(); // days and fiscal year are the module's derived columns

        $kinds = [['morning', '07:00', '08:00', 'ประตูหน้าโรงเรียน'], ['lunch', '12:05', '12:55', 'โรงอาหาร'], ['evening', '15:40', '16:30', 'จุดรับส่งนักเรียน']];
        $rows = [];
        foreach (array_merge(array_slice($past, -6), array_slice($next, 0, 10)) as $n => $day) {
            foreach ($kinds as $j => [$kind, $from, $to, $place]) {
                $staff = ($n * 3 + $j) % 12 + 1;
                for ($try = 0; $try < 12 && isset($away[$staff.'|'.$day->toDateString()]); $try++) $staff = $staff % 12 + 1;
                $rows[] = ['teacher_id' => $c->teachers[$staff]['id'], 'date' => $day->toDateString(), 'starts_at' => $from, 'ends_at' => $to, 'location' => $place, 'status' => 'assigned', 'kind' => $kind];
            }
        }
        $c->insert('duties', $rows);
        $duty = DB::table('duties')->where('school_id', $c->school)->where('date', $next[2]->toDateString())->where('kind', 'morning')->first();
        if ($duty) {
            $from = (int) $duty->teacher_id;
            $to = collect($c->teachers)->slice(1)->first(fn ($t) => $t['id'] !== $from && !DB::table('duties')->where('date', $duty->date)->where('teacher_id', $t['id'])->exists());
            if ($to) $c->insertId('ld_duty_swaps', ['duty_id' => $duty->id, 'from_teacher_id' => $from, 'to_teacher_id' => $to['id'], 'status' => 'requested', 'open_duty_id' => $duty->id,
                'note' => 'ติดประชุมกลุ่มสาระช่วงเช้า ขอแลกเวร (ข้อมูลสมมติ)']);
        }
    }

    // ---------------------------------------------------------------- payslip

    /** One published period (last month) and one draft (this month) for all staff. The amounts are repeated digits so nobody takes them for real pay. */
    public function payslip(): void
    {
        $c = $this->c;
        if (!$c->has('payslip', 'psl_payees', 'psl_periods', 'psl_slips')) return;
        Settings::put('payslip.officer_user_ids', [$c->users['payroll']]);
        Settings::put('payslip.payer_name', DemoNames::SCHOOL.' (สมมติ)');
        Settings::put('payslip.payer_tax_id', '0-9999-00000-00-0');
        Settings::put('payslip.payer_address', DemoNames::ADDRESS);
        $payees = \Saan\Modules\Payslip\Payees::class;
        $slips = \Saan\Modules\Payslip\Slips::class;
        $periods = \Saan\Modules\Payslip\Periods::class;
        $people = [];
        foreach (DemoNames::STAFF as $i => $s) $people[] = [$s[7] ?? 't'.$i, $i === 0 ? 5555500 : (str_contains($s[5], 'ชำนาญการ') ? 3333300 : 2222200), str_contains($s[5], 'ชำนาญการ') ? 350000 : 0];
        $people[] = ['editor', 1111100, 0];
        $made = [];
        foreach ($people as $n => [$key, $salary, $standing]) {
            $user = $c->user($key);
            $row = Mutation::insert('psl_payees', ['user_id' => $user->id, 'name' => $user->name] + $payees::columns(['payee_code' => sprintf('P%03d', $n + 1),
                'national_id' => DemoNames::thaiId(8000 + $n), 'bank_name' => 'ธนาคารสมมติ', 'bank_account' => sprintf('000%07d', 500 + $n)], true), 'payslip.payee.created', ['user_id' => $user->id]);
            $made[] = [$row, $salary, $standing];
        }
        $last = $c->today->subMonthNoOverflow()->startOfMonth();
        foreach ([[$last, true], [$c->today->startOfMonth(), false]] as [$month, $publish]) {
            $period = $periods::create($month->year, $month->month, '', $month->day(25)->toDateString());
            foreach ($made as [$payee, $salary, $standing]) {
                $earnings = [['name' => 'เงินเดือน (ตัวเลขสมมติ)', 'amount' => self::money($salary)]];
                if ($standing) $earnings[] = ['name' => 'เงินวิทยฐานะ (ตัวเลขสมมติ)', 'amount' => self::money($standing)];
                $fund = intdiv($salary * 3, 100);
                $tax = intdiv($salary, 100);
                $deductions = [['name' => 'เงินสะสมกองทุน (ตัวเลขสมมติ)', 'amount' => self::money($fund)], ['name' => 'สหกรณ์ออมทรัพย์ (ตัวเลขสมมติ)', 'amount' => '1000.00']];
                $built = $slips::build($earnings, $deductions, self::money($tax), '0', '0', self::money($salary + $standing - $fund - 100000 - $tax));
                if (!($built['ok'] ?? false)) throw new \RuntimeException('สร้างสลิปเงินเดือนทดลองไม่สำเร็จ: '.($built['error'] ?? ''));
                $slips::create($period, (int) $payee->id, $slips::withBank($built['data'], $payee), null);
            }
            $slips::recompute((int) $period->id);
            if ($publish) $periods::publish((int) $period->id, (int) DB::table('psl_periods')->where('id', $period->id)->value('version'));
        }
        if (class_exists(\Saan\Modules\Payslip\Certificates::class) && \Illuminate\Support\Facades\Schema::hasTable('psl_certs')) \Saan\Modules\Payslip\Certificates::generate($last->year);
    }

    // ---------------------------------------------------------------- finance

    /** Fee schedule, two receiving accounts, an invoice for every student, receipts, and transfer slips waiting, approved and rejected. */
    public function finance(): void
    {
        $c = $this->c;
        if (!$c->has('finance', 'fin_fee_items', 'fin_invoices', 'fin_payments', 'fin_payment_accounts', 'fin_payment_claims', 'fin_claim_slips')) return;
        $billing = \Saan\Modules\Finance\Billing::class;
        $claims = \Saan\Modules\Finance\Claims::class;
        $officer = $c->user('finance');
        Settings::put('finance.staff_user_ids', [$officer->id]);
        $accounts = [];
        foreach ([['ktb', '0000000101'], ['gsb', '0000000202']] as $n => [$bank, $number]) {
            $accounts[] = $c->insertId('fin_payment_accounts', ['bank_code' => $bank, 'bank_name' => \Saan\Modules\Finance\Banks::name($bank), 'account_name' => DemoNames::SCHOOL.' (บัญชีสมมติ)',
                'account_number' => $number, 'branch' => 'สาขาต้นธาร (สมมติ)', 'is_active' => true, 'sort_order' => $n + 1]);
        }
        $items = [];
        foreach ([['FEE-EDU', 'ค่าบำรุงการศึกษา', 150000], ['FEE-ACT', 'ค่ากิจกรรมพัฒนาผู้เรียน', 50000], ['FEE-INS', 'ค่าประกันอุบัติเหตุ', 20000]] as [$code, $label, $amount]) {
            $items[] = ['fee_item_id' => $c->insertId('fin_fee_items', ['code' => $code, 'code_key' => strtolower($code), 'label' => $label, 'default_amount_satang' => $amount, 'term_id' => null,
                'grade_levels' => null, 'is_active' => true, 'account_ids' => null]), 'amount_satang' => $amount];
        }
        $total = array_sum(array_column($items, 'amount_satang'));
        $issued = $c->daysAgo(25, $c->term['starts_on']);
        $invoice = [];
        foreach ($c->students as $k => $s) {
            $invoice[$k] = $billing::createInvoice(['student_id' => $s['id'], 'term_id' => $c->term['id'], 'issued_on' => $issued->toDateString(), 'due_on' => $issued->addDays(30)->toDateString(), 'lines' => $items]);
        }
        // receipts in date order, so the receipt numbers follow the dates
        $span = max(0, (int) $issued->diffInDays($c->today));
        $pays = [];
        foreach ($c->students as $k => $s) {
            if (in_array($k, [0, 21, 7, 13, 17, 27], true)) continue; // these pay by transfer slip below
            $amount = $k === 60 ? 100000 : ($k === 81 || $k % 10 < 5 ? $total : ($k % 10 < 7 ? 100000 : 0));
            if ($amount > 0) $pays[] = [min($span, $k % 18), $k, $amount];
        }
        usort($pays, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($pays as [$offset, $k, $amount]) {
            $billing::pay((int) $invoice[$k]->id, ['amount_satang' => $amount, 'method' => ['cash', 'transfer', 'promptpay'][$k % 3], 'paid_on' => $issued->addDays($offset)->toDateString(),
                'reference' => $k % 3 === 0 ? null : 'อ้างอิงสมมติ '.sprintf('%05d', $k)], $officer);
        }
        foreach ([['2026 stationery', 'วัสดุสำนักงาน', 245000, 'ซื้อกระดาษและหมึกพิมพ์ (รายการสมมติ)'], ['sports', 'กิจกรรมนักเรียน', 380000, 'อุปกรณ์กีฬาสีภายใน (รายการสมมติ)'], ['water', 'สาธารณูปโภค', 126050, 'ค่าน้ำประปา (รายการสมมติ)']] as $n => [, $category, $amount, $text]) {
            Mutation::insert('fin_ledger', ['entry_date' => $issued->addDays(min($span, $n * 3))->toDateString(), 'kind' => 'expense', 'category' => $category, 'amount_satang' => $amount, 'description' => $text,
                'document_no' => 'บจ.สมมติ '.($n + 1), 'payment_id' => null, 'created_by' => $officer->id], 'finance.ledger_created');
        }
        // transfer slips: 0 and 21 are children of the two trial guardians (one waits for the officer, one is approved with its receipt)
        $when = $c->today->subDay()->gte($issued) ? $c->today->subDay() : $issued;
        $clock = $when->eq($c->today) ? '00:0%d:00' : '10:%d5:00';
        foreach ([0 => 'pending', 21 => 'approved', 7 => 'pending', 13 => 'pending', 17 => 'rejected', 27 => 'approved'] as $k => $state) {
            $png = DemoContext::png(['Invoice '.$invoice[$k]->invoice_no, 'Amount '.self::money($total).' THB', 'Sample slip no. '.$k]);
            if ($png === null || !isset($c->guardians[$k])) continue;
            $claim = $c->insertId('fin_payment_claims', ['invoice_id' => $invoice[$k]->id, 'student_id' => $c->students[$k]['id'], 'guardian_id' => $c->guardians[$k], 'entered_by' => null, 'source' => 'guardian',
                'amount_satang' => $total, 'approved_satang' => null, 'transferred_at' => $when->toDateString().' '.sprintf($clock, $k % 6), 'account_id' => $accounts[$k % 2], 'status' => 'pending']);
            $path = $c->put('finance/'.$c->school.'/slips/'.bin2hex(random_bytes(16)).'.png', $png);
            $c->insertId('fin_claim_slips', ['claim_id' => $claim, 'path' => $path, 'mime' => 'image/png', 'size' => strlen($png), 'sha256' => hash('sha256', $png), 'dup_key' => hash('sha256', $png)]);
            if ($state === 'approved') $claims::approve($claim, 1, null, $c->today->toDateString(), null, $officer, true);
            if ($state === 'rejected') $claims::reject($claim, 1, 'ยอดเงินในสลิปไม่ตรงกับใบแจ้งหนี้ กรุณาตรวจสอบแล้วแจ้งใหม่ (ตัวอย่าง)', $officer);
        }
    }

    // ---------------------------------------------------------------- documents

    /** Correspondence registers (incoming, outgoing, orders, announcements, memos) with routing to staff inboxes. */
    public function documents(): void
    {
        $c = $this->c;
        if (!$c->has('documents', 'doc_documents', 'doc_routes')) return;
        Settings::put('documents.clerk_user_ids', [$c->users['clerk']]);
        $docs = app(\Saan\Modules\Documents\Documents::class);
        $office = 'สำนักงานเขตพื้นที่การศึกษาสมมติ เขต 9';
        // kind, days ago, number, other party, subject, urgency, secrecy, recipients, what happened next
        $list = [
            ['incoming', 24, 'ศธ 99999/ว101', $office, 'แจ้งกำหนดการนิเทศติดตามการจัดการเรียนการสอน', 'normal', 'normal', ['principal', 'homeroom', 'subject'], 'done'],
            ['incoming', 21, 'ศธ 99999/ว118', $office, 'ขอเชิญเข้าร่วมอบรมการวัดและประเมินผลการเรียนรู้', 'urgent', 'normal', ['homeroom', 'subject', 'finance'], 'read'],
            ['outgoing', 20, 'ที่ ตธ 001/2569', $office, 'ส่งรายงานจำนวนนักเรียน ณ วันที่ 10 ของเดือน', 'normal', 'normal', [], null],
            ['order', 18, 'คำสั่ง 12/2569', null, 'แต่งตั้งคณะกรรมการดำเนินงานกีฬาสีภายใน', 'normal', 'normal', ['homeroom', 'subject', 'payroll', 'clerk'], 'ack'],
            ['incoming', 15, 'สธ 99998/55', 'โรงพยาบาลธารทอง (สมมติ)', 'ขอความร่วมมือสำรวจการได้รับวัคซีนของนักเรียน', 'normal', 'normal', ['homeroom'], 'ack'],
            ['memo', 13, 'บันทึก 7/2569', null, 'ขออนุมัติจัดซื้อวัสดุฝึกวิชาการงานอาชีพ', 'normal', 'normal', ['principal'], 'read'],
            ['incoming', 10, 'ศธ 99999/ว140', $office, 'การรายงานข้อมูลนักเรียนรายบุคคล ภาคเรียนปัจจุบัน', 'most_urgent', 'normal', ['principal', 'clerk'], null],
            ['outgoing', 8, 'ที่ ตธ 002/2569', 'องค์การบริหารส่วนตำบลต้นธาร (สมมติ)', 'ขอความอนุเคราะห์รถรับส่งนักเรียนทัศนศึกษา', 'normal', 'normal', [], null],
            ['announcement', 6, 'ประกาศ 3/2569', null, 'กำหนดการสอบกลางภาค', 'normal', 'normal', ['homeroom', 'subject'], null],
            ['incoming', 4, 'ลับ ที่ 9/2569', $office, 'แจ้งผลการตรวจสอบภายใน (เอกสารตัวอย่างชั้นความลับ)', 'normal', 'confidential', ['principal'], null],
            ['incoming', 2, 'ศธ 99999/ว151', $office, 'ประชาสัมพันธ์โครงการทุนการศึกษา', 'normal', 'normal', [], 'cancel'],
            ['incoming', 0, 'ศธ 99999/ว160', $office, 'ซักซ้อมแนวทางการจัดกิจกรรมวันสำคัญ', 'normal', 'normal', [], null],
        ];
        foreach ($list as $n => [$kind, $ago, $number, $party, $subject, $urgency, $secrecy, $to, $then]) {
            $on = $c->today->subDays($ago)->toDateString();
            $docs->register(['kind' => $kind, 'doc_no' => $number, 'doc_date' => $c->today->subDays($ago + ($kind === 'incoming' ? 3 : 0))->toDateString(), 'registered_on' => $on,
                'from_org' => $kind === 'incoming' ? $party : null, 'to_org' => $kind === 'outgoing' ? $party : null, 'subject' => $subject.' (เอกสารสมมติ)', 'urgency' => $urgency, 'secrecy' => $secrecy,
                'summary' => 'สรุปย่อของเอกสารตัวอย่างสำหรับทดลองระบบสารบรรณ ไม่ใช่หนังสือราชการจริง']);
            $id = (int) DB::table('doc_documents')->where('school_id', $c->school)->max('id');
            if ($to) $docs->route($id, array_map(fn ($key) => $c->users[$key], $to), 'เพื่อทราบและดำเนินการ');
            $version = fn () => (int) DB::table('doc_documents')->where('id', $id)->value('version');
            if ($then === 'read' || $then === 'ack' || $then === 'done') {
                $seen = $c->at($on, '13:20');
                DB::table('doc_routes')->where('document_id', $id)->update(['read_at' => $seen] + ($then === 'read' ? [] : ['acknowledged_at' => $seen, 'action_note' => 'รับทราบ (ข้อมูลสมมติ)']));
            }
            if ($then === 'done') $docs->setDone($id, $version(), true);
            if ($then === 'cancel') $docs->cancel($id, $version(), 'ลงทะเบียนซ้ำกับเลขรับก่อนหน้า (ตัวอย่าง)');
            if ($n === 0) {
                $docs->upload($id, $c->upload(DemoContext::pdf('Incoming letter (sample)', ['Registered '.$on]), 'หนังสือรับตัวอย่าง.pdf', 'application/pdf'));
                $c->track(DB::table('doc_files')->where('document_id', $id)->value('path'));
            }
        }
    }

    // ---------------------------------------------------------------- maintenance

    /** Asset register, repair requests in every state with their history, and a small spare-part stock. */
    public function maintenance(): void
    {
        $c = $this->c;
        if (!$c->has('maintenance', 'mnt_assets', 'mnt_requests', 'mnt_request_logs', 'mnt_stock_items', 'mnt_stock_moves')) return;
        $technician = $c->users['clerk'];
        Settings::put('maintenance.technician_user_ids', [$technician]);
        $assets = [];
        $list = [['คอมพิวเตอร์ตั้งโต๊ะ', 'คอมพิวเตอร์', 'ห้องคอมพิวเตอร์', 1850000, 4], ['เครื่องฉายภาพ', 'โสตทัศนูปกรณ์', 'ห้อง 101', 2490000, 2], ['เครื่องปรับอากาศ', 'เครื่องใช้ไฟฟ้า', 'ห้องสมุด', 3200000, 2],
            ['โต๊ะนักเรียน', 'ครุภัณฑ์สำนักงาน', 'ห้อง 103', 120000, 2], ['กล้องจุลทรรศน์', 'วิทยาศาสตร์', 'ห้องปฏิบัติการวิทยาศาสตร์ 1', 1450000, 2], ['เครื่องพิมพ์เลเซอร์', 'คอมพิวเตอร์', 'ห้องธุรการ', 890000, 1],
            ['พัดลมเพดาน', 'เครื่องใช้ไฟฟ้า', 'ห้อง 105', 180000, 1], ['ตู้เก็บเอกสาร', 'ครุภัณฑ์สำนักงาน', 'ห้องธุรการ', 450000, 1]];
        $n = 0;
        foreach ($list as [$name, $category, $place, $price, $count]) {
            for ($i = 1; $i <= $count; $i++) {
                $code = sprintf('TTW-%03d', ++$n);
                $assets[$n] = $c->insertId('mnt_assets', ['asset_code' => $code, 'asset_code_key' => strtolower($code), 'name' => $name.' เครื่องที่ '.$i, 'category' => $category, 'location' => $place,
                    'acquired_on' => (2019 + $n % 6).'-06-01', 'price_satang' => $price, 'status' => $n === 15 ? 'disposed' : 'in_use', 'status_auto' => false,
                    'responsible_teacher_id' => $n % 5 === 0 ? $c->teachers[11]['id'] : null]);
            }
        }
        // asset number or null, place, title, urgency, reporter, days ago, steps [status, note], resolution, cost
        $requests = [
            [null, 'ห้องน้ำอาคาร 2 ชั้น 1', 'ก๊อกน้ำรั่ว น้ำไหลตลอดเวลา', 'urgent', 'homeroom', 0, [], null, null],
            [5, 'ห้อง 101', 'เครื่องฉายภาพเปิดไม่ติด', 'normal', 'subject', 1, [], null, null],
            [7, 'ห้องสมุด', 'เครื่องปรับอากาศไม่เย็น มีน้ำหยด', 'normal', 'homeroom', 3, [['accepted', 'รับเรื่อง จะเข้าตรวจพรุ่งนี้']], null, null],
            [1, 'ห้องคอมพิวเตอร์', 'คอมพิวเตอร์เครื่องที่ 1 จอฟ้า', 'normal', 't11', 6, [['accepted', null], ['in_progress', 'กำลังตรวจหน่วยความจำ']], null, null],
            [13, 'ห้องธุรการ', 'เครื่องพิมพ์ดึงกระดาษไม่เข้า', 'normal', 'clerk', 9, [['accepted', null], ['waiting_parts', 'รอลูกยางดึงกระดาษ']], null, null],
            [14, 'ห้อง 105', 'พัดลมเพดานมีเสียงดัง', 'normal', 't5', 16, [['accepted', null], ['in_progress', null], ['done', 'ซ่อมเสร็จแล้ว']], 'เปลี่ยนลูกปืนและขันน็อตยึดใหม่ (ข้อมูลสมมติ)', 35000],
            [9, 'ห้อง 103', 'โต๊ะนักเรียนขาโยก 1 ตัว', 'normal', 't3', 22, [['accepted', null], ['done', 'ซ่อมเสร็จแล้ว']], 'เชื่อมขาโต๊ะและทาสีใหม่ (ข้อมูลสมมติ)', 0],
            [null, 'สนามกีฬา', 'ขอติดตั้งไฟส่องสว่างเพิ่ม', 'normal', 'payroll', 12, [['rejected', 'อยู่นอกงานซ่อมบำรุง ให้เสนอเป็นโครงการ (ตัวอย่าง)']], null, null],
            [null, 'ห้อง 102', 'หลอดไฟกะพริบ', 'normal', 'homeroom', 5, [['cancelled', 'ครูเปลี่ยนหลอดเองแล้ว']], null, null],
        ];
        foreach ($requests as [$asset, $place, $title, $urgency, $who, $ago, $steps, $resolution, $cost]) {
            $at = $c->at($c->today->subDays($ago), '09:15');
            $state = $steps ? $steps[count($steps) - 1][0] : 'new';
            $closed = in_array($state, ['done', 'rejected', 'cancelled'], true);
            $ended = $c->at($c->today->subDays(max(0, $ago - count($steps))), '15:00');
            $id = $c->insertId('mnt_requests', ['asset_id' => $asset ? $assets[$asset] : null, 'location' => $place, 'title' => $title, 'detail' => 'รายละเอียดการแจ้งซ่อมตัวอย่าง (ข้อมูลสมมติ)', 'urgency' => $urgency,
                'reported_by' => $c->users[$who], 'status' => $state, 'assigned_to' => $steps && $state !== 'cancelled' ? $technician : null, 'resolution' => $resolution, 'cost_satang' => $cost,
                'closed_at' => $closed ? $ended : null, 'created_at' => $at, 'updated_at' => $closed ? $ended : $at]);
            $logs = [['request_id' => $id, 'from_status' => null, 'to_status' => 'new', 'note' => null, 'by' => $c->users[$who], 'at' => $at]];
            $from = 'new';
            foreach ($steps as $i => [$to, $note]) {
                $logs[] = ['request_id' => $id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'by' => $to === 'cancelled' ? $c->users[$who] : $technician,
                    'at' => $c->at($c->today->subDays(max(0, $ago - $i - 1)), '14:00')];
                $from = $to;
            }
            $c->insert('mnt_request_logs', $logs);
            if ($asset && in_array($state, ['accepted', 'in_progress', 'waiting_parts'], true)) DB::table('mnt_assets')->where('id', $assets[$asset])->update(['status' => 'repair', 'status_auto' => true]);
        }
        foreach ([['หลอดไฟ LED 18 วัตต์', 'หลอด', 40, 10, 12], ['ก๊อกน้ำทองเหลือง', 'ตัว', 6, 4, 3], ['สายไฟ VAF 2x2.5', 'เมตร', 100, 20, 35], ['ลูกยางดึงกระดาษเครื่องพิมพ์', 'ชิ้น', 2, 2, 2], ['สีน้ำอะคริลิกสีขาว', 'ถัง', 5, 1, 1], ['น็อตและสกรูคละขนาด', 'กล่อง', 10, 2, 0]] as $i => [$name, $unit, $in, $level, $out]) {
            $code = sprintf('ST-%03d', $i + 1);
            $item = $c->insertId('mnt_stock_items', ['code' => $code, 'code_key' => strtolower($code), 'name' => $name, 'unit' => $unit, 'qty_on_hand' => $in - $out, 'reorder_level' => $level]);
            $moves = [['item_id' => $item, 'kind' => 'in', 'qty' => $in, 'ref' => 'รับเข้าต้นภาคเรียน', 'by' => $technician, 'at' => $c->at($c->today->subDays(30), '10:00'), 'note' => null]];
            if ($out) $moves[] = ['item_id' => $item, 'kind' => 'out', 'qty' => $out, 'ref' => 'เบิกใช้งานซ่อม', 'by' => $technician, 'at' => $c->at($c->today->subDays(7), '10:00'), 'note' => null];
            $c->insert('mnt_stock_moves', $moves);
        }
    }

    // ---------------------------------------------------------------- projects

    /** Annual action plan of the current fiscal year with projects in every state, their activities (the budget lines) and reports; last year's plan is closed. */
    public function projects(): void
    {
        $c = $this->c;
        if (!$c->has('projects', 'prj_plans', 'prj_projects', 'prj_activities', 'prj_reports')) return;
        $fy = $c->today->month >= 10 ? $c->today->year + 544 : $c->today->year + 543;
        $start = CarbonImmutable::create($fy - 544, 10, 1, 0, 0, 0, 'Asia/Bangkok');
        $principal = $c->users['principal'];
        $day = fn (int $ago) => max($start->toDateString(), $c->today->subDays($ago)->toDateString());
        $plan = $c->insertId('prj_plans', ['fiscal_year_be' => $fy, 'title' => 'แผนปฏิบัติการประจำปีงบประมาณ '.$fy, 'status' => 'approved']);
        $old = $c->insertId('prj_plans', ['fiscal_year_be' => $fy - 1, 'title' => 'แผนปฏิบัติการประจำปีงบประมาณ '.($fy - 1), 'status' => 'closed']);
        // plan, code, title, owner (staff index), budget, source, state, activities [title, budget, spent, done days ago or null, reason], report [summary, percent] or null
        $projects = [
            [$plan, 'วช.01', 'ยกระดับผลสัมฤทธิ์ทางการเรียน 8 กลุ่มสาระ', 1, 12000000, 'อุดหนุนรายหัว', 'in_progress', [['จัดซื้อสื่อและแบบฝึก', 6000000, 5850000, 20, null], ['ค่ายวิชาการ', 4000000, 0, null, null]], ['ดำเนินการแล้ว 1 ใน 2 กิจกรรม สื่อถึงห้องเรียนครบทุกระดับชั้น', 50]],
            [$plan, 'วช.02', 'ห้องเรียนวิทยาศาสตร์ลงมือทำ', 2, 8000000, 'เรียนฟรี 15 ปี', 'approved', [['ปรับปรุงห้องปฏิบัติการ', 5000000, 0, null, null], ['วัสดุทดลอง', 3000000, 0, null, null]], null],
            [$plan, 'กน.01', 'กีฬาสีภายในและส่งเสริมสุขภาพ', 9, 4500000, 'รายได้สถานศึกษา', 'done', [['จัดการแข่งขันกีฬาสี', 4500000, 4720000, 8, 'ค่าเช่าเครื่องเสียงสูงกว่าที่ประมาณไว้ (ตัวอย่าง)']], ['จัดกิจกรรมครบตามแผน นักเรียนเข้าร่วมร้อยละ 96', 100]],
            [$plan, 'บท.01', 'ปรับปรุงภูมิทัศน์และอาคารสถานที่', 11, 15000000, 'อุดหนุนรายหัว', 'proposed', [['ทาสีอาคารเรียน', 9000000, 0, null, null]], null],
            [$plan, 'บค.01', 'พัฒนาครูสู่การเรียนรู้เชิงรุก', 4, 3000000, 'อื่นๆ', 'in_progress', [['อบรมเชิงปฏิบัติการ', 2000000, 1800000, 12, null]], ['อบรมครูแล้ว 1 รุ่น 12 คน', 60]],
            [$plan, 'วช.03', 'ทัศนศึกษาแหล่งเรียนรู้นอกสถานที่', 6, 5000000, 'เรียนฟรี 15 ปี', 'cancelled', [['เดินทางทัศนศึกษา', 5000000, 0, null, null]], null],
            [$old, 'วช.01', 'ยกระดับผลสัมฤทธิ์ทางการเรียน', 1, 10000000, 'อุดหนุนรายหัว', 'done', [['จัดซื้อสื่อการเรียนรู้', 10000000, 9940000, 200, null]], ['บรรลุตามเป้าหมาย ผลสัมฤทธิ์เฉลี่ยเพิ่มขึ้น', 100]],
            [$old, 'กน.01', 'กีฬาสีภายใน', 9, 4000000, 'รายได้สถานศึกษา', 'done', [['จัดการแข่งขัน', 4000000, 3980000, 300, null]], ['ดำเนินการครบ', 100]],
        ];
        foreach ($projects as [$planId, $code, $title, $owner, $budget, $source, $state, $activities, $report]) {
            $isOld = $planId === $old;
            $from = $isOld ? $start->subYear() : $start;
            $approved = in_array($state, ['approved', 'in_progress', 'done'], true);
            $id = $c->insertId('prj_projects', ['plan_id' => $planId, 'code' => $code, 'code_key' => mb_strtolower($code), 'title' => $title.' (โครงการสมมติ)', 'owner_teacher_id' => $c->teachers[$owner]['id'],
                'department' => DemoNames::STAFF[$owner][6], 'strategy' => 'กลยุทธ์ที่ '.(1 + $owner % 4).' พัฒนาคุณภาพผู้เรียน', 'objective' => 'เพื่อทดลองการวางแผน ติดตาม และรายงานโครงการในระบบ (ข้อมูลสมมติ)',
                'target' => 'นักเรียนและครูของโรงเรียนสมมติทุกคน', 'starts_on' => $from->toDateString(), 'ends_on' => $from->addYear()->subDay()->toDateString(), 'budget_satang' => $budget, 'budget_source' => $source,
                'status' => $state, 'approved_by' => $approved ? $principal : null, 'approved_at' => $approved ? $c->at($isOld ? $from->addDays(10) : $day(30), '10:00') : null]);
            $rows = [];
            foreach ($activities as [$name, $planned, $spent, $ago, $reason]) {
                $done = $ago === null ? null : ($isOld ? $from->addDays(365 - min(360, $ago))->toDateString() : $day($ago));
                $rows[] = ['project_id' => $id, 'title' => $name, 'planned_on' => $done ?? $from->addDays(120)->toDateString(), 'done_on' => $done, 'budget_satang' => $planned, 'spent_satang' => $spent,
                    'note' => null, 'over_budget_reason' => $reason, 'fin_ledger_id' => null];
            }
            $c->insert('prj_activities', $rows);
            if ($report) $c->insertId('prj_reports', ['project_id' => $id, 'summary' => $report[0].' (ข้อมูลสมมติ)', 'outcome_pct' => $report[1], 'submitted_by' => $c->teachers[$owner]['user_id'],
                'submitted_at' => $c->at($isOld ? $from->addYear()->subDays(20) : $day(3), '16:00')]);
        }
    }
}
