<?php

namespace App\Domain\Demo;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\DB;

/** Trial school, part 4: clubs and the student-council election, project-based learning, exam checking, learning links, archives, the API gateway. */
final class DemoStudentLife
{
    public function __construct(private DemoContext $c) {}

    // ---------------------------------------------------------------- activities

    /** Four clubs of the current term with weekly roll calls, results and certificates, and one finished student-council election (secret ballots). */
    public function activities(): void
    {
        $c = $this->c;
        if (!$c->has('activities', 'act_clubs', 'act_members', 'act_sessions', 'act_attendance', 'act_elections', 'act_candidates', 'act_voters', 'act_ballots')) return;
        Settings::put('activities.signer_name', $c->teachers[0]['name']);
        Settings::put('activities.signer_title', 'ผู้อำนวยการ'.DemoNames::SCHOOL);
        $clubs = app(\Saan\Modules\Activities\Clubs::class);
        // weekly club period: the Wednesdays of the current term up to today (at most four)
        $days = array_slice(array_values(array_filter($c->lastSchoolDays(40, \Carbon\CarbonImmutable::parse($c->term['starts_on'], 'Asia/Bangkok')), fn ($d) => $d->dayOfWeekIso === 3)), -4);
        $made = [];
        foreach ([['ชุมนุมหุ่นยนต์และโค้ดดิ้ง', 11, 'ต่อวงจรและเขียนโปรแกรมควบคุมหุ่นยนต์อย่างง่าย'], ['ชุมนุมดนตรีไทย', 10, 'ฝึกเครื่องดนตรีไทยและรวมวง'],
            ['ชุมนุมนักอ่านรุ่นเยาว์', 3, 'อ่านหนังสือเล่มโปรดแล้วเล่าให้เพื่อนฟัง'], ['ชุมนุมแบดมินตัน', 9, 'ฝึกทักษะพื้นฐานและแข่งขันภายใน']] as $j => [$name, $advisor, $about]) {
            $club = $clubs->create(['name' => $name, 'term_id' => $c->term['id'], 'advisor_teacher_id' => $c->teachers[$advisor]['id'], 'capacity' => 30, 'grade_levels' => [],
                'description' => $about.' (ข้อมูลสมมติ)', 'is_open' => true]);
            $members = array_filter($c->students, fn ($s, $k) => $k % 8 === $j, ARRAY_FILTER_USE_BOTH);
            $result = $clubs->enroll((int) $club->id, array_column($members, 'id'));
            if ($result['rejected']) throw new \RuntimeException('ลงทะเบียนชุมนุมทดลองไม่สำเร็จ: '.$result['rejected'][0]['reason']);
            foreach ($days as $n => $day) {
                $session = $clubs->sessionCreate((int) $club->id, $day->toDateString(), 'กิจกรรมครั้งที่ '.($n + 1));
                $clubs->saveAttendance((int) $session->id, (int) $session->version, array_map(fn ($k, $s) => ['student_id' => $s['id'],
                    'present' => in_array($k, DemoAcademic::STUDENT_ACCOUNTS, true) || DemoNames::pick('club'.$k.'|'.$n, 25) !== 0], array_keys($members), $members));
            }
            if ($days) $clubs->computeResults((int) $club->id);
            $made[] = (int) $club->id;
        }
        if ($days && class_exists(\Saan\Modules\Activities\Certificates::class) && \Illuminate\Support\Facades\Schema::hasTable('act_certificates')) {
            app(\Saan\Modules\Activities\Certificates::class)->issue($made[0]);
        }

        // The election is written directly in its closed state: the voting screens need a live kiosk ticket per voter.
        $day = $c->lastSchoolDays(8)[0];
        $election = $c->insertId('act_elections', ['title' => 'เลือกตั้งประธานนักเรียน ปีการศึกษา '.$c->term['year'], 'opens_at' => $c->at($day, '08:30'), 'closes_at' => $c->at($day, '15:00'),
            'eligible_grade_levels' => null, 'status' => 'closed', 'key_secret' => null]);
        $candidates = [];
        foreach ([[80, 'โรงเรียนสะอาด ทุกคนมีส่วนร่วม'], [83, 'เสียงของนักเรียนต้องมีคนฟัง'], [86, 'กีฬา ดนตรี และรอยยิ้มทุกวันศุกร์']] as $n => [$k, $slogan]) {
            $candidates[] = $c->insertId('act_candidates', ['election_id' => $election, 'number' => $n + 1, 'student_id' => $c->students[$k]['id'], 'slogan' => $slogan.' (ข้อมูลสมมติ)']);
        }
        $voters = [];
        $ballots = [];
        foreach ($c->students as $k => $s) {
            if (DemoNames::pick('voted'.$k, 100) >= 86) continue;
            $voters[] = ['election_id' => $election, 'student_id' => $s['id'], 'classroom_id' => $c->classrooms[$s['room']]['id'], 'voted_at' => $c->at($day, sprintf('%02d:%02d', 9 + $k % 5, $k % 60))];
            $p = DemoNames::pick('ballot'.$k, 100);
            // a ballot carries no trace of the voter: random id and random voter key, stored in another order than the roll
            $ballots[bin2hex(random_bytes(16))] = ['election_id' => $election, 'voter_key' => bin2hex(random_bytes(32)), 'candidate_id' => $p < 44 ? $candidates[0] : ($p < 78 ? $candidates[1] : ($p < 95 ? $candidates[2] : null)),
                'cast_at' => $day->toDateString()];
        }
        ksort($ballots);
        $c->insert('act_voters', $voters);
        $c->insert('act_ballots', array_map(fn ($id, $b) => ['id' => $id] + $b, array_keys($ballots), $ballots));
    }

