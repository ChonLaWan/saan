<?php

namespace App\Domain\Demo;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Trial school, part 5: what the public website shows - news, group hubs, surveys, name lists, complaints, the contact inbox, ITA - and the "trial" marks on the site. */
final class DemoWebsite
{
    public function __construct(private DemoContext $c) {}

    // ---------------------------------------------------------------- news

    /**
     * The installer's full mode already brings the website sample (62 records). A blank install that gets the trial school through the
     * command has no published news, so three short invented stories are added there; reader counts go to the news that exist.
     */
    public function news(): void
    {
        $c = $this->c;
        $articles = fn () => DB::table('posts')->where('school_id', $c->school)->where('status', 'published')->where('content_type', 'article')->whereNull('deleted_at');
        if (!$articles()->exists()) {
            foreach ([['demo-sports-day', 'กีฬาสีภายในเชื่อมความสามัคคีของนักเรียนทุกระดับชั้น', 'นักเรียนทั้งหกระดับชั้นร่วมแข่งขันกีฬาและกองเชียร์ตลอดทั้งวัน'],
                ['demo-parent-meeting', 'ประชุมผู้ปกครองต้นภาคเรียน', 'ครูประจำชั้นพบผู้ปกครองเพื่อแจ้งแนวทางการเรียนและช่องทางติดต่อของโรงเรียน'],
                ['demo-reading-club', 'ชุมนุมนักอ่านรุ่นเยาว์ชวนเพื่อนอ่านหนังสือเล่มโปรด', 'กิจกรรมเล่าเรื่องจากหนังสือช่วงพักกลางวันที่ห้องสมุด']] as $n => [$slug, $title, $text]) {
                $at = $c->at($c->today->subDays($n * 3 + 1), '09:00');
                $c->insertId('posts', ['title' => $title, 'body' => $text.' (ข่าวสมมติของชุดทดลอง ไม่ใช่เหตุการณ์จริง)', 'status' => 'published', 'author_id' => $c->admin->id, 'published_at' => $at,
                    'slug' => $slug, 'excerpt' => $text, 'category' => 'news', 'content_type' => 'article', 'metadata' => ['body_format' => 'plain', 'source_kind' => 'mock'], 'created_at' => $at, 'updated_at' => $at]);
            }
        }
        if (!$c->has('web-cms') || !Schema::hasTable('cms_post_views')) return;
        $rows = [];
        foreach ($articles()->orderByDesc('published_at')->limit(6)->pluck('id') as $n => $post) {
            for ($d = 0; $d < 7; $d++) $rows[] = ['post_id' => (int) $post, 'day' => $c->today->subDays($d)->toDateString(), 'n' => 3 + DemoNames::pick('views'.$n.'|'.$d, 40), 'sent_n' => 0];
        }
        if ($rows) $c->insert('cms_post_views', $rows);
    }

    // ---------------------------------------------------------------- group hubs

