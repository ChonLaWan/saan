<?php
namespace Tests\Feature;

use App\Domain\Cms\Observance;
use App\Domain\Cms\SiteSettings;
use App\Domain\Cms\SvgSanitizer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** F7: mourning ribbon in a corner (extends the existing observance popup / grayscale). Demo school: โรงเรียนต้นธารวิทยา, mock data only. */
class ObservanceRibbonTest extends TestCase
{
    use RefreshDatabase;
    /** @var array<string,array{getenv:string|false,env:mixed,server:mixed,had_env:bool,had_server:bool}> */
    private array $envSet = [];

    protected function setUp(): void {parent::setUp();config(['school.demo'=>true]);$this->seed();Storage::fake('local');$this->actingAs(User::where('role','admin')->first());}
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        config(['database.default'=>$this->baseConnection,'saan.edition'=>'combined']); // the public-chain tests switch the default connection; RefreshDatabase must roll back the right one
        try { DB::purge('pubdb'); } catch (\Throwable) {}
        try { parent::tearDown(); } finally { $this->restoreAfterTeardown(); }
    }

    /** Always runs, even when the teardown throws: a leaked DB_DATABASE would run every later test on this test's temporary database. */
    private function restoreAfterTeardown(): void
    {
        foreach ($this->tempDirs as $dir) register_shutdown_function(static function () use ($dir) { (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir); });
        foreach ($this->envSet as $k => $old) {
            if ($old['getenv'] === false) putenv($k); else putenv("$k={$old['getenv']}");
            if ($old['had_env']) $_ENV[$k] = $old['env']; else unset($_ENV[$k]);
            if ($old['had_server']) $_SERVER[$k] = $old['server']; else unset($_SERVER[$k]);
        }
    }
    private function mourning(array $extra=[]): array {return array_replace(Observance::defaults(),['mode'=>'mourning','title'=>'ไว้อาลัย โรงเรียนต้นธารวิทยา','body'=>'ข้อความสมมติ'],$extra);}
    private function version(): int {return (int)$this->getJson('/api/site/state')->json('settings.version');}
    private function save(array $observance,string $intent='publish')
    {
        return $this->putJson('/api/site/settings',['version'=>$this->version(),'intent'=>$intent,'section'=>'all','settings'=>array_replace(SiteSettings::defaults(),['observance'=>$observance])]);
    }
    private function publish(array $extra=[]): void {$this->save($this->mourning($extra))->assertOk();}
    private function css(): string {return file_get_contents(public_path('assets/observance.css'));}
    private function guest(): void {$this->app['auth']->forgetGuards();}
    private function admin(): void {$this->actingAs(User::where('role','admin')->first());}
    private function picture(): int {return $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->image('ribbon.png',200,200)])->assertCreated()->json('id');}
    private function svg(string $body='<path d="M0 0h10v10z" fill="#000"/>'): string {return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'.$body.'</svg>';}

    // ------------------------------------------------------------------ defaults and backward compatibility

    public function test_new_observance_defaults_to_ribbon_without_grayscale(): void
    {
        $d=Observance::defaults();
        $this->assertTrue($d['ribbon']);$this->assertFalse($d['grayscale']);$this->assertSame('top-right',$d['ribbon_corner']);$this->assertSame('builtin',$d['ribbon_source']);
        $this->assertSame('ribbon',Observance::display($d));$this->assertSame('ร่วมไว้อาลัย',$d['ribbon_label']);$this->assertSame('none',$d['ribbon_target']);
        $this->publish();
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('data-observance-ribbon',$html);$this->assertStringContainsString('data-corner="top-right"',$html);
        $this->assertStringNotContainsString('data-school-mourning',$html);$this->assertStringContainsString('data-grayscale="false"',$html);
        $this->assertStringContainsString('<svg class="observance-ribbon-art"',$html);$this->assertStringContainsString('fill="#000000"',$html);
        $this->assertStringContainsString('ร่วมไว้อาลัย',$html);
    }
    public function test_settings_saved_before_the_ribbon_existed_render_as_before(): void
    {
        $this->publish(['ribbon'=>false]);
        $legacy=['mode'=>'mourning','title'=>'ประกาศเดิม','body'=>'','image_media_id'=>null,'mobile_media_id'=>null,'button_label'=>'เข้าสู่เว็บไซต์','starts_at'=>null,'ends_at'=>null,'frequency'=>'session','grayscale'=>true,'image_fit'=>'contain'];
        $set=function(array $o){$row=DB::table('cms_site_settings')->first();$published=json_decode($row->published,true);$published['observance']=$o;DB::table('cms_site_settings')->update(['published'=>json_encode($published)]);};
        $set($legacy);
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('data-observance-mode="mourning"',$html);$this->assertStringContainsString('data-grayscale="true"',$html);$this->assertStringContainsString('data-school-mourning="true"',$html);
        $this->assertStringNotContainsString('data-observance-ribbon',$html);$this->assertStringNotContainsString('observance-logo-ribbon',$html);
        $set(['grayscale'=>false]+$legacy);
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('ประกาศเดิม',$html);$this->assertStringContainsString('data-grayscale="false"',$html);$this->assertStringNotContainsString('data-school-mourning',$html);$this->assertStringNotContainsString('data-observance-ribbon',$html);
        $read=app(SiteSettings::class)->read((int)config('school.school_id'))['published']['observance'];
        $this->assertFalse($read['ribbon']);$this->assertTrue($read['popup']);$this->assertSame('none',Observance::display($read));
        // the old API payload (no ribbon keys at all) still saves and keeps the old behaviour
        $old=array_intersect_key($this->mourning(['grayscale'=>true]),array_flip(array_keys($legacy)));
        $this->save($old)->assertOk()->assertJsonPath('published.observance.ribbon',false)->assertJsonPath('published.observance.popup',true)->assertJsonPath('published.observance.grayscale',true);
    }
    public function test_display_modes_ribbon_grayscale_and_both(): void
    {
        $this->publish(['ribbon'=>true,'grayscale'=>false]);
        $html=$this->get('/portal')->getContent();$this->assertStringContainsString('data-observance-ribbon',$html);$this->assertStringNotContainsString('data-school-mourning',$html);
        $this->publish(['ribbon'=>false,'grayscale'=>true]);
        $html=$this->get('/portal')->getContent();$this->assertStringNotContainsString('data-observance-ribbon',$html);$this->assertStringContainsString('data-school-mourning="true"',$html);
        $this->publish(['ribbon'=>true,'grayscale'=>true]);
        $html=$this->get('/portal')->getContent();$this->assertStringContainsString('data-observance-ribbon',$html);$this->assertStringContainsString('data-school-mourning="true"',$html);
        $this->publish(['ribbon'=>true,'grayscale'=>true,'popup'=>false]);
        $html=$this->get('/portal')->getContent();$this->assertStringNotContainsString('class="observance-dialog"',$html);$this->assertStringContainsString('data-observance-ribbon',$html);
        $this->save($this->mourning(['mode'=>'celebration','ribbon'=>true,'grayscale'=>true]))->assertOk();
        $html=$this->get('/portal')->getContent();$this->assertStringNotContainsString('data-observance-ribbon',$html);$this->assertStringNotContainsString('data-school-mourning',$html);$this->assertStringContainsString('data-observance-mode="celebration"',$html);
    }

    // ------------------------------------------------------------------ corners, appearance, texts

    public function test_each_corner_renders_its_class_geometry_and_css_position(): void
    {
        $css=$this->css();
        $transform=['top-left'=>null,'top-right'=>'translate(100 0) scale(-1 1)','bottom-left'=>'translate(0 100) scale(1 -1)','bottom-right'=>'translate(100 100) scale(-1 -1)'];
        foreach(Observance::CORNERS as $corner){
            $this->publish(['ribbon_corner'=>$corner]);
            $html=$this->get('/portal')->assertOk()->getContent();
            $this->assertStringContainsString('data-corner="'.$corner.'"',$html,$corner);
            if($transform[$corner])$this->assertStringContainsString($transform[$corner],$html,$corner);else $this->assertStringNotContainsString('<g transform',$html);
            $this->assertStringContainsString('.observance-ribbon-wrap[data-corner='.$corner.']{',$css,$corner);
            $this->assertStringContainsString('[data-source=builtin][data-corner='.$corner.'] .observance-ribbon-hit{clip-path:polygon(',$css,$corner);
        }
        // top corners ignore the configured offset (any offset pushes the band onto header controls); bottom corners use it as set
        $this->assertMatchesRegularExpression('/data-corner=top-left\]\{top:0;left:0\}/',$css);
        $this->assertMatchesRegularExpression('/data-corner=top-right\]\{top:0;right:0\}/',$css);
        $this->assertMatchesRegularExpression('/data-corner=bottom-left\]\{bottom:var\(--rb-offset,0\);left:var\(--rb-offset,0\)\}/',$css);
        $this->assertMatchesRegularExpression('/data-corner=bottom-right\]\{bottom:var\(--rb-offset,0\);right:var\(--rb-offset,0\)\}/',$css);
    }
    public function test_size_offset_opacity_colour_edge_and_texts_are_rendered_escaped(): void
    {
        $this->publish(['ribbon_size'=>'l','ribbon_offset'=>12,'ribbon_opacity'=>80,'ribbon_color'=>'#1A1A1A','ribbon_edge'=>'white','ribbon_label'=>'ร่วมไว้อาลัย <b>x</b>','ribbon_note'=>'ข้อความ "ทดสอบ" & อื่น ๆ']);
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('--rb-size:64px;--rb-offset:12px;--rb-opacity:0.8',$html);$this->assertStringContainsString('fill="#1a1a1a"',$html);$this->assertStringContainsString('stroke="#ffffff"',$html);
        $this->assertStringContainsString('ร่วมไว้อาลัย &lt;b&gt;x&lt;/b&gt; — ข้อความ &quot;ทดสอบ&quot; &amp; อื่น ๆ',$html);$this->assertStringNotContainsString('<b>x</b>',$html);
        $this->assertStringContainsString('aria-hidden="true" focusable="false"',$html);
        // values read back from a damaged row are clamped, never printed as given
        $row=DB::table('cms_site_settings')->first();$p=json_decode($row->published,true);$p['observance']=array_replace($p['observance'],['ribbon_color'=>'red;" onload="x','ribbon_size'=>'huge','ribbon_offset'=>9999,'ribbon_opacity'=>-5,'ribbon_corner'=>'center','ribbon_url'=>'javascript:alert(1)','ribbon_target'=>'url']);DB::table('cms_site_settings')->update(['published'=>json_encode($p)]);
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('--rb-size:52px;--rb-offset:48px;--rb-opacity:0.4',$html);$this->assertStringContainsString('data-corner="top-right"',$html);$this->assertStringContainsString('fill="#000000"',$html);
        $this->assertStringNotContainsString('onload',$html);$this->assertStringNotContainsString('javascript:',$html);$this->assertStringNotContainsString('<a class="observance-ribbon-hit"',$html);
    }
    public function test_click_target_none_popup_and_url_markup(): void
    {
        $this->publish();
        $html=$this->get('/portal')->getContent();
        $this->assertStringContainsString('<span class="observance-ribbon-hit">',$html);$this->assertStringNotContainsString('<a class="observance-ribbon-hit"',$html);$this->assertStringNotContainsString('<button type="button" class="observance-ribbon-hit"',$html);
        $this->publish(['ribbon_target'=>'popup']);
        $html=$this->get('/portal')->getContent();
        $this->assertStringContainsString('<button type="button" class="observance-ribbon-hit" data-observance-open aria-haspopup="dialog" aria-labelledby="observance-ribbon-tip">',$html);$this->assertStringContainsString('class="observance-dialog"',$html);
        $this->publish(['ribbon_target'=>'url','ribbon_url'=>'/portal/news']);
        $html=$this->get('/portal')->getContent();
        $this->assertStringContainsString('<a class="observance-ribbon-hit" href="http://localhost/portal/news"',$html);$this->assertStringNotContainsString('rel="noopener noreferrer"><svg',$html);
        $this->publish(['ribbon_target'=>'url','ribbon_url'=>'https://www.example.go.th/notice']);
        $html=$this->get('/portal')->getContent();
        $this->assertStringContainsString('href="https://www.example.go.th/notice"',$html);$this->assertStringContainsString('rel="noopener noreferrer"',$html);
        // the focus order only gains a stop when there is a target, and the tooltip text is the accessible name
        $this->assertStringContainsString('aria-labelledby="observance-ribbon-tip"',$html);$this->assertStringContainsString('id="observance-ribbon-tip"',$html);
    }

    // ------------------------------------------------------------------ validation

    public function test_colour_size_offset_opacity_and_corner_are_validated(): void
    {
        $v=0;
        foreach([['ribbon_color'=>'red'],['ribbon_color'=>'#12345'],['ribbon_color'=>'#000000;background:url(x)'],['ribbon_color'=>'#gggggg'],['ribbon_size'=>'xl'],['ribbon_size'=>'60'],['ribbon_offset'=>-1],['ribbon_offset'=>49],['ribbon_offset'=>'a'],['ribbon_opacity'=>39],['ribbon_opacity'=>101],['ribbon_corner'=>'center'],['ribbon_edge'=>'red'],['ribbon_source'=>'url'],['ribbon_target'=>'script'],['ribbon_label'=>''],['ribbon_label'=>str_repeat('ก',61)],['ribbon_note'=>str_repeat('ก',161)],['ribbon_media_id'=>0],['ribbon_logo'=>'maybe']] as $bad){
            $this->save($this->mourning($bad),'draft')->assertUnprocessable();
        }
        foreach([['ribbon_color'=>'#1f1f1f'],['ribbon_color'=>'#FFFFFF'],['ribbon_size'=>'s'],['ribbon_offset'=>0],['ribbon_offset'=>48],['ribbon_opacity'=>40],['ribbon_opacity'=>100],['ribbon_corner'=>'bottom-left'],['ribbon_edge'=>'grey']] as $good){
            $this->save($this->mourning($good),'draft')->assertOk();
        }
        $this->assertSame(9,$this->version());
        // unknown observance keys are refused (nothing but the declared render settings can be stored or published)
        $this->save($this->mourning(['internal_note'=>'ห้ามเผยแพร่']),'draft')->assertUnprocessable();
    }
    public function test_click_target_refuses_script_other_schemes_and_odd_hosts(): void
    {
        foreach(['javascript:alert(1)','JaVaScRiPt:alert(1)',' javascript:alert(1)','data:text/html,x','vbscript:x','http://evil.example/x','//evil.example/x','\\\\evil.example\\x','https://user:pw@evil.example/','https://evil.example/a b','https://evil.example/"x','/portal/a"onclick="x','ftp://evil.example/x','mailto:a@example.com','#anchor','portal/news','https://'] as $bad){
            $this->save($this->mourning(['ribbon_target'=>'url','ribbon_url'=>$bad]),'draft')->assertUnprocessable();
            $this->assertFalse(Observance::safeTarget($bad),$bad);
        }
        $this->save($this->mourning(['ribbon_target'=>'url','ribbon_url'=>'']),'draft')->assertUnprocessable();
        $this->save($this->mourning(['ribbon_target'=>'popup','popup'=>false]),'draft')->assertUnprocessable();
        $this->save($this->mourning(['ribbon_source'=>'image']),'draft')->assertUnprocessable();
        $this->save($this->mourning(['ribbon'=>false,'grayscale'=>false,'popup'=>false]),'draft')->assertUnprocessable();
        foreach(['/portal/news','/portal/pages/ประกาศ','https://www.example.go.th/notice?id=3'] as $good){
            $this->save($this->mourning(['ribbon_target'=>'url','ribbon_url'=>$good]),'draft')->assertOk();$this->assertTrue(Observance::safeTarget($good),$good);
        }
    }

    // ------------------------------------------------------------------ uploaded images and SVG

    public function test_uploaded_svg_is_sanitised_and_unsafe_files_are_refused(): void
    {
        $evil=$this->svg('<script>alert(1)</script><g onload="alert(1)" onclick="x()"><path d="M0 0h10v10z" fill="#000" style="background:url(http://evil.example/x)"/><image href="http://evil.example/a.png" xlink:href="https://evil.example/b.png" width="5" height="5"/><a href="javascript:alert(1)"><rect width="3" height="3" fill="url(http://evil.example/p)"/></a><foreignObject width="10" height="10"><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject><use href="#a"/><rect width="9" height="9" fill="#ff0000" stroke="url(#grad)"/></g>');
        $id=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('ribbon.svg',$evil),'purpose'=>'ribbon','folder'=>'ไว้อาลัย'])->assertCreated()->assertJsonPath('mime','image/svg+xml')->assertJsonPath('width',64)->json('id');
        $row=DB::table('cms_media')->find($id);$stored=Storage::disk('local')->get($row->path);
        foreach(['script','onload','onclick','href','foreignObject','evil.example','javascript','<image','<use','style=','<a ','url(http'] as $bad)$this->assertStringNotContainsStringIgnoringCase($bad,$stored,$bad);
        $this->assertStringContainsString('fill="#ff0000"',$stored);$this->assertStringContainsString('stroke="url(#grad)"',$stored);$this->assertSame(strlen($stored),(int)$row->bytes);
        // served with a locked-down policy, never as an executable document
        $this->get('/media/'.$id)->assertOk()->assertHeader('Content-Type','image/svg+xml')->assertHeader('X-Content-Type-Options','nosniff')->assertHeader('Content-Security-Policy',"default-src 'none'; style-src 'unsafe-inline'; sandbox");
        // plain uploads still refuse SVG; the ribbon purpose cannot be used to smuggle a non-SVG; hostile structures are refused outright
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('plain.svg',$this->svg())])->assertUnprocessable();
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('fake.svg','<html><script>alert(1)</script></html>'),'purpose'=>'ribbon'])->assertUnprocessable();
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('doctype.svg','<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$this->svg('<text>&x;</text>')),'purpose'=>'ribbon'])->assertUnprocessable();
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('big.svg',$this->svg(str_repeat('<path d="M0 0h1v1z"/>',20000))),'purpose'=>'ribbon'])->assertUnprocessable();
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('nosize.svg','<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z"/></svg>'),'purpose'=>'ribbon'])->assertUnprocessable();
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('ns.svg','<svg viewBox="0 0 10 10"><path d="M0 0h1v1z"/></svg>'),'purpose'=>'ribbon'])->assertUnprocessable();
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('image.png',$this->svg()),'purpose'=>'ribbon'])->assertUnprocessable();
        $this->assertSame(1,DB::table('cms_media')->where('mime','image/svg+xml')->count());
    }
    public function test_svg_sanitiser_keeps_shapes_and_drops_everything_else(): void
    {
        $out=SvgSanitizer::clean($this->svg('<g fill="#112233" transform="rotate(10)"><circle cx="5" cy="5" r="3" onmouseover="x"/><title>ป้าย</title></g><script/>'));
        $this->assertSame(64,$out['width']);$this->assertSame(64,$out['height']);
        $this->assertStringContainsString('<circle cx="5" cy="5" r="3"/>',$out['svg']);$this->assertStringContainsString('<title>ป้าย</title>',$out['svg']);$this->assertStringNotContainsString('onmouseover',$out['svg']);$this->assertStringNotContainsString('script',$out['svg']);
        $this->assertNull(SvgSanitizer::clean(''));$this->assertNull(SvgSanitizer::clean('not xml'));$this->assertNull(SvgSanitizer::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0"/>'));
        $this->assertNull(SvgSanitizer::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 9000 9000"/>'));
    }
    public function test_uploaded_image_ribbon_with_small_screen_picture_is_public_after_publication_and_protected(): void
    {
        $desktop=$this->picture();$mobile=$this->picture();
        $svg=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('r.svg',$this->svg()),'purpose'=>'ribbon'])->assertCreated()->json('id');
        $this->save($this->mourning(['ribbon_source'=>'image','ribbon_media_id'=>$desktop,'ribbon_mobile_media_id'=>$mobile]),'draft')->assertOk();
        $this->guest();$this->get('/media/'.$desktop)->assertNotFound();$this->admin();
        $this->save($this->mourning(['ribbon_source'=>'image','ribbon_media_id'=>$desktop,'ribbon_mobile_media_id'=>$mobile]))->assertOk();
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('data-source="image"',$html);$this->assertStringContainsString('<picture class="observance-ribbon-art"><source media="(max-width:640px)" srcset="http://localhost/media/'.$mobile.'"><img src="http://localhost/media/'.$desktop.'" alt="" decoding="async">',$html);$this->assertStringNotContainsString('class="observance-ribbon-art" viewBox',$html);
        $this->deleteJson('/api/site/media/'.$desktop,['version'=>1])->assertUnprocessable();$this->deleteJson('/api/site/media/'.$mobile,['version'=>1])->assertUnprocessable();
        $this->guest();$this->get('/media/'.$desktop)->assertOk();$this->get('/media/'.$mobile)->assertOk();$this->get('/media/'.$svg)->assertNotFound();$this->admin();
        // a document is not a picture
        $doc=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('a.txt','harmless text marker')])->assertCreated()->json('id');
        $this->save($this->mourning(['ribbon_source'=>'image','ribbon_media_id'=>$doc]),'draft')->assertUnprocessable();
        // an svg ribbon works the same way
        $this->save($this->mourning(['ribbon_source'=>'image','ribbon_media_id'=>$svg]))->assertOk();$this->guest();$this->get('/media/'.$svg)->assertOk()->assertHeader('Content-Type','image/svg+xml');
    }

    // ------------------------------------------------------------------ time window, logo, projection

    public function test_date_window_uses_bangkok_time(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-05 12:00:00','Asia/Bangkok'));
        $this->publish(['starts_at'=>'2026-11-05T12:01','ends_at'=>'2026-11-05T12:03','ribbon_logo'=>true]);
        $this->get('/portal')->assertDontSee('data-observance-ribbon',false)->assertDontSee('observance-logo-ribbon',false);
        $this->travel(2)->minutes();$this->get('/portal')->assertSee('data-observance-ribbon',false)->assertSee('observance-logo-ribbon',false);
        $this->travel(2)->minutes();$this->get('/portal')->assertDontSee('data-observance-ribbon',false)->assertDontSee('observance-logo-ribbon',false)->assertDontSee('data-school-mourning',false);
        // 11:59 UTC is 18:59 in Bangkok: not yet; one minute later the window opens
        $this->travelTo(\Carbon\Carbon::parse('2026-11-05 11:59:00','UTC'));
        $this->publish(['starts_at'=>'2026-11-05T19:00','ends_at'=>null]);
        $this->get('/portal')->assertDontSee('data-observance-ribbon',false);
        $this->travel(1)->minutes();$this->get('/portal')->assertSee('data-observance-ribbon',false);
        $this->travelBack();
    }
    public function test_logo_ribbon_toggle(): void
    {
        $this->publish(['ribbon_logo'=>false]);$this->get('/portal')->assertDontSee('observance-logo-ribbon',false);
        $this->publish(['ribbon_logo'=>true,'ribbon_color'=>'#222222']);
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="observance-logo-ribbon" style="--rb-color:#222222" aria-hidden="true"></span>',$html);
        // the mark sits inside the school logo box and is not a corner ribbon
        $this->assertMatchesRegularExpression('/<span class="school-logo"[^>]*>.*<span class="observance-logo-ribbon".*<\/span><span class="school-wordmark">/s',$html);
        $this->publish(['ribbon_logo'=>true,'mode'=>'celebration']);$this->get('/portal')->assertDontSee('observance-logo-ribbon',false);
        $css=$this->css();$this->assertStringContainsString('.school-logo{position:relative}',$css);$this->assertMatchesRegularExpression('/\.observance-logo-ribbon\{[^}]*pointer-events:none/',$css);
    }
    public function test_published_row_carries_only_declared_observance_settings_and_no_draft(): void
    {
        $this->save($this->mourning(['title'=>'ฉบับร่างลับ']),'draft')->assertOk();
        $this->publish();
        $row=DB::table('cms_site_settings')->first();$published=json_decode($row->published,true);
        $this->assertSame([],array_diff(array_keys($published['observance']),array_keys(Observance::defaults())));
        $this->assertSame(array_keys(Observance::defaults()),array_keys($published['observance']));
        // an extra key in a stored row never reaches a render or a re-save
        $published['observance']['secret_memo']='ห้ามส่งต่อ';DB::table('cms_site_settings')->update(['published'=>json_encode($published)]);
        $read=app(SiteSettings::class)->read((int)config('school.school_id'))['published']['observance'];
        $this->assertArrayNotHasKey('secret_memo',$read);$this->get('/portal')->assertDontSee('ห้ามส่งต่อ');
        $projection=collect(\App\Support\Saan\Projection::declared(true))->firstWhere('table','cms_site_settings');
        $this->assertSame('const:{}',$projection['columns']['draft']);$this->assertSame('keep',$projection['columns']['published']);
        // the media of the published ribbon travels with the settings (otherwise the public edition shows a broken picture)
        $pic=$this->picture();$this->publish(['ribbon_source'=>'image','ribbon_media_id'=>$pic]);
        $filter=new \ReflectionMethod(\App\Support\Saan\Publish\Builder::class,'applyFilter');$filter->setAccessible(true);
        $q=DB::table('cms_media');$filter->invoke(app(\App\Support\Saan\Publish\Builder::class),$q,'published_media');
        $this->assertContains($pic,$q->pluck('id')->map('intval')->all());
    }

    // ------------------------------------------------------------------ preview

    public function test_preview_shows_the_ribbon_before_saving_and_before_the_start_date_to_staff_only(): void
    {
        $future=now('Asia/Bangkok')->addDays(3)->format('Y-m-d\TH:i');
        $payload=['observance'=>$this->mourning(['starts_at'=>$future,'ribbon_corner'=>'bottom-left','ribbon_label'=>'ร่วมไว้อาลัยตัวอย่าง'])];
        $this->get('/portal')->assertDontSee('data-observance-ribbon',false);
        $url=$this->postJson('/api/site/observance/preview',$payload)->assertCreated()->json('url');
        $this->assertStringContainsString('/observance-preview/',$url);$this->assertStringContainsString('signature=',$url);$this->assertStringContainsString('expires=',$url);
        $res=$this->get($url)->assertOk();$html=$res->getContent();
        $this->assertStringContainsString('data-observance-ribbon',$html);$this->assertStringContainsString('data-ribbon-preview="true"',$html);$this->assertStringContainsString('data-corner="bottom-left"',$html);$this->assertStringContainsString('ร่วมไว้อาลัยตัวอย่าง',$html);
        $this->assertStringContainsString('data-observance-autoshow="false"',$html);$this->assertStringContainsString('noindex',$html);
        $this->assertStringContainsString('no-store',$res->headers->get('Cache-Control'));$this->assertStringContainsString('private',$res->headers->get('Cache-Control'));$this->assertStringContainsString('noindex',$res->headers->get('X-Robots-Tag'));
        // nothing was saved or published
        $this->assertSame(0,$this->version());$this->get('/portal')->assertDontSee('data-observance-ribbon',false);$this->get('/portal')->assertDontSee('ร่วมไว้อาลัยตัวอย่าง');
        // bad values are refused like a save
        $this->postJson('/api/site/observance/preview',['observance'=>$this->mourning(['ribbon_color'=>'red'])])->assertUnprocessable();
        $this->postJson('/api/site/observance/preview',['observance'=>$this->mourning(['ribbon_target'=>'url','ribbon_url'=>'javascript:alert(1)'])])->assertUnprocessable();
        $this->postJson('/api/site/observance/preview',['observance'=>$this->mourning(['ribbon_media_id'=>987654,'ribbon_source'=>'image'])])->assertNotFound();
        // tampered, unsigned and unknown links
        $this->get(preg_replace('/signature=[^&]+/','signature=abc',$url))->assertForbidden();
        $this->get(strtok($url,'?'))->assertForbidden();
        // a visitor gets nothing: no preview route access, no query parameter that turns the ribbon on, and cannot create one
        $this->guest();
        $this->assertNotSame(200,$this->get($url)->getStatusCode());
        $this->postJson('/api/site/observance/preview',$payload)->assertUnauthorized();
        $guestHtml=$this->get('/portal?observance_preview='.basename(parse_url($url,PHP_URL_PATH)))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-observance-ribbon',$guestHtml);
        // another staff role without CMS access cannot read it either
        $this->actingAs(User::where('role','teacher')->first());
        $this->postJson('/api/site/observance/preview',$payload)->assertForbidden();$this->get($url)->assertForbidden();
    }
    public function test_preview_belongs_to_the_staff_member_who_created_it(): void
    {
        $url=$this->postJson('/api/site/observance/preview',['observance'=>$this->mourning()])->assertCreated()->json('url');
        $other=User::where('role','admin')->where('id','!=',auth()->id())->first()??User::factory()->create(['role'=>'admin','school_id'=>auth()->user()->school_id,'is_active'=>true]);
        $this->actingAs($other);$this->get($url)->assertNotFound();
    }

    // ------------------------------------------------------------------ CSS contract

    public function test_css_hides_in_print_has_no_motion_and_hits_only_the_ribbon(): void
    {
        $css=$this->css();
        $this->assertMatchesRegularExpression('/@media print\{[^}]*\.observance-ribbon-wrap[^}]*display:none!important/',$css);
        $this->assertMatchesRegularExpression('/@media print\{[^}]*\.observance-logo-ribbon[^}]*display:none!important/',$css);
        $this->assertMatchesRegularExpression('/\.observance-ribbon-wrap\{[^}]*position:fixed[^}]*pointer-events:none[^}]*\}/',$css);
        $this->assertMatchesRegularExpression('/\.observance-ribbon-hit\{[^}]*pointer-events:auto/',$css);
        $this->assertMatchesRegularExpression('/\.observance-ribbon-tip\{[^}]*pointer-events:none/',$css);
        foreach(preg_split('/\R/',$css) as $line){
            if(str_contains($line,'observance-ribbon')&&!str_contains($line,'observance-ribbon-tip'))$this->assertDoesNotMatchRegularExpression('/100vw|100vh|100dvh|inset:/',$line,'ribbon must never be a full-screen box: '.$line);
        }
        // no animation unless the visitor accepts motion; none at all is declared outside that query
        $this->assertDoesNotMatchRegularExpression('/animation|@keyframes/',$css);
        $outside=preg_replace('/@media\(prefers-reduced-motion:no-preference\)\{.*?\}\}/s','',$css);$this->assertStringNotContainsString('transition',$outside);
        // a keyboard stop exists only on an <a> or <button>; the plain ribbon is a span and the tooltip needs no focus
        $this->assertStringContainsString('.observance-ribbon-hit:focus-visible+.observance-ribbon-tip',$css);$this->assertStringContainsString('background:#111;color:#fff',$css);
    }
        private function headerField(string $key): array
    {
        $schema=json_decode(file_get_contents(resource_path('data/presentation-schema.json')),true);
        foreach($schema['header']['fields'] as $f)if($f['key']===$key)return $f;
        $this->fail('header field missing in presentation-schema.json: '.$key);
    }
    public function test_css_keeps_the_ribbon_inside_390px_and_clear_of_the_menu_button(): void
    {
        $css=$this->css();
        $presentation=file_get_contents(public_path('assets/presentation.css'));
        // the rule that really sets the header padding below the breakpoint is the variable one (it comes after, and overrides, the 10px 14px layout fallback)
        $this->assertSame(1,preg_match('/@media\(max-width:(\d+)px\)\{\.presentation-header\{padding:var\(--header-mobile-padding-y\) var\(--header-mobile-padding-x\)\}\}/',$presentation,$m),'header padding rule changed: re-check the ribbon');
        $breakpoint=(int)$m[1];
        $this->assertGreaterThan(strpos($presentation,'padding:10px 14px'),strpos($presentation,'padding:var(--header-mobile-padding-y) var(--header-mobile-padding-x)'));
        $this->assertSame(1,preg_match('/@media\(max-width:(\d+)px\)\{\.observance-ribbon-wrap/',$css,$r));$this->assertGreaterThanOrEqual($breakpoint,(int)$r[1]);
        $this->assertStringContainsString('.presentation-header .site-menu-toggle{display:none}',str_replace('@media(min-width:'.($breakpoint+1).'px){','',$presentation));
        // mobile cap: min(36px, (x+y)/.62 - 2px) with the header padding the admin can set; the band's inner edge is at 62% of the box
        $this->assertSame(1,preg_match('/@media\(max-width:1100px\)\{\.observance-ribbon-wrap\[data-corner\^=top\]\{--rb-cap:min\((\d+)px,calc\(\(var\(--header-mobile-padding-x,(\d+)px\) \+ var\(--header-mobile-padding-y,(\d+)px\)\)\/(\.\d+) - (\d+)px\)\)/',$css,$c),'mobile cap rule');
        [$maxPx,$fx,$fy,$factor,$margin]=[(int)$c[1],(int)$c[2],(int)$c[3],(float)$c[4],(int)$c[5]];
        $this->assertSame(Observance::TOP_MOBILE_MAX,$maxPx);$this->assertSame((int)$this->headerField('mobile_padding_x')['default'],$fx);$this->assertSame((int)$this->headerField('mobile_padding_y')['default'],$fy);
        $this->assertSame(0.62,$factor);
        $mx=$this->headerField('mobile_padding_x');$my=$this->headerField('mobile_padding_y');
        foreach([[$mx['min'],$my['min']],[$mx['min'],$my['default']],[$mx['default'],$my['min']],[$mx['default'],$my['default']],[$mx['max'],$my['max']]] as [$x,$y]){
            $cap=min($maxPx,($x+$y)/$factor-$margin);
            // the menu button corner is at dx=x, dy=y from the box corner: the band must end before dx+dy=x+y (the old test read the overridden 10px 14px and never saw x=8,y=6)
            $this->assertLessThan($x+$y,0.62*$cap,"band reaches the menu button with padding x=$x y=$y");
            $this->assertGreaterThan(0,$cap);
        }
        $this->assertMatchesRegularExpression('/data-corner\^=bottom\]\{width:min\(var\(--rb-size,52px\),'.Observance::BOTTOM_MOBILE_MAX.'px\)/',$css);
        foreach([390,360,320] as $viewport){$this->assertLessThanOrEqual($viewport/8,Observance::TOP_MOBILE_MAX);$this->assertLessThanOrEqual($viewport/6,Observance::BOTTOM_MOBILE_MAX);}
        // desktop: the same rule with --header-padding-x/y (lowest 12/6) and the size set by the width/height rule
        $this->assertSame(1,preg_match('/\.observance-ribbon-wrap\[data-corner\^=top\]\{--rb-cap:calc\(\(var\(--header-padding-x,(\d+)px\) \+ var\(--header-padding-y,(\d+)px\)\)\/(\.\d+) - (\d+)px\);width:min\(var\(--rb-size,52px\),var\(--rb-cap\),var\(--rb-cap-utility,999px\)\);height:min\(var\(--rb-size,52px\),var\(--rb-cap\),var\(--rb-cap-utility,999px\)\)\}/',$css,$d),'desktop cap rule');
        $dx=$this->headerField('padding_x');$dy=$this->headerField('padding_y');
        $this->assertSame((int)$dx['default'],(int)$d[1]);$this->assertSame((int)$dy['default'],(int)$d[2]);
        foreach([[$dx['min'],$dy['min']],[$dx['default'],$dy['default']],[$dx['max'],$dy['max']]] as [$x,$y]){
            foreach(Observance::SIZES as $size){$cap=min($size,($x+$y)/(float)$d[3]-(int)$d[4]);$this->assertLessThan($x+$y,0.62*$cap,"desktop band reaches header content, padding $x/$y");}
        }
        // top corners sit flush at every width (an offset would only push the band toward the header controls)
        $this->assertStringContainsString('.observance-ribbon-wrap[data-corner=top-left]{top:0;left:0}',$css);
        $this->assertStringContainsString('.observance-ribbon-wrap[data-corner=top-right]{top:0;right:0}',$css);
        // with the utility bar shown (its links start padding-y + padding-x from the corner) a top ribbon is capped so its band ends before them
        $this->assertSame(1,preg_match('/body:has\(\.site-utility\) \.observance-ribbon-wrap\[data-corner\^=top\]\{--rb-cap-utility:(\d+)px\}/',$css,$u),'utility cap rule');
        $tightest=PHP_INT_MAX;
        foreach(glob(public_path('assets/*.css')) as $sheet){preg_match_all('/\.site-utility\{[^}]*padding:(\d+)px (?:max\()?(\d+)px/',file_get_contents($sheet),$pm,PREG_SET_ORDER);foreach($pm as $p)$tightest=min($tightest,(int)$p[1]+(int)$p[2]);}
        $this->assertNotSame(PHP_INT_MAX,$tightest);
        $this->assertLessThan($tightest,0.62*(int)$u[1],'band reaches the utility links');
        // the menu button and header are never styled or targeted from the ribbon sheet
        $this->assertStringNotContainsString('site-menu-toggle',$css);$this->assertStringNotContainsString('site-header',$css);$this->assertStringNotContainsString('presentation-header',$css);
        // the button is at least 44px: a ribbon capped at 36px cannot cover it even without the offset rule
        $this->assertMatchesRegularExpression('/\.presentation-header \.site-menu-toggle[^{]*\{width:44px!important;height:44px!important/',file_get_contents(public_path('assets/accessibility.css')));
        // top ribbons ignore the configured offset on small screens (offset only pushes the band toward the button)
        $this->assertMatchesRegularExpression('/@media\(max-width:1100px\)\{[^@]*data-corner\^=top\][^}]*top:0/',$css);
        $this->assertMatchesRegularExpression('/data-corner=top-right\]\{right:0\}/',$css);$this->assertMatchesRegularExpression('/data-corner=top-left\]\{left:0\}/',$css);
    }
    public function test_uploaded_image_ribbon_is_clickable_only_inside_the_band_so_it_never_covers_the_menu_button(): void
    {
        $css=$this->css();
        // the hit box of an image ribbon takes no pointer events; only a band-shaped ::after of a link or button does
        $this->assertStringContainsString('.observance-ribbon-wrap[data-source=image] .observance-ribbon-hit{pointer-events:none}',$css);
        $this->assertStringContainsString('.observance-ribbon-wrap[data-source=image] a.observance-ribbon-hit::after,.observance-ribbon-wrap[data-source=image] button.observance-ribbon-hit::after{pointer-events:auto}',$css);
        $bands=['top-left'=>'polygon(0 30%,30% 0,62% 0,0 62%)','top-right'=>'polygon(100% 30%,70% 0,38% 0,100% 62%)','bottom-left'=>'polygon(0 70%,30% 100%,62% 100%,0 38%)','bottom-right'=>'polygon(100% 70%,70% 100%,38% 100%,100% 38%)'];
        foreach($bands as $corner=>$polygon){
            $this->assertStringContainsString('.observance-ribbon-wrap[data-source=image][data-corner='.$corner.'] .observance-ribbon-hit::after{clip-path:'.$polygon.'}',$css,$corner);
            $this->assertStringContainsString('[data-source=builtin][data-corner='.$corner.'] .observance-ribbon-hit{clip-path:'.$polygon.'}',$css,$corner);
        }
        $this->assertMatchesRegularExpression('/data-source=image\] \.observance-ribbon-hit::after\{content:"";position:absolute;top:0;left:0;width:100%;height:100%\}/',$css);
        // markup: every kind of target gets data-source=image, so the rules above apply to the span, the link and the button
        $pic=$this->picture();
        foreach([[],['ribbon_target'=>'popup'],['ribbon_target'=>'url','ribbon_url'=>'/portal/news']] as $extra){
            $this->publish(['ribbon_source'=>'image','ribbon_media_id'=>$pic,'ribbon_corner'=>'top-right']+$extra);
            $html=$this->get('/portal')->assertOk()->getContent();
            $this->assertStringContainsString('data-source="image"',$html);$this->assertStringContainsString('data-corner="top-right"',$html);$this->assertStringContainsString('class="observance-ribbon-hit"',$html);
        }
    }
