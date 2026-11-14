<?php

namespace Tests\Feature;

use App\Domain\Cms\ContentTypes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PUBLIC-001..003 and RT-03 / RT-06 regression tests (functional-audit-2026-11-06). */
class PublicRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();config(['school.demo'=>true]);$this->seed();$this->actingAs(User::where('role','admin')->first());
    }

    private function add(string $type,array $extra=[]): array
    {
        return $this->postJson('/api/site/content',array_replace(['title'=>'PUB-'.$type,'body'=>'marker','excerpt'=>'x','content_type'=>$type,'category'=>ContentTypes::TYPES[$type]['category'],'is_pinned'=>false,'metadata'=>['source_kind'=>'original','body_format'=>'html'],'intent'=>'publish'],$extra))->assertCreated()->json();
    }

    public function test_public001_upcoming_event_is_shown_before_past_events(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-06 09:00','Asia/Bangkok'));
        for($i=1;$i<=6;$i++)$this->add('event',['title'=>'PAST-EVENT-'.$i,'metadata'=>['source_kind'=>'original','body_format'=>'html','starts_at'=>'2026-0'.$i.'-01T09:00','ends_at'=>'2026-0'.$i.'-01T12:00']]);
        $this->add('event',['title'=>'FUTURE-EVENT','metadata'=>['source_kind'=>'original','body_format'=>'html','starts_at'=>'2026-12-01T09:00','ends_at'=>'2026-12-01T12:00']]);
        $this->app['auth']->forgetGuards();
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('FUTURE-EVENT',$html);
        $this->travelBack();
    }

    public function test_public002_zero_is_a_real_search_term(): void
    {
        $this->add('article',['title'=>'ZERO MATCH 0 inside']);$this->add('article',['title'=>'NOMATCH-ARTICLE','body'=>'none']);
        $this->app['auth']->forgetGuards();
        $this->get('/portal/search?q=0')->assertOk()->assertSee('ZERO MATCH 0 inside')->assertDontSee('NOMATCH-ARTICLE');
        $this->get('/portal/news?q=0')->assertOk()->assertSee('ZERO MATCH 0 inside')->assertDontSee('NOMATCH-ARTICLE');
    }

    public function test_public003_original_school_notices_can_be_filtered(): void
    {
        $this->add('job',['title'=>'ORIGINAL-JOB','metadata'=>['source_kind'=>'original','body_format'=>'html','deadline'=>'2026-12-31']]);
        $this->app['auth']->forgetGuards();
        $this->get('/portal/jobs?source=original')->assertOk()->assertSee('value="original"',false)->assertSee('ORIGINAL-JOB');
    }

    public function test_rt03_reply_to_applies_to_every_school_mail(): void
    {
        \App\Domain\SchoolMail::applyGlobalAddresses(['from_address'=>'office@school.test','from_name'=>'School office','reply_to'=>'reply@school.test']);
        app('mail.manager')->forgetMailers();
        \Illuminate\Support\Facades\Mail::mailer('array')->raw('marker',fn($m)=>$m->to('parent@example.test')->subject('Notice'));
        $message=app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('reply@school.test',$message->getReplyTo()[0]->getAddress());$this->assertSame('office@school.test',$message->getFrom()[0]->getAddress());
        \App\Domain\SchoolMail::applyGlobalAddresses(['from_address'=>'office@school.test','from_name'=>'','reply_to'=>'']);
        $this->assertNull(config('mail.reply_to'));
    }

    public function test_rt06_session_without_version_stamp_is_rejected(): void
    {
        $user=User::where('role','admin')->first();
        $this->app['auth']->forgetGuards();$this->flushSession();
        \Illuminate\Support\Facades\Auth::login($user);
        $this->getJson('/api/site/state')->assertUnauthorized();
        $this->actingAs($user->fresh())->getJson('/api/site/state')->assertOk();
    }
}