    // ---------------------------------------------------------------- constructionism

    /** Two project-based learning projects with members, a progress log, linked artifacts and rubric reflections. */
    public function projectLearning(): void
    {
        $c = $this->c;
        if (!$c->has('constructionism', 'con_projects', 'con_members', 'con_logs', 'con_artifacts', 'con_reflections')) return;
        $pbl = \Saan\Modules\Constructionism\Pbl::class;
        $admin = $c->admin;
        $days = $c->lastSchoolDays(12);
        foreach ([[1, 2, 'เตาพลังงานแสงอาทิตย์จากวัสดุเหลือใช้', 'เราจะใช้แสงอาทิตย์ทำอาหารกลางวันโดยไม่ใช้เชื้อเพลิงได้อย่างไร', 'active', 'solar-cooker'],
            [4, 11, 'ระบบแจ้งเตือนถังขยะเต็มในโรงเรียน', 'เทคโนโลยีราคาถูกช่วยให้โรงเรียนสะอาดขึ้นได้อย่างไร', 'presenting', 'smart-bin']] as [$room, $advisor, $title, $question, $status, $slug]) {
            $detail = $pbl::create(['classroom_id' => $c->classrooms[$room]['id'], 'title' => $title, 'driving_question' => $question.' (ข้อมูลสมมติ)', 'advisor_teacher_id' => $c->teachers[$advisor]['id']], $admin);
            $project = (int) $detail['project']['id'];
            $members = array_slice(array_values($c->studentsOf($room)), 2, 4);
            foreach ($members as $n => $s) $pbl::addMember($project, ['student_id' => $s['id'], 'role' => ['หัวหน้าทีม', 'ออกแบบ', 'ทดลองและบันทึกผล', 'นำเสนอ'][$n]], $admin);
            foreach (['ระดมความคิดและเลือกปัญหาที่อยากแก้', 'ทำต้นแบบชิ้นแรก พบว่ายังไม่แข็งแรงพอ', 'ปรับแบบรอบที่ 2 และเก็บข้อมูลเปรียบเทียบ'] as $n => $entry) {
                $pbl::addLog($project, ['date' => $days[min(count($days) - 1, $n * 4)]->toDateString(), 'entry' => $entry.' (ข้อมูลสมมติ)', 'by_teacher' => $n !== 1], $admin);
            }
            \Saan\Modules\Constructionism\Files::add($project, ['title' => 'สไลด์นำเสนอความก้าวหน้า', 'kind' => 'doc', 'url' => 'https://example.org/saan-demo/'.$slug.'/slides'], null, $admin);
            \Saan\Modules\Constructionism\Files::add($project, ['title' => 'วิดีโอทดสอบต้นแบบ', 'kind' => 'video', 'url' => 'https://example.org/saan-demo/'.$slug.'/video'], null, $admin);
            foreach (array_slice($members, 0, 2) as $n => $s) {
                \Saan\Modules\Constructionism\Reflections::add($project, ['student_id' => $s['id'], 'text' => 'ได้เรียนรู้ว่าการทดลองซ้ำช่วยให้งานดีขึ้น (ข้อมูลสมมติ)',
                    'rubric' => ['inquiry' => 3 + $n % 2, 'making' => 3, 'teamwork' => 4, 'iteration' => 2 + $n, 'sharing' => 3]], $admin);
            }
            DB::table('con_projects')->where('id', $project)->update(['status' => $status]);
        }
    }