    /** The two sample pages of the module itself (alumni association, monthly finance reports) and one work-group page with documents and a link. */
    public function hubs(): void
    {
        $c = $this->c;
        if (!$c->has('web-cms', 'cms_hubs', 'cms_hub_categories', 'cms_hub_documents')) return;
        $hubs = \Saan\Modules\WebCms\HubAdmin::class;
        $before = (int) DB::table('cms_media')->max('id');
        try {
            if (class_exists(\Saan\Modules\WebCms\HubDemo::class) && Schema::hasColumn('cms_hub_categories', 'rule')) \Saan\Modules\WebCms\HubDemo::seed();
            $hub = $hubs::createHub(['slug' => 'academic-affairs', 'title' => 'กลุ่มบริหารวิชาการ', 'kind' => 'work', 'group_name' => 'กลุ่มบริหารวิชาการ', 'status' => 'published',
                'description' => 'หน้ากลุ่มงานตัวอย่างของโรงเรียนสมมติ รวมเอกสารและข่าวของกลุ่มบริหารวิชาการ', 'contact' => 'โทร '.DemoNames::PHONE.' ต่อ 12 (สมมติ)']);
            $categories = DB::table('cms_hub_categories')->where('hub_id', $hub->id)->whereNull('rule')->orderBy('sort')->orderBy('id')->pluck('id')->all();
            if ($categories) {
                $hubs::addDocuments($hub, ['category_id' => $categories[0], 'title' => 'ปฏิทินวิชาการ ภาคเรียนที่ '.$c->term['term'].'/'.$c->term['year'].' (ตัวอย่าง)'],
                    [$c->upload(DemoContext::pdf('Academic calendar (sample)', ['Term '.$c->term['term'].'/'.$c->term['year']]), 'academic-calendar.pdf', 'application/pdf')]);
                $hubs::addDocuments($hub, ['category_id' => $categories[min(1, count($categories) - 1)], 'title' => 'แบบฟอร์มขออนุญาตใช้แผนการจัดการเรียนรู้ (ตัวอย่าง)'],
                    [$c->upload(DemoContext::pdf('Lesson plan approval form (sample)'), 'lesson-plan-form.pdf', 'application/pdf')]);
                $hubs::addDocuments($hub, ['category_id' => $categories[0], 'title' => 'หลักสูตรสถานศึกษา ฉบับเผยแพร่ (ลิงก์ตัวอย่าง)', 'external_url' => 'https://example.org/saan-demo/curriculum'], []);
            }
        } finally {
            foreach (DB::table('cms_media')->where('id', '>', $before)->pluck('path') as $path) $c->track($path);
        }
        if (Schema::hasTable('cms_hub_downloads')) {
            $rows = [];
            foreach (DB::table('cms_hub_documents')->where('school_id', $c->school)->where('is_published', true)->pluck('id') as $n => $doc) {
                for ($d = 0; $d < 4; $d++) $rows[] = ['document_id' => (int) $doc, 'day' => $c->today->subDays($d)->toDateString(), 'n' => 1 + DemoNames::pick('dl'.$n.'|'.$d, 9), 'sent_n' => 0];
            }
            if ($rows) $c->insert('cms_hub_downloads', $rows);
        }
    }

    // ---------------------------------------------------------------- surveys

