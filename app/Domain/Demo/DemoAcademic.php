<?php

namespace App\Domain\Demo;

use App\Support\Saan\Edition;
use App\Support\Saan\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Trial school, part 1: academic year and terms, calendar, bell periods, staff and classes, staff accounts, the student register, portal accounts. */
final class DemoAcademic
{
    private const HOLIDAYS = ['01-01' => 'วันขึ้นปีใหม่', '04-06' => 'วันจักรี', '04-13' => 'วันสงกรานต์', '04-14' => 'วันสงกรานต์', '04-15' => 'วันสงกรานต์',
        '05-01' => 'วันแรงงานแห่งชาติ', '05-04' => 'วันฉัตรมงคล', '06-03' => 'วันเฉลิมพระชนมพรรษาสมเด็จพระราชินี', '07-28' => 'วันเฉลิมพระชนมพรรษาพระบาทสมเด็จพระเจ้าอยู่หัว',
        '08-12' => 'วันแม่แห่งชาติ', '10-13' => 'วันนวมินทรมหาราช', '10-23' => 'วันปิยมหาราช', '12-05' => 'วันพ่อแห่งชาติ', '12-10' => 'วันรัฐธรรมนูญ', '12-31' => 'วันสิ้นปี'];

    /** Students who hold a portal account, and the students whose first guardian holds one (index over the whole school). */
    public const STUDENT_ACCOUNTS = [0, 81];
    public const GUARDIAN_ACCOUNTS = [0, 21];
    /** The second child of the first trial guardian (same family name as student 0). */
    public const SIBLING = 60;

    public function __construct(private DemoContext $c) {}