    // ---------------------------------------------------------------- exam checker

    /** One 20-question quiz of ม.1/1 with an answer key (one cancelled question, two sections) and a scored sheet for every student of the room. */
    public function exams(): void
    {
        $c = $this->c;
        if (!$c->has('exam-checker', 'exm_exams', 'exm_sheets')) return;
        $key = [1, 3, 2, 4, 1, 2, 2, 3, 4, 1, 3, 3, 'cancelled', 2, 1, 4, 4, 2, 1, 3];
        $exam = Mutation::insert('exm_exams', ['term_id' => $c->term['id'], 'title' => 'สอบย่อยคณิตศาสตร์ ม.1 ครั้งที่ 1 (ข้อสอบสมมติ)', 'kind' => 'quiz', 'subject_label' => 'คณิตศาสตร์', 'grade_level' => 'ม.1',
            'question_count' => count($key), 'choices_per_question' => 4, 'points_per_question' => 1, 'answer_key' => $key,
            'sections' => [['label' => 'ตอนที่ 1 จำนวนเต็ม', 'from' => 1, 'to' => 10], ['label' => 'ตอนที่ 2 เศษส่วน', 'from' => 11, 'to' => 20]],
            'status' => 'open', 'created_by' => $c->teachers[5]['user_id']], 'exam_checker.exam_created', ['title' => 'demo']);
        $rows = [];
        foreach ($c->studentsOf(0) as $k => $s) {
            $answers = '';
            foreach ($key as $q => $right) {
                $right = is_int($right) ? $right : 1;
                $p = DemoNames::pick('exam'.$k.'|'.$q, 100);
                $answers .= $p < 6 ? '-' : ($p < 50 + DemoNames::pick('ability'.$k, 45) ? $right : $right % 4 + 1);
            }
            $rows[] = ['student_code' => $s['code'], 'answers' => $answers];
        }
        $saved = \Saan\Modules\ExamChecker\SheetService::save($exam, $rows, ['source' => 'keyed']);
        if ($saved['errors']) throw new \RuntimeException('บันทึกใบตอบทดลองไม่สำเร็จ: '.$saved['errors'][0]['message']);
    }

    // ---------------------------------------------------------------- learning links

    /** Links of the whole school and of two rooms; each of those rooms gets an access code for its public page (listed with the accounts). */
    public function learningLinks(): void
    {
        $c = $this->c;
        if (!$c->has('learning-links', 'lrn_links', 'lrn_access')) return;
        $links = \Saan\Modules\LearningLinks\Links::class;
        $admin = $c->admin;
        foreach ([[null, 'คลังสื่อการเรียนรู้ของโรงเรียน', 'library', 'doc'], [null, 'ตารางสอบและปฏิทินวิชาการ', 'calendar', 'doc'], [0, 'ห้องเรียนออนไลน์ภาษาไทย ม.1', 'm1-thai', 'lms'],
            [0, 'วิดีโอทบทวนคณิตศาสตร์ ม.1', 'm1-math-video', 'video'], [0, 'แบบฝึกหัดออนไลน์วิทยาศาสตร์ ม.1', 'm1-science', 'app'], [3, 'ห้องเรียนออนไลน์ฟิสิกส์ ม.4', 'm4-physics', 'lms'],
            [3, 'คลังข้อสอบเก่า ม.4', 'm4-exams', 'doc']] as $n => [$room, $title, $slug, $kind]) {
            $links::create(['title' => $title, 'url' => 'https://classroom.example.org/saan-demo/'.$slug, 'kind' => $kind, 'sort' => $n + 1] + ($room === null ? [] : ['classroom_id' => $c->classrooms[$room]['id']]), $admin);
        }
        foreach ([0, 3] as $room) {
            $out = $links::issueCode($c->classrooms[$room]['id'], 180, $admin);
            $c->accounts[] = ['role' => 'รหัสห้องเรียนออนไลน์ '.$c->classrooms[$room]['name'].' (หน้าลิงก์การเรียนรู้)', 'login' => '-', 'password' => $out['code'],
                'url' => DemoAcademic::publicBase($c).'/learn/'.$c->classrooms[$room]['id'], 'note' => 'ไม่ใช่บัญชี: รหัสเปิดหน้าลิงก์ของห้อง ใช้ได้ 180 วัน'];
        }
    }