    /** One open anonymous survey with answers, and one closed quiz whose participants received numbered certificates. */
    public function surveys(): void
    {
        $c = $this->c;
        if (!$c->has('surveys', 'srv_surveys', 'srv_responses')) return;
        $svc = app(\Saan\Modules\Surveys\SurveyService::class);
        $year = (int) $c->term['year'];
        $channels = ['เว็บไซต์โรงเรียน', 'กลุ่มไลน์ห้องเรียน', 'เอกสารที่นักเรียนนำกลับบ้าน', 'ประชุมผู้ปกครอง'];
        $wishes = ['อาคารสถานที่', 'โรงอาหาร', 'กิจกรรมหลังเลิกเรียน', 'การสื่อสารกับผู้ปกครอง'];
        $open = Mutation::insert('srv_surveys', ['slug' => 'satisfaction-'.$year, 'title' => 'แบบสำรวจความพึงพอใจของผู้ปกครอง ปีการศึกษา '.$year,
            'intro' => 'แบบสำรวจตัวอย่างของชุดทดลอง ตอบได้โดยไม่ต้องระบุชื่อ', 'identity_mode' => 'anonymous', 'status' => 'open', 'certificate' => null,
            'questions' => $svc->normaliseQuestions([
                ['key' => 'overall', 'type' => 'scale', 'label' => 'ความพึงพอใจโดยรวมต่อการจัดการเรียนการสอน', 'required' => true, 'max' => 5],
                ['key' => 'channel', 'type' => 'single', 'label' => 'ท่านติดตามข่าวสารของโรงเรียนทางใดมากที่สุด', 'required' => true, 'options' => $channels],
                ['key' => 'improve', 'type' => 'multi', 'label' => 'ด้านที่อยากให้โรงเรียนพัฒนาเพิ่ม', 'required' => false, 'options' => $wishes],
                ['key' => 'comment', 'type' => 'text', 'label' => 'ข้อเสนอแนะเพิ่มเติม', 'required' => false],
            ])], 'survey.created', ['slug' => 'satisfaction-'.$year]);
        for ($i = 0; $i < 12; $i++) {
            $answers = ['overall' => 3 + DemoNames::pick('sv-o'.$i, 3), 'channel' => $channels[DemoNames::pick('sv-c'.$i, 4)], 'improve' => array_values(array_unique([$wishes[$i % 4], $wishes[($i + 1 + $i % 2) % 4]]))];
            if ($i % 3 === 0) $answers['comment'] = 'อยากให้มีกิจกรรมที่ผู้ปกครองเข้าร่วมได้มากขึ้น (คำตอบสมมติ)';
            $svc->submit($open, ['a' => $answers], '203.0.113.'.(10 + $i), null);
        }

        $options = [['ทิ้งลงถังตามประเภท', 'ทิ้งรวมกัน', 'เผาหลังอาคาร'], ['ปิดเมื่อไม่ใช้', 'เปิดทิ้งไว้ทั้งวัน', 'เปิดเฉพาะตอนพัก'], ['นำกลับมาใช้ซ้ำ', 'ทิ้งทันที', 'ฝังดิน']];
        $quiz = Mutation::insert('srv_surveys', ['slug' => 'green-school-quiz-'.$year, 'title' => 'แบบทดสอบความรู้โรงเรียนปลอดขยะ', 'intro' => 'แบบทดสอบตัวอย่าง ผู้ที่ได้คะแนนร้อยละ 60 ขึ้นไปได้รับเกียรติบัตร',
            'identity_mode' => 'name_email', 'status' => 'open',
            'certificate' => ['enabled' => true, 'title' => 'เกียรติบัตร', 'body_template' => 'ขอมอบเกียรติบัตรฉบับนี้ให้แก่ {name} เพื่อแสดงว่าผ่าน {survey} เมื่อ {date} เลขที่ {no} {school}',
                'signer_name' => $c->teachers[0]['name'], 'signer_title' => 'ผู้อำนวยการโรงเรียน', 'min_score' => 60],
            'questions' => $svc->normaliseQuestions([
                ['key' => 'waste', 'type' => 'single', 'label' => 'ขยะในโรงเรียนควรจัดการอย่างไร', 'required' => true, 'options' => $options[0], 'correct' => $options[0][0]],
                ['key' => 'power', 'type' => 'single', 'label' => 'ไฟฟ้าและพัดลมในห้องเรียนควรทำอย่างไร', 'required' => true, 'options' => $options[1], 'correct' => $options[1][0]],
                ['key' => 'reuse', 'type' => 'single', 'label' => 'ขวดพลาสติกที่ยังสะอาดควรทำอย่างไร', 'required' => true, 'options' => $options[2], 'correct' => $options[2][0]],
            ])], 'survey.created', ['slug' => 'green-school-quiz-'.$year]);
        foreach (array_slice(array_values($c->studentsOf(2)), 0, 8) as $i => $s) {
            $raw = ['waste' => $options[0][0], 'power' => $options[1][$i === 5 ? 1 : 0], 'reuse' => $options[2][$i >= 6 ? 1 : 0]];
            if ($i === 7) $raw['power'] = $options[1][2];
            $key = $svc->key((int) $quiz->id, 'name_email', \Saan\Modules\Surveys\SurveyService::emailIdentity('quiz'.($i + 1).'@'.DemoNames::MAIL_DOMAIN));
            $svc->finalize($quiz, $key, $s['prefix'].$s['first'].' '.$s['last'], $svc->validateAnswers($svc->questions($quiz), $raw), $svc->ipHash('203.0.113.'.(60 + $i), (int) $quiz->id), null);
        }
        DB::table('srv_responses')->where('survey_id', $quiz->id)->update(['ip_hash' => '']);
        Mutation::update('srv_surveys', (int) $quiz->id, (int) DB::table('srv_surveys')->where('id', $quiz->id)->value('version'), ['status' => 'closed'], 'survey.close');
    }

    // ---------------------------------------------------------------- name lists