public function test_ribbon_markup_has_no_layout_shift_and_no_inline_script(): void
    {
        $this->publish(['ribbon_target'=>'popup']);
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertSame(1,preg_match('/<div class="observance-ribbon-wrap".*?<\/div>/s',$html,$m));
        $this->assertStringContainsString('viewBox="0 0 100 100" width="100%" height="100%"',$m[0]);$this->assertStringNotContainsString('<script',$m[0]);$this->assertStringNotContainsString(' on',preg_replace('/ aria-|data-observance-open/','',$m[0]));
        $this->assertStringContainsString('width:var(--rb-size,52px);height:var(--rb-size,52px)',$this->css());
        $this->assertStringContainsString('observance.css',$html);$this->assertStringContainsString('observance.js',$html);
    }

    // ------------------------------------------------------------------ public edition (published settings only)

    private function bootPublic(): void
    {
        // the public edition's migrated database is built once per test process (migrate:fresh takes minutes on MariaDB) and cloned per test
        $tpl=$this->templateDatabase('ribbon-public',function(string $db):void{$this->bootEdition($db);$this->artisan('migrate:fresh')->run();});
        $this->bootEdition($this->tempDatabase($tpl));
    }
    /** Boots a fresh application as the public edition on the given database; the previous values are restored in restoreAfterTeardown. */
    private function bootEdition(string $database): void
    {
        foreach(['SAAN_EDITION'=>'public','DB_DATABASE'=>$database]as$k=>$v){
            $this->envSet[$k]??=['getenv'=>getenv($k),'env'=>$_ENV[$k]??null,'server'=>$_SERVER[$k]??null,'had_env'=>array_key_exists($k,$_ENV),'had_server'=>array_key_exists($k,$_SERVER)];
            putenv("$k=$v");$_ENV[$k]=$v;$_SERVER[$k]=$v;
        }
        $this->refreshApplication();
    }
    public function test_public_edition_renders_the_ribbon_from_published_settings_only(): void
    {
        $this->bootPublic();
        $school=(int)config('school.school_id');
        $published=array_replace(SiteSettings::defaults(),['observance'=>$this->mourning(['ribbon_corner'=>'top-left','ribbon_logo'=>true,'ribbon_label'=>'ร่วมไว้อาลัยหน้าบ้าน'])]);
        $draft=array_replace(SiteSettings::defaults(),['observance'=>$this->mourning(['ribbon_corner'=>'bottom-right','ribbon_label'=>'ฉบับร่างห้ามเห็น'])]);
        DB::table('cms_site_settings')->insert(['school_id'=>$school,'draft'=>'{}','published'=>json_encode($published),'version'=>3,'published_version'=>3,'created_at'=>now(),'updated_at'=>now()]);
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertSame('public',\App\Support\Saan\Edition::current());
        $this->assertStringContainsString('data-observance-ribbon',$html);$this->assertStringContainsString('data-corner="top-left"',$html);$this->assertStringContainsString('ร่วมไว้อาลัยหน้าบ้าน',$html);$this->assertStringContainsString('observance-logo-ribbon',$html);
        $this->assertStringNotContainsString('data-school-mourning',$html);$this->assertStringNotContainsString('ฉบับร่างห้ามเห็น',$html);$this->assertStringNotContainsString('bottom-right',$html);
        // the draft is not in the public database, and a stored extra key or a damaged value is not rendered
        $published['observance']['internal_memo']='ห้ามเผยแพร่';$published['observance']['ribbon_color']='#000000" onload="x';DB::table('cms_site_settings')->update(['published'=>json_encode($published)]);
        $html=$this->get('/portal')->assertOk()->getContent();$this->assertStringNotContainsString('ห้ามเผยแพร่',$html);$this->assertStringNotContainsString('onload',$html);$this->assertStringContainsString('fill="#000000"',$html);
        // window is evaluated on the public side too
        $published['observance']['starts_at']=now('Asia/Bangkok')->addDay()->format('Y-m-d\TH:i');unset($published['observance']['internal_memo']);DB::table('cms_site_settings')->update(['published'=>json_encode($published)]);
        $this->get('/portal')->assertOk()->assertDontSee('data-observance-ribbon',false);
        // no internal preview surface in the public edition
        $this->get('/observance-preview/'.str_repeat('a',40))->assertNotFound();$this->postJson('/api/site/observance/preview',['observance'=>$this->mourning()])->assertStatus(404);
        $this->assertFalse(collect($this->app['router']->getRoutes()->getRoutes())->contains(fn($r)=>str_contains($r->uri(),'observance-preview')||str_contains($r->uri(),'observance/preview')));
        unset($draft);
    }
    // ------------------------------------------------------------------ review round: defaults, damaged rows, media, svg

    private function storedRow(array $observance): void
    {
        $row=DB::table('cms_site_settings')->first();
        foreach(['draft','published'] as $col){$v=json_decode($row->$col,true)?:[];$v['observance']=$observance;DB::table('cms_site_settings')->update([$col=>json_encode($v)]);}
    }
    public function test_a_school_installed_before_the_ribbon_gets_the_ribbon_default_when_the_mode_is_first_switched_on(): void
    {
        // the row every existing school and the demo content hold: the old eleven keys, mode off, no 'ribbon' key
        $old=['mode'=>'off','title'=>'ประกาศจากโรงเรียน','body'=>'','image_media_id'=>null,'mobile_media_id'=>null,'button_label'=>'เข้าสู่เว็บไซต์','starts_at'=>null,'ends_at'=>null,'frequency'=>'session','grayscale'=>false,'image_fit'=>'contain'];
        $this->publish();$this->storedRow($old);
        $o=Observance::merge($old);$this->assertTrue($o['ribbon']);$this->assertSame('ribbon',Observance::display($o));
        $this->assertTrue(Observance::merge(array_diff_key($old,['mode'=>1]))['ribbon']);
        // a row with a configured announcement (mourning / celebration, no ribbon key) still means the old behaviour
        $this->assertFalse(Observance::merge(['mode'=>'mourning']+$old)['ribbon']);$this->assertFalse(Observance::merge(['mode'=>'celebration']+$old)['ribbon']);
        // what the admin form is given (state endpoint): ribbon is on, so the form does not preselect "no ribbon"
        $state=$this->getJson('/api/site/state')->assertOk()->assertJsonPath('settings.draft.observance.ribbon',true)->assertJsonPath('settings.draft.observance.mode','off');
        $draft=$state->json('settings.draft.observance');$this->assertSame('ribbon',Observance::display($draft));
        // switching the mode on through the API (the form sends back what it was given) shows the ribbon, not only the popup
        $this->save(array_replace($draft,['mode'=>'mourning','title'=>'ไว้อาลัย โรงเรียนต้นธารวิทยา']))->assertOk()->assertJsonPath('published.observance.ribbon',true);
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('data-observance-ribbon',$html);$this->assertStringContainsString('data-observance-mode="mourning"',$html);
    }
    public function test_damaged_observance_values_never_break_a_public_page(): void
    {
        $this->publish();
        $bad=array_replace(Observance::defaults(),['mode'=>'mourning','ribbon'=>true,'popup'=>true,'ribbon_size'=>['x'],'ribbon_corner'=>['top-left'],'ribbon_source'=>['image'],'ribbon_edge'=>['white'],'ribbon_target'=>['url'],'ribbon_url'=>['/portal'],'ribbon_label'=>['a'],'ribbon_note'=>['b'],'ribbon_color'=>['#000000'],'ribbon_offset'=>['9'],'ribbon_opacity'=>['9'],'ribbon_media_id'=>['1'],'ribbon_mobile_media_id'=>[1],'title'=>['t'],'body'=>['b'],'button_label'=>['b'],'frequency'=>['day'],'image_fit'=>['cover'],'image_media_id'=>['1'],'mobile_media_id'=>['1'],'starts_at'=>['2020-01-01T00:00'],'ends_at'=>'not a date']);
        $n=Observance::normalize($bad);
        $this->assertSame(['m','top-right','builtin','none','none','',Observance::LABEL],[$n['ribbon_size'],$n['ribbon_corner'],$n['ribbon_source'],$n['ribbon_edge'],$n['ribbon_target'],$n['ribbon_url'],$n['ribbon_label']]);
        $this->assertSame([null,null,null,null,null],[$n['ribbon_media_id'],$n['ribbon_mobile_media_id'],$n['image_media_id'],$n['mobile_media_id'],$n['starts_at']]);
        $this->assertNull($n['ends_at']);$this->assertSame('session',$n['frequency']);$this->assertSame('contain',$n['image_fit']);
        $row=DB::table('cms_site_settings')->first();$p=json_decode($row->published,true);$p['observance']=$bad;DB::table('cms_site_settings')->update(['published'=>json_encode($p)]);
        $html=$this->get('/portal')->assertOk()->getContent();$this->assertStringContainsString('data-observance-ribbon',$html);
        $this->get('/portal/news')->assertOk();
    }
    public function test_popup_identity_ignores_ribbon_settings_but_follows_what_the_popup_shows(): void
    {
        $a=$this->mourning(['ribbon_corner'=>'top-left','ribbon_color'=>'#111111']);$b=$this->mourning(['ribbon_corner'=>'bottom-right','ribbon_color'=>'#abcdef','ribbon_size'=>'l','ribbon_label'=>'อื่น','ribbon'=>false]);
        $this->assertSame(Observance::identity($a),Observance::identity($b));
        $this->assertNotSame(Observance::identity($a),Observance::identity($this->mourning(['title'=>'หัวข้อใหม่'])));
        $this->assertNotSame(Observance::identity($a),Observance::identity($this->mourning(['body'=>'เนื้อหาใหม่'])));
        $this->publish(['ribbon_corner'=>'top-left']);$one=$this->get('/portal')->getContent();
        $this->publish(['ribbon_corner'=>'bottom-left','ribbon_color'=>'#222222']);$two=$this->get('/portal')->getContent();
        preg_match('/data-observance="([0-9a-f]{20})"/',$one,$m1);preg_match('/data-observance="([0-9a-f]{20})"/',$two,$m2);
        $this->assertNotEmpty($m1);$this->assertSame($m1[1],$m2[1]);
        $this->assertSame(Observance::identity($a),$m1[1]);
    }
    public function test_an_announcement_published_before_the_ribbon_keeps_the_popup_hash_its_visitors_stored(): void
    {
        // the pre-ribbon view: data-observance = first 20 hex of sha256(json_encode(array_replace(old defaults, stored)))
        $oldDefaults=['mode'=>'off','title'=>'ประกาศจากโรงเรียน','body'=>'','image_media_id'=>null,'mobile_media_id'=>null,'button_label'=>'เข้าสู่เว็บไซต์','starts_at'=>null,'ends_at'=>null,'frequency'=>'session','grayscale'=>false,'image_fit'=>'contain'];
        $rows=[
            ['mode'=>'mourning','title'=>'ร่วมไว้อาลัย','body'=>"ข้อความ\nบรรทัดสอง",'image_media_id'=>null,'mobile_media_id'=>null,'button_label'=>'เข้าสู่เว็บไซต์','starts_at'=>'2026-11-01 08:00','ends_at'=>null,'frequency'=>'day','grayscale'=>true,'image_fit'=>'contain'],
            ['mode'=>'celebration','title'=>'ยินดีด้วย','body'=>'','image_media_id'=>12,'mobile_media_id'=>13,'button_label'=>'ไปต่อ','starts_at'=>null,'ends_at'=>'2026-12-01 00:00','frequency'=>'session','grayscale'=>false,'image_fit'=>'cover'],
        ];
        foreach($rows as $stored){
            $old=substr(hash('sha256',json_encode(array_replace($oldDefaults,$stored))),0,20);
            $this->assertSame($old,Observance::identity(Observance::normalize($stored)));
        }
    }
    public function test_only_media_that_a_page_really_prints_is_listed_for_publication_and_served_to_guests(): void
    {
        $popup=$this->picture();$popupMobile=$this->picture();$ribbon=$this->picture();$ribbonMobile=$this->picture();
        $all=['image_media_id'=>$popup,'mobile_media_id'=>$popupMobile,'ribbon_media_id'=>$ribbon,'ribbon_mobile_media_id'=>$ribbonMobile];
        $this->assertSame([],Observance::renderedMedia($this->mourning(['mode'=>'off']+$all)));
        $this->assertSame([$popup,$popupMobile],Observance::renderedMedia($this->mourning($all)),'builtin ribbon: its pictures are not rendered');
        $this->assertSame([$ribbon,$ribbonMobile],Observance::renderedMedia($this->mourning(['popup'=>false,'ribbon_source'=>'image']+$all)));
        $this->assertSame([$popup,$popupMobile,$ribbon,$ribbonMobile],Observance::renderedMedia($this->mourning(['ribbon_source'=>'image']+$all)));
        $this->assertSame([$popup,$popupMobile],Observance::renderedMedia($this->mourning(['mode'=>'celebration','ribbon_source'=>'image']+$all)),'celebration has no ribbon');
        $this->assertSame([],Observance::renderedMedia($this->mourning(['popup'=>false,'ribbon'=>false,'grayscale'=>true]+$all)));
        // the Builder lists exactly those; the ribbon picture that was switched back to the built-in ribbon stays internal
        $this->publish(['ribbon_source'=>'builtin']+$all);
        $filter=new \ReflectionMethod(\App\Support\Saan\Publish\Builder::class,'applyFilter');$filter->setAccessible(true);
        $ids=function()use($filter){$q=DB::table('cms_media');$filter->invoke(app(\App\Support\Saan\Publish\Builder::class),$q,'published_media');return $q->pluck('id')->map('intval')->all();};
        $this->assertContains($popup,$ids());$this->assertContains($popupMobile,$ids());$this->assertNotContains($ribbon,$ids());$this->assertNotContains($ribbonMobile,$ids());
        $this->guest();$this->get('/media/'.$popup)->assertOk();$this->get('/media/'.$ribbon)->assertNotFound();$this->get('/media/'.$ribbonMobile)->assertNotFound();$this->admin();
        // popup off: its pictures are not needed any more, the image ribbon's are
        $this->publish(['popup'=>false,'ribbon_source'=>'image']+$all);
        $this->assertNotContains($popup,$ids());$this->assertContains($ribbon,$ids());
        $this->guest();$this->get('/media/'.$popup)->assertNotFound();$this->get('/media/'.$ribbon)->assertOk();$this->admin();
        // but none of them may be deleted while a setting still names it
        $this->deleteJson('/api/site/media/'.$popup,['version'=>1])->assertUnprocessable();
    }
    public function test_svg_sanitiser_refuses_utf16_and_doctype_whatever_the_encoding(): void
    {
        $plain='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64"><path d="M0 0h10v10z" fill="#000"/></svg>';
        $this->assertNotNull(SvgSanitizer::clean($plain));
        $dtd='<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg [<!ENTITY x "harmless marker">]>'.$plain;
        foreach(['UTF-16LE'=>"\xFF\xFE",'UTF-16BE'=>"\xFE\xFF"] as $enc=>$bom){
            $this->assertNull(SvgSanitizer::clean($bom.mb_convert_encoding($dtd,$enc,'UTF-8')),$enc.' with DOCTYPE');
            $this->assertNull(SvgSanitizer::clean($bom.mb_convert_encoding($plain,$enc,'UTF-8')),$enc.' plain svg is refused too');
        }
        $this->assertNull(SvgSanitizer::clean(mb_convert_encoding($plain,'UTF-16LE','UTF-8')),'no BOM');
        $this->assertNull(SvgSanitizer::clean("\xFF\xFE<svg"),'invalid bytes');
        $this->assertNull(SvgSanitizer::clean('<?xml version="1.0"?><!DOCTYPE svg>'.$plain));$this->assertNull(SvgSanitizer::clean('<?xml version="1.0"?><!  ENTITY x "y">'.$plain));
        $this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('u16.svg',"\xFF\xFE".mb_convert_encoding($dtd,'UTF-16LE','UTF-8')),'purpose'=>'ribbon'])->assertUnprocessable();
    }

    // ------------------------------------------------------------------ the real chain: builder -> publisher -> receiver -> public edition

    private function tempDir(): string
    {
        $d=sys_get_temp_dir().'/saan-rb-'.bin2hex(random_bytes(6));mkdir($d,0777,true);$this->tempDirs[]=$d;return $d;
    }
    /** @return array{0:\App\Support\Saan\Publish\Publisher,1:string} publisher wired straight to a Receiver on a migrated second database, and the receiving disk root */
    private function publicChain(): array
    {
        config(['saan.keys_dir'=>$this->tempDir()]);\App\Support\Saan\Publish\Outbox::forget();
        $this->migratedTempConnection('pubdb'); // a fresh copy of a fully migrated database (Tests\Concerns\TempDatabases), dropped at teardown
        $root=$this->tempDir();config(['filesystems.disks.pubfiles'=>['driver'=>'local','root'=>$root]]);
        $receiver=new \App\Support\Saan\Publish\Receiver('pubdb',\App\Support\Saan\Publish\Keys::publicKey());
        $publisher=new \App\Support\Saan\Publish\Publisher(null,function(string $body,array $h)use($receiver){config(['saan.push.receive_disk'=>'pubfiles']);return $receiver->handle($body,$h);});
        config(['saan.edition'=>'internal','saan.public_push_url'=>'https://public.test/_saan/push']);
        return [$publisher,$root];
    }
    public function test_ribbon_svg_and_pictures_reach_the_public_edition_through_builder_publisher_and_receiver(): void
    {
        $svg=$this->postJson('/api/site/media',['file'=>UploadedFile::fake()->createWithContent('r.svg',$this->svg()),'purpose'=>'ribbon'])->assertCreated()->json('id');
        $unusedPopup=$this->picture();$unusedRibbon=$this->picture();
        $this->publish(['ribbon_source'=>'image','ribbon_media_id'=>$svg,'ribbon_corner'=>'top-left','popup'=>false,'image_media_id'=>$unusedPopup]);
        $path=(string)DB::table('cms_media')->where('id',$svg)->value('path');
        [$publisher,$root]=$this->publicChain();
        $r=$publisher->run(120);$this->assertSame(0,$r['failed'],json_encode($r));
        $pub=DB::connection('pubdb');
        $this->assertSame($svg,(int)$pub->table('cms_media')->where('id',$svg)->value('id'));
        $this->assertTrue(Storage::disk('pubfiles')->exists($path),'the sanitised svg was written on the public disk');
        $this->assertSame(Storage::disk('local')->get($path),Storage::disk('pubfiles')->get($path));
        $this->assertNull($pub->table('cms_media')->where('id',$unusedPopup)->first(),'a popup picture that is not shown stays internal');
        // the public edition itself: page, picture, headers
        config(['saan.edition'=>'public','database.default'=>'pubdb','filesystems.disks.local.root'=>$root]);Storage::forgetDisk('local');$this->guest();
        $this->get('/media/'.$svg)->assertOk()->assertHeader('Content-Type','image/svg+xml')->assertHeader('X-Content-Type-Options','nosniff');
        $html=$this->get('/portal')->assertOk()->getContent();
        $this->assertStringContainsString('data-observance-ribbon',$html);$this->assertStringContainsString('data-source="image"',$html);$this->assertStringContainsString('<img src="http://localhost/media/'.$svg.'"',$html);
        $this->get('/media/'.$unusedPopup)->assertNotFound();$this->get('/media/'.$unusedRibbon)->assertNotFound();
    }
    public function test_receiver_accepts_only_svg_that_the_sanitiser_gives_back_unchanged_and_only_under_cms(): void
    {
        config(['saan.keys_dir'=>$this->tempDir(),'filesystems.disks.pubfiles'=>['driver'=>'local','root'=>$this->tempDir()],'saan.push.receive_disk'=>'pubfiles']);
        $receiver=new \App\Support\Saan\Publish\Receiver(null,\App\Support\Saan\Publish\Keys::publicKey());
        $send=function(string $path,string $content)use($receiver){
            $payload=['path'=>$path,'sha256'=>hash('sha256',$content),'size'=>strlen($content),'mime'=>'image/svg+xml','offset'=>0,'data'=>base64_encode($content)];
            $json=\App\Support\Saan\Publish\Wire::json($payload);$ts=time();
            $body='{"ts":'.$ts.',"kind":"chunk","payload_hash":"'.hash('sha256',$json).'","payload":'.$json.'}';
            return $receiver->handle($body,['X-Saan-Timestamp'=>(string)$ts,'X-Saan-Signature'=>\App\Support\Saan\Publish\Keys::sign($ts,$body)]);
        };
        $clean=SvgSanitizer::clean($this->svg())['svg'];
        $cms='cms/1/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.svg';
        $this->assertSame([200,['ok'=>true,'complete'=>true]],$send($cms,$clean));$this->assertSame($clean,Storage::disk('pubfiles')->get($cms));
        // sanitising is idempotent, so what the internal side stores is what the receiver accepts
        $this->assertSame($clean,SvgSanitizer::clean($clean)['svg']);
        // a script, a foreign attribute, or merely unsanitised bytes are refused and nothing is left behind
        foreach(['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><script>alert(1)</script></svg>',$this->svg('<g onload="x()"><path d="M0 0h1v1z"/></g>'),"\xEF\xBB\xBF".$clean,"<?xml version=\"1.0\"?>\n".$clean] as $i=>$bad){
            $path='cms/1/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb0'.$i.'.svg';
            $this->assertSame([422,['error'=>'svg']],$send($path,$bad),'bad svg '.$i);$this->assertFalse(Storage::disk('pubfiles')->exists($path));
        }
        // svg is a media-library type: not a staff photo path, and the file list check rejects it too
        $this->assertSame(422,$send('personnel/1/cccccccc-cccc-4ccc-8ccc-cccccccccccc.svg',$clean)[0]);$this->assertFalse(Storage::disk('pubfiles')->exists('personnel/1/cccccccc-cccc-4ccc-8ccc-cccccccccccc.svg'));
        $this->assertSame(422,$send($cms.'.php',$clean)[0]);
        $this->assertSame(['svg'],array_values(array_filter(array_keys(\App\Support\Saan\Publish\Wire::FILE_EXT),fn($e)=>$e==='svg')));
    }

    public function test_preview_routes_are_internal_authenticated_and_blocked_in_public(): void
    {
        foreach(['/observance-preview/abc','/api/site/observance/preview']as$uri)$this->assertTrue(\App\Support\Saan\Edition::blockedInPublic($uri),$uri);
        foreach($this->app['router']->getRoutes()->getRoutes()as$r){
            if(!str_contains($r->uri(),'observance'))continue;
            $this->assertContains('auth',$r->gatherMiddleware(),$r->uri());
        }
    }
}