    // ---------------------------------------------------------------- connectors

    /** A read-only archive of an older school year, imported from three small CSV texts (no real file is involved). */
    public function connectors(): void
    {
        $c = $this->c;
        if (!$c->has('connectors', 'con_archives', 'con_archive_files', 'con_rows')) return;
        $service = app(\Saan\Modules\Connectors\ArchiveService::class);
        $year = (int) $c->term['year'] - 2;
        $archive = $service->create('ปีการศึกษา '.$year.' (ข้อมูลสมมติ)', $year, 'ไฟล์ตัวอย่างของชุดทดลอง');
        $students = "เลขประจำตัวนักเรียน,คำนำหน้า,ชื่อ,นามสกุล,ชั้น,ห้อง,เลขที่,เพศ\n";
        $grades = "เลขประจำตัวนักเรียน,ชื่อ-สกุล,ปีการศึกษา,ภาคเรียน,รหัสวิชา,ชื่อวิชา,คะแนน,เกรด,หน่วยกิต\n";
        $attendance = "เลขประจำตัวนักเรียน,ปีการศึกษา,ภาคเรียน,วันมา,วันขาด,วันลา,วันเรียน\n";
        foreach (array_slice(array_values($c->studentsOf(5)), 0, 8) as $n => $s) {
            $students .= implode(',', [$s['code'], $s['prefix'], $s['first'], $s['last'], 'ม.4', 1, $n + 1, $s['sex'] === 'M' ? 'ชาย' : 'หญิง'])."\n";
            foreach ([['ท31101', 'ภาษาไทย 1', 1.5], ['ค31101', 'คณิตศาสตร์ 1', 1.5]] as $j => [$code, $name, $credit]) {
                $score = 55 + DemoNames::pick('old'.$n.$j, 40);
                $grades .= implode(',', [$s['code'], $s['first'].' '.$s['last'], $year, 1, $code, $name, $score, $score >= 80 ? 4 : ($score >= 70 ? 3 : ($score >= 60 ? 2 : 1)), $credit])."\n";
            }
            $absent = DemoNames::pick('oldatt'.$n, 6);
            $attendance .= implode(',', [$s['code'], $year, 1, 98 - $absent, $absent, 2, 100])."\n";
        }
        $service->importFile((int) $archive['id'], 'student', 'generic_th', [], 'students-'.$year.'.csv', $students);
        $service->importFile((int) $archive['id'], 'grade', 'generic_th', [], 'grades-'.$year.'.csv', $grades);
        $service->importFile((int) $archive['id'], 'attendance_summary', 'generic_th', [], 'attendance-'.$year.'.csv', $attendance);
        Settings::put('connectors.teacher_lookup', true);
    }

    // ---------------------------------------------------------------- api gateway

    /** One API client whose secret nobody knows (the administrator issues a new one to really use it) and a short request log. */
    public function gateway(): void
    {
        $c = $this->c;
        if (!$c->has('api-gateway', 'api_clients', 'api_request_log')) return;
        $name = 'ระบบห้องสมุดสมมติ (ตัวอย่างการเชื่อมต่อ)';
        $client = $c->insertId('api_clients', ['name' => $name, 'name_key' => \Saan\Modules\ApiGateway\Net::key($name), 'client_id' => 'cl_'.bin2hex(random_bytes(10)),
            'secret_hash' => $c->lockedHash(), 'scopes' => ['classrooms:read', 'courses:read'], 'allowed_ips' => null, 'redirect_uris' => null,
            'rate_per_minute' => 120, 'is_active' => true, 'last_used_at' => $c->at($c->today->subDay(), '10:05')]);
        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $rows[] = ['client_id' => $client, 'route' => ['api/v1/classrooms', 'api/v1/courses', 'api/v1/classrooms'][$i % 3], 'status' => $i % 7 === 6 ? 401 : 200, 'ms' => 20 + DemoNames::pick('ms'.$i, 60),
                'at' => $c->at($c->today->subDays(intdiv($i, 3) + 1), sprintf('10:%02d', $i * 4))];
        }
        $c->insert('api_request_log', $rows);
    }
}