    /** One published list of 20 invented applicants; the public page shows the family names masked ("first name + initial"). */
    public function notices(): void
    {
        $c = $this->c;
        if (!$c->has('web-notices', 'notice_lists', 'notice_rows')) return;
        $repo = app(\Saan\Modules\WebNotices\NoticeRepository::class);
        $year = (int) $c->term['year'] + 1;
        $text = "ลำดับ\tชื่อ-สกุล\tเลขที่สมัคร\tหมายเหตุ\n";
        for ($i = 0; $i < 20; $i++) {
            [, $prefix, $first, $last] = DemoNames::student(121 + $i * 3, 1);
            $text .= ($i + 1)."\t".$prefix.' '.$first.' '.$last."\t".(70001 + $i)."\t".($i < 15 ? 'ตัวจริง' : 'สำรอง')."\n";
        }
        $list = $repo->create(['title' => 'ประกาศรายชื่อผู้ผ่านการคัดเลือกเข้าเรียนชั้น ม.1 ปีการศึกษา '.$year.' (รายชื่อสมมติ)', 'slug' => 'm1-admission-'.$year,
            'intro' => 'รายชื่อตัวอย่างของชุดทดลอง ทุกชื่อเป็นชื่อสมมติ หน้าเว็บแสดงนามสกุลแบบย่อ', 'columns' => ['order_no', 'display_name', 'applicant_no', 'note'], 'name_mode' => 'first_initial',
            'publish_from' => $c->today->toDateString(), 'expires_on' => $c->today->addDays(90)->toDateString()])['list'];
        $list = $repo->replaceRows((int) $list['id'], (int) $list['version'], $text)['list'];
        $repo->setStatus((int) $list['id'], (int) $list['version'], 'publish');
    }

    // ---------------------------------------------------------------- complaints

    /** Three cases: one answered (its receipt and tracking code are listed with the accounts), one just received, one corruption case only the principal sees. */
    public function complaints(): void
    {
        $c = $this->c;
        if (!$c->has('complaints', 'cmp_cases', 'cmp_actions')) return;
        $rules = \Saan\Modules\Complaints\Complaints::class;
        Settings::put('complaints.executive_user_ids', [$c->users['principal']]);
        $contact = fn (string $name, string $mail) => Crypt::encryptString(json_encode(['name' => $name, 'email' => $mail, 'phone' => ''], JSON_UNESCAPED_UNICODE));
        $cases = [
            ['general', $contact('ผู้ปกครองตัวอย่าง (สมมติ)', 'parent@'.DemoNames::MAIL_DOMAIN), 'ขอให้จัดจุดรับส่งนักเรียนหน้าโรงเรียน', 'ช่วงเย็นรถจอดแออัดบริเวณประตูหลัง ขอให้โรงเรียนพิจารณาจัดจุดรับส่งใหม่ (ข้อมูลสมมติ)', 9,
                'โรงเรียนจะปรับจุดรับส่งตั้งแต่สัปดาห์หน้า และแจ้งผู้ปกครองผ่านครูประจำชั้น (ข้อความตอบตัวอย่าง)'],
            ['suggestion', null, 'ข้อเสนอแนะเรื่องเวลาเปิดห้องสมุด', 'อยากให้ห้องสมุดเปิดช่วงพักกลางวันนานขึ้น (ข้อมูลสมมติ)', 1, null],
            ['corruption', null, 'เรื่องตัวอย่างสำหรับทดลองสิทธิ์การเห็นเรื่องทุจริต', 'ข้อความสมมติสำหรับทดลองว่าเรื่องประเภทนี้เห็นได้เฉพาะผู้บริหารที่กำหนด ไม่มีเหตุการณ์จริง', 2, null],
        ];
        foreach ($cases as [$category, $who, $subject, $detail, $ago, $reply]) {
            $received = CarbonImmutable::parse($c->today->subDays($ago)->toDateString().' 10:20', 'Asia/Bangkok');
            $at = $c->at($received->toDateString(), '10:20');
            $answered = $reply ? $c->at($received->addDays(3)->toDateString(), '14:00') : null;
            $receipt = $rules::newReceipt();
            $code = $rules::newTrackingCode();
            $id = $c->insertId('cmp_cases', ['receipt_no' => $receipt, 'track_hash' => $rules::trackHash($receipt, $code), 'envelope_hash' => null, 'category' => $category, 'anonymous' => $who === null,
                'contact' => $who, 'subject' => $subject, 'detail' => $detail, 'status' => $reply ? 'replied' : 'received', 'assignee_id' => $reply ? $c->admin->id : null,
                'due_on' => $rules::dueDate($category, $received), 'received_at' => $at, 'status_changed_at' => $answered ?? $at, 'public_reply' => $reply, 'public_reply_at' => $answered,
                'created_at' => $at, 'updated_at' => $answered ?? $at]);
            $actions = [['case_id' => $id, 'actor_id' => null, 'action' => 'received', 'detail' => json_encode(['files' => 0]), 'created_at' => $at]];
            if ($reply) $actions[] = ['case_id' => $id, 'actor_id' => $c->admin->id, 'action' => 'replied', 'detail' => null, 'created_at' => $answered];
            $c->insert('cmp_actions', $actions);
            if ($reply) $c->accounts[] = ['role' => 'เลขรับเรื่องร้องเรียนตัวอย่าง (หน้าติดตามเรื่อง)', 'login' => $receipt, 'password' => $code, 'url' => DemoAcademic::publicBase($c).'/portal/complaints/status',
                'note' => 'ไม่ใช่บัญชี: เลขรับเรื่องและรหัสติดตามของเรื่องที่โรงเรียนตอบแล้ว'];
        }
    }