    private static function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_strtolower((string) preg_replace('/\s+/u', '', $v));
    }

    /**
     * The term that contains today and the one before it. Term 1 runs 1 May - 15 October, term 2 16 October - 30 April, without a gap,
     * so there is a current term on whatever day the trial is installed. @return array{0:array,1:array} [current, previous]
     */
    public static function terms(CarbonImmutable $today): array
    {
        $y = $today->year;
        $md = $today->format('m-d');
        $one = fn (int $ce) => ['year' => $ce + 543, 'term' => 1, 'starts_on' => $ce.'-05-01', 'ends_on' => $ce.'-10-15'];
        $two = fn (int $ce) => ['year' => $ce + 543, 'term' => 2, 'starts_on' => $ce.'-10-16', 'ends_on' => ($ce + 1).'-04-30'];
        if ($md >= '10-16') return [$two($y), $one($y)];
        if ($md <= '04-30') return [$two($y - 1), $one($y - 1)];
        return [$one($y), $two($y - 1)];
    }

    public function structure(): void
    {
        $c = $this->c;
        [$cur, $prev] = self::terms($c->today);
        // The current term must own the lowest id: SCHOOL_TERM_ID (1 in the installer's .env) is the fallback of TermScope and the term
        // shown in the navigation. A blank install already has one term row, which becomes the current term here.
        $existing = DB::table('academic_terms')->where('school_id', $c->school)->orderBy('id')->first();
        if ($existing) {
            DB::table('academic_terms')->where('id', $existing->id)->update(array_intersect_key($cur + ['label' => null], $c->columns('academic_terms')));
            $cur['id'] = (int) $existing->id;
        } else {
            $cur['id'] = $c->insertId('academic_terms', $cur);
        }
        $prev['id'] = $c->insertId('academic_terms', $prev);
        $c->term = $cur;
        $c->prev = $prev;
        Settings::put('academic.current_term_id', $cur['id']);
        // prefix of certificate and document numbers (surveys, clubs); an existing choice of the school is kept
        if (Settings::get('school.short_code') === null) Settings::put('school.short_code', 'TTW');

        for ($y = (int) substr($prev['starts_on'], 0, 4); $y <= (int) substr($cur['ends_on'], 0, 4); $y++) {
            foreach (self::HOLIDAYS as $md => $title) {
                $d = $y.'-'.$md;
                if ($d >= $prev['starts_on'] && $d <= $cur['ends_on']) $c->holidays[$d] = $title;
            }
        }
        if ($c->has('academic', 'acad_calendar_days')) {
            $days = [];
            foreach ($c->holidays as $d => $title) $days[] = [$d, 'holiday', $title];
            $start = CarbonImmutable::parse($cur['starts_on'], 'Asia/Bangkok');
            $weekday = fn (CarbonImmutable $d) => $d->isSaturday() ? $d->addDays(2) : ($d->isSunday() ? $d->addDay() : $d);
            $days[] = [$weekday($start->addDays(14))->toDateString(), 'event', 'ประชุมผู้ปกครองนักเรียน'];
            foreach ([0, 1, 2] as $i) $days[] = [$weekday($start->addDays(63))->addDays($i)->toDateString(), 'exam', 'สอบกลางภาค'];
            $days[] = [$weekday($start->addDays(84))->toDateString(), 'event', 'กีฬาสีภายใน'];
            $end = $weekday(CarbonImmutable::parse($prev['ends_on'], 'Asia/Bangkok')->subDays(16));
            foreach ([0, 1, 2] as $i) $days[] = [$end->addDays($i)->toDateString(), 'exam', 'สอบปลายภาค'];
            $rows = [];
            $seen = [];
            foreach ($days as [$date, $kind, $title]) {
                if (isset($seen[$date.$title]) || ($kind !== 'holiday' && isset($c->holidays[$date]))) continue;
                $seen[$date.$title] = true;
                $rows[] = ['date' => $date, 'kind' => $kind, 'title' => $title, 'title_key' => mb_substr(self::key($title), 0, 160), 'term_id' => $c->termOf($date)];
            }
            $c->insert('acad_calendar_days', $rows);
        }

        $times = [['08:30', '09:20'], ['09:20', '10:10'], ['10:20', '11:10'], ['11:10', '12:00'], ['13:00', '13:50'], ['13:50', '14:40'], ['14:40', '15:30']];
        foreach ($times as $i => [$from, $to]) $c->periods[$i + 1] = $c->insertId('bell_periods', ['number' => $i + 1, 'starts_at' => $from, 'ends_at' => $to, 'kind' => 'class']);
        if (isset($c->columns('bell_periods')['kind'])) $c->insertId('bell_periods', ['number' => 101, 'starts_at' => '12:00', 'ends_at' => '13:00', 'kind' => 'break']);

        foreach (DemoNames::STAFF as $i => [$prefix, $first, $last, $group]) {
            $name = $prefix.$first.' '.$last;
            $c->teachers[$i] = ['id' => $c->insertId('teachers', ['name' => $name, 'subject' => $group, 'prefix' => $prefix, 'first_name' => $first, 'last_name' => $last,
                'subject_group' => $group, 'is_active' => true, 'name_key' => mb_substr(self::key($first.' '.$last), 0, 200)]), 'name' => $name, 'user_id' => null];
        }
        for ($level = 1; $level <= 6; $level++) {
            $name = 'ม.'.$level.'/1';
            $c->classrooms[$level - 1] = ['id' => $c->insertId('classrooms', ['name' => $name, 'homeroom_teacher_id' => $c->teachers[$level]['id'], 'grade_level' => 'ม.'.$level,
                'section' => 1, 'name_key' => self::key($name), 'is_active' => true]), 'name' => $name, 'grade' => 'ม.'.$level, 'level' => $level, 'homeroom' => $level];
        }
        $c->has('academic');
    }

    /**
     * One staff account per person. Seven of them are the listed trial accounts with their own random password; the other teachers get an
     * account nobody knows the password of (the administrator can send a password link), so every teacher can still be chosen in the modules.
     */
    public function staffAccounts(): void
    {
        $c = $this->c;
        $roles = [
            'principal' => ['ผู้อำนวยการ (principal)', 'อนุมัติการลา อนุมัติโครงการ เห็นเรื่องร้องเรียนทุจริต ดูได้ทุกโมดูล'],
            'homeroom' => ['ครูประจำชั้น ม.1/1 (teacher)', 'เช็คชื่อ กรอกคะแนน สุขภาพ พฤติกรรมของห้องตัวเอง ที่หน้า /work'],
            'subject' => ['ครูผู้สอน ไม่มีห้องประจำชั้น (teacher)', 'กรอกคะแนนรายวิชาคณิตศาสตร์ ม.4-ม.6 ยื่นใบลา แจ้งซ่อม'],
            'finance' => ['เจ้าหน้าที่การเงิน (teacher + ตั้งค่า finance.staff_user_ids)', 'ออกใบแจ้งค่าธรรมเนียม รับชำระ ตรวจสลิป ที่ /work#finance'],
            'payroll' => ['เจ้าหน้าที่เงินเดือน (teacher + ตั้งค่า payslip.officer_user_ids)', 'จัดการงวดและสลิปเงินเดือน ที่ /work#payslip'],
            'clerk' => ['ธุรการ สารบรรณ และช่างซ่อมบำรุง (teacher + ตั้งค่า documents.clerk_user_ids, maintenance.technician_user_ids)', 'ลงทะเบียนหนังสือ รับงานซ่อม ทะเบียนครุภัณฑ์'],
            'editor' => ['ผู้ดูแลเว็บไซต์ (editor)', 'เขียนข่าว แบบสำรวจ ประกาศรายชื่อ ITA ที่หน้า /cms'],
        ];
        $locked = Hash::make(bin2hex(random_bytes(32))); // one unusable hash for the accounts that are not listed
        $people = [];
        foreach (DemoNames::STAFF as $i => $s) $people[] = [$s[7] ?? 't'.$i, $c->teachers[$i]['name'], $i === 0 ? 'principal' : 'teacher', $i, $s[7] !== null];
        $people[] = ['editor', 'กานต์ ประสานงาน', 'editor', null, true];
        foreach ($people as [$key, $name, $role, $staff, $listed]) {
            $email = ($listed ? $key : sprintf('teacher%02d', $staff)).'@'.DemoNames::MAIL_DOMAIN;
            $password = $listed ? DemoNames::password() : null;
            $room = $staff !== null && $staff >= 1 && $staff <= 6 ? $c->classrooms[$staff - 1]['id'] : null;
            $id = $c->insertId('users', ['name' => $name.' (สมมติ)', 'email' => $email, 'password' => $password ? Hash::make($password) : $locked, 'role' => $role,
                'teacher_id' => $staff !== null ? $c->teachers[$staff]['id'] : null, 'classroom_id' => $room, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
            $c->users[$key] = $id;
            if ($staff !== null) {
                $c->teachers[$staff]['user_id'] = $id;
                if (isset($c->columns('teachers')['user_id'])) DB::table('teachers')->where('id', $c->teachers[$staff]['id'])->update(['user_id' => $id]);
            }
            if ($listed) $c->accounts[] = ['role' => $roles[$key][0], 'login' => $email, 'password' => $password, 'url' => $c->baseUrl.'/login', 'note' => $name.' - '.$roles[$key][1]];
        }
    }

    /** 120 students in six rooms, their guardians and identity numbers (Thai, foreign, stateless and "documents pending" cases). */
    public function registry(): void
    {
        $c = $this->c;
        $registry = $c->has('registry', 'reg_guardians', 'reg_student_guardians', 'reg_identifiers');
        $be = (int) $c->term['year'];
        $startYear = (int) substr($c->prev['starts_on'], 0, 4);
        $special = [37 => ['foreign', 'ภาษาอังกฤษ'], 58 => ['stateless', 'ภาษาไทย'], 79 => ['undetermined', 'ภาษาไทย'], 99 => ['foreign', 'ภาษาลาว']];
        $rows = [];
        $people = [];
        for ($k = 0; $k < 120; $k++) {
            $room = intdiv($k, 20);
            $level = $room + 1;
            [$sex, $prefix, $first, $last] = DemoNames::student($k, $level);
            if ($k === self::SIBLING) $last = DemoNames::student(0, 1)[3];
            $code = sprintf('%02d%03d', ($be - $room) % 100, $k % 20 + 1);
            $birth = CarbonImmutable::create($startYear - 12 - $room, 1 + DemoNames::pick('bm'.$k, 12), 1 + DemoNames::pick('bd'.$k, 28));
            $entry = min(($startYear - ($room % 3)).'-05-16', $c->prev['starts_on']);
            $rows[] = ['classroom_id' => $c->classrooms[$room]['id'], 'code' => $code, 'name' => $first.' '.$last, 'prefix' => $prefix, 'first_name' => $first, 'last_name' => $last,
                'sex' => $sex, 'birth_date' => $birth->toDateString(), 'birth_year_be' => $birth->year + 543, 'nationality_status' => $special[$k][0] ?? 'thai',
                'preferred_language' => $special[$k][1] ?? null, 'needs_interpreter' => ($special[$k][0] ?? '') === 'foreign', 'status' => 'studying', 'admitted_on' => $entry,
                'admission_type' => $k % 23 === 22 ? 'transfer_in' : 'regular', 'previous_school' => $room % 3 === 0 ? 'โรงเรียนบ้านต้นธาร (สมมติ)' : null,
                'roll_number' => $k % 20 + 1, 'code_key' => mb_strtolower($code)];
            $people[$k] = ['code' => $code, 'name' => $first.' '.$last, 'prefix' => $prefix, 'first' => $first, 'last' => $last, 'sex' => $sex, 'room' => $room, 'roll' => $k % 20 + 1];
        }
        $c->insert('students', $rows);
        $ids = DB::table('students')->where('school_id', $c->school)->pluck('id', 'code');
        foreach ($people as $k => $p) $c->students[$k] = ['id' => (int) $ids[$p['code']]] + $p;
        if (!$registry) return;

        $identity = \Saan\Modules\Registry\Identity::class;
        $display = fn ($prefix, $first, $last) => \Saan\Modules\Registry\RegistryRepository::displayName($prefix, $first, $last);
        // guardians: one primary each, a second one for every fourth student; student SIBLING shares the first guardian of student 0
        $guardians = [];
        $links = [];
        $n = 0;
        foreach ($c->students as $k => $s) {
            foreach ($k % 4 === 3 ? [0, 1] : [0] as $second) {
                if ($k === self::SIBLING && $second === 0) { $links[] = [$k, 'G0001', DemoNames::guardian(0, 0)[0], true]; continue; }
                [$relation, $prefix, $first] = DemoNames::guardian($k, $second);
                $ref = sprintf('G%04d', ++$n);
                $account = $second === 0 ? array_search($k, self::GUARDIAN_ACCOUNTS, true) : false;
                $guardians[] = ['prefix' => $prefix, 'first_name' => $first, 'last_name' => $s['last'], 'display_name' => $display($prefix, $first, $s['last']),
                    'phone_enc' => $identity::encrypt(DemoNames::phone(1000 + $n)), 'email' => $account !== false ? 'guardian'.($account + 1).'@'.DemoNames::MAIL_DOMAIN : null,
                    'line_id' => null, 'occupation' => ['เกษตรกร', 'ค้าขาย', 'รับจ้างทั่วไป', 'พนักงานบริษัท', 'รับราชการ', 'ธุรกิจส่วนตัว'][DemoNames::pick('occ'.$k.$second, 6)],
                    'ref_code' => $ref, 'ref_key' => mb_strtolower($ref)];
                $links[] = [$k, $ref, $relation, $second === 0];
            }
        }
        $c->insert('reg_guardians', $guardians);
        $gid = DB::table('reg_guardians')->where('school_id', $c->school)->pluck('id', 'ref_code');
        $rows = [];
        foreach ($links as [$k, $ref, $relation, $primary]) {
            $rows[] = ['student_id' => $c->students[$k]['id'], 'guardian_id' => (int) $gid[$ref], 'relationship' => $relation, 'is_primary' => $primary,
                'is_emergency_contact' => $primary, 'can_pick_up' => true, 'pickup_note' => null];
            if ($primary) $c->guardians[$k] = (int) $gid[$ref];
        }
        $c->insert('reg_student_guardians', $rows);

        $identifier = function (string $type, int $person, string $kind, ?string $value, string $state, ?string $issuer = null, ?string $expires = null, ?string $note = null) use ($identity) {
            $norm = $identity::normalise($kind, $value);
            return ['person_type' => $type, 'person_id' => $person, 'kind' => $kind, 'value_enc' => $identity::encrypt($norm),
                'value_hash' => $norm === null ? null : $identity::hash($identity::hashInput($kind, $norm, $issuer)), 'last4' => $identity::last4($norm),
                'verification_state' => $state, 'note' => $note, 'issuer' => $issuer, 'expires_on' => $expires];
        };
        $rows = [];
        foreach ($c->students as $k => $s) {
            $rows[] = match ($k) {
                37 => $identifier('student', $s['id'], 'passport', 'ZZ'.sprintf('%07d', 9000000 + $k), 'verified', 'ZZ', $c->today->addYears(3)->toDateString(), 'หนังสือเดินทางสมมติ (ประเทศ ZZ ไม่มีจริง)'),
                58 => $identifier('student', $s['id'], 'g_code', 'G99'.sprintf('%010d', $k), 'unverified', null, null, 'เลข G สมมติสำหรับนักเรียนที่ไม่มีเลขประจำตัวประชาชน'),
                79 => $identifier('student', $s['id'], 'pending', null, 'unverified', null, null, 'รอเอกสารจากผู้ปกครอง'),
                99 => $identifier('student', $s['id'], 'other', 'DEMO-ID-'.sprintf('%04d', $k), 'unverified', null, null, 'เลขประจำตัวจากเอกสารอื่น (สมมติ)'),
                default => $identifier('student', $s['id'], 'thai_id', DemoNames::thaiId(100 + $k), $k % 10 === 9 ? 'unverified' : 'verified'),
            };
        }
        foreach (array_slice(array_values(array_unique($c->guardians)), 0, 12) as $i => $guardian) $rows[] = $identifier('guardian', $guardian, 'thai_id', DemoNames::thaiId(5000 + $i), 'unverified');
        $c->insert('reg_identifiers', $rows);
    }

    /** Two student accounts and two guardian accounts, with the sign-in each portal really uses. */
    public function portalAccounts(): void
    {
        $c = $this->c;
        if ($c->has('guardian-portal')) Settings::put('portal.show_pp6', true);
        if ($c->has('student-portal', 'api_student_pins') && class_exists(\Saan\Modules\StudentPortal\Credentials::class)) {
            foreach (self::STUDENT_ACCOUNTS as $k) {
                $s = $c->students[$k];
                $row = (object) ['code' => $s['code']];
                do { $password = DemoNames::password(); } while (\Saan\Modules\StudentPortal\Credentials::check($password, $row) !== null);
                \Saan\Modules\StudentPortal\Credentials::set($s['id'], $password);
                $c->accounts[] = ['role' => 'นักเรียน '.$c->classrooms[$s['room']]['name'].' (พอร์ทัลนักเรียน)', 'login' => $s['code'], 'password' => $password, 'url' => $c->baseUrl.'/student',
                    'note' => $s['prefix'].$s['first'].' '.$s['last'].' - เข้าด้วยรหัสนักเรียนและรหัสผ่าน ดูผลการเรียน GPA ตารางเรียน การมาเรียน'];
            }
        }
        if ($c->has('guardian-portal', 'gp_accounts', 'gp_links') && $c->guardians) {
            foreach (self::GUARDIAN_ACCOUNTS as $i => $k) {
                $guardian = $c->guardians[$k];
                $email = 'guardian'.($i + 1).'@'.DemoNames::MAIL_DOMAIN;
                do { $password = DemoNames::password(); } while (\Saan\Modules\GuardianPortal\SecretPolicy::check($password, ['guardian'.($i + 1)]) !== null);
                $c->insertId('gp_accounts', ['guardian_id' => $guardian, 'email' => $email, 'email_key' => $email, 'password' => Hash::make($password), 'is_active' => true,
                    'must_change' => false, 'auth_version' => 1]);
                $children = array_keys(array_filter($c->guardians, fn ($g) => $g === $guardian));
                foreach ($children as $child) {
                    // a used invitation is what ties the account to a child (same rows as the staff screen writes)
                    $c->insertId('gp_links', ['guardian_id' => $guardian, 'student_id' => $c->students[$child]['id'], 'token_hash' => hash('sha256', random_bytes(24)), 'purpose' => 'invite',
                        'expires_at' => now(), 'used_at' => now(), 'created_by' => $c->admin->id]);
                }
                $names = implode(', ', array_map(fn ($child) => $c->students[$child]['first'].' ('.$c->classrooms[$c->students[$child]['room']]['name'].')', $children));
                $c->accounts[] = ['role' => 'ผู้ปกครอง (พอร์ทัลผู้ปกครอง)', 'login' => $email, 'password' => $password, 'url' => $c->baseUrl.'/guardian',
                    'note' => 'ผู้ปกครองของ '.$names.' - ดูผลการเรียน การมาเรียน ค่าธรรมเนียมและแจ้งชำระ'];
            }
        }
    }

    /** Where the public pages live: this site, or the public side of a two-app install (its address is not known to the builder). */
    public static function publicBase(DemoContext $c): string
    {
        if (!Edition::isInternal()) return $c->baseUrl;
        $url = rtrim((string) config('saan.public_url'), '/');
        return $url !== '' ? $url : '(เว็บหน้าบ้าน)';
    }
}
