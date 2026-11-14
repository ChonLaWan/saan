<?php

namespace Tests\Feature;

use App\Support\Saan\TermScope;
use App\Models\User;
use App\Support\Saan\Inbox\FileScrub;
use App\Support\Saan\Inbox\Handlers;
use App\Support\Saan\Inbox\InboxRejected;
use App\Support\Saan\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

/** Security fixes in the core: staff login ceilings, quarantine redaction, attachment scrub, /api/state leave rows, token tables, spreadsheet ceilings. */
class CoreSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $email, string $role = 'editor'): User
    {
        return User::forceCreate(['name' => 'S '.$role, 'email' => $email, 'password' => 'Right-Password-2026', 'role' => $role, 'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1]);
    }

    private function loginFrom(string $ip, string $email, string $pw)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', ['email' => $email, 'password' => $pw]);
    }

    // ---- 1. login ceilings

    public function test_login_has_a_per_account_ceiling_across_networks_and_other_accounts_are_unaffected(): void
    {
        config(['school.demo' => false, 'school.local_only' => false]); $this->seed();
        $this->staff('victim@school.test'); $this->staff('other@school.test');
        // an attacker spreads 20 wrong guesses over 7 networks (3 each, below every per-network limit)
        for ($i = 0; $i < 20; $i++) $this->loginFrom('203.0.113.'.(1 + intdiv($i, 3)), 'victim@school.test', 'wrong-'.$i)->assertSessionHasErrors('email');
        // a network that already failed 3 times on this account is refused by the account ceiling, even with the right password
        $r = $this->loginFrom('203.0.113.1', 'victim@school.test', 'Right-Password-2026');
        $r->assertSessionHasErrors('email'); $this->assertGuest();
        $this->assertNotNull($r->headers->get('Retry-After'));
        $r->assertSessionHasErrors(['email' => 'ลองเข้าสู่ระบบหลายครั้งแล้ว กรุณารอสักครู่แล้วลองใหม่']);
        // the owner, from a network with no failure on this account, is NOT locked out by somebody else's guesses
        $this->loginFrom('198.51.100.7', 'victim@school.test', 'Right-Password-2026')->assertRedirect('/');
        $this->assertAuthenticated(); $this->post('/logout'); auth()->logout();
        // one /48 (here: one IPv4 address) cannot go on guessing one account: 30 failures per hour, whatever the per-minute window does
        for ($i = 0; $i < 30; $i++) { RateLimiter::clear('login:'.hash('sha256', 'other@school.test|'.\App\Support\Saan\NetKey::rate('192.0.2.9'))); RateLimiter::clear('login-ip:'.hash('sha256', \App\Support\Saan\NetKey::wide('192.0.2.9'))); $this->loginFrom('192.0.2.9', 'other@school.test', 'wrong-'.$i)->assertSessionHasErrors('email'); }
        RateLimiter::clear('login:'.hash('sha256', 'other@school.test|'.\App\Support\Saan\NetKey::rate('192.0.2.9'))); RateLimiter::clear('login-ip:'.hash('sha256', \App\Support\Saan\NetKey::wide('192.0.2.9')));
        $this->loginFrom('192.0.2.9', 'other@school.test', 'Right-Password-2026')->assertSessionHasErrors(['email' => 'ลองเข้าสู่ระบบหลายครั้งแล้ว กรุณารอสักครู่แล้วลองใหม่']);
        // an unknown account gives the same shape of answer, and another account is not blocked by the victim's failures
        $this->loginFrom('198.51.100.8', 'nobody@school.test', 'x')->assertSessionHasErrors('email');
        $this->loginFrom('198.51.100.9', 'other@school.test', 'Right-Password-2026')->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_login_failures_are_counted_per_ipv6_64_not_per_full_address(): void
    {
        config(['school.demo' => false, 'school.local_only' => false]); $this->seed();
        $this->staff('v6@school.test');
        for ($i = 1; $i <= 5; $i++) $this->loginFrom('2001:db8:1:2::'.dechex($i), 'v6@school.test', 'wrong')->assertSessionHasErrors('email');
        $r = $this->loginFrom('2001:db8:1:2::ffff', 'v6@school.test', 'Right-Password-2026');
        $r->assertSessionHasErrors('email'); $this->assertGuest();
        $this->assertNotNull($r->headers->get('Retry-After'));
        // another network may still sign in (below the per-account ceiling)
        $this->loginFrom('203.0.113.50', 'v6@school.test', 'Right-Password-2026')->assertRedirect('/');
    }

    public function test_mfa_codes_have_a_cumulative_hourly_lockout(): void
    {
        config(['school.demo' => false]); $this->seed();
        $u = $this->staff('mfa@school.test');
        $u->forceFill(['mfa_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'), 'mfa_recovery' => Crypt::encryptString('[]'), 'mfa_enabled_at' => now()])->save();
        $pending = ['id' => $u->id, 'auth_version' => 1, 'at' => time()];
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::clear('mfa:'.$u->id); // the per-minute window passes
            $this->withSession(['mfa_login' => $pending])->post('/login/mfa', ['code' => 'zzzzzz'])->assertSessionHasErrors('code');
        }
        RateLimiter::clear('mfa:'.$u->id);
        $this->withSession(['mfa_login' => $pending])->post('/login/mfa', ['code' => 'zzzzzz'])->assertSessionHasErrors(['code' => 'ลองผิดหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่']);
        $this->assertGuest();
    }

    // ---- 5. quarantine redaction

    public function test_quarantine_keeps_no_survey_or_contact_values_in_plaintext(): void
    {
        $survey = Handlers::redact('survey', ['slug' => 'teacher-eval', 'email' => 'teacher.a@school.test', 'roster_code' => 'R-1234', 'answers' => ['q1' => 'very private text'], 'ip' => '203.0.113.77']);
        $j = json_encode($survey, JSON_UNESCAPED_UNICODE);
        foreach (['teacher.a@school.test', 'R-1234', 'very private text', '203.0.113.77'] as $s) $this->assertStringNotContainsString($s, $j);
        $this->assertSame('teacher-eval', $survey['slug']); $this->assertTrue($survey['redacted']);
        $contact = Handlers::redact('contact', ['name' => 'Somchai', 'email' => 'p@x.test', 'subject' => 'Hello', 'message' => 'secret body']);
        $j = json_encode($contact, JSON_UNESCAPED_UNICODE);
        foreach (['Somchai', 'p@x.test', 'secret body'] as $s) $this->assertStringNotContainsString($s, $j);
        $this->assertSame(5, $contact['subject_length']);
        $this->assertSame('secret body', json_decode(Crypt::decryptString($contact['sealed']), true)['message']);
        $this->assertSame(['x' => 1], Handlers::redact('weird.kind', ['x' => 1]));
    }

    // ---- 8. attachment scrub

    private function ooxml(array $parts): string
    {
        $p = tempnam(sys_get_temp_dir(), 'ox'); $z = new ZipArchive(); $z->open($p, ZipArchive::OVERWRITE);
        $i = 0;
        foreach ($parts as $n => $c) { $z->addFromString($n, $c); if ($i++ < 3) $z->setCompressionName($n, ZipArchive::CM_STORE); }
        $z->close(); $b = (string) file_get_contents($p); @unlink($p);
        return $b;
    }

    private function jpegWithExif(): string
    {
        $im = imagecreatetruecolor(8, 8); ob_start(); imagejpeg($im, null, 90); $jpg = (string) ob_get_clean();
        return substr($jpg, 0, 2)."\xFF\xE1\x00\x1CExif\x00\x00MM\x00*SECRETGPS-HOME\x00\x00\x00\x00\x00\x00\x00\x00".substr($jpg, 2);
    }

    private function scrub(string $name, string $bytes): ZipArchive
    {
        $out = FileScrub::anonymous([['name' => $name, 'mime' => '', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'content' => $bytes]]);
        $p = tempnam(sys_get_temp_dir(), 'sc'); file_put_contents($p, $out[0]['content']);
        $z = new ZipArchive(); $this->assertTrue($z->open($p) === true, 'the scrubbed file still opens'); // the temp file is removed by the OS tmp cleanup
        return $z;
    }

    public function test_docx_scrub_removes_comment_authors_people_abspath_and_media_exif(): void
    {
        $ct = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/people.xml" ContentType="x"/><Override PartName="/word/document.xml" ContentType="y"/></Types>';
        $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="t" Target="document.xml"/><Relationship Id="rId2" Type="t" Target="people.xml"/></Relationships>';
        $bytes = $this->ooxml([
            '[Content_Types].xml' => $ct, '_rels/.rels' => '<Relationships/>', 'word/document.xml' => '<w:document xmlns:w="w"><w:ins w:id="1" w:author="Secret Author" w:date="2026-01-01T00:00:00Z"><w:r><w:t>kept text</w:t></w:r></w:ins></w:document>',
            'word/_rels/document.xml.rels' => $rels,
            'word/comments.xml' => '<w:comments xmlns:w="w"><w:comment w:id="0" w:author="Secret Author" w:initials="SA"><w:p/></w:comment></w:comments>',
            'word/people.xml' => '<w15:people xmlns:w15="x"><w15:person w15:author="Secret Author"><w15:presenceInfo w15:userId="secret@corp.example" w15:providerId="AD"/></w15:person></w15:people>',
            'word/settings.xml' => '<w:settings xmlns:w="w" xmlns:mc="m" xmlns:x15ac="x" xmlns:r="r"><w:attachedTemplate r:id="rId1"/><mc:AlternateContent><mc:Choice><x15ac:absPath url="C:\\Users\\SecretWinUser\\Documents" xmlns:x15ac="x"/></mc:Choice></mc:AlternateContent><w:zoom w:percent="100"/></w:settings>',
            // a document made from a template names that template by its path on the author's machine
            'word/_rels/settings.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="file:///C:\\Users\\SecretTemplateUser\\Documents\\Custom%20Office%20Templates\\letter.dotx" TargetMode="External"/></Relationships>',
            'word/media/image1.jpg' => $this->jpegWithExif(),
            'docProps/core.xml' => '<cp:coreProperties xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>SecretAuthorName</dc:creator></cp:coreProperties>',
            'docProps/thumbnail.jpeg' => $this->jpegWithExif(),
        ]);
        $z = $this->scrub('evidence.docx', $bytes);
        $all = '';
        for ($i = 0; $i < $z->numFiles; $i++) $all .= (string) $z->getNameIndex($i)."\n".(string) $z->getFromIndex($i)."\n";
        foreach (['Secret Author', 'secret@corp.example', 'SecretWinUser', 'SECRETGPS-HOME', 'SecretAuthorName', 'SecretTemplateUser', 'attachedTemplate'] as $s) $this->assertStringNotContainsString($s, $all, $s);
        $this->assertStringContainsString('<w:zoom w:percent="100"/>', (string) $z->getFromName('word/settings.xml')); // the rest of the settings part is kept
        $this->assertFalse($z->locateName('word/people.xml'));
        $this->assertFalse($z->locateName('docProps/thumbnail.jpeg'));
        $this->assertStringNotContainsString('people.xml', (string) $z->getFromName('word/_rels/document.xml.rels'));
        $this->assertStringNotContainsString('people.xml', (string) $z->getFromName('[Content_Types].xml'));
        $this->assertStringContainsString('kept text', (string) $z->getFromName('word/document.xml'));
        $this->assertNotFalse($z->getFromName('word/media/image1.jpg'));
        $z->close();
    }

    public function test_xlsx_and_pptx_comment_authors_are_blanked_and_unsafe_parts_are_refused(): void
    {
        $x = $this->scrub('sheet.xlsx', $this->ooxml([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>', '_rels/.rels' => '<Relationships/>', 'xl/workbook.xml' => '<workbook/>',
            'xl/comments1.xml' => '<comments><authors><author>Secret Teacher</author></authors><commentList/></comments>',
            'xl/persons/person.xml' => '<personList><person displayName="Secret Teacher" id="{1}" userId="secret@corp.example" providerId="AD"/></personList>',
            'xl/revisions/revisionHeaders.xml' => '<headers><header userName="Secret Teacher"/></headers>',
            // what Excel writes for almost every workbook that was ever opened in page set-up: it must be removed, not a reason to refuse the file
            'xl/printerSettings/printerSettings1.bin' => 'HP-OFFICE-ROOM3-SECRET-PRINTER',
            'xl/worksheets/_rels/sheet1.xml.rels' => '<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/printerSettings" Target="../printerSettings/printerSettings1.bin"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<worksheet><sheetData/><pageSetup paperSize="9" orientation="portrait" r:id="rId1"/></worksheet>',
        ]));
        $all = ''; for ($i = 0; $i < $x->numFiles; $i++) $all .= (string) $x->getFromIndex($i);
        $this->assertStringNotContainsString('Secret Teacher', $all); $this->assertStringNotContainsString('secret@corp.example', $all);
        $this->assertStringNotContainsString('SECRET-PRINTER', $all); $this->assertFalse($x->getFromName('xl/printerSettings/printerSettings1.bin'));
        $this->assertStringNotContainsString('printerSettings', (string) $x->getFromName('xl/worksheets/_rels/sheet1.xml.rels'));
        $this->assertSame('<worksheet><sheetData/><pageSetup paperSize="9" orientation="portrait"/></worksheet>', $x->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertNotFalse($x->getFromName('xl/workbook.xml')); $x->close();
        $p = $this->scrub('deck.docx', $this->ooxml([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>', '_rels/.rels' => '<Relationships/>', 'word/document.xml' => '<w:document/>',
            'word/commentAuthors.xml' => '<p:cmAuthorLst><p:cmAuthor id="0" name="Secret Teacher" initials="ST"/></p:cmAuthorLst>',
        ]));
        $this->assertStringNotContainsString('Secret Teacher', (string) $p->getFromName('word/commentAuthors.xml')); $p->close();
        // an embedded OLE object cannot be scrubbed here: the file is refused
        $this->expectException(InboxRejected::class);
        $this->scrub('ole.docx', $this->ooxml(['[Content_Types].xml' => '<Types/>', '_rels/.rels' => '<Relationships/>', 'word/document.xml' => '<w:document/>', 'word/embeddings/oleObject1.bin' => 'x']));
    }

    // ---- 9. /api/state leave rows

    public function test_state_hides_other_peoples_drafts_and_attachment_paths_and_counts_submitted(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 10:00')); config(['school.demo' => true]); $this->seed();
        $admin = User::where('role', 'admin')->first();
        $school = (int) config('school.school_id');
        $term = DB::table('academic_terms')->where('id', TermScope::currentId())->first();
        $teachers = DB::table('teachers')->where('school_id', $school)->orderBy('id')->limit(2)->pluck('id')->all();
        $row = fn (int $t, string $status, string $reason) => ['school_id' => $school, 'teacher_id' => $t, 'starts_on' => $term->starts_on, 'ends_on' => $term->starts_on, 'type' => 'sick', 'reason' => $reason, 'status' => $status, 'attachment_path' => 'leave/secret-'.$status.'.pdf', 'created_at' => now(), 'updated_at' => now()];
        DB::table('leave_requests')->insert([$row($teachers[0], 'draft', 'MEDICAL-DRAFT-REASON'), $row($teachers[0], 'submitted', 'visible reason'), $row($teachers[1], 'approved', 'approved reason')]);
        $this->actingAs($admin);
        $res = $this->getJson('/api/state')->assertOk();
        $json = $res->getContent();
        $this->assertStringNotContainsString('MEDICAL-DRAFT-REASON', $json); $this->assertStringNotContainsString('attachment_path', $json); $this->assertStringNotContainsString('secret-', $json);
        $reasons = array_column($res->json('leaves'), 'reason');
        $this->assertContains('visible reason', $reasons); $this->assertContains('approved reason', $reasons); $this->assertNotContains('MEDICAL-DRAFT-REASON', $reasons);
        $submitted = DB::table('leave_requests')->where('status', 'submitted')->count();
        $this->assertGreaterThanOrEqual(1, $submitted);
        $this->assertSame($submitted + DB::table('leave_requests')->where('status', 'pending')->count(), $res->json('counts.leaves_pending'));
    }

    // ---- 11. reset and invite tokens live in separate tables

    public function test_a_reset_token_expires_after_60_minutes_while_an_invite_keeps_72_hours(): void
    {
        config(['school.demo' => false]); $this->seed(); Notification::fake();
        $u = $this->staff('tok@school.test');
        $reset = Password::broker('users')->createToken($u);
        $invite = Password::broker('invites')->createToken($u);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $u->email)->count());
        $this->assertSame(1, DB::table('password_invite_tokens')->where('email', $u->email)->count());
        $this->travel(2)->hours();
        $pw = ['email' => $u->email, 'password' => 'Brand-New-Pass-2026', 'password_confirmation' => 'Brand-New-Pass-2026'];
        $this->post('/reset-password', $pw + ['token' => $reset])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Right-Password-2026', $u->fresh()->password));
        $this->post('/reset-password', $pw + ['token' => $invite])->assertRedirect('/login');
        $this->assertTrue(Hash::check('Brand-New-Pass-2026', $u->fresh()->password));
        $this->assertSame(0, DB::table('password_invite_tokens')->count());
        // an invite older than 72 hours fails as well
        $invite2 = Password::broker('invites')->createToken($u->fresh());
        $this->travel(73)->hours();
        $this->post('/reset-password', ['token' => $invite2, 'email' => $u->email, 'password' => 'Another-Pass-2026', 'password_confirmation' => 'Another-Pass-2026'])->assertSessionHasErrors('email');
    }

    // ---- 23. spreadsheet ceilings

    private function sheetZip(string $sharedXml, string $stylesXml = ''): string
    {
        $p = tempnam(sys_get_temp_dir(), 'xs'); $z = new ZipArchive(); $z->open($p, ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $z->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="t" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>');
        $z->addFromString('xl/sharedStrings.xml', $sharedXml);
        if ($stylesXml !== '') $z->addFromString('xl/styles.xml', $stylesXml);
        $z->close(); $b = (string) file_get_contents($p); @unlink($p);
        return $b;
    }

    public function test_spreadsheet_reader_caps_shared_strings_and_styles_before_building_arrays(): void
    {
        $ok = $this->sheetZip('<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>hello</t></si></sst>');
        $this->assertSame([['hello']], SpreadsheetReader::rows($ok));
        $many = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.str_repeat('<si/>', SpreadsheetReader::MAX_SHARED + 1).'</sst>';
        try { SpreadsheetReader::rows($this->sheetZip($many)); $this->fail('too many shared strings'); } catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $big = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>'.str_repeat('a', SpreadsheetReader::MAX_SHARED_BYTES + 10).'</t></si></sst>';
        try { SpreadsheetReader::rows($this->sheetZip($big)); $this->fail('shared strings too large'); } catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $styles = '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs>'.str_repeat('<xf numFmtId="0"/>', SpreadsheetReader::MAX_STYLES + 1).'</cellXfs></styleSheet>';
        try { SpreadsheetReader::rows($this->sheetZip('<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>x</t></si></sst>', $styles)); $this->fail('too many styles'); } catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }

    // ---- 24a. public per-slug pages are throttled with their own counters

    public function test_public_page_routes_have_their_own_throttle_counter(): void
    {
        $want = ['portal/directory' => 'get-portal-directory', 'portal/search' => 'get-portal-search', 'portal/{section}/{slug}' => 'get-portal-article', 'portal/{section}' => 'get-portal-collection', 'media/{id}' => 'get-media-id', 'portal/contact' => 'get-portal-contact'];
        $seen = [];
        foreach (app('router')->getRoutes() as $route) if (in_array('GET', $route->methods(), true) && isset($want[$route->uri()])) {
            foreach ($route->gatherMiddleware() as $m) if (is_string($m) && str_starts_with($m, 'throttle:')) $seen[$route->uri()] = explode(',', substr($m, 9))[2] ?? null;
        }
        foreach ($want as $uri => $name) $this->assertSame($name, $seen[$uri] ?? null, $uri);
    }
}