    /** The yearly summary the public page and ITA item O14 link to (figures only, frozen at this moment): runs after every case of the trial exists. */
    public function complaintsReport(): void
    {
        $c = $this->c;
        if (!$c->has('complaints', 'cmp_cases') || !class_exists(\Saan\Modules\Complaints\Reports::class) || !Schema::hasTable('cmp_reports')) return;
        \Saan\Modules\Complaints\Reports::publish(\Saan\Modules\Complaints\Complaints::currentFiscalYear(), 'publish', $c->admin);
    }

    // ---------------------------------------------------------------- inbox and social

    public function messages(): void
    {
        $c = $this->c;
        $rows = [];
        foreach ([['สอบถามกำหนดการรับสมัครนักเรียนใหม่', 'อยากทราบวันรับสมัครและเอกสารที่ต้องใช้สำหรับชั้น ม.1 ปีการศึกษาหน้า', 'unread', 0], ['ขอใบรับรองการเป็นนักเรียน', 'ต้องการใบรับรองเพื่อใช้ประกอบการขอทุน ต้องติดต่อที่ใด', 'unread', 1],
            ['ชื่นชมกิจกรรมกีฬาสี', 'ขอชื่นชมครูและนักเรียนที่จัดงานได้เรียบร้อยมาก', 'read', 4], ['สอบถามเส้นทางรถรับส่ง', 'รถรับส่งนักเรียนผ่านหมู่ 9 ตำบลต้นธารหรือไม่', 'read', 7],
            ['เสนอเป็นวิทยากรอาชีพ', 'ยินดีมาเล่าประสบการณ์อาชีพให้นักเรียนฟังในคาบแนะแนว', 'archived', 15]] as $n => [$subject, $message, $status, $ago]) {
            $at = $c->at($c->today->subDays($ago), '11:30');
            $rows[] = ['name' => 'ผู้ติดต่อตัวอย่าง '.($n + 1).' (สมมติ)', 'email' => 'visitor'.($n + 1).'@'.DemoNames::MAIL_DOMAIN, 'subject' => $subject, 'message' => $message.' (ข้อความสมมติ)',
                'status' => $status, 'created_at' => $at, 'updated_at' => $at];
        }
        $c->insert('cms_messages', $rows);
    }

    /** web-social stores settings only. The row is created switched off: showing a feed needs the address of a real Facebook page of the school. */
    public function social(): void
    {
        $c = $this->c;
        if (!$c->has('web-social', 'social_feeds') || DB::table('social_feeds')->where('school_id', $c->school)->exists()) return;
        $c->insertId('social_feeds', ['facebook_url' => '', 'heading' => 'ข่าวจากเพจ Facebook ของโรงเรียน', 'height' => 640, 'enabled' => false]);
    }

    // ---------------------------------------------------------------- trial marks

