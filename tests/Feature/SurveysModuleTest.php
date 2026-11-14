<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\Surveys\Mail\SurveyVerifyMail;
use Saan\Modules\Surveys\SurveyRejected;
use Saan\Modules\Surveys\SurveyService;
use Tests\TestCase;

class SurveysModuleTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTIONS = [
        ['key' => 'q1', 'type' => 'single', 'label' => 'ท่านพอใจกิจกรรมหรือไม่', 'options' => ['พอใจ', 'ไม่พอใจ'], 'required' => true, 'correct' => 'พอใจ'],
        ['key' => 'q2', 'type' => 'multi', 'label' => 'หัวข้อที่สนใจ', 'options' => ['ก', 'ข', 'ค'], 'required' => false],
        ['key' => 'q3', 'type' => 'scale', 'label' => 'ความพึงพอใจรวม', 'required' => true, 'max' => 5],
        ['key' => 'q4', 'type' => 'text', 'label' => '=ข้อเสนอแนะ', 'required' => false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->first());
    }

    private function guest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function admin(): void
    {
        $this->actingAs(User::where('role', 'admin')->first());
    }

    /** Create + configure + (optionally) open a survey through the admin API. */
    private function make(string $mode = 'anonymous', array $over = [], bool $open = true): array
    {
        $s = $this->postJson('/api/surveys', ['title' => 'ประเมินกิจกรรม', 'identity_mode' => $mode])->assertCreated()->json();
        $payload = array_replace([
            'version' => $s['version'], 'title' => 'ประเมินกิจกรรม', 'intro' => "สวัสดี\nขอบคุณ", 'identity_mode' => $mode, 'opens_at' => null, 'closes_at' => null,
            'max_responses' => null, 'questions' => self::QUESTIONS, 'certificate' => null,
        ], $over);
        $s = $this->putJson('/api/surveys/'.$s['id'], $payload)->assertOk()->json();
        if ($open) $s = $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk()->json();
        return $s;
    }

    private function cert(array $extra = []): array
    {
        return array_replace(['enabled' => true, 'title' => 'เกียรติบัตร', 'body_template' => 'ขอมอบให้ {name} ตอบ {survey} เมื่อ {date}', 'signer_name' => 'ผอ. ตัวอย่าง', 'signer_title' => 'ผู้อำนวยการ', 'min_score' => null], $extra);
    }

    private function answers(array $over = []): array
    {
        return array_replace(['q1' => 'พอใจ', 'q2' => ['ก', 'ค'], 'q3' => 4, 'q4' => 'ดี'], $over);
    }

    private function roster(array $s, string $text): void
    {
        $this->postJson('/api/surveys/'.$s['id'].'/roster', ['text' => $text])->assertOk();
    }

    private function service(): SurveyService
    {
        return app(SurveyService::class);
    }

    public function test_admin_lifecycle_validation_and_conflicts(): void
    {
        $s = $this->postJson('/api/surveys', ['title' => 'A', 'slug' => 'eval-2569'])->assertCreated()->json();
        $this->assertSame('draft', $s['status']);
        $this->postJson('/api/surveys', ['title' => 'B', 'slug' => 'eval-2569'])->assertConflict();
        $this->postJson('/api/surveys', ['title' => 'B', 'slug' => 'Bad Slug'])->assertUnprocessable();
        // cannot open without questions
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => 1, 'action' => 'open'])->assertUnprocessable();
        $base = ['version' => 1, 'title' => 'A', 'identity_mode' => 'anonymous', 'questions' => self::QUESTIONS];
        $bad = fn (array $q) => $this->putJson('/api/surveys/'.$s['id'], array_replace($base, ['questions' => $q]))->assertUnprocessable();
        $bad([['key' => 'q1', 'type' => 'single', 'label' => 'x', 'options' => ['a']]]);                                   // one option
        $bad([['key' => 'q1', 'type' => 'single', 'label' => 'x', 'options' => ['a', 'b'], 'correct' => 'z']]);          // bad answer key
        $bad([['key' => 'Q 1', 'type' => 'text', 'label' => 'x']]);                                                        // bad key
        $bad([['key' => 'q1', 'type' => 'text', 'label' => 'x'], ['key' => 'q1', 'type' => 'text', 'label' => 'y']]);    // dup key
        $this->putJson('/api/surveys/'.$s['id'], $base + ['certificate' => $this->cert()])->assertUnprocessable();           // cert needs a name -> not anonymous
        $this->putJson('/api/surveys/'.$s['id'], $base + ['opens_at' => '2026-12-02T10:00', 'closes_at' => '2026-12-01T10:00'])->assertUnprocessable();
        $saved = $this->putJson('/api/surveys/'.$s['id'], $base)->assertOk()->json();
        $this->assertSame(2, $saved['version']);
        $this->putJson('/api/surveys/'.$s['id'], $base)->assertConflict()->assertJsonStructure(['message', 'current']);
        $this->getJson('/api/surveys')->assertOk()->assertJsonPath('data.0.question_count', 4)->assertJsonPath('data.0.responses', 0);
        $open = $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => 2, 'action' => 'open'])->assertOk()->json();
        $this->assertSame('open', $open['status']);
        // audit trail exists
        $this->assertTrue(DB::table('audit_events')->where('action', 'survey.saved')->exists());
        // delete only when nothing was answered
        $this->guest();
        $this->postJson('/portal/surveys/eval-2569/submit', ['a' => $this->answers()])->assertCreated();
        $this->admin();
        $this->deleteJson('/api/surveys/'.$s['id'], ['version' => 3])->assertUnprocessable();
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => 3, 'action' => 'draft'])->assertUnprocessable();
        // structure locked, label editable
        $q = self::QUESTIONS; $q[0]['label'] = 'ใหม่';
        $this->putJson('/api/surveys/'.$s['id'], ['version' => 3] + array_replace($base, ['questions' => $q]))->assertOk();
        $q[0]['options'] = ['พอใจ', 'ไม่พอใจ', 'กลาง'];
        $this->putJson('/api/surveys/'.$s['id'], ['version' => 4] + array_replace($base, ['questions' => $q]))->assertUnprocessable();
        $empty = $this->postJson('/api/surveys', ['title' => 'ลบได้'])->assertCreated()->json();
        $this->deleteJson('/api/surveys/'.$empty['id'], ['version' => 1])->assertOk();
    }

    public function test_roles_and_module_registration(): void
    {
        $this->actingAs(User::where('role', 'teacher')->first())->getJson('/api/surveys')->assertForbidden();
        $this->actingAs(User::where('role', 'principal')->first())->getJson('/api/surveys')->assertOk();
        $editor = User::where('role', 'principal')->first();
        DB::table('users')->where('id', $editor->id)->update(['role' => 'editor']);
        $this->actingAs(User::find($editor->id))->getJson('/api/surveys')->assertOk();
        $this->assertContains('surveys', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->admin();
        $this->get('/cms')->assertOk()->assertSee('assets/modules/surveys/surveys-admin.js', false)->assertSee('แบบสอบถามและเกียรติบัตร');
        $this->assertContains('post-portal-survey-submit', collect(app('router')->getRoutes()->getRoutes())->flatMap(fn ($r) => $r->gatherMiddleware())->map(fn ($m) => is_string($m) ? substr($m, strrpos($m, ',') + 1) : '')->all());
    }

    public function test_anonymous_mode_blocks_repeat_with_cookie_and_limits_ip(): void
    {
        $s = $this->make('anonymous', ['anon_ip_hour' => 5]);
        $this->assertSame(5, $s['anon_ip_hour']);
        $this->assertSame(SurveyService::ANON_PER_IP_HOUR, $this->make('anonymous')['anon_ip_hour']); // generous default (CGNAT)
        $this->guest();
        $this->get('/portal/surveys/'.$this->slug($s))->assertOk()->assertSee('ไม่ระบุตัวตน')->assertSee('ประเมินกิจกรรม')->assertSee('name="a[q1]"', false)->assertSee('name="a[q2][]"', false);
        $first = $this->post('/portal/surveys/'.$this->slug($s).'/submit', ['a' => $this->answers()])->assertOk()->assertSee('ขอบคุณ');
        $cookie = $first->getCookie('srv_'.$s['id']);
        $this->assertNotNull($cookie);
        $this->flushSession();
        $this->withCredentials()->withCookie('srv_'.$s['id'], $cookie->getValue())->postJson('/portal/surveys/'.$this->slug($s).'/submit', ['a' => $this->answers()])->assertStatus(409);
        $this->assertSame(1, DB::table('srv_responses')->count());
        // another device (no cookie) still counts: documented limitation, bounded by the per-IP budget
        $this->defaultCookies = [];
        $this->postJson('/portal/surveys/'.$this->slug($s).'/submit', ['a' => $this->answers()])->assertCreated();
        $survey = DB::table('srv_surveys')->find($s['id']);
        $ip = $this->service()->ipHash('127.0.0.1', (int) $s['id']);
        for ($i = 0; $i < 5; $i++) $this->service()->finalize($survey, hash('sha256', 'k'.$i), null, [], $ip);
        $this->postJson('/portal/surveys/'.$this->slug($s).'/submit', ['a' => $this->answers()])->assertStatus(429);
        // raw ip is never stored
        $this->assertSame(0, DB::table('srv_responses')->where('ip_hash', '127.0.0.1')->count());
    }

    public function test_one_response_per_person_survives_key_rotation_and_a_later_dedicated_hash_key(): void
    {
        Mail::fake();
        $old = config('app.key');
        $mail = $this->make('name_email');
        $anon = $this->make('anonymous');
        $roster = $this->make('roster_code', [], false);
        $svc = $this->service();
        $this->roster($roster, "K7QXM2PA9,สมหญิง รักเรียน\nK7QXM2PB8,สมชาย ใจดี");
        $this->postJson('/api/surveys/'.$roster['id'].'/status', ['version' => $roster['version'], 'action' => 'open'])->assertOk();
        $mailRow = DB::table('srv_surveys')->find($mail['id']);
        $anonRow = DB::table('srv_surveys')->find($anon['id']);
        $token = str_repeat('ab', 16);
        $svc->finalize($mailRow, $svc->key($mail['id'], 'name_email', 'dup@example.org'), 'Dup', [], $svc->ipHash('10.0.0.1'));
        $svc->finalize($anonRow, $svc->key($anon['id'], 'anonymous', $token), null, [], $svc->ipHash('10.0.0.1'));

        // 1) APP_KEY rotated, old key kept in previous_keys; 2) then a dedicated SAAN_HASH_KEY is introduced on the live system
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
        foreach ([false, true] as $dedicated) {
            if ($dedicated) config(['saan.hash_key' => 'dedicated-secret']);
            $svc = $this->service();
            $this->assertContains($svc->key($mail['id'], 'name_email', 'x'), $svc->keys($mail['id'], 'name_email', 'x'));
            $r = $svc->submit($mailRow, ['a' => $this->answers(), 'name' => 'Dup', 'email' => 'dup@example.org'], '10.0.0.1', null);
            $this->assertSame('pending', $r['status']);
            Mail::assertNothingSent(); // already answered: same reply, no mail
            try { $svc->submit($anonRow, ['a' => $this->answers()], '10.0.0.1', $token); $this->fail('anonymous repeat accepted'); }
            catch (SurveyRejected $e) { $this->assertSame(409, $e->status); }
        }
        // a roster imported before the rotation still resolves (hash made with the old key), and its code is consumed once
        $rosterRow = DB::table('srv_surveys')->find($roster['id']);
        $stored = DB::table('srv_roster')->where('survey_id', $roster['id'])->pluck('code_hash')->all();
        $this->assertNotContains($svc->key($roster['id'], 'roster_code', 'K7QXM2PA9'), $stored); // current key differs from the imported one
        $r = $svc->submit($rosterRow, ['a' => $this->answers(), 'code' => 'k7qxm2pa9'], '10.0.0.2', null, 's1');
        $this->assertSame('done', $r['status']);
        try { $svc->submit($rosterRow, ['a' => $this->answers(), 'code' => 'K7QXM2PA9'], '10.0.0.2', null, 's1'); $this->fail('roster code reused'); }
        catch (SurveyRejected $e) { $this->assertSame(409, $e->status); }
    }

    private function slug(array $s): string { return $s['slug']; }

    public function test_answer_validation(): void
    {
        $s = $this->make('anonymous');
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $this->postJson($url, ['a' => []])->assertUnprocessable()->assertJsonValidationErrors(['a.q1', 'a.q3']);
        $this->postJson($url, ['a' => $this->answers(['q1' => 'อื่น'])])->assertUnprocessable()->assertJsonValidationErrors(['a.q1']);
        $this->postJson($url, ['a' => $this->answers(['q3' => 9])])->assertUnprocessable()->assertJsonValidationErrors(['a.q3']);
        $this->postJson($url, ['a' => $this->answers(['q2' => ['ก', 'ฆ']])])->assertUnprocessable()->assertJsonValidationErrors(['a.q2']);
        $this->postJson($url, ['a' => $this->answers(['q4' => str_repeat('ก', 2001)])])->assertUnprocessable();
        $this->post($url, ['a' => []])->assertRedirect(); // HTML form goes back with errors
        $this->assertSame(0, DB::table('srv_responses')->count());
    }

    public function test_email_mode_needs_verification_link_and_blocks_duplicates(): void
    {
        Mail::fake();
        $s = $this->make('name_email', ['certificate' => $this->cert()]);
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'ก', 'email' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมชาย ใจดี', 'email' => ' Somchai@Example.org '])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('certificate_no', null);
        $this->assertSame(0, DB::table('srv_responses')->count(), 'not counted before verification');
        Mail::assertSent(SurveyVerifyMail::class, 1);
        $link = null;
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$link) { $link = $m->url; return $m->hasTo('somchai@example.org'); });
        $this->assertStringContainsString('signature=', $link);
        $this->assertStringContainsString('expires=', $link);
        // email is not stored anywhere
        $this->assertFalse(str_contains(json_encode(DB::table('srv_pending')->get()), 'omchai@'));
        // tampered link is rejected; real one counts once
        $this->get($link.'x')->assertForbidden();
        $this->post($link.'x')->assertForbidden();
        $this->assertSame(0, DB::table('srv_responses')->count());
        // a mail scanner / repeated GET only sees the button and never uses the link
        $this->get($link)->assertOk()->assertSee('ยืนยันคำตอบของฉัน')->assertDontSee('ขอบคุณ');
        $this->get($link)->assertOk()->assertSee('ยืนยันคำตอบของฉัน');
        $this->assertSame(0, DB::table('srv_responses')->count());
        $done = $this->post($link)->assertOk()->assertSee('ขอบคุณ')->assertSee('-'.SurveyService::buddhistYear().'-000001')->assertSee('?k=', false);
        $row = DB::table('srv_responses')->first();
        $this->assertSame('สมชาย ใจดี', $row->display_name);
        $this->assertSame(100, (int) $row->score);
        // double click / second tab: same result page and same certificate, still exactly one response
        $this->post($link)->assertOk()->assertSee($row->certificate_no);
        $this->get($link)->assertOk()->assertSee($row->certificate_no)->assertSee('ยืนยันไว้แล้ว');
        $this->assertSame(1, DB::table('srv_responses')->count());
        $this->assertSame($row->id, (int) DB::table('srv_pending')->value('response_id'));
        // same mailbox (different case/spaces) cannot answer again
        // ... and the reply is the same "pending" as for a fresh address (no probing of who took part), with no new mail
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมชาย', 'email' => 'SOMCHAI@example.org'])->assertCreated()->assertJsonPath('status', 'pending');
        Mail::assertSent(SurveyVerifyMail::class, 1);
        $this->assertSame(1, DB::table('srv_responses')->count());
    }

    public function test_email_identity_strips_plus_tags_and_dots_for_gmail_only(): void
    {
        $id = fn (string $e) => SurveyService::emailIdentity($e);
        $this->assertSame($id('a@gmail.com'), $id('a+1@gmail.com'));
        $this->assertSame($id('ab@gmail.com'), $id('a.b@gmail.com'));
        $this->assertSame($id('ab@gmail.com'), $id('A.B+x@googlemail.com'));
        $this->assertNotSame($id('a@school.ac.th'), $id('a+news@school.ac.th'));   // plus-tag: gmail only
        $this->assertNotSame($id('a.b@school.ac.th'), $id('ab@school.ac.th'));     // dots: gmail only
        $this->assertSame('+x@gmail.com', $id('+x@gmail.com'));                     // a leading + is not a tag

        Mail::fake();
        $s = $this->make('name_email', ['certificate' => $this->cert()]);
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมชาย ใจดี', 'email' => 'som.chai@gmail.com'])->assertCreated();
        $link = null;
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$link) { $link = $m->url; return $m->hasTo('som.chai@gmail.com'); });
        $this->post($link)->assertOk();
        // same mailbox with another dot/tag: no second mail, no second response
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมชาย ใจดี', 'email' => 'somchai+2@gmail.com'])->assertCreated()->assertJsonPath('status', 'pending');
        Mail::assertSent(SurveyVerifyMail::class, 1);
        $this->assertSame(1, DB::table('srv_responses')->count());
    }

        public function test_roster_import_refuses_guessable_codes(): void
    {
        $s = $this->make('roster_code', [], false);
        $weak = $this->postJson('/api/surveys/'.$s['id'].'/roster', ['text' => "12345,สมชาย ใจดี\nK7QXM2PA9,สมหญิง รักเรียน\nAB-12,สมศรี"])->assertStatus(422);
        $weak->assertJsonPath('weak_codes', 2)->assertJsonPath('weak_lines', [1, 3]);
        $this->assertSame(0, DB::table('srv_roster')->where('survey_id', $s['id'])->count()); // nothing imported
        $s2 = $this->make('roster_code', [], false);
        $this->postJson('/api/surveys/'.$s2['id'].'/roster', ['text' => 'K7QXM2PA9,สมชาย ใจดี'])->assertOk()->assertJsonMissingPath('weak_codes');
    }

    public function test_wrong_codes_lock_the_survey_and_admin_sees_it(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert()], false);
        $this->roster($s, "GOODCODE-01,สมชาย ใจดี\nGOODCODE-02,สมหญิง รักเรียน");
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk();
        $row = DB::table('srv_surveys')->find($s['id']);
        $svc = $this->service();
        // guessing from many networks and sessions: every per-network limiter stays below its ceiling, only the survey-wide one trips
        for ($i = 0; $i < SurveyService::ROSTER_WRONG_MIN; $i++) {
            try { $svc->submit($row, ['a' => $this->answers(), 'code' => 'GUESS-'.$i], '10.9.'.intdiv($i, 250).'.'.($i % 250), null, 'sess'.$i); } catch (\Illuminate\Validation\ValidationException) {}
        }
        try { $svc->submit($row, ['a' => $this->answers(), 'code' => 'GOODCODE-01'], '10.8.0.1', null, 'fresh'); $this->fail('survey not locked'); }
        catch (SurveyRejected $e) { $this->assertSame('code_locked', $e->reason); $this->assertSame(429, $e->status); }
        $this->assertSame(0, DB::table('srv_responses')->where('survey_id', $s['id'])->count());
        $this->getJson('/api/surveys/'.$s['id'].'/roster')->assertJsonPath('code_locked', true);
        \Illuminate\Support\Facades\Cache::forget('srv-code-lock:'.$s['id']);
        $this->assertIsArray($svc->submit($row, ['a' => $this->answers(), 'code' => 'GOODCODE-01'], '10.8.0.1', null, 'fresh'));
    }

    public function test_one_network_alone_cannot_lock_code_entry_for_everybody(): void
    {
        $s = $this->make('roster_code', [], false);
        $this->roster($s, "GOODCODE-01,สมชาย ใจดี\nGOODCODE-02,สมหญิง รักเรียน");
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk();
        $row = DB::table('srv_surveys')->find($s['id']);
        $svc = $this->service();
        $this->assertLessThan(SurveyService::ROSTER_WRONG_MIN / 2, SurveyService::ROSTER_PER_WIDE_HOUR);
        // one address, a new browser session every time, pausing so that the 10-minute ceilings start again: more guesses than the survey-wide lock needs
        $refused = 0;
        for ($i = 0; $i < SurveyService::ROSTER_WRONG_MIN + 100; $i++) {
            if ($i > 0 && $i % 150 === 0) $this->travel(11)->minutes();
            try { $svc->submit($row, ['a' => $this->answers(), 'code' => 'GUESS-'.$i], '203.0.113.9', null, 'sess'.$i); }
            catch (\Illuminate\Validation\ValidationException) {}
            catch (SurveyRejected $e) { $this->assertSame('code_limit', $e->reason); $refused++; }
        }
        $this->assertSame(SurveyService::ROSTER_WRONG_MIN + 100 - SurveyService::ROSTER_PER_WIDE_HOUR, $refused); // stopped by its own hourly share
        $this->assertFalse(SurveyService::codeLocked((int) $s['id']));
        // everybody else still gets in
        $this->assertSame('done', $svc->submit($row, ['a' => $this->answers(), 'code' => 'GOODCODE-01'], '10.8.0.1', null, 'fresh')['status']);
    }

    public function test_verification_mail_of_a_direct_submission_leaves_after_the_response_like_a_duplicate(): void
    {
        Mail::fake();
        $s = $this->make('name_email', [], false);
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk();
        $row = DB::table('srv_surveys')->find($s['id']);
        $svc = $this->service();
        // the answer is built without talking to the mail server (a duplicate address returns the very same way) ...
        $this->assertSame('pending', $svc->submit($row, ['a' => $this->answers(), 'name' => 'สมชาย ใจดี', 'email' => 'later@example.org'], '10.0.0.9', null)['status']);
        Mail::assertNothingSent();
        $this->assertSame(1, DB::table('srv_pending')->where('survey_id', $s['id'])->count());
        // ... and the mail is still sent, once, when the request ends
        $this->app->terminate();
        Mail::assertSent(SurveyVerifyMail::class, fn ($m) => $m->hasTo('later@example.org'));
        $this->app->terminate();
        Mail::assertSent(SurveyVerifyMail::class, 1);
        // an envelope from the public side keeps the synchronous send: its handler needs the failure to retry
        $svc->submit($row, ['a' => $this->answers(), 'name' => 'สมหญิง รักเรียน', 'email' => 'now@example.org'], '10.0.0.10', null, '', false);
        Mail::assertSent(SurveyVerifyMail::class, 2);
    }

    public function test_a_mail_failure_after_the_response_looks_like_a_sent_mail_and_drops_the_pending_row(): void
    {
        $s = $this->make('name_email');
        $this->guest();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers(), 'name' => 'สมชาย ใจดี', 'email' => 'down@example.org'])->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertSame(0, DB::table('srv_pending')->where('survey_id', $s['id'])->count());
    }

    public function test_one_network_cannot_use_the_whole_host_mail_budget_and_ip_hash_is_per_survey_and_wiped_on_close(): void
    {
        Mail::fake();
        $s = $this->make('name_email', [], false);
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk();
        $row = DB::table('srv_surveys')->find($s['id']);
        $svc = $this->service();
        $share = intdiv(SurveyService::MAIL_PER_HOUR_ALL, SurveyService::MAIL_HOUR_NET_SHARE);
        $this->assertLessThan(SurveyService::MAIL_PER_HOUR_ALL, $share);
        $hit = '2001:db8:77:'.dechex(1).'::1';
        \Illuminate\Support\Facades\RateLimiter::clear('srv-mail-hour-net:'.$svc->wideHash($hit));
        for ($i = 0; $i < $share; $i++) \Illuminate\Support\Facades\RateLimiter::hit('srv-mail-hour-net:'.$svc->wideHash($hit), 3600);
        // another /64 of the same /48 is stopped by the shared network share, a different /48 is not
        try { $svc->submit($row, ['a' => $this->answers(), 'name' => 'สมชาย ใจดี', 'email' => 'net1@example.org'], '2001:db8:77:9::5', null); $this->fail('network share not enforced'); }
        catch (SurveyRejected $e) { $this->assertSame('mail_ip_limit', $e->reason); }
        $this->assertSame('pending', $svc->submit($row, ['a' => $this->answers(), 'name' => 'สมชาย ใจดี', 'email' => 'net2@example.org'], '2001:db8:88::5', null)['status']);
        // ip_hash differs per survey for the same client and is wiped when the survey closes
        $this->assertNotSame($svc->ipHash('203.0.113.7', 1), $svc->ipHash('203.0.113.7', 2));
        $svc->finalize($row, hash('sha256', 'kk'), null, [], $svc->ipHash('203.0.113.7', (int) $row->id));
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => DB::table('srv_surveys')->find($s['id'])->version, 'action' => 'close'])->assertOk();
        $this->assertSame(0, DB::table('srv_responses')->where('survey_id', $s['id'])->where('ip_hash', '!=', '')->count());
    }
