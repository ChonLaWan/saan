<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Projection;
use App\Support\Saan\Settings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Saan\Modules\Finance\Slips;
use Tests\TestCase;

/** F3 guardian fees (internal only): payment accounts + QR, guardian view, slip claims, finance queue, counter slips, search, retention. */
class GuardianFeesTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '1234567890';
    private int $seq = 0;
    private int $acc = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        Storage::fake('local');
        RateLimiter::clear('gp-slip:1');
        RateLimiter::clear('gp-slip:3');
        $now = now();
        foreach ([[1, 'ผู้ปกครองทดสอบ ก'], [2, 'ผู้ปกครองทดสอบ ข'], [3, 'ผู้ปกครองทดสอบ ค']] as [$id, $name]) {
            DB::table('reg_guardians')->insert(['id' => $id, 'school_id' => 1, 'display_name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([[1, 1, true], [2, 1, true], [9, 3, true]] as [$student, $guardian, $pick]) {
            DB::table('reg_student_guardians')->insert(['school_id' => 1, 'student_id' => $student, 'guardian_id' => $guardian, 'relationship' => 'mother',
                'can_pick_up' => $pick, 'pickup_note' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private function today(): string { return Carbon::now('Asia/Bangkok')->toDateString(); }

    private function as(string $role): static
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs(User::where('role', $role)->first());
    }

    private function finance(): static { return $this->as('admin'); }

    private function guardianLogin(int $student, int $guardian): void
    {
        $made = $this->as('admin')->postJson('/api/guardian-portal/links', ['student_id' => $student, 'guardian_id' => $guardian, 'purpose' => 'view', 'days' => 14])->assertCreated()->json();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $path = parse_url($made['url'], PHP_URL_PATH);
        $this->post($path)->assertRedirect('/guardian');
    }

    private function png(int $seed): string
    {
        $im = imagecreatetruecolor(48, 48);
        for ($i = 0; $i < 48; $i++) for ($j = 0; $j < 48; $j++) imagesetpixel($im, $i, $j, ((($i * $seed) + $j * 7) % 255) << 16 | (($seed * 37) % 255) << 8 | (($i + $j + $seed) % 255));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    private function file(?int $seed = null, ?string $name = null): UploadedFile
    {
        $seed ??= ++$this->seq + 100;
        return UploadedFile::fake()->createWithContent($name ?? 'slip-'.$seed.'.png', $this->png($seed));
    }

    private function account(array $o = []): array
    {
        return $this->finance()->postJson('/api/finance/accounts', $o + ['bank_code' => 'kbank', 'account_name' => 'โรงเรียนต้นธารวิทยา (บัญชีทดสอบ)', 'account_number' => self::NUMBER, 'branch' => 'สาขาสมมติ', 'sort_order' => 1])->assertCreated()->json();
    }

    private function invoice(int $student, int $amount = 100000, array $line = []): array
    {
        return $this->finance()->postJson('/api/finance/invoices', ['student_id' => $student, 'issued_on' => $this->today(), 'lines' => [$line + ['label' => 'ค่าบำรุงการศึกษา', 'amount_satang' => $amount]]])->assertCreated()->json();
    }

    private function when(int $minutesAgo = 5): string { return Carbon::now('Asia/Bangkok')->subMinutes($minutesAgo)->format('Y-m-d\TH:i'); }

    /** Guardian (already logged in) sends a claim. */
    private function claim(int $child, int $invoice, array $o = [], bool $limited = false)
    {
        if (!$limited) { RateLimiter::clear('gp-slip:1'); RateLimiter::clear('gp-slip:3'); }
        $o += ['slips' => [$this->file()], 'amount' => '1,000.00', 'transferred_at' => $this->when(), 'account_id' => $this->acc];
        return $this->post("/guardian/api/children/$child/invoices/$invoice/claims", $o, ['Accept' => 'application/json']);
    }

    /** Standard scene: one active account, invoice of 1,000 baht for student 1, guardian 1 logged in. */
    private function scene(): array
    {
        $this->acc = $this->account()['id'];
        $inv = $this->invoice(1)['invoice'];
        $this->guardianLogin(1, 1);
        return $inv;
    }

    private function queryCount(\Closure $fn): int
    {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $fn();
        return $n;
    }

    // ---- 1. payment accounts ---------------------------------------------------------------------------------

    public function test_finance_manages_accounts_with_fixed_bank_list_and_audit(): void
    {
        $a = $this->account();
        $this->assertSame('ธนาคารกสิกรไทย', $a['bank_name']);
        $this->assertSame(self::NUMBER, $a['account_number']);
        $this->finance()->postJson('/api/finance/accounts', ['bank_code' => 'nobank', 'account_name' => 'x', 'account_number' => self::NUMBER])->assertUnprocessable();
        $this->postJson('/api/finance/accounts', ['bank_code' => 'scb', 'account_name' => 'โรงเรียนต้นธารวิทยา', 'account_number' => '12-34'])->assertUnprocessable();
        $this->postJson('/api/finance/accounts', ['bank_code' => 'kbank', 'account_name' => 'ซ้ำ', 'account_number' => '123-456-7890'])->assertStatus(409); // same bank and number
        $b = $this->postJson('/api/finance/accounts', ['bank_code' => 'scb', 'account_name' => 'บัญชีที่สอง (ทดสอบ)', 'account_number' => '999-8-77665-4', 'sort_order' => 2])->assertCreated()->json();
        $this->assertSame('9998776654', $b['account_number']);
        $this->assertCount(2, $this->getJson('/api/finance/accounts')->assertOk()->json('data'));

        $this->putJson('/api/finance/accounts/'.$a['id'], ['version' => $a['version'], 'account_number' => '5550001112'])->assertOk()->assertJsonPath('account_number', '5550001112');
        $this->putJson('/api/finance/accounts/'.$a['id'], ['version' => $a['version'], 'account_name' => 'เก่า'])->assertStatus(409); // optimistic version
        $audit = DB::table('audit_events')->where('action', 'finance.account_updated')->first();
        $this->assertStringContainsString('xxxx7890', $audit->detail);
        $this->assertStringContainsString('xxxx1112', $audit->detail);
        foreach (DB::table('audit_events')->pluck('detail') as $d) {
            $this->assertStringNotContainsString(self::NUMBER, (string) $d);
            $this->assertStringNotContainsString('5550001112', (string) $d);
        }
        $this->assertTrue(DB::table('audit_events')->where('action', 'finance.account_created')->exists());
    }

    public function test_only_finance_staff_reach_accounts(): void
    {
        $this->account();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/finance/accounts')->assertUnauthorized();
        $teacher = $this->as('teacher');
        $this->getJson('/api/finance/accounts')->assertForbidden();
        $this->postJson('/api/finance/accounts', ['bank_code' => 'kbank', 'account_name' => 'ทดสอบ', 'account_number' => '1112223334'])->assertForbidden();
        $t = User::where('role', 'teacher')->first();
        Settings::put('finance.staff_user_ids', [$t->id]);
        $this->getJson('/api/finance/accounts')->assertOk();
    }

    // ---- QR image --------------------------------------------------------------------------------------------

    public function test_qr_upload_checks_content_strips_trailing_data_and_serves_only_to_finance(): void
    {
        $a = $this->account();
        $png = $this->png(5).'SECRETTRAILER-MARKER';
        $r = $this->post('/api/finance/accounts/'.$a['id'].'/qr', ['version' => $a['version'], 'qr' => UploadedFile::fake()->createWithContent('merchant.png', $png)], ['Accept' => 'application/json'])->assertOk();
        $this->assertTrue($r->json('has_qr'));
        $row = DB::table('fin_payment_accounts')->find($a['id']);
        $this->assertStringStartsWith('finance/1/qr/', $row->qr_path);
        $stored = Storage::disk('local')->get($row->qr_path);
        $this->assertStringNotContainsString('SECRETTRAILER', $stored); // re-encoded
        $this->assertSame('image/png', $row->qr_mime);
        $this->assertSame(strlen($stored), (int) $row->qr_size);
        $res = $this->get('/api/finance/accounts/'.$a['id'].'/qr')->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('/storage/', $row->qr_path.'');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/finance/accounts/'.$a['id'].'/qr')->assertUnauthorized();
        $this->as('teacher')->get('/api/finance/accounts/'.$a['id'].'/qr')->assertForbidden();
        // replacing deletes the old file; removing clears the columns
        $this->finance();
        $ver = (int) DB::table('fin_payment_accounts')->where('id', $a['id'])->value('version');
        $this->post('/api/finance/accounts/'.$a['id'].'/qr', ['version' => $ver, 'qr' => $this->file(6, 'second.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($row->qr_path);
        $ver = (int) DB::table('fin_payment_accounts')->where('id', $a['id'])->value('version');
        $this->deleteJson('/api/finance/accounts/'.$a['id'].'/qr', ['version' => $ver])->assertOk()->assertJsonPath('has_qr', false);
        $this->get('/api/finance/accounts/'.$a['id'].'/qr')->assertNotFound();
    }

    public function test_qr_upload_refuses_spoofed_svg_pdf_oversize_and_stale_version(): void
    {
        $a = $this->account();
        $up = fn (UploadedFile $f, ?int $v = null) => $this->post('/api/finance/accounts/'.$a['id'].'/qr', ['version' => $v ?? (int) DB::table('fin_payment_accounts')->where('id', $a['id'])->value('version'), 'qr' => $f], ['Accept' => 'application/json']);
        $up(UploadedFile::fake()->createWithContent('qr.png', '<?php echo "x"; ?>'))->assertUnprocessable();
        $up(UploadedFile::fake()->createWithContent('qr.jpg', '<html><script>alert(1)</script></html>'))->assertUnprocessable();
        $up(UploadedFile::fake()->createWithContent('qr.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertUnprocessable();
        $up(UploadedFile::fake()->createWithContent('qr.png', '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"></svg>'))->assertUnprocessable();
        $up(UploadedFile::fake()->createWithContent('qr.png', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF"))->assertUnprocessable(); // a QR must be an image
        $up(UploadedFile::fake()->createWithContent('qr.png', 'GIF89a'.str_repeat("\0", 40)))->assertUnprocessable();
        $up(UploadedFile::fake()->createWithContent('qr.png', str_repeat("\0", 6 * 1048576)))->assertUnprocessable();
        $this->assertNull(DB::table('fin_payment_accounts')->where('id', $a['id'])->value('qr_path'));
        $up($this->file(9), 999)->assertStatus(409);
        $this->assertSame([], Storage::disk('local')->allFiles()); // a refused or stale upload leaves no file behind
    }

    // ---- account choice on fee items / lines -----------------------------------------------------------------

    public function test_invoice_shows_the_accounts_chosen_by_fee_item_or_line_and_default_all_active(): void
    {
        $a = $this->account();
        $b = $this->account(['bank_code' => 'scb', 'account_number' => '2223334445', 'sort_order' => 2]);
        $c = $this->account(['bank_code' => 'ktb', 'account_number' => '3334445556', 'sort_order' => 3, 'is_active' => false]);
        $this->finance();
        $this->postJson('/api/finance/fee-items', ['code' => 'X', 'label' => 'x', 'default_amount_satang' => 1000, 'account_ids' => [99999]])->assertUnprocessable();
        $fee = $this->postJson('/api/finance/fee-items', ['code' => 'LUNCH', 'label' => 'ค่าอาหาร', 'default_amount_satang' => 50000, 'account_ids' => [$b['id']]])->assertCreated()->json();
        $this->assertSame([$b['id']], $fee['account_ids']);
        $one = $this->postJson('/api/finance/invoices', ['student_id' => 1, 'issued_on' => $this->today(), 'lines' => [['fee_item_id' => $fee['id'], 'amount_satang' => 50000]]])->assertCreated()->json('invoice.id');
        $all = $this->invoice(2)['invoice']['id']; // no fee item: every active account
        $ov = $this->postJson('/api/finance/invoices', ['student_id' => 2, 'issued_on' => $this->today(), 'lines' => [['label' => 'บริจาค', 'amount_satang' => 100, 'account_ids' => [$a['id'], $c['id']]]]])->assertCreated()->json('invoice.id');
        $this->guardianLogin(1, 1);
        $ids = fn (array $inv) => array_column($inv['accounts'], 'id');
        $fees = $this->getJson('/guardian/api/children/1/fees')->assertOk()->json();
        $this->assertSame([$b['id']], $ids($fees['invoices'][0]));
        $this->assertSame('2223334445', $fees['invoices'][0]['accounts'][0]['account_number']); // shown in full: the guardian must transfer to it
        $this->assertSame('ธนาคารไทยพาณิชย์', $fees['invoices'][0]['accounts'][0]['bank_name']);
        // a line's own list wins; an inactive account is never shown
        $this->assertSame([$a['id']], array_map(fn ($x) => (int) $x->id, \Saan\Modules\Finance\Accounts::forInvoice($ov)));
        $this->assertSame([$a['id'], $b['id']], array_map(fn ($x) => (int) $x->id, \Saan\Modules\Finance\Accounts::forInvoice($all)));
        $this->assertSame([$b['id']], array_map(fn ($x) => (int) $x->id, \Saan\Modules\Finance\Accounts::forInvoice($one)));
    }

    // ---- 2. guardian view ------------------------------------------------------------------------------------

    public function test_guardian_sees_only_own_children_invoices_accounts_and_reference(): void
    {
        $inv = $this->scene();
        $other = $this->invoice(9)['invoice'];
        $this->guardianLogin(1, 1);
        $fees = $this->getJson('/guardian/api/children/1/fees')->assertOk()->json();
        $this->assertTrue($fees['available']);
        $this->assertSame(100000, $fees['balance_satang']);
        $this->assertCount(1, $fees['invoices']);
        $i = $fees['invoices'][0];
        $this->assertSame($inv['invoice_no'], $i['reference']);
        $this->assertSame(100000, $i['outstanding_satang']);
        $this->assertSame(self::NUMBER, $i['accounts'][0]['account_number']);
        $this->assertStringNotContainsString($other['invoice_no'], json_encode($fees));
        $this->assertStringNotContainsString('student_code', json_encode($fees));
        $this->getJson('/guardian/api/children/9/fees')->assertNotFound();
        $this->getJson('/guardian/api/children/2/fees')->assertNotFound(); // linked in the registry but no link for this child in the session
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/guardian/api/children/1/fees')->assertUnauthorized();
    }

    public function test_guardian_fee_overview_uses_a_constant_number_of_queries(): void
    {
        $this->acc = $this->account()['id'];
        $this->invoice(1);
        $this->guardianLogin(1, 1);
        $n1 = $this->queryCount(fn () => $this->getJson('/guardian/api/children/1/fees')->assertOk());
        $this->finance();
        for ($k = 0; $k < 6; $k++) $this->invoice(1, 5000 + $k);
        $this->guardianLogin(1, 1);
        $inv = $this->getJson('/guardian/api/children/1/fees')->json('invoices');
        $this->claim(1, $inv[0]['id'], ['amount' => '10.00'])->assertCreated();
        $n2 = $this->queryCount(fn () => $this->getJson('/guardian/api/children/1/fees')->assertOk()->assertJsonCount(7, 'invoices'));
        $this->assertLessThanOrEqual($n1 + 2, $n2, "$n1 vs $n2");
    }

    public function test_qr_is_served_only_for_an_open_invoice_of_the_childs_guardian_that_uses_the_account(): void
    {
        $a = $this->account();
        $b = $this->account(['bank_code' => 'scb', 'account_number' => '2223334445', 'sort_order' => 2]);
        foreach ([$a, $b] as $x) $this->post('/api/finance/accounts/'.$x['id'].'/qr', ['version' => $x['version'], 'qr' => $this->file()], ['Accept' => 'application/json'])->assertOk();
        $fee = $this->postJson('/api/finance/fee-items', ['code' => 'ONLYB', 'label' => 'b', 'default_amount_satang' => 1000, 'account_ids' => [$b['id']]])->assertCreated()->json('id');
        $mine = $this->postJson('/api/finance/invoices', ['student_id' => 1, 'issued_on' => $this->today(), 'lines' => [['fee_item_id' => $fee, 'amount_satang' => 100000]]])->assertCreated()->json('invoice');
        $theirs = $this->invoice(9)['invoice'];
        $paid = $this->invoice(1, 2000)['invoice'];
        $this->postJson('/api/finance/invoices/'.$paid['id'].'/payments', ['amount_satang' => 2000, 'method' => 'cash', 'paid_on' => $this->today()])->assertCreated();
        $this->guardianLogin(1, 1);
        $url = fn ($child, $inv, $acc) => "/guardian/api/children/$child/invoices/$inv/accounts/$acc/qr";
        $ok = $this->get($url(1, $mine['id'], $b['id']))->assertOk();
        $this->assertSame('image/png', $ok->headers->get('Content-Type'));
        $this->assertSame('nosniff', $ok->headers->get('X-Content-Type-Options'));
        $this->get($url(1, $mine['id'], $a['id']))->assertNotFound(); // account not used by this invoice
        $this->get($url(1, $theirs['id'], $a['id']))->assertNotFound(); // invoice of another child via my child path
        $this->get($url(9, $theirs['id'], $a['id']))->assertNotFound(); // child that is not mine
        $this->get($url(1, $paid['id'], $a['id']))->assertNotFound(); // paid invoice: no longer a payable invoice
        $this->get($url(1, 99999, $a['id']))->assertNotFound();
        $this->guardianLogin(9, 3);
        $this->get($url(1, $mine['id'], $b['id']))->assertNotFound(); // guardian of another child
        $this->get($url(9, $theirs['id'], $a['id']))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($url(9, $theirs['id'], $a['id']))->assertUnauthorized();
    }

    // ---- 3. slip upload --------------------------------------------------------------------------------------

    public function test_guardian_sends_a_claim_with_slips_stored_private_and_audited_without_secrets(): void
    {
        $inv = $this->scene();
        $f1 = $this->file(11);
        $bytes = file_get_contents($f1->getRealPath());
        $res = $this->claim(1, $inv['id'], ['slips' => [$f1, $this->file(12)], 'amount' => '400.50'])->assertCreated();
        $claim = DB::table('fin_payment_claims')->first();
        $this->assertSame('pending', $claim->status);
        $this->assertSame('guardian', $claim->source);
        $this->assertSame(1, (int) $claim->guardian_id);
        $this->assertSame(40050, (int) $claim->amount_satang);
        $this->assertSame($this->acc, (int) $claim->account_id);
        $slips = DB::table('fin_claim_slips')->get();
        $this->assertCount(2, $slips);
        $this->assertSame(hash('sha256', $bytes), $slips[0]->sha256);
        foreach ($slips as $s) {
            $this->assertStringStartsWith('finance/1/slips/', $s->path);
            Storage::disk('local')->assertExists($s->path);
        }
        $json = json_encode($res->json());
        $this->assertStringNotContainsString($slips[0]->sha256, $json);
        $this->assertStringNotContainsString('finance/1/slips', $json);
        $this->assertSame('pending', $res->json('invoices.0.claims.0.status'));
        $this->assertTrue($res->json('invoices.0.claims.0.can_withdraw'));
        $this->assertSame(100000, $res->json('invoices.0.outstanding_satang')); // nothing is paid until finance approves
        $ev = DB::table('gp_events')->where('action', 'guardian.fee_claim.submitted')->first();
        $this->assertSame(1, (int) $ev->guardian_id);
        foreach ([$ev->detail, ...DB::table('audit_events')->pluck('detail')->all()] as $d) {
            $this->assertStringNotContainsString(self::NUMBER, (string) $d);
            $this->assertStringNotContainsString($slips[0]->sha256, (string) $d);
            $this->assertStringNotContainsString('finance/1/slips', (string) $d);
        }
    }

    public function test_claim_rules_amount_account_time_and_overpayment(): void
    {
        $inv = $this->scene();
        $this->claim(1, $inv['id'], ['amount' => '1,000.01'])->assertUnprocessable(); // more than the outstanding balance: refused
        $this->claim(1, $inv['id'], ['amount' => '0'])->assertUnprocessable();
        $this->claim(1, $inv['id'], ['amount' => '12.345'])->assertUnprocessable();
        $this->claim(1, $inv['id'], ['amount' => 'abc'])->assertUnprocessable();
        $this->claim(1, $inv['id'], ['account_id' => null])->assertUnprocessable(); // the account paid into is required
        $this->claim(1, $inv['id'], ['account_id' => 99999])->assertUnprocessable();
        $this->claim(1, $inv['id'], ['transferred_at' => Carbon::now('Asia/Bangkok')->addDay()->format('Y-m-d\TH:i')])->assertUnprocessable();
        $this->claim(1, $inv['id'], ['transferred_at' => Carbon::now('Asia/Bangkok')->subDays(3)->format('Y-m-d\TH:i')])->assertUnprocessable(); // before the invoice date
        $this->claim(1, $inv['id'], ['transferred_at' => 'yesterday'])->assertUnprocessable();
        $this->claim(1, $inv['id'], ['slips' => []])->assertUnprocessable();
        $this->claim(1, $inv['id'], ['slips' => [$this->file(), $this->file(), $this->file(), $this->file()]])->assertUnprocessable();
        $this->assertSame(0, DB::table('fin_payment_claims')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->claim(1, $inv['id'], ['amount' => '1000.00'])->assertCreated(); // exactly the balance is fine
    }

    public function test_slip_type_is_checked_from_content_not_from_name_or_declared_type(): void
    {
        $inv = $this->scene();
        $bad = [
            'php as png' => UploadedFile::fake()->createWithContent('slip.png', '<?php system($_GET["c"]); ?>'),
            'html as jpg' => UploadedFile::fake()->createWithContent('slip.jpg', '<!doctype html><script>alert(1)</script>'),
            'svg' => UploadedFile::fake()->createWithContent('slip.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'svg as png' => UploadedFile::fake()->createWithContent('slip.png', '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/>'),
            'gif' => UploadedFile::fake()->createWithContent('slip.png', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')),
            'empty' => UploadedFile::fake()->createWithContent('slip.png', ''),
            'pdf with script' => UploadedFile::fake()->createWithContent('slip.pdf', "%PDF-1.4\n1 0 obj<</S/JavaScript/JS(app.alert(1))>>endobj\n%%EOF"),
            'text as pdf' => UploadedFile::fake()->createWithContent('slip.pdf', 'not a pdf at all'),
            'oversize' => UploadedFile::fake()->createWithContent('slip.png', str_repeat("\0", 6 * 1048576)),
        ];
        foreach ($bad as $label => $file) $this->claim(1, $inv['id'], ['slips' => [$file]])->assertUnprocessable();
        $this->assertSame(0, DB::table('fin_claim_slips')->count(), 'nothing may be stored from a refused upload');
        $this->assertSame([], Storage::disk('local')->allFiles());
        // a JPEG sent under a .png name and a plain PDF are real slips
        $jpeg = (function () { $im = imagecreatetruecolor(30, 30); imagefilledrectangle($im, 0, 0, 14, 14, 0xAA3311); ob_start(); imagejpeg($im); return (string) ob_get_clean(); })();
        $this->claim(1, $inv['id'], ['slips' => [UploadedFile::fake()->createWithContent('slip.png', $jpeg)], 'amount' => '10'])->assertCreated();
        $this->claim(1, $inv['id'], ['slips' => [UploadedFile::fake()->createWithContent('bank.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")], 'amount' => '10'])->assertCreated();
        $this->assertEqualsCanonicalizing(['image/jpeg', 'application/pdf'], DB::table('fin_claim_slips')->pluck('mime')->all());
    }

    public function test_duplicate_slip_is_refused_school_wide_with_a_neutral_message(): void
    {
        $inv = $this->scene();
        $other = $this->invoice(9)['invoice'];
        $this->guardianLogin(1, 1);
        $bytes = $this->png(77);
        $mk = fn () => UploadedFile::fake()->createWithContent('a.png', $bytes);
        $this->claim(1, $inv['id'], ['slips' => [$mk()], 'amount' => '100'])->assertCreated();
        $dup = $this->claim(1, $inv['id'], ['slips' => [$mk()], 'amount' => '100'])->assertUnprocessable();
        $this->assertSame(Slips::NEUTRAL, $dup->json('message'));
        $this->assertStringNotContainsString('ซ้ำ', $dup->json('message')); // does not say the file already exists
        $this->claim(1, $inv['id'], ['slips' => [$mk(), $this->file()], 'amount' => '100'])->assertUnprocessable(); // inside a batch too
        $this->claim(1, $inv['id'], ['slips' => [$mk(), UploadedFile::fake()->createWithContent('b.png', $bytes)], 'amount' => '100'])->assertUnprocessable(); // same file twice in one claim
        $this->guardianLogin(9, 3); // another guardian, another child: still the same school
        $theirs = $this->claim(9, $other['id'], ['slips' => [$mk()], 'amount' => '100'])->assertUnprocessable();
        $this->assertSame($dup->json('message'), $theirs->json('message'));
        $this->assertSame(1, DB::table('fin_payment_claims')->count());
        $this->assertSame(1, DB::table('fin_claim_slips')->count());
    }

    public function test_slip_upload_is_rate_limited_per_guardian(): void
    {
        $inv = $this->scene();
        for ($k = 0; $k < 10; $k++) $this->claim(1, $inv['id'], ['amount' => 'abc'], true)->assertUnprocessable(); // every attempt counts, also refused ones
        $this->claim(1, $inv['id'], ['amount' => '10'], true)->assertStatus(429);
        $this->assertSame(0, DB::table('fin_payment_claims')->count());
        $this->guardianLogin(9, 3); // another guardian has their own budget
        $theirs = $this->invoice(9)['invoice'];
        $this->guardianLogin(9, 3);
        $this->claim(9, $theirs['id'], ['amount' => '10'])->assertCreated();
    }

    public function test_idempotent_retry_of_a_claim_does_not_double_submit_and_holds_no_body(): void
    {
        $inv = $this->scene();
        $slip = $this->file(31);
        $h = ['Accept' => 'application/json', 'Idempotency-Key' => 'claim-key-0001'];
        $post = fn () => $this->post("/guardian/api/children/1/invoices/{$inv['id']}/claims", ['slips' => [$slip], 'amount' => '50', 'transferred_at' => $this->when(), 'account_id' => $this->acc], $h);
        $post()->assertCreated();
        $again = $post();
        $again->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('fin_payment_claims')->count());
        $this->assertSame(self::NUMBER, $again->json('invoices.0.accounts.0.account_number')); // rebuilt through the normal read path
        foreach (DB::table('cache')->pluck('value') as $v) $this->assertStringNotContainsString(self::NUMBER, (string) $v); // the cache holds no copy of the personal data
    }

    // ---- withdraw --------------------------------------------------------------------------------------------

    public function test_guardian_withdraws_a_pending_claim_until_finance_acts(): void
    {
        $inv = $this->scene();
        $this->claim(1, $inv['id'], ['amount' => '100', 'slips' => [$this->file(41)]])->assertCreated();
        $claim = DB::table('fin_payment_claims')->first();
        $path = DB::table('fin_claim_slips')->value('path');
        $this->postJson("/guardian/api/children/1/claims/{$claim->id}/withdraw", ['version' => 99])->assertStatus(409);
        $this->postJson("/guardian/api/children/1/claims/{$claim->id}/withdraw", ['version' => $claim->version])->assertOk()->assertJsonPath('invoices.0.claims.0.status', 'withdrawn');
        $this->assertSame('withdrawn', DB::table('fin_payment_claims')->value('status'));
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, DB::table('fin_claim_slips')->count());
        $this->postJson("/guardian/api/children/1/claims/{$claim->id}/withdraw", ['version' => $claim->version + 1])->assertUnprocessable();
        $this->assertTrue(DB::table('gp_events')->where('action', 'guardian.fee_claim.withdrawn')->exists());
        // the same file can be sent again after a withdrawal
        $again = $this->claim(1, $inv['id'], ['amount' => '100', 'slips' => [UploadedFile::fake()->createWithContent('a.png', $this->png(41))]])->assertCreated();
        // once finance approved, the guardian cannot take it back
        $c2 = DB::table('fin_payment_claims')->where('status', 'pending')->first();
        $this->finance()->postJson("/api/finance/claims/{$c2->id}/approve", ['version' => $c2->version])->assertOk();
        $this->guardianLogin(1, 1);
        $this->postJson("/guardian/api/children/1/claims/{$c2->id}/withdraw", ['version' => $c2->version + 1])->assertUnprocessable();
        $this->assertSame('approved', DB::table('fin_payment_claims')->where('id', $c2->id)->value('status'));
    }

    // ---- IDOR on claims and slips ----------------------------------------------------------------------------

    public function test_guardian_cannot_see_upload_withdraw_or_fetch_for_another_guardians_child(): void
    {
        $this->acc = $this->account()['id'];
        $mine = $this->invoice(1)['invoice'];
        $theirs = $this->invoice(9)['invoice'];
        $this->guardianLogin(9, 3);
        $this->claim(9, $theirs['id'], ['amount' => '100', 'slips' => [$this->file(51)]])->assertCreated();
        $cc = DB::table('fin_payment_claims')->first();
        $cs = DB::table('fin_claim_slips')->first();
        $this->get("/guardian/api/children/9/claims/{$cc->id}/slips/{$cs->id}")->assertOk();
        $this->guardianLogin(1, 1);
        $this->claim(9, $theirs['id'])->assertNotFound(); // upload for guardian C's child
        $this->claim(1, $theirs['id'])->assertNotFound(); // child 9's invoice through child 1's path
        $this->claim(1, 99999)->assertNotFound();
        $this->postJson("/guardian/api/children/9/claims/{$cc->id}/withdraw", ['version' => 1])->assertNotFound();
        $this->postJson("/guardian/api/children/1/claims/{$cc->id}/withdraw", ['version' => 1])->assertNotFound();
        $this->get("/guardian/api/children/9/claims/{$cc->id}/slips/{$cs->id}")->assertNotFound();
        $this->get("/guardian/api/children/1/claims/{$cc->id}/slips/{$cs->id}")->assertNotFound();
        $this->getJson('/guardian/api/children/9/fees')->assertNotFound();
        $this->assertSame('pending', DB::table('fin_payment_claims')->where('id', $cc->id)->value('status'));
        // own slip works; the slip id must belong to that claim
        $this->claim(1, $mine['id'], ['amount' => '100', 'slips' => [$this->file(52)]])->assertCreated();
        $my = DB::table('fin_payment_claims')->where('guardian_id', 1)->first();
        $mySlip = DB::table('fin_claim_slips')->where('claim_id', $my->id)->first();
        $res = $this->get("/guardian/api/children/1/claims/{$my->id}/slips/{$mySlip->id}")->assertOk();
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("sandbox", (string) $res->headers->get('Content-Security-Policy'));
        $this->get("/guardian/api/children/1/claims/{$my->id}/slips/{$cs->id}")->assertNotFound();
        // the portal overview of guardian A never lists guardian C's claim
        $this->assertSame([$my->id], array_column($this->getJson('/guardian/api/children/1/fees')->json('invoices.0.claims'), 'id'));
    }

    // ---- 4. finance verification queue ----------------------------------------------------------------------

    public function test_queue_lists_filters_and_opens_slips_through_an_authorised_controller(): void
    {
        $this->acc = $this->account()['id'];
        $i1 = $this->invoice(1)['invoice'];
        $i9 = $this->invoice(9)['invoice'];
        $room9 = (int) DB::table('students')->where('id', 9)->value('classroom_id');
        $this->guardianLogin(1, 1);
        $this->claim(1, $i1['id'], ['amount' => '100', 'slips' => [$this->file(61)]])->assertCreated();
        $this->guardianLogin(9, 3);
        $this->claim(9, $i9['id'], ['amount' => '200', 'slips' => [$this->file(62), $this->file(63)]])->assertCreated();
        $this->finance();
        $q = $this->getJson('/api/finance/claims')->assertOk();
        $this->assertSame(2, $q->json('total')); // default: pending, oldest first
        $this->assertSame([1, 9], array_column($q->json('data'), 'student_id'));
        $row = $q->json('data.0');
        $this->assertSame($i1['invoice_no'], $row['invoice_no']);
        $this->assertSame('ผู้ปกครองทดสอบ ก', $row['guardian_name']);
        $this->assertSame(1, $row['slip_count']);
        $this->assertStringNotContainsString(self::NUMBER, json_encode($q->json())); // the queue never needs the account number
        $this->assertSame(2, $this->getJson('/api/finance/claims?status=pending&from='.$this->today().'&to='.$this->today())->json('total'));
        $this->assertSame(0, $this->getJson('/api/finance/claims?from='.date('Y-m-d', strtotime('+2 day')))->json('total'));
        $this->assertSame(0, $this->getJson('/api/finance/claims?status=approved')->json('total'));
        $this->assertSame(2, $this->getJson('/api/finance/claims?status=all')->json('total'));
        $in = $this->getJson('/api/finance/claims?classroom_id='.$room9)->json('data');
        $this->assertContains(9, array_column($in, 'student_id'));
        $this->getJson('/api/finance/claims?status=bogus')->assertUnprocessable();
        $show = $this->getJson('/api/finance/claims/'.$row['id'])->assertOk();
        $url = $show->json('slips.0.url');
        $res = $this->get($url)->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->get('/api/finance/claims/'.$row['id'].'/slips/'.DB::table('fin_claim_slips')->where('claim_id', '!=', $row['id'])->value('id'))->assertNotFound(); // slip of another claim
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/finance/claims')->assertUnauthorized();
        $this->getJson($url)->assertUnauthorized();
        $this->as('teacher');
        $this->getJson('/api/finance/claims')->assertForbidden();
        $this->get($url)->assertForbidden();
        $this->postJson('/api/finance/claims/'.$row['id'].'/approve', ['version' => 1])->assertForbidden();
    }

    public function test_queue_list_query_count_does_not_grow_with_the_number_of_claims(): void
    {
        $this->acc = $this->account()['id'];
        $inv = $this->invoice(1)['invoice'];
        $this->guardianLogin(1, 1);
        $this->claim(1, $inv['id'], ['amount' => '10'])->assertCreated();
        $this->finance();
        $one = $this->queryCount(fn () => $this->getJson('/api/finance/claims')->assertOk()->assertJsonCount(1, 'data'));
        $this->guardianLogin(1, 1);
        for ($k = 0; $k < 4; $k++) $this->claim(1, $inv['id'], ['amount' => '10'])->assertCreated();
        $this->finance();
        $five = $this->queryCount(fn () => $this->getJson('/api/finance/claims')->assertOk()->assertJsonCount(5, 'data'));
        $this->assertSame($one, $five, "$one vs $five");
    }

    public function test_approve_records_the_payment_issues_the_receipt_and_notifies_the_guardian(): void
    {
        $inv = $this->scene();
        $this->claim(1, $inv['id'], ['amount' => '400', 'slips' => [$this->file(71)]])->assertCreated();
        $c = DB::table('fin_payment_claims')->first();
        $this->finance();
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version + 5])->assertStatus(409);
        $res = $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version], ['Idempotency-Key' => 'approve-0001'])->assertOk();
        $this->assertMatchesRegularExpression('/^RC-\d{4}-\d{6}$/', $res->json('payment.receipt_no')); // the existing receipt numbering
        $this->assertSame('transfer', $res->json('payment.method'));
        $this->assertSame(40000, $res->json('payment.amount_satang'));
        $this->assertSame('partial', $res->json('invoice.status'));
        $this->assertSame(40000, $res->json('invoice.paid_satang'));
        $row = DB::table('fin_payment_claims')->first();
        $this->assertSame('approved', $row->status);
        $this->assertSame((int) $res->json('payment.id'), (int) $row->payment_id);
        $this->assertSame(40000, (int) $row->approved_satang);
        $this->assertSame(1, DB::table('fin_ledger')->where('payment_id', $row->payment_id)->count());
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version], ['Idempotency-Key' => 'approve-0001'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('fin_payments')->count()); // the retry did not pay twice
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $row->version])->assertUnprocessable(); // already decided
        $this->postJson('/api/finance/claims/'.$c->id.'/reject', ['version' => $row->version, 'reason' => 'ลองปฏิเสธ'])->assertUnprocessable();
        $this->assertTrue(DB::table('audit_events')->where('action', 'finance.claim_approved')->exists());
        foreach (DB::table('audit_events')->pluck('detail') as $d) {
            $this->assertStringNotContainsString(self::NUMBER, (string) $d);
            $this->assertStringNotContainsString('finance/1/slips', (string) $d);
            $this->assertStringNotContainsString(DB::table('fin_claim_slips')->value('sha256'), (string) $d);
        }
        // the guardian is told in the portal and can open the receipt
        $n = DB::table('gp_notices')->first();
        $this->assertSame(1, (int) $n->guardian_id);
        $this->assertStringContainsString($res->json('payment.receipt_no'), $n->body);
        $this->assertStringNotContainsString(self::NUMBER, $n->title.$n->body);
        $this->guardianLogin(1, 1);
        $fees = $this->getJson('/guardian/api/children/1/fees')->assertOk();
        $this->assertSame(60000, $fees->json('balance_satang'));
        $this->assertSame('approved', $fees->json('invoices.0.claims.0.status'));
        $this->assertCount(1, $fees->json('notices'));
        $this->assertFalse($fees->json('notices.0.read'));
        $receipt = $fees->json('invoices.0.receipts.0');
        $page = $this->get('/guardian/api/children/1/receipts/'.$receipt['id'])->assertOk();
        $page->assertSee($receipt['receipt_no']);
        $page->assertDontSee(User::where('role', 'admin')->value('name') ?: 'NO-SUCH-NAME-XYZ'); // staff name hidden from the guardian
        $page->assertSee('เจ้าหน้าที่การเงิน');
        $this->postJson('/guardian/api/children/1/notices/read')->assertOk();
        $this->assertTrue($this->getJson('/guardian/api/children/1/fees')->json('notices.0.read'));
    }

    public function test_approve_can_pay_less_than_claimed_and_refuses_overpayment_and_closed_periods(): void
    {
        $inv = $this->scene();
        $this->claim(1, $inv['id'], ['amount' => '1000', 'slips' => [$this->file(81)]])->assertCreated();
        $c = DB::table('fin_payment_claims')->first();
        $this->finance();
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version, 'amount_satang' => 100001])->assertUnprocessable(); // more than the invoice
        $this->postJson('/api/finance/invoices/'.$inv['id'].'/payments', ['amount_satang' => 70000, 'method' => 'cash', 'paid_on' => $this->today()])->assertCreated();
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version])->assertUnprocessable(); // claimed 1,000 but only 300 is still owed: refused
        $this->assertSame('pending', DB::table('fin_payment_claims')->value('status'));
        $this->assertSame(1, DB::table('fin_payments')->count());
        DB::table('fin_periods')->insert(['school_id' => 1, 'period' => substr($this->today(), 0, 7), 'closed_at' => now(), 'closed_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version, 'amount_satang' => 30000])->assertUnprocessable(); // closed period
        DB::table('fin_periods')->delete();
        $ok = $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version, 'amount_satang' => 30000])->assertOk();
        $this->assertSame('paid', $ok->json('invoice.status'));
        $this->assertSame(30000, (int) DB::table('fin_payment_claims')->value('approved_satang'));
        $this->assertSame(100000, (int) DB::table('fin_invoices')->value('paid_satang'));
    }

    public function test_reject_needs_a_reason_notifies_the_guardian_and_frees_the_file(): void
    {
        $inv = $this->scene();
        $bytes = $this->png(91);
        $this->claim(1, $inv['id'], ['amount' => '100', 'slips' => [UploadedFile::fake()->createWithContent('s.png', $bytes)]])->assertCreated();
        $c = DB::table('fin_payment_claims')->first();
        $this->finance();
        $this->postJson('/api/finance/claims/'.$c->id.'/reject', ['version' => $c->version])->assertUnprocessable();
        $this->postJson('/api/finance/claims/'.$c->id.'/reject', ['version' => $c->version, 'reason' => 'x'])->assertUnprocessable();
        $this->postJson('/api/finance/claims/'.$c->id.'/reject', ['version' => 9, 'reason' => 'ยอดไม่ตรงกับสลิป'])->assertStatus(409);
        $this->postJson('/api/finance/claims/'.$c->id.'/reject', ['version' => $c->version, 'reason' => 'ยอดไม่ตรงกับสลิป'])->assertOk()->assertJsonPath('claim.status', 'rejected');
        $this->assertSame(0, DB::table('fin_payments')->count());
        $this->assertSame(100000, (int) DB::table('fin_invoices')->value('total_satang') - (int) DB::table('fin_invoices')->value('paid_satang'));
        $this->assertStringContainsString('ยอดไม่ตรงกับสลิป', DB::table('gp_notices')->value('body'));
        $this->assertTrue(DB::table('audit_events')->where('action', 'finance.claim_rejected')->exists());
        $this->guardianLogin(1, 1);
        $claim = $this->getJson('/guardian/api/children/1/fees')->json('invoices.0.claims.0');
        $this->assertSame('rejected', $claim['status']);
        $this->assertSame('ยอดไม่ตรงกับสลิป', $claim['reject_reason']);
        $this->assertFalse($claim['can_withdraw']);
        // a corrected claim with the same file is accepted again (rejection frees the hash)
        $this->claim(1, $inv['id'], ['amount' => '1000', 'slips' => [UploadedFile::fake()->createWithContent('s.png', $bytes)]])->assertCreated();
    }

    public function test_receipt_pages_are_limited_to_the_childs_valid_receipts(): void
    {
        $inv = $this->scene();
        $other = $this->invoice(9)['invoice'];
        $p1 = $this->postJson('/api/finance/invoices/'.$inv['id'].'/payments', ['amount_satang' => 1000, 'method' => 'cash', 'paid_on' => $this->today()])->assertCreated()->json('payment');
        $p9 = $this->postJson('/api/finance/invoices/'.$other['id'].'/payments', ['amount_satang' => 1000, 'method' => 'cash', 'paid_on' => $this->today()])->assertCreated()->json('payment');
        $this->guardianLogin(1, 1);
        $this->get('/guardian/api/children/1/receipts/'.$p1['id'])->assertOk()->assertSee($p1['receipt_no']);
        $this->get('/guardian/api/children/1/receipts/'.$p9['id'])->assertNotFound();
        $this->get('/guardian/api/children/9/receipts/'.$p9['id'])->assertNotFound();
        $this->finance()->postJson('/api/finance/payments/'.$p1['id'].'/void', ['version' => $p1['version'], 'reason' => 'บันทึกผิดรายการ'])->assertOk();
        $this->guardianLogin(1, 1);
        $this->get('/guardian/api/children/1/receipts/'.$p1['id'])->assertNotFound();
    }

    // ---- 5. staff enters a slip -----------------------------------------------------------------------------

    public function test_finance_attaches_a_slip_for_a_counter_payment_with_the_same_checks(): void
    {
        $this->acc = $this->account()['id'];
        $inv = $this->invoice(1)['invoice'];
        $url = '/api/finance/invoices/'.$inv['id'].'/claims';
        $send = fn (array $o = []) => $this->post($url, $o + ['amount_satang' => 30000, 'transferred_at' => $this->when(), 'account_id' => $this->acc, 'slips' => [$this->file()]], ['Accept' => 'application/json']);
        $this->finance();
        $send(['slips' => [UploadedFile::fake()->createWithContent('s.png', '<?php ?>')]])->assertUnprocessable();
        $send(['amount_satang' => 100001])->assertUnprocessable();
        $send(['transferred_at' => 'now'])->assertUnprocessable();
        $res = $send()->assertCreated();
        $this->assertSame('staff', $res->json('source'));
        $row = DB::table('fin_payment_claims')->first();
        $this->assertNull($row->guardian_id);
        $this->assertSame((int) User::where('role', 'admin')->value('id'), (int) $row->entered_by);
        $this->assertTrue(DB::table('audit_events')->where('action', 'finance.claim_created')->exists());
        $queue = $this->getJson('/api/finance/claims')->json('data.0');
        $this->assertSame('staff', $queue['source']);
        // a guardian cannot reuse a slip that finance already holds, and the answer is the neutral one
        $this->assertSame(1, DB::table('fin_claim_slips')->count());
        $dup = $send(['slips' => [UploadedFile::fake()->createWithContent('x.png', $this->png($this->seq + 100))]]); // the same bytes as the slip stored above
        $dup->assertUnprocessable();
        $this->assertSame(Slips::NEUTRAL, $dup->json('message'));
        // approving a staff-entered claim issues the receipt, no guardian notice because no guardian is attached
        $ok = $this->postJson('/api/finance/claims/'.$row->id.'/approve', ['version' => $row->version])->assertOk();
        $this->assertSame('partial', $ok->json('invoice.status'));
        $this->assertSame(0, DB::table('gp_notices')->count());
        $this->as('teacher');
        $send()->assertForbidden();
        $this->app['auth']->forgetGuards();
        $send()->assertUnauthorized();
    }

    // ---- 6. search and classroom summary ---------------------------------------------------------------------

    public function test_search_by_code_name_and_classroom_with_outstanding_totals_and_a_fixed_query_budget(): void
    {
        $this->finance();
        $a = $this->invoice(3, 70000)['invoice'];
        $this->postJson('/api/finance/invoices/'.$a['id'].'/payments', ['amount_satang' => 20000, 'method' => 'cash', 'paid_on' => $this->today()])->assertCreated();
        $this->invoice(3, 30000);
        $this->invoice(4, 10000);
        $find = fn (string $qs) => $this->getJson('/api/finance/students?'.$qs)->assertOk()->json('data');
        $n = $this->queryCount(fn () => $this->getJson('/api/finance/students?q='.urlencode('DEMO-03'))->assertOk());
        $this->assertLessThanOrEqual(3, $n);
        $byCode = $find('q='.urlencode('DEMO-03'));
        $this->assertCount(1, $byCode);
        $this->assertSame(80000, $byCode[0]['balance_satang']);
        $this->assertSame(2, $byCode[0]['open_invoices']);
        $byName = $find('q='.urlencode('ตัวอย่าง 03'));
        $this->assertSame('DEMO-03', $byName[0]['code']);
        // classroom: name prefix finds the whole room, a classroom_id filter combines with a name or code
        $nRoom = $this->queryCount(fn () => $this->getJson('/api/finance/students?q='.urlencode('ป.4/1'))->assertOk());
        $this->assertLessThanOrEqual(3, $nRoom);
        $room = $find('q='.urlencode('ป.4/1'));
        $this->assertSame((int) DB::table('students')->where('classroom_id', 1)->count(), count($room));
        $this->assertSame(['ป.4/1'], array_values(array_unique(array_column($room, 'classroom'))));
        $this->assertSame(10000, collect($room)->firstWhere('code', 'DEMO-04')['balance_satang']);
        $this->assertCount(count($room), $find('classroom_id=1'));
        $this->assertCount(1, $find('classroom_id=1&q='.urlencode('DEMO-03')));
        $owing = $find('classroom_id=1&only_owing=1');
        $this->assertSame(['DEMO-03', 'DEMO-04'], array_column($owing, 'code'));
        $this->assertSame([], $find('q='.urlencode('%')));
        $this->assertSame([], $find('q='.urlencode('DEMO_0')));
        $this->getJson('/api/finance/students')->assertUnprocessable();
    }

    public function test_classroom_outstanding_summary_is_one_grouped_query(): void
    {
        $this->finance();
        $a = $this->invoice(3, 70000)['invoice'];
        $this->postJson('/api/finance/invoices/'.$a['id'].'/payments', ['amount_satang' => 20000, 'method' => 'cash', 'paid_on' => $this->today()])->assertCreated();
        $this->invoice(4, 10000);
        $paid = $this->invoice(5, 5000)['invoice'];
        $this->postJson('/api/finance/invoices/'.$paid['id'].'/payments', ['amount_satang' => 5000, 'method' => 'cash', 'paid_on' => $this->today()])->assertCreated();
        $void = $this->invoice(6, 9999)['invoice'];
        $this->postJson('/api/finance/invoices/'.$void['id'].'/void', ['version' => $void['version'], 'reason' => 'ออกผิด'])->assertOk();
        $other = $this->invoice(9, 1)['invoice'];
        $n = $this->queryCount(fn () => $this->getJson('/api/finance/classrooms/outstanding')->assertOk());
        $this->assertLessThanOrEqual(3, $n);
        $res = $this->getJson('/api/finance/classrooms/outstanding')->json();
        $room1 = collect($res['data'])->firstWhere('id', 1);
        $room9 = (int) DB::table('students')->where('id', 9)->value('classroom_id');
        $expect1 = 50000 + 10000 + ($room9 === 1 ? 1 : 0);
        $this->assertSame($expect1, $room1['owed_satang']);
        $this->assertSame($room9 === 1 ? 3 : 2, $room1['owing_students']);
        $this->assertSame(array_sum(array_column($res['data'], 'owed_satang')), $res['total_owed_satang']);
        $this->assertSame(50000 + 10000 + 1, $res['total_owed_satang']); // paid and void invoices owe nothing
        $this->as('teacher')->getJson('/api/finance/classrooms/outstanding')->assertForbidden();
    }

    // ---- retention -------------------------------------------------------------------------------------------

    public function test_slip_retention_prune_deletes_old_decided_slips_only(): void
    {
        $inv = $this->scene();
        foreach ([201, 202, 203] as $s) $this->claim(1, $inv['id'], ['amount' => '10', 'slips' => [$this->file($s)]])->assertCreated();
        $claims = DB::table('fin_payment_claims')->orderBy('id')->get();
        $this->finance();
        $this->postJson('/api/finance/claims/'.$claims[0]->id.'/approve', ['version' => $claims[0]->version, 'confirm_duplicate' => true])->assertOk();
        $this->postJson('/api/finance/claims/'.$claims[1]->id.'/reject', ['version' => $claims[1]->version, 'reason' => 'ไม่ตรงกับยอดค้าง'])->assertOk();
        $paths = DB::table('fin_claim_slips')->orderBy('claim_id')->pluck('path', 'claim_id')->all();
        $old = now()->subYears(6)->format('Y-m-d H:i:s');
        DB::table('fin_payment_claims')->where('id', $claims[0]->id)->update(['decided_at' => $old]);
        DB::table('fin_payment_claims')->where('id', $claims[2]->id)->update(['created_at' => $old, 'updated_at' => $old]); // old but still pending: must stay
        $this->artisan('saan:finance-prune --dry-run')->assertSuccessful();
        Storage::disk('local')->assertExists($paths[$claims[0]->id]);
        Settings::put('finance.slip_retention_years', 0);
        $this->artisan('saan:finance-prune')->assertSuccessful();
        Storage::disk('local')->assertExists($paths[$claims[0]->id]); // 0 = keep forever
        Settings::put('finance.slip_retention_years', 10);
        $this->artisan('saan:finance-prune')->assertSuccessful();
        Storage::disk('local')->assertExists($paths[$claims[0]->id]);
        $this->artisan('saan:finance-prune --years=5')->assertSuccessful();
        Storage::disk('local')->assertMissing($paths[$claims[0]->id]); // approved 6 years ago
        Storage::disk('local')->assertExists($paths[$claims[1]->id]); // rejected today
        Storage::disk('local')->assertExists($paths[$claims[2]->id]); // pending is never pruned
        $this->assertSame(0, DB::table('fin_claim_slips')->where('claim_id', $claims[0]->id)->count());
        $this->assertSame('approved', DB::table('fin_payment_claims')->where('id', $claims[0]->id)->value('status')); // the claim, payment and receipt stay
        $this->assertSame(1, DB::table('fin_payments')->count());
        Settings::put('finance.slip_retention_years', 5);
        $this->assertSame(5, (int) \App\Support\Saan\Settings::get('finance.slip_retention_years'));
        $this->assertContains('saan:finance-prune', array_keys(\Illuminate\Support\Facades\Artisan::all()));
    }

    // ---- internal only ---------------------------------------------------------------------------------------

    public function test_nothing_of_f3_is_in_the_public_projection(): void
    {
        foreach (['fin_payment_accounts', 'fin_payment_claims', 'fin_claim_slips', 'fin_invoices', 'gp_notices'] as $t) $this->assertTrue(Projection::isForbiddenTable($t), $t);
        $declared = array_column(Projection::declared(false, true), 'table');
        foreach ($declared as $t) $this->assertDoesNotMatchRegularExpression('/^(fin_|gp_)/', $t);
        foreach (['finance', 'guardian-portal'] as $m) {
            $json = json_decode((string) file_get_contents(base_path("modules/$m/module.json")), true);
            $this->assertSame('internal', $json['edition'], $m);
            $this->assertArrayNotHasKey('public_projection', $json, $m);
        }
        $cfg = file_get_contents(base_path('config/saan_projection.php'));
        $this->assertStringNotContainsString("'fin_", $cfg);
        $this->assertStringNotContainsString("'gp_", $cfg);
    }

    public function test_fee_routes_are_not_registered_in_the_public_edition(): void
    {
        $paths = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();
        $this->assertContains('guardian/api/children/{id}/fees', $paths); // combined edition (tests) serves the internal surfaces
        $this->assertContains('api/finance/claims', $paths);
        $this->assertTrue(\App\Support\Saan\Edition::serves('guardian'));
        $this->assertFalse(in_array('finance', \App\Support\Saan\Edition::PUBLIC_ALLOWED, true));
        $this->assertFalse(in_array('guardian', \App\Support\Saan\Edition::PUBLIC_ALLOWED, true));
    }

    // ---- review round 2: regressions ---------------------------------------------------------------------------

    public function test_approve_refuses_more_than_the_slip_claims(): void
    {
        $inv = $this->scene();
        $this->claim(1, $inv['id'], ['amount' => '400', 'slips' => [$this->file(171)]])->assertCreated();
        $c = DB::table('fin_payment_claims')->first();
        $this->finance();
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version, 'amount_satang' => 100000])->assertUnprocessable(); // typed 4000 instead of 400
        $this->assertSame('pending', DB::table('fin_payment_claims')->value('status'));
        $this->assertSame(0, DB::table('fin_payments')->count());
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version, 'amount_satang' => 40001])->assertUnprocessable();
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version, 'amount_satang' => 40000])->assertOk();
        $this->assertSame(40000, (int) DB::table('fin_payment_claims')->value('approved_satang'));
    }

    public function test_approve_after_the_transfer_month_was_closed_receives_today_instead_of_failing(): void
    {
        $this->acc = $this->account()['id'];
        $first = Carbon::now('Asia/Bangkok')->subMonthNoOverflow()->startOfMonth();
        $inv = $this->finance()->postJson('/api/finance/invoices', ['student_id' => 1, 'issued_on' => $first->toDateString(), 'lines' => [['label' => 'ค่าบำรุงการศึกษา', 'amount_satang' => 100000]]])->assertCreated()->json('invoice');
        $this->guardianLogin(1, 1);
        $this->claim(1, $inv['id'], ['amount' => '1000', 'slips' => [$this->file(181)], 'transferred_at' => $first->addDays(9)->format('Y-m-d\TH:i')])->assertCreated();
        $c = DB::table('fin_payment_claims')->first();
        DB::table('fin_periods')->insert(['school_id' => 1, 'period' => $first->format('Y-m'), 'closed_at' => now(), 'closed_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->finance();
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version, 'paid_on' => $first->addDays(9)->toDateString()])->assertUnprocessable(); // explicit date inside the closed period
        $ok = $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version])->assertOk(); // no date: the closed month is skipped
        $this->assertSame($this->today(), (string) substr((string) $ok->json('payment.paid_on'), 0, 10));
        $this->assertSame('paid', $ok->json('invoice.status'));
    }

    public function test_approve_cache_holds_no_personal_data_and_replay_is_rebuilt(): void
    {
        $inv = $this->scene();
        $this->claim(1, $inv['id'], ['amount' => '400', 'slips' => [$this->file(191)]])->assertCreated();
        $c = DB::table('fin_payment_claims')->first();
        $res = $this->finance()->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version], ['Idempotency-Key' => 'approve-pii-0001'])->assertOk();
        $name = (string) DB::table('students')->where('id', 1)->value('name');
        $staff = (string) User::where('role', 'admin')->value('name');
        $this->assertNotSame('', $name);
        foreach (['snap_student_name', 'snap_address', 'received_by_name'] as $k) $this->assertArrayNotHasKey($k, $res->json('payment'));
        $store = \Illuminate\Support\Facades\Cache::getStore();
        $dump = json_encode((new \ReflectionProperty($store, 'storage'))->getValue($store), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('idem:', $dump); // the key was cached
        $this->assertStringNotContainsString($name, $dump);
        $this->assertStringNotContainsString('DEMO-01', $dump);
        if ($staff !== '') $this->assertStringNotContainsString($staff, $dump);
        $again = $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version], ['Idempotency-Key' => 'approve-pii-0001'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($res->json('payment.receipt_no'), $again->json('payment.receipt_no'));
        $this->assertSame(1, DB::table('fin_payments')->count());
    }

    public function test_receiving_accounts_can_be_changed_only_by_admin_or_principal(): void
    {
        $a = $this->account();
        $t = User::where('role', 'teacher')->first();
        Settings::put('finance.staff_user_ids', [$t->id]);
        $this->as('teacher');
        $this->getJson('/api/finance/accounts')->assertOk(); // finance staff may read
        $this->putJson('/api/finance/accounts/'.$a['id'], ['version' => $a['version'], 'account_number' => '5550001112'])->assertForbidden();
        $this->postJson('/api/finance/accounts', ['bank_code' => 'scb', 'account_name' => 'ทดสอบ', 'account_number' => '9998776654'])->assertForbidden();
        $this->post('/api/finance/accounts/'.$a['id'].'/qr', ['version' => $a['version'], 'qr' => $this->file(1)], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/finance/accounts/'.$a['id'].'/qr', ['version' => $a['version']])->assertForbidden();
        $this->assertSame(self::NUMBER, DB::table('fin_payment_accounts')->value('account_number'));
        $this->finance()->putJson('/api/finance/accounts/'.$a['id'], ['version' => $a['version'], 'account_name' => 'ชื่อใหม่ (ทดสอบ)', 'sort_order' => 3])->assertOk(); // the screen edits name, branch and order too
    }

    public function test_voiding_the_receipt_of_an_approved_claim_reverts_the_claim_frees_the_slip_and_tells_the_guardian(): void
    {
        $inv = $this->scene();
        $bytes = $this->png(201);
        $this->claim(1, $inv['id'], ['amount' => '400', 'slips' => [UploadedFile::fake()->createWithContent('s.png', $bytes)]])->assertCreated();
        $c = DB::table('fin_payment_claims')->first();
        $this->finance()->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version])->assertOk();
        $pay = DB::table('fin_payments')->first();
        $this->assertSame(1, DB::table('gp_notices')->count());
        $this->postJson('/api/finance/payments/'.$pay->id.'/void', ['version' => $pay->version, 'reason' => 'อนุมัติผิดรายการ'])->assertOk();
        $row = DB::table('fin_payment_claims')->first();
        $this->assertSame('voided', $row->status);
        $this->assertSame(0, DB::table('fin_claim_slips')->whereNotNull('dup_key')->count());
        $this->assertTrue(DB::table('audit_events')->where('action', 'finance.claim_voided')->exists());
        $this->assertSame(1, $this->getJson('/api/finance/claims?status=voided')->json('total'));
        $n = DB::table('gp_notices')->orderByDesc('id')->first();
        $this->assertSame(2, DB::table('gp_notices')->count());
        $this->assertStringContainsString('ยกเลิกใบเสร็จ', $n->title);
        $this->assertStringNotContainsString('อนุมัติผิดรายการ', (string) $n->body); // the internal reason stays internal
        $this->guardianLogin(1, 1);
        $fees = $this->getJson('/guardian/api/children/1/fees')->assertOk();
        $this->assertSame(100000, $fees->json('balance_satang'));
        $this->assertSame([], $fees->json('invoices.0.receipts'));
        $this->assertSame('voided', $fees->json('invoices.0.claims.0.status'));
        $this->assertFalse($fees->json('invoices.0.claims.0.can_withdraw'));
        // the same slip can be sent again, and the voided claim cannot be approved a second time
        $this->claim(1, $inv['id'], ['amount' => '400', 'slips' => [UploadedFile::fake()->createWithContent('s.png', $bytes)]])->assertCreated();
        $this->finance()->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $row->version])->assertUnprocessable();
    }

    public function test_counter_claims_notify_the_guardians_linked_to_the_student_on_approval_and_rejection(): void
    {
        $this->acc = $this->account()['id'];
        $inv = $this->invoice(1)['invoice'];
        DB::table('gp_links')->insert(['school_id' => 1, 'guardian_id' => 1, 'student_id' => 1, 'token_hash' => hash('sha256', 'link-a'), 'purpose' => 'invite', 'expires_at' => now()->addDay(),
            'used_at' => now(), 'revoked_at' => null, 'created_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gp_links')->insert(['school_id' => 1, 'guardian_id' => 2, 'student_id' => 1, 'token_hash' => hash('sha256', 'link-b'), 'purpose' => 'invite', 'expires_at' => now()->addDay(),
            'used_at' => now(), 'revoked_at' => now(), 'created_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]); // revoked: must not be told
        $send = fn () => $this->post('/api/finance/invoices/'.$inv['id'].'/claims', ['amount_satang' => 30000, 'transferred_at' => $this->when(), 'account_id' => $this->acc, 'slips' => [$this->file()]], ['Accept' => 'application/json'])->assertCreated();
        $this->finance();
        $send();
        $send();
        [$one, $two] = DB::table('fin_payment_claims')->orderBy('id')->get()->all();
        $this->postJson('/api/finance/claims/'.$one->id.'/approve', ['version' => $one->version, 'confirm_duplicate' => true])->assertOk();
        $this->assertSame([1], DB::table('gp_notices')->pluck('guardian_id')->map(fn ($v) => (int) $v)->all());
        $this->postJson('/api/finance/claims/'.$two->id.'/reject', ['version' => $two->version, 'reason' => 'สลิปไม่ชัดเจน'])->assertOk();
        $this->assertSame([1, 1], DB::table('gp_notices')->orderBy('id')->pluck('guardian_id')->map(fn ($v) => (int) $v)->all());
        $this->assertStringContainsString('สลิปไม่ชัดเจน', DB::table('gp_notices')->orderByDesc('id')->value('body'));
    }

    public function test_pdf_slips_are_screened_after_decoding_name_escapes_and_served_as_attachments(): void
    {
        $inv = $this->scene();
        $pdf = fn (string $body) => UploadedFile::fake()->createWithContent('bank.pdf', "%PDF-1.4\n1 0 obj<<$body>>endobj\ntrailer<<>>\n%%EOF");
        $this->claim(1, $inv['id'], ['slips' => [$pdf('/S/J#61vaScript/JS(app.alert(1))')], 'amount' => '10'])->assertUnprocessable(); // hex-escaped name
        $this->claim(1, $inv['id'], ['slips' => [$pdf('/S/#4Aava#53cript')], 'amount' => '10'])->assertUnprocessable();
        // a real bank PDF with an initial view is not refused
        $this->claim(1, $inv['id'], ['slips' => [$pdf('/Type/Catalog/OpenAction[3 0 R/Fit]/AA<<>>')], 'amount' => '10'])->assertCreated();
        $s = DB::table('fin_claim_slips')->first();
        $res = $this->finance()->get('/api/finance/claims/'.$s->claim_id.'/slips/'.$s->id)->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $res->headers->get('Content-Security-Policy'));
    }

    public function test_a_deadlock_that_survives_the_retries_becomes_409_and_approve_creates_the_counter_row_once_outside_the_payment(): void
    {
        try {
            \Saan\Modules\Finance\Support::guard(fn () => throw new \Illuminate\Database\DeadlockException('Deadlock found when trying to get lock'));
            $this->fail('expected a 409');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $inv = $this->scene();
        $this->claim(1, $inv['id'], ['amount' => '400', 'slips' => [$this->file(211)]])->assertCreated();
        $c = DB::table('fin_payment_claims')->first();
        $this->finance();
        $seen = [];
        DB::listen(function ($q) use (&$seen) { if (preg_match('/^insert (or )?ignore/i', $q->sql) && str_contains($q->sql, 'fin_counters')) $seen[] = $q->bindings; });
        $this->postJson('/api/finance/claims/'.$c->id.'/approve', ['version' => $c->version])->assertOk();
        $this->assertCount(1, $seen, 'one INSERT IGNORE of the receipt counter, issued before the transaction, not a second one inside it');
    }

    public function test_classroom_summary_counts_students_without_a_classroom(): void
    {
        $this->finance();
        DB::table('students')->where('id', 6)->update(['classroom_id' => null]);
        $this->invoice(6, 9999);
        $this->invoice(4, 10000);
        $res = $this->getJson('/api/finance/classrooms/outstanding')->assertOk()->json();
        $none = collect($res['data'])->firstWhere('name', 'ไม่ระบุห้อง');
        $this->assertNotNull($none);
        $this->assertNull($none['id']);
        $this->assertSame(9999, $none['owed_satang']);
        $this->assertSame(1, $none['owing_students']);
        $this->assertSame(9999 + 10000, $res['total_owed_satang']);
        $this->assertSame($res['total_owed_satang'], (int) DB::table('fin_invoices')->whereIn('status', ['open', 'partial'])->sum(DB::raw('total_satang - paid_satang')));
    }

    public function test_search_finds_a_classroom_named_with_ascii_digits_and_still_finds_codes(): void
    {
        $this->finance();
        $room = (int) DB::table('students')->where('code', 'DEMO-04')->value('classroom_id');
        $count = (int) DB::table('students')->where('classroom_id', $room)->count();
        $find = fn (string $q) => $this->getJson('/api/finance/students?q='.urlencode($q))->assertOk()->json('data');
        foreach (['1/2', 'M.1/2'] as $name) {
            DB::table('classrooms')->where('id', $room)->update(['name' => $name]);
            $this->assertCount($count, $find($name), $name);
            $this->assertSame([$name], array_values(array_unique(array_column($find($name), 'classroom'))));
        }
        $this->assertSame([], $find('7/9')); // no such room: falls back to a code search, nothing matches
        $this->assertSame('DEMO-04', $find('DEMO-04')[0]['code']);
    }

    // ---- audit fixes (2026-11-07) ----------------------------------------------------------------------------

    public function test_voiding_an_invoice_rejects_its_pending_claim_frees_the_slip_and_tells_the_guardian(): void
    {
        $inv = $this->scene();
        $file = fn () => UploadedFile::fake()->createWithContent('same.png', $this->png(77));
        $this->claim(1, $inv['id'], ['amount' => '100', 'slips' => [$file()]])->assertCreated();
        $claim = DB::table('fin_payment_claims')->first();
        $this->finance()->postJson('/api/finance/invoices/'.$inv['id'].'/void', ['version' => $inv['version'], 'reason' => 'ยอดผิด'])->assertOk();
        $row = DB::table('fin_payment_claims')->where('id', $claim->id)->first();
        $this->assertSame('rejected', $row->status);
        $this->assertStringContainsString('ใบแจ้งหนี้ถูกยกเลิก', (string) $row->reject_reason);
        $this->assertNull(DB::table('fin_claim_slips')->where('claim_id', $claim->id)->value('dup_key'), 'the slip hash is free again');
        $this->assertTrue(DB::table('audit_events')->where('action', 'finance.claim_rejected')->where('entity_id', $claim->id)->exists());
        // the same slip can go to the replacement invoice
        $new = $this->invoice(1);
        $this->guardianLogin(1, 1);
        $this->claim(1, $new['invoice']['id'], ['amount' => '100', 'slips' => [$file()]])->assertCreated();
    }

    public function test_approving_a_claim_that_looks_like_the_same_transfer_needs_a_confirmation(): void
    {
        $inv = $this->scene();
        $inv2 = $this->invoice(1)['invoice'];
        $this->guardianLogin(1, 1);
        $when = $this->when();
        $this->claim(1, $inv['id'], ['amount' => '1,000.00', 'transferred_at' => $when, 'slips' => [$this->file(61)]])->assertCreated();
        $this->claim(1, $inv2['id'], ['amount' => '1,000.00', 'transferred_at' => $when, 'slips' => [$this->file(62)]])->assertCreated(); // another file (different hash), same transfer
        $rows = DB::table('fin_payment_claims')->orderBy('id')->get();
        $this->assertNotSame($rows[0]->id, $rows[1]->id);
        $show = $this->finance()->getJson('/api/finance/claims/'.$rows[0]->id)->assertOk();
        $this->assertSame([(int) $rows[1]->id], $show->json('claim.possible_duplicates'));
        $this->postJson('/api/finance/claims/'.$rows[0]->id.'/approve', ['version' => $rows[0]->version])->assertUnprocessable();
        $this->assertSame('pending', DB::table('fin_payment_claims')->where('id', $rows[0]->id)->value('status'));
        $this->postJson('/api/finance/claims/'.$rows[0]->id.'/approve', ['version' => $rows[0]->version, 'confirm_duplicate' => true])->assertOk();
        $detail = (string) DB::table('audit_events')->where('action', 'finance.claim_approved')->value('detail');
        $this->assertStringContainsString('duplicate_confirmed_against', $detail);
    }
}