    /** The school name and the site badge say "trial" on every page (the staff navigation shows the school name), and the staff page reads the personnel register. */
    public function markSite(): void
    {
        $c = $this->c;
        $edit = function (string $table, callable $change) use ($c) {
            $row = DB::table($table)->where('school_id', $c->school)->first();
            if (!$row) return;
            $set = [];
            foreach (['draft', 'published'] as $col) {
                $data = json_decode((string) $row->$col, true);
                if (is_array($data)) $set[$col] = json_encode($change($data), JSON_UNESCAPED_UNICODE);
            }
            if ($set) DB::table($table)->where('id', $row->id)->update($set + ['updated_at' => now()]);
        };
        $edit('cms_themes', function (array $t) {
            $name = trim((string) ($t['school_name'] ?? '')) ?: DemoNames::SCHOOL;
            if (!str_contains($name, 'ทดลอง')) $name .= DemoSchool::NAME_SUFFIX;
            $t['school_name'] = $name;
            if (trim((string) ($t['phone'] ?? '')) === '') $t['phone'] = DemoNames::PHONE;
            if (trim((string) ($t['address'] ?? '')) === '') $t['address'] = DemoNames::ADDRESS;
            return $t;
        });
        $personnel = isset($c->filled['personnel']);
        $stats = $this->statistics();
        $edit('cms_site_settings', function (array $s) use ($personnel, $stats) {
            $s['badge'] = DemoSchool::BADGE;
            if ($personnel) $s['directory_source'] = 'personnel';
            if ($stats) $s['statistics'] = $stats; // the numbers under the home page banner describe this trial school, not the 24-student sample set
            return $s;
        });
        // header of printed documents: the same marked name the website and the staff navigation show
        if (Settings::get('school.name') === null) {
            $theme = json_decode((string) DB::table('cms_themes')->where('school_id', $c->school)->value('published'), true);
            Settings::put('school.name', mb_substr((string) ($theme['school_name'] ?? DemoNames::SCHOOL.DemoSchool::NAME_SUFFIX), 0, 150));
        }
    }

    /** Home page figures counted from the trial school's own rows (students who are studying, active staff, classrooms); a figure of zero is left out. */
    private function statistics(): array
    {
        $school = $this->c->school;
        $count = function (string $table, array $where = []) use ($school): int {
            if (!Schema::hasTable($table)) return 0;
            foreach ($where as $column => $value) if (!Schema::hasColumn($table, $column)) unset($where[$column]);
            return (int) DB::table($table)->where('school_id', $school)->where($where)->count();
        };
        $rows = [
            [$count('students', ['status' => 'studying']), 'นักเรียนสมมติ'],
            [$count('teachers', ['is_active' => true]), 'ครูและบุคลากรสมมติ'],
            [$count('classrooms'), 'ห้องเรียน'],
        ];
        $out = [];
        foreach ($rows as [$n, $label]) if ($n > 0) $out[] = ['value' => (string) $n, 'label' => $label];
        return $out;
    }

    // ---------------------------------------------------------------- ITA

    /** The 18 standard ITA items of the current fiscal year. Items with an automatic source link themselves to the data made above; the others get a page of this site. */
    public function ita(): void
    {
        $c = $this->c;
        if (!$c->has('web-ita', 'ita_years', 'ita_topics', 'ita_items')) return;
        $ita = app(\Saan\Modules\WebIta\ItaRepository::class);
        $pages = ['O8' => '/portal/contact', 'O9' => '/portal/procurement', 'O11' => '/portal/downloads', 'O12' => '/portal/downloads', 'O17' => '/portal/people'];
        // this fiscal year and the two before it, all published (the older ones keep what the sources link to today); no draft year: the original trial checks "the" year's status
        foreach ([0, 1, 2] as $back) {
            $year = $ita->create(\App\Support\Fiscal::current() - $back, null, true);
            $topics = array_map(fn ($t) => ['code' => $t['code'], 'title' => $t['title'], 'items' => array_map(fn ($i) => ['title' => $i['title'],
                'url' => $i['url'] ?: (empty($i['source']) ? ($pages[$t['code']] ?? '/portal/pages') : null), 'media_id' => $i['media_id'], 'note' => $i['note'],
                'source' => $i['source'] ?? null, 'source_opts' => $i['source_opts'] ?: null], $t['items'])], $year['topics']);
            $year = $ita->save((int) $year['year']['id'], (int) $year['year']['version'], (string) $year['year']['title'], $topics);
            $ita->setStatus((int) $year['year']['id'], (int) $year['year']['version'], true);
        }
    }
}