public function test_resubmit_replaces_nonce_and_the_old_mailed_link_stops_working(): void
    {
        Mail::fake();
        $s = $this->make('name_email', ['certificate' => $this->cert()]);
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'เหยื่อ จริง', 'email' => 'victim@example.org'])->assertCreated();
        $links = [];
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$links) { $links[] = $m->url; return true; });
        $old = $links[0];
        $this->postJson($url, ['a' => $this->answers(['q3' => 1]), 'name' => 'ผู้โจมตี เลือกเอง', 'email' => 'victim@example.org'])->assertCreated();
        $links = [];
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$links) { $links[] = $m->url; return true; });
        $this->assertCount(2, $links);
        $this->assertNotSame($links[0], $links[1]);
        $this->get($old)->assertStatus(410)->assertSee('หากท่านไม่ได้ส่งใหม่เอง')->assertDontSee('ใช้ลิงก์จากอีเมลฉบับล่าสุด');
        $this->post($old)->assertStatus(410);
        $this->assertSame(0, DB::table('srv_responses')->count());
        $this->get($links[1])->assertOk()->assertSee('ยืนยันคำตอบของฉัน')->assertSee('ผู้โจมตี เลือกเอง')->assertSee('ชื่อที่จะปรากฏบนเกียรติบัตร');
        $this->post($links[1])->assertOk();
        $this->assertSame('ผู้โจมตี เลือกเอง', DB::table('srv_responses')->value('display_name')); // the newest content is what the newest link confirms
        // a link with the nonce removed or altered (still validly signed only with the original query) cannot be forged
        $this->get(preg_replace('/([?&])n=[a-f0-9]+/', '$1n=deadbeef', $links[1]))->assertForbidden();
    }

    public function test_mail_ceilings_are_below_host_quota_and_ipv6_shares_one_budget(): void
    {
        $this->assertLessThanOrEqual(30, SurveyService::MAIL_PER_SURVEY_MINUTE);
        $svc = $this->service();
        $this->assertSame($svc->ipHash('2001:db8:1:2:aaaa:bbbb:cccc:1'), $svc->ipHash('2001:db8:1:2:1111:2222:3333:4444'));
        $this->assertNotSame($svc->ipHash('2001:db8:1:3::1'), $svc->ipHash('2001:db8:1:2::1'));
        $this->assertSame($svc->ipHash('203.0.113.7'), $svc->ipHash('::ffff:203.0.113.7'));
        $this->assertNotSame($svc->ipHash('203.0.113.7'), $svc->ipHash('203.0.113.8'));

        // whole-system hourly cap across surveys
        Mail::fake();
        config(['saan.mail.max_per_hour' => 3]);
        RateLimiter::clear('srv-mail-hour-all');
        $s = $this->make('name_email');
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        for ($i = 0; $i < 3; $i++) $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมมติ '.$i, 'email' => 'cap'.$i.'@example.org'])->assertCreated();
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมมติ เกิน', 'email' => 'capx@example.org'])->assertStatus(429)->assertJsonPath('reason', 'mail_hour_limit');
        Mail::assertSent(SurveyVerifyMail::class, 3);
    }

    public function test_email_link_expires_after_24_hours(): void
    {
        Mail::fake();
        $s = $this->make('name_email');
        $this->guest();
        $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers(), 'name' => 'สมหญิง', 'email' => 'somying@example.org'])->assertCreated();
        $link = null;
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$link) { $link = $m->url; return true; });
        $this->travel(25)->hours();
        $this->get($link)->assertForbidden();
        $this->assertSame(0, DB::table('srv_responses')->count());
    }

    public function test_email_resubmit_before_verification_replaces_pending_and_mail_rate_limit(): void
    {
        Mail::fake();
        $s = $this->make('name_email');
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $body = ['a' => $this->answers(), 'name' => 'สมศรี', 'email' => 'somsri@example.org'];
        for ($i = 0; $i < 3; $i++) $this->postJson($url, $body)->assertCreated();
        $this->assertSame(1, DB::table('srv_pending')->count());
        $this->postJson($url, $body)->assertStatus(429);
        Mail::assertSent(SurveyVerifyMail::class, 3);
    }

    public function test_email_pending_race_does_not_500_and_ip_mail_ceiling(): void
    {
        Mail::fake();
        $s = $this->make('name_email');
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        // a pending row for the same mailbox already exists (double click / second tab): upsert, no unique violation, no 500
        $key = $this->service()->key($s['id'], 'name_email', 'race@example.org');
        DB::table('srv_pending')->insert(['survey_id' => $s['id'], 'respondent_key' => $key, 'display_name' => 'เก่า', 'answers' => '{}', 'expires_at' => now()->addHour(), 'created_at' => now()]);
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมคิด', 'email' => 'race@example.org'])->assertCreated();
        $this->assertSame(1, DB::table('srv_pending')->count());
        $this->assertSame('สมคิด', DB::table('srv_pending')->value('display_name'));
        // one network cannot make the school SMTP mail random addresses: 20 per hour per survey and IP
        for ($i = 2; $i <= SurveyService::MAIL_PER_IP_HOUR; $i++) $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมมติ '.$i, 'email' => 'u'.$i.'@example.org'])->assertCreated();
        $this->postJson($url, ['a' => $this->answers(), 'name' => 'สมมติ เกิน', 'email' => 'over@example.org'])->assertStatus(429)->assertJsonPath('reason', 'mail_ip_limit');
        Mail::assertSent(SurveyVerifyMail::class, SurveyService::MAIL_PER_IP_HOUR);
    }

    public function test_link_for_survey_closed_or_changed_after_submit_counts_nothing(): void
    {
        Mail::fake();
        $s = $this->make('name_email');
        $this->guest();
        $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers(), 'name' => 'สมปอง', 'email' => 'sompong@example.org'])->assertCreated();
        $link = null;
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$link) { $link = $m->url; return true; });
        // admin cannot change structure or identity while a link is waiting
        $this->admin();
        $cur = $this->getJson('/api/surveys/'.$s['id'])->assertOk()->json();
        $this->assertTrue($cur['locked']);
        $this->assertSame(0, $cur['responses']);
        $q = self::QUESTIONS; $q[0]['options'] = ['พอใจ', 'ไม่พอใจ', 'กลาง'];
        $this->putJson('/api/surveys/'.$s['id'], ['version' => $cur['version'], 'title' => 'x', 'identity_mode' => 'name_email', 'questions' => $q])->assertUnprocessable();
        $this->putJson('/api/surveys/'.$s['id'], ['version' => $cur['version'], 'title' => 'x', 'identity_mode' => 'anonymous', 'questions' => self::QUESTIONS])->assertUnprocessable();
        // closed between submit and confirm
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $cur['version'], 'action' => 'close'])->assertOk();
        $this->guest();
        $this->get($link)->assertStatus(410);
        $this->post($link)->assertStatus(410);
        $this->assertSame(0, DB::table('srv_responses')->count());
    }

    public function test_confirm_revalidates_against_current_structure_and_mode(): void
    {
        Mail::fake();
        $s = $this->make('name_email');
        $this->guest();
        $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers(), 'name' => 'สมหมาย', 'email' => 'sommai@example.org'])->assertCreated();
        $link = null;
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$link) { $link = $m->url; return true; });
        // simulate a change that bypassed the admin lock: structure and identity mode
        DB::table('srv_surveys')->where('id', $s['id'])->update(['questions' => json_encode([['key' => 'q1', 'type' => 'single', 'label' => 'x', 'options' => ['ใช่', 'ไม่'], 'required' => true]])]);
        $this->post($link)->assertStatus(410);
        DB::table('srv_surveys')->where('id', $s['id'])->update(['questions' => json_encode(self::QUESTIONS), 'identity_mode' => 'anonymous']);
        $this->post($link)->assertStatus(410);
        $this->assertSame(0, DB::table('srv_responses')->count());
    }

    public function test_dates_beyond_2038_are_stored_and_year_2100_is_refused(): void
    {
        $s = $this->make('anonymous', ['closes_at' => '2099-12-31T23:00'], false);
        $this->assertSame('2099-12-31T23:00', $s['closes_at']);
        $this->assertSame('datetime', strtolower(\Illuminate\Support\Facades\Schema::getColumnType('srv_surveys', 'closes_at')));
        $this->putJson('/api/surveys/'.$s['id'], ['version' => $s['version'], 'title' => 'x', 'identity_mode' => 'anonymous', 'questions' => self::QUESTIONS, 'closes_at' => '2100-01-01T00:00'])->assertUnprocessable()->assertJsonValidationErrors(['closes_at']);
        $this->putJson('/api/surveys/'.$s['id'], ['version' => $s['version'], 'title' => 'x', 'identity_mode' => 'anonymous', 'questions' => self::QUESTIONS, 'opens_at' => '9999-01-01T00:00'])->assertUnprocessable()->assertJsonValidationErrors(['opens_at']);
    }

    public function test_answer_key_validation_zero_option_and_nested_arrays(): void
    {
        $s = $this->postJson('/api/surveys', ['title' => 'เฉลย'])->assertCreated()->json();
        $base = ['version' => 1, 'title' => 'เฉลย', 'identity_mode' => 'anonymous'];
        $mk = fn ($correct) => ['questions' => [['key' => 'q1', 'type' => 'single', 'label' => 'x', 'options' => ['0', '1'], 'correct' => $correct]]];
        $this->putJson('/api/surveys/'.$s['id'], $base + $mk([['x']]))->assertUnprocessable();            // nested array: 422, not ErrorException 500
        $this->putJson('/api/surveys/'.$s['id'], $base + $mk([['x'], 'y']))->assertUnprocessable();
        $this->putJson('/api/surveys/'.$s['id'], $base + $mk('2'))->assertUnprocessable();                // not an option
        $saved = $this->putJson('/api/surveys/'.$s['id'], $base + $mk('0'))->assertOk()->json();         // "0" is a real answer key
        $this->assertSame('0', $saved['questions'][0]['correct']);
        $svc = $this->service();
        $this->assertTrue(SurveyService::hasCorrect(['correct' => '0']));
        $this->assertFalse(SurveyService::hasCorrect(['correct' => '']));
        $this->assertSame(100, $svc->score($saved['questions'], ['q1' => '0']));
        $this->assertSame(0, $svc->score($saved['questions'], ['q1' => '1']));
        // min_score is allowed because "0" counts as an answer key
        $this->putJson('/api/surveys/'.$s['id'], ['version' => $saved['version'], 'title' => 'เฉลย', 'identity_mode' => 'name_email', 'questions' => $saved['questions'], 'certificate' => $this->cert(['min_score' => 50])])->assertOk();
    }

    public function test_certificate_snapshot_survives_later_edits_and_disabling(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert(['title' => 'เกียรติบัตรเดิม', 'signer_name' => 'ผู้ลงนามเดิม'])], false);
        $this->roster($s, 'SNCODE-001,สมศรี ใจดี');
        $s = $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk()->json();
        $this->guest();
        $no = $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers(), 'code' => 'SNCODE-001'])->assertCreated()->json('certificate_no');
        $k = '?k='.$this->service()->certKey($no);
        $this->get('/portal/certificates/'.$no.$k)->assertOk()->assertSee('เกียรติบัตรเดิม')->assertSee('ผู้ลงนามเดิม');
        // admin renames the survey, rewrites and then disables the certificate
        $this->admin();
        $cur = $this->getJson('/api/surveys/'.$s['id'])->json();
        $edit = ['version' => $cur['version'], 'title' => 'ชื่อใหม่ทั้งหมด', 'identity_mode' => 'roster_code', 'questions' => $cur['questions'],
            'certificate' => $this->cert(['title' => 'หัวใหม่', 'signer_name' => 'คนใหม่', 'body_template' => 'ข้อความใหม่ {name}'])];
        $cur = $this->putJson('/api/surveys/'.$s['id'], $edit)->assertOk()->json();
        $this->putJson('/api/surveys/'.$s['id'], ['version' => $cur['version'], 'certificate' => null] + $edit)->assertOk();
        $this->guest();
        $this->get('/portal/certificates/'.$no.$k)->assertOk()->assertSee('เกียรติบัตรเดิม')->assertSee('ผู้ลงนามเดิม')->assertSee('ขอมอบให้ สมศรี ใจดี ตอบ ประเมินกิจกรรม', false)
            ->assertDontSee('หัวใหม่')->assertDontSee('คนใหม่')->assertDontSee('ชื่อใหม่ทั้งหมด');
        $this->get('/portal/certificates/verify?no='.$no)->assertOk()->assertSee('ถูกต้อง')->assertDontSee('ประเมินกิจกรรม')->assertDontSee('สมศรี');
        $this->get('/portal/certificates/verify?no='.$no.'&'.ltrim($k, '?'))->assertOk()->assertSee('ถูกต้อง')->assertSee('ประเมินกิจกรรม');
        $snap = json_decode((string) DB::table('srv_responses')->where('certificate_no', $no)->value('certificate_snapshot'), true);
        $this->assertSame(['title', 'body', 'signer_name', 'signer_title', 'survey_title', 'school_name', 'date'], array_keys($snap));
    }

    public function test_certificate_page_needs_token_and_verify_masks_name_without_it(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert()], false);
        $this->roster($s, 'TKCODE-001,สมชาย ใจดี');
        $s = $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk()->json();
        $this->guest();
        $r = $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers(), 'code' => 'TKCODE-001'])->assertCreated()->json();
        $no = $r['certificate_no'];
        $this->assertStringContainsString('?k='.$this->service()->certKey($no), $r['certificate_url']);
        // sequential number alone is not enough
        $this->get('/portal/certificates/'.$no)->assertNotFound();
        $this->get('/portal/certificates/'.$no.'?k=')->assertNotFound();
        $this->get('/portal/certificates/'.$no.'?k=0000000000')->assertNotFound();
        $this->get($r['certificate_url'])->assertOk()->assertSee('สมชาย ใจดี')->assertSee('&amp;k='.$this->service()->certKey($no), false);
        // without the key: valid / invalid and the issue date only, no name (not even masked), so numbers cannot be walked for names
        $this->get('/portal/certificates/verify?no='.$no)->assertOk()->assertSee('ถูกต้อง')->assertDontSee('สมชาย')->assertDontSee('ผู้รับ:');
        $this->get('/portal/certificates/verify?no='.$no.'&k=0000000000')->assertOk()->assertDontSee('สมชาย');
        $this->get('/portal/certificates/verify?no='.$no.'&k='.$this->service()->certKey($no))->assertOk()->assertSee('สมชาย ใจดี');
        // key-less lookups have a tight per-network budget (10 per minute), lookups with the key do not
        \Illuminate\Support\Facades\RateLimiter::clear('cert-verify-nokey:127.0.0.1');
        for ($i = 0; $i < \Saan\Modules\Surveys\Http\SurveyPublicController::VERIFY_NOKEY_PER_MINUTE; $i++) $this->get('/portal/certificates/verify?no=X-2569-'.sprintf('%06d', $i + 900))->assertOk()->assertSee('ไม่ถูกต้อง');
        $this->get('/portal/certificates/verify?no='.$no)->assertStatus(429);
        $this->get('/portal/certificates/verify?no='.$no.'&k='.$this->service()->certKey($no))->assertOk()->assertSee('สมชาย ใจดี');
        \Illuminate\Support\Facades\RateLimiter::clear('cert-verify-nokey:127.0.0.1');
        $this->assertSame('สมชาย ใ.', SurveyService::maskName('สมชาย ใจดี'));
        $this->assertSame('ส...', SurveyService::maskName('สมชาย'));
    }

    public function test_roster_duplicate_returns_existing_certificate_and_limits_are_per_session(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert()], false);
        $this->roster($s, "DPCODE-001,สมชาย ใจดี\nDPCODE-002,สมหญิง รักเรียน");
        $s = $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk()->json();
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $first = $this->postJson($url, ['a' => $this->answers(), 'code' => 'DPCODE-001'])->assertCreated()->json();
        // a used code gets a plain 409: NEVER the certificate number or link (codes are guessable, e.g. student numbers)
        $again = $this->postJson($url, ['a' => $this->answers(), 'code' => 'dpCODE-001'])->assertStatus(409)->assertJsonPath('reason', 'duplicate');
        $this->assertArrayNotHasKey('certificate_no', $again->json());
        $this->assertArrayNotHasKey('certificate_url', $again->json());
        $this->assertStringNotContainsString($first['certificate_no'], $again->getContent());
        $page = $this->post($url, ['a' => $this->answers(), 'code' => 'DPCODE-001'])->assertStatus(409);
        $this->assertStringNotContainsString($first['certificate_no'], $page->getContent());
        $this->assertStringNotContainsString('?k=', $page->getContent());
        $this->assertSame(1, DB::table('srv_responses')->count());
        // used codes count against the limiter like unknown ones (no free probing)
        $this->assertSame(2, (int) \Illuminate\Support\Facades\RateLimiter::attempts('srv-code-ip:'.$s['id'].':'.$this->service()->ipHash('127.0.0.1', (int) $s['id'])));
        \Illuminate\Support\Facades\RateLimiter::clear('srv-code-ip:'.$s['id'].':'.$this->service()->ipHash('127.0.0.1', (int) $s['id']));

        // wrong codes are counted per session: one student's typos do not lock the lab
        $row = DB::table('srv_surveys')->find($s['id']);
        $svc = $this->service();
        $try = function (string $code, string $session, ?object $survey = null) use ($svc, $row) {
            try { return $svc->submit($survey ?? $row, ['a' => $this->answers(), 'code' => $code], '10.1.1.1', null, $session); }
            catch (\Illuminate\Validation\ValidationException) { return 'wrong'; }
            catch (SurveyRejected $e) { return $e->status; }
        };
        for ($i = 0; $i < SurveyService::ROSTER_PER_SESSION; $i++) $this->assertSame('wrong', $try('BAD-'.$i, 'S1'));
        $this->assertSame(429, $try('DPCODE-002', 'S1'));
        $this->assertIsArray($try('DPCODE-002', 'S2'));                                   // another student on the same IP still gets in
        // per-network ceiling still stops a script that rotates sessions
        $this->admin();
        $s2 = $this->make('roster_code', [], false);
        $this->roster($s2, 'ZZCODE-001,x');
        $s2 = $this->postJson('/api/surveys/'.$s2['id'].'/status', ['version' => $s2['version'], 'action' => 'open'])->assertOk()->json();
        $row = DB::table('srv_surveys')->find($s2['id']);
        for ($i = 0; $i < SurveyService::ROSTER_PER_IP; $i++) $this->assertSame('wrong', $try('NOPE-'.$i, 'R'.$i, $row));
        $this->assertSame(429, $try('ZZCODE-001', 'R-new', $row));
    }

    public function test_deleting_a_survey_with_responses_is_refused_by_the_database_too(): void
    {
        $s = $this->make('anonymous');
        $this->guest();
        $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers()])->assertCreated();
        // restrict FK: a response that slipped in after the admin check cannot be cascaded away
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('srv_surveys')->where('id', $s['id'])->delete();
    }

    public function test_certificate_number_first_of_year_is_race_safe(): void
    {
        $svc = $this->service();
        DB::transaction(fn () => $this->assertSame('1-'.SurveyService::buddhistYear().'-000001', $svc->nextCertificateNo()));
        DB::transaction(fn () => $this->assertSame('1-'.SurveyService::buddhistYear().'-000002', $svc->nextCertificateNo()));
        $this->assertSame(1, DB::table('srv_counters')->count());
    }

    public function test_roster_code_is_single_use_and_name_comes_from_roster(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert()], false);
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertUnprocessable(); // empty roster
        $res = $this->postJson('/api/surveys/'.$s['id'].'/roster', ['text' => "abCODE-001, สมชาย ใจดี\nABCODE-002\tสมหญิง รักเรียน\nABCODE-001 ,ซ้ำ\nx,สั้น\nABCODE-003;สมศักดิ์\n\nไม่มีชื่อ,"])->assertOk()->json();
        $this->assertSame(3, $res['added']);
        $this->assertCount(2, $res['invalid_lines']);
        // re-import is idempotent
        $this->assertSame(0, $this->postJson('/api/surveys/'.$s['id'].'/roster', ['text' => 'ABCODE-001,สมชาย'])->json('added'));
        $this->getJson('/api/surveys/'.$s['id'].'/roster')->assertJson(['total' => 3, 'used' => 0]);
        $this->assertSame(0, DB::table('srv_roster')->where('code_hash', 'ABCODE-001')->count()); // stored hashed only
        $open = $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk();
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $this->postJson($url, ['a' => $this->answers(), 'code' => 'zzCODE-999'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $ok = $this->postJson($url, ['a' => $this->answers(), 'code' => ' abCODE-001 '])->assertCreated()->json();
        $this->assertNotNull($ok['certificate_no']);
        $this->assertSame('สมชาย ใจดี', DB::table('srv_responses')->first()->display_name);
        $this->postJson($url, ['a' => $this->answers(), 'code' => 'ABCODE-001'])->assertStatus(409);
        $this->assertSame(1, DB::table('srv_roster')->whereNotNull('used_at')->count());
        // a rejected answer set must not burn the code
        $this->postJson($url, ['a' => [], 'code' => 'ABCODE-002'])->assertUnprocessable();
        $this->assertSame(1, DB::table('srv_roster')->whereNotNull('used_at')->count());
        // brute force of codes is limited (per-session / per-network ceilings are exercised in the dedicated test)
        $this->assertSame(1, DB::table('srv_responses')->count());
        // roster import is refused for other modes
        $this->admin();
        $other = $this->make('anonymous');
        $this->postJson('/api/surveys/'.$other['id'].'/roster', ['text' => 'ABCODE-009,x'])->assertUnprocessable();
    }

    public function test_closed_expired_not_open_draft_and_full_surveys(): void
    {
        $draft = $this->make('anonymous', [], false);
        $closed = $this->make('anonymous');
        $this->postJson('/api/surveys/'.$closed['id'].'/status', ['version' => $closed['version'], 'action' => 'close'])->assertOk();
        $expired = $this->make('anonymous', ['opens_at' => '2020-01-01T08:00', 'closes_at' => '2020-02-01T08:00']);
        $future = $this->make('anonymous', ['opens_at' => '2099-01-01T08:00']);
        $full = $this->make('anonymous', ['max_responses' => 2]);
        $this->guest();
        $this->get('/portal/surveys/'.$draft['slug'])->assertNotFound();
        $this->postJson('/portal/surveys/'.$draft['slug'].'/submit', ['a' => $this->answers()])->assertNotFound();
        foreach ([[$closed, 410, 'ปิดรับ'], [$expired, 410, 'หมดเวลา'], [$future, 403, 'ยังไม่ถึงเวลา']] as [$s, $code, $text]) {
            $this->get('/portal/surveys/'.$s['slug'])->assertStatus($code)->assertSee($text)->assertDontSee('name="a[q1]"', false);
            $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers()])->assertStatus($code);
        }
        $this->assertSame(0, DB::table('srv_responses')->count());
        $svc = $this->service();
        $fullRow = DB::table('srv_surveys')->find($full['id']);
        $svc->finalize($fullRow, hash('sha256', '1'), null, [], 'h');
        $svc->finalize($fullRow, hash('sha256', '2'), null, [], 'h');
        $this->postJson('/portal/surveys/'.$full['slug'].'/submit', ['a' => $this->answers()])->assertStatus(410)->assertJsonPath('reason', 'full');
        $this->get('/portal/surveys/'.$full['slug'])->assertStatus(410);
        $this->assertSame(2, DB::table('srv_responses')->where('survey_id', $full['id'])->count());
    }

    public function test_certificate_numbers_unique_under_200_sequential_submissions_and_verify_page(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $s = $this->make('roster_code', ['certificate' => $this->cert()], false);
        $lines = [];
        for ($i = 1; $i <= 200; $i++) $lines[] = sprintf('CERT%05d,ผู้เข้าร่วมสมมติ %d', $i, $i);
        $this->roster($s, implode("\n", $lines));
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk();
        $this->guest();
        $numbers = [];
        for ($i = 1; $i <= 200; $i++) {
            $numbers[] = $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers(), 'code' => sprintf('cert%05d', $i)])->assertCreated()->json('certificate_no');
        }
        $this->assertCount(200, array_unique($numbers));
        $be = SurveyService::buddhistYear();
        $this->assertSame('1-'.$be.'-000001', $numbers[0]);
        $this->assertSame('1-'.$be.'-000200', $numbers[199]);
        foreach ($numbers as $n) $this->assertMatchesRegularExpression('/^1-'.$be.'-\d{6}$/', $n);
        $this->assertSame(200, DB::table('srv_responses')->whereNotNull('certificate_no')->distinct()->count('certificate_no'));

        // printable certificate page
        $k = fn (string $n) => '?k='.$this->service()->certKey($n);
        $this->get('/portal/certificates/'.$numbers[6])->assertNotFound();
        $page = $this->get('/portal/certificates/'.$numbers[6].$k($numbers[6]))->assertOk()->assertSee('ผู้เข้าร่วมสมมติ 7')->assertSee($numbers[6])->assertSee('@page', false)->assertSee('@media print', false)->assertSee('size:A4', false)->assertSee('ผอ. ตัวอย่าง');
        $page->assertSee('ขอมอบให้ ผู้เข้าร่วมสมมติ 7 ตอบ ประเมินกิจกรรม', false);
        $this->get('/portal/certificates/1-'.$be.'-999999'.$k('1-'.$be.'-999999'))->assertNotFound();
        $this->get('/portal/certificates/not-a-number')->assertNotFound();

        // verification page: valid shows only the name (no answers, no code, no ip)
        $ok = $this->get('/portal/certificates/verify?no='.strtolower($numbers[6]).'&k='.$this->service()->certKey($numbers[6]))->assertOk()->assertSee('ถูกต้อง')->assertSee('ผู้เข้าร่วมสมมติ 7');
        $ok->assertDontSee('C0007')->assertDontSee('ดี</', false);
        $this->get('/portal/certificates/verify?no=1-'.$be.'-999999')->assertOk()->assertSee('ไม่ถูกต้อง')->assertDontSee('ผู้เข้าร่วมสมมติ');
        $this->get('/portal/certificates/verify?no='.rawurlencode("x'; DROP TABLE srv_responses;--"))->assertOk()->assertSee('ไม่ถูกต้อง');
        $this->get('/portal/certificates/verify')->assertOk()->assertDontSee('ไม่ถูกต้อง');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('srv_responses'));
    }

    public function test_min_score_gates_certificate_and_html_in_names_is_escaped(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert(['min_score' => 100])], false);
        $this->roster($s, "PCODE-001,<b>แทรก</b> หนึ่ง\nPCODE-002,สอง ผ่าน");
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open']);
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $fail = $this->postJson($url, ['a' => $this->answers(['q1' => 'ไม่พอใจ']), 'code' => 'PCODE-001'])->assertCreated()->json();
        $this->assertNull($fail['certificate_no']);
        $this->assertSame(0, (int) DB::table('srv_responses')->first()->score);
        $pass = $this->postJson($url, ['a' => $this->answers(), 'code' => 'PCODE-002'])->assertCreated()->json();
        $this->assertNotNull($pass['certificate_no']);
        DB::table('srv_responses')->where('certificate_no', $pass['certificate_no'])->update(['display_name' => '<script>alert(1)</script>']);
        $this->get($pass['certificate_url'])->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_admin_can_erase_one_response_or_purge_a_closed_survey(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert()], false);
        $this->roster($s, "RCODE-001,สมชาย ใจดี\nRCODE-002,สมหญิง รักเรียน");
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open']);
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $this->postJson($url, ['code' => 'RCODE-001', 'a' => $this->answers()])->assertCreated();
        $this->postJson($url, ['code' => 'RCODE-002', 'a' => $this->answers()])->assertCreated();
        $this->admin();

        // the export carries ids only on request, as the last column
        $csv = $this->get('/api/surveys/'.$s['id'].'/export?with_ids=1')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
        $this->assertSame('รหัสคำตอบ', end($rows[0]));
        $rid = (int) end($rows[1]);
        $this->assertSame($rid, (int) DB::table('srv_responses')->where('survey_id', $s['id'])->orderBy('id')->value('id'));

        $this->deleteJson('/api/surveys/'.$s['id'].'/responses/'.$rid)->assertOk();
        $this->assertSame(1, DB::table('srv_responses')->where('survey_id', $s['id'])->count());
        $this->assertSame(1, DB::table('srv_roster')->where('survey_id', $s['id'])->count()); // that person's name is gone too
        $this->assertTrue(DB::table('audit_events')->where('action', 'survey.response_erased')->exists());
        $this->deleteJson('/api/surveys/'.$s['id'].'/responses/'.$rid)->assertNotFound();

        // purge: closed only, typed confirmation
        $title = DB::table('srv_surveys')->where('id', $s['id'])->value('title');
        DB::table('srv_pending')->insert(['survey_id' => $s['id'], 'respondent_key' => str_repeat('a', 64), 'display_name' => 'ค้าง', 'answers' => '{}', 'expires_at' => now()->subDay(), 'created_at' => now()]);
        $this->postJson('/api/surveys/'.$s['id'].'/purge', ['confirm' => 'ลบคำตอบ '.$title])->assertStatus(422); // still open
        $v = (int) DB::table('srv_surveys')->where('id', $s['id'])->value('version');
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $v, 'action' => 'close'])->assertOk();
        $this->postJson('/api/surveys/'.$s['id'].'/purge', ['confirm' => 'ผิด'])->assertStatus(422);
        $this->postJson('/api/surveys/'.$s['id'].'/purge', ['confirm' => 'ลบคำตอบ '.$title])->assertOk()->assertJsonPath('deleted', 1);
        foreach (['srv_responses', 'srv_pending', 'srv_roster'] as $t) $this->assertSame(0, DB::table($t)->where('survey_id', $s['id'])->count());
        $this->assertTrue(DB::table('audit_events')->where('action', 'survey.responses_purged')->exists());
        $this->deleteJson('/api/surveys/'.$s['id'], ['version' => (int) DB::table('srv_surveys')->where('id', $s['id'])->value('version')])->assertOk(); // now deletable
    }

    public function test_prune_command_removes_expired_pending_rows_of_closed_surveys(): void
    {
        $s = $this->make('name_email', [], true);
        DB::table('srv_surveys')->where('id', $s['id'])->update(['status' => 'closed']);
        DB::table('srv_pending')->insert([
            ['survey_id' => $s['id'], 'respondent_key' => str_repeat('a', 64), 'display_name' => 'หมดอายุ', 'answers' => '{}', 'expires_at' => now()->subHour(), 'created_at' => now()],
            ['survey_id' => $s['id'], 'respondent_key' => str_repeat('b', 64), 'display_name' => 'ยังไม่หมด', 'answers' => '{}', 'expires_at' => now()->addHour(), 'created_at' => now()],
        ]);
        $this->artisan('saan:surveys-prune')->assertSuccessful();
        $this->assertSame(['ยังไม่หมด'], DB::table('srv_pending')->pluck('display_name')->all());
    }

    public function test_expired_csrf_on_a_public_survey_goes_back_with_input_and_thai_message(): void
    {
        // the framework skips CSRF verification under test, so the exception is thrown by probe routes inside the web group
        \Illuminate\Support\Facades\Route::middleware('web')->post('/portal/surveys/probe-419', fn () => throw new \Illuminate\Session\TokenMismatchException('Page Expired'));
        \Illuminate\Support\Facades\Route::middleware('web')->post('/probe-419', fn () => throw new \Illuminate\Session\TokenMismatchException('Page Expired'));
        $this->guest();
        $res = $this->from('/portal/surveys/some-survey')->post('/portal/surveys/probe-419', ['name' => 'สมคิด', 'email' => 'a@example.org', 'code' => 'SECRET1', '_token' => 'expired']);
        $res->assertRedirect('/portal/surveys/some-survey')->assertSessionHasErrors('session')->assertSessionHasInput('name', 'สมคิด');
        $res->assertSessionMissing('_old_input._token');
        $res->assertSessionMissing('_old_input.code');
        $this->assertSame('หน้าเว็บหมดเวลา กรุณากดส่งอีกครั้ง', session('errors')->first('session'));
        // other routes get the Thai 419 page instead of the English one; JSON callers a Thai message
        $this->post('/probe-419')->assertStatus(419)->assertSee('หน้าเว็บหมดเวลา');
        $this->postJson('/probe-419')->assertStatus(419)->assertJsonPath('message', 'หน้าเว็บหมดเวลา กรุณากดส่งอีกครั้ง');
    }

    public function test_results_summary_and_csv_export(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert()], false);
        $this->roster($s, "RCODE-001,สมชาย ใจดี\nRCODE-002,สมหญิง รักเรียน\nRCODE-003,สมศักดิ์ มั่นคง");
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open']);
        $this->guest();
        $url = '/portal/surveys/'.$s['slug'].'/submit';
        $this->postJson($url, ['code' => 'RCODE-001', 'a' => $this->answers(['q3' => 5, 'q4' => '=HYPERLINK("x")'])])->assertCreated();
        $this->postJson($url, ['code' => 'RCODE-002', 'a' => $this->answers(['q1' => 'ไม่พอใจ', 'q2' => ['ข'], 'q3' => 2, 'q4' => ''])])->assertCreated();
        $this->admin();
        $r = $this->getJson('/api/surveys/'.$s['id'].'/results')->assertOk()->json();
        $this->assertSame(2, $r['total']);
        $this->assertSame(2, $r['certificates']);
        $this->assertEquals(50, $r['average_score']);
        $q1 = collect($r['questions'])->firstWhere('key', 'q1');
        $this->assertSame(['พอใจ' => 1, 'ไม่พอใจ' => 1], collect($q1['counts'])->pluck('count', 'label')->all());
        $q2 = collect($r['questions'])->firstWhere('key', 'q2');
        $this->assertSame(['ก' => 1, 'ข' => 1, 'ค' => 1], collect($q2['counts'])->pluck('count', 'label')->all());
        $q3 = collect($r['questions'])->firstWhere('key', 'q3');
        $this->assertEquals(3.5, $q3['average']);
        $this->assertSame(['=HYPERLINK("x")'], collect($r['questions'])->firstWhere('key', 'q4')['texts'] ?? []);

        $csv = $this->get('/api/surveys/'.$s['id'].'/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
        $this->assertCount(3, $rows);
        $this->assertSame(['ลำดับ', 'เวลาตอบ (เวลาไทย)', 'ชื่อ-สกุล', 'เลขที่เกียรติบัตร', 'คะแนน (ร้อยละ)'], array_slice($rows[0], 0, 5));
        $this->assertSame("'=ข้อเสนอแนะ", $rows[0][8]);                       // header formula guarded
        $this->assertSame('สมชาย ใจดี', $rows[1][2]);
        $this->assertSame("'=HYPERLINK(\"x\")", $rows[1][8]);                 // cell formula guarded
        $this->assertSame('ก | ค', $rows[1][6]);
        $this->assertStringNotContainsString('RCODE-001', $csv);                   // codes never exported
        $this->assertTrue(DB::table('audit_events')->where('action', 'survey.exported')->exists());

        // anonymous export has no name / certificate columns
        $a = $this->make('anonymous');
        $this->guest();
        $this->postJson('/portal/surveys/'.$a['slug'].'/submit', ['a' => $this->answers()])->assertCreated();
        $this->admin();
        $head = str_getcsv(explode("\n", substr($this->get('/api/surveys/'.$a['id'].'/export')->streamedContent(), 3))[0]);
        $this->assertNotContains('ชื่อ-สกุล', $head);
        $this->assertNotContains('เลขที่เกียรติบัตร', $head);
    }

    public function test_csv_of_name_email_mode_has_names_but_never_emails(): void
    {
        Mail::fake();
        $s = $this->make('name_email', ['certificate' => $this->cert()]);
        $this->guest();
        $this->postJson('/portal/surveys/'.$s['slug'].'/submit', ['a' => $this->answers(), 'name' => 'สมนึก ตัวอย่าง', 'email' => 'somnuk@example.org'])->assertCreated();
        $link = null;
        Mail::assertSent(SurveyVerifyMail::class, function ($m) use (&$link) { $link = $m->url; return true; });
        $this->post($link)->assertOk();
        $this->admin();
        $csv = $this->get('/api/surveys/'.$s['id'].'/export')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
        $this->assertContains('ชื่อ-สกุล', $rows[0]);
        $this->assertSame('สมนึก ตัวอย่าง', $rows[1][2]);
        $this->assertStringNotContainsString('somnuk', $csv);
    }

    public function test_other_school_data_is_invisible(): void
    {
        $now = now();
        $foreign = DB::table('srv_surveys')->insertGetId(['school_id' => 2, 'slug' => 'other-school', 'title' => 'โรงเรียนอื่น', 'questions' => json_encode(self::QUESTIONS), 'identity_mode' => 'anonymous', 'status' => 'open', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('srv_responses')->insert(['survey_id' => $foreign, 'respondent_key' => str_repeat('a', 64), 'display_name' => 'คนอื่น', 'answers' => '{}', 'certificate_no' => '9-2569-000001', 'created_at' => $now, 'ip_hash' => str_repeat('b', 64)]);
        $mine = $this->make('anonymous');
        $this->getJson('/api/surveys')->assertOk()->assertJsonCount(1, 'data');
        foreach (['', '/results', '/export', '/roster'] as $suffix) $this->get('/api/surveys/'.$foreign.$suffix)->assertNotFound();
        $this->putJson('/api/surveys/'.$foreign, ['version' => 1, 'title' => 'x', 'identity_mode' => 'anonymous', 'questions' => []])->assertNotFound();
        $this->postJson('/api/surveys/'.$foreign.'/status', ['version' => 1, 'action' => 'close'])->assertNotFound();
        $this->deleteJson('/api/surveys/'.$foreign, ['version' => 1])->assertNotFound();
        $this->postJson('/api/surveys/'.$foreign.'/roster', ['text' => 'ABCODE-001,x'])->assertNotFound();
        $this->guest();
        $this->get('/portal/surveys/other-school')->assertNotFound();
        $this->postJson('/portal/surveys/other-school/submit', ['a' => []])->assertNotFound();
        $this->get('/portal/certificates/9-2569-000001')->assertNotFound();
        $this->get('/portal/certificates/verify?no=9-2569-000001')->assertSee('ไม่ถูกต้อง');
        // the same slug may exist in another school: school 1 can create its own 'other-school'
        $this->admin();
        $this->postJson('/api/surveys', ['title' => 'ซ้ำได้', 'slug' => 'other-school'])->assertCreated();
        $this->assertSame(2, DB::table('srv_surveys')->where('school_id', 1)->count());
        $this->assertSame(2, DB::table('srv_surveys')->where('slug', 'other-school')->count());
    }

    public function test_5000_responses_keep_uniqueness_and_count(): void
    {
        $s = $this->make('roster_code', ['certificate' => $this->cert()], false);
        $this->roster($s, 'LONGCODE-1,x');
        $this->postJson('/api/surveys/'.$s['id'].'/status', ['version' => $s['version'], 'action' => 'open'])->assertOk();
        $row = DB::table('srv_surveys')->find($s['id']);
        $svc = $this->service();
        $ip = $svc->ipHash('10.0.0.1');
        $t = microtime(true);
        $numbers = [];
        for ($i = 1; $i <= 5000; $i++) {
            $numbers[] = $svc->finalize($row, $svc->key($row->id, 'roster_code', 'L'.$i), 'ผู้ตอบ '.$i, ['q1' => $i % 2 ? 'พอใจ' : 'ไม่พอใจ', 'q3' => ($i % 5) + 1], $ip)->certificate_no;
        }
        $this->assertSame(5000, DB::table('srv_responses')->where('survey_id', $row->id)->count());
        $this->assertCount(5000, array_unique($numbers));
        $this->assertSame(5000, DB::table('srv_responses')->where('survey_id', $row->id)->distinct()->count('respondent_key'));
        $this->assertSame(5000, (int) DB::table('srv_counters')->value('last'));
        // a repeat of any respondent is refused and the counter does not move
        foreach ([1, 2500, 5000] as $i) {
            try { $svc->finalize($row, $svc->key($row->id, 'roster_code', 'L'.$i), 'x', [], $ip); $this->fail('duplicate accepted'); }
            catch (SurveyRejected $e) { $this->assertSame(409, $e->status); }
        }
        $this->assertSame(5000, (int) DB::table('srv_counters')->value('last'));
        $this->assertSame(5000, DB::table('srv_responses')->where('survey_id', $row->id)->count());
        // the database server costs a round trip per statement (about 10 statements per response)
        $this->assertLessThan(60, microtime(true) - $t, 'load proof must stay under its limit');
        // summary and export stay correct at this size
        $this->assertSame(5000, $this->getJson('/api/surveys/'.$row->id.'/results')->json('total'));
        $this->assertSame(5001, count(array_filter(explode("\n", substr($this->get('/api/surveys/'.$row->id.'/export')->streamedContent(), 3)))));
    }
}
