<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Saan\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Finance\Baht;
use Saan\Modules\Finance\Support;
use Tests\TestCase;

class FinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => true]);
        $this->seed();
        $this->today = CarbonImmutable::now('Asia/Bangkok')->toDateString();
    }

    private function user(string $role, array $extra = []): User
    {
        $this->n++;
        return User::forceCreate(['name' => 'Fictional '.$role.$this->n, 'email' => $role.$this->n.'@school.test', 'password' => 'Original-Password-2026', 'role' => $role,
            'school_id' => 1, 'is_active' => true, 'is_demo' => false, 'auth_version' => 1] + $extra);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u);
    }

    private function admin(): User
    {
        $u = $this->user('admin');
        $this->as($u);
        return $u;
    }

    private function fee(string $code = 'TUITION', int $amount = 100000, array $extra = []): int
    {
        return $this->postJson('/api/finance/fee-items', array_merge(['code' => $code, 'label' => 'ค่าบำรุงการศึกษา '.$code, 'default_amount_satang' => $amount], $extra))->assertCreated()->json('id');
    }

    private function sid(int $classroom = 1, int $i = 0): int
    {
        return (int) DB::table('students')->where('classroom_id', $classroom)->orderBy('id')->skip($i)->value('id');
    }

    private function invoice(int $student, int $amount = 100000, ?string $issued = null): array
    {
        return $this->postJson('/api/finance/invoices', ['student_id' => $student, 'issued_on' => $issued ?? $this->today, 'lines' => [['label' => 'ค่าบำรุง', 'amount_satang' => $amount]]])->assertCreated()->json();
    }

    private function pay(int $invoice, int $amount, ?string $on = null, array $extra = [], array $headers = [])
    {
        return $this->postJson('/api/finance/invoices/'.$invoice.'/payments', array_merge(['amount_satang' => $amount, 'method' => 'cash', 'paid_on' => $on ?? $this->today], $extra), $headers);
    }

    private function day(int $d, int $monthsAgo = 1): string
    {
        return CarbonImmutable::now('Asia/Bangkok')->startOfMonth()->subMonthsNoOverflow($monthsAgo)->day($d)->toDateString();
    }

    // ---- units: money and Thai baht text -----------------------------------------------------------------------

    public function test_baht_text_conversion(): void
    {
        $cases = [
            0 => 'ศูนย์บาทถ้วน', 100 => 'หนึ่งบาทถ้วน', 10000 => 'หนึ่งร้อยบาทถ้วน', 1100 => 'สิบเอ็ดบาทถ้วน', 1000 => 'สิบบาทถ้วน', 2000 => 'ยี่สิบบาทถ้วน',
            2100 => 'ยี่สิบเอ็ดบาทถ้วน', 10100 => 'หนึ่งร้อยเอ็ดบาทถ้วน', 12550 => 'หนึ่งร้อยยี่สิบห้าบาทห้าสิบสตางค์', 50 => 'ห้าสิบสตางค์', 1 => 'หนึ่งสตางค์',
            21 => 'ยี่สิบเอ็ดสตางค์', 150000 => 'หนึ่งพันห้าร้อยบาทถ้วน', 100000000 => 'หนึ่งล้านบาทถ้วน', 100000100 => 'หนึ่งล้านเอ็ดบาทถ้วน',
            123456789 => 'หนึ่งล้านสองแสนสามหมื่นสี่พันห้าร้อยหกสิบเจ็ดบาทแปดสิบเก้าสตางค์', 1100000 => 'หนึ่งหมื่นหนึ่งพันบาทถ้วน', 10100001 => 'หนึ่งแสนหนึ่งพันบาทหนึ่งสตางค์',
            -10000 => 'ลบหนึ่งร้อยบาทถ้วน',
        ];
        foreach ($cases as $satang => $text) $this->assertSame($text, Baht::text($satang), (string) $satang);
    }

    public function test_satang_arithmetic_has_no_float_drift(): void
    {
        $this->assertSame('1,234.56', Baht::format(123456));
        $this->assertSame('0.05', Baht::format(5));
        $this->assertSame('0.00', Baht::format(0));
        $this->assertSame('-12.30', Baht::format(-1230));
        $this->assertSame('1234.56', Baht::plain(123456));
        $this->assertSame(123450, Baht::toSatang('1,234.5'));
        $this->assertSame(7, Baht::toSatang('0.07'));
        $this->assertSame(30, Baht::toSatang('0.10') + Baht::toSatang('0.20'));
        $this->assertSame(1999 * 100 + 99, Baht::toSatang('1999.99'));
        foreach (['1.234', '1e3', '-5', '1,23', '', 'abc', '1.'] as $bad) {
            try { Baht::toSatang($bad); $this->fail('accepted '.$bad); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->admin();
        $f = $this->fee('LUNCH', 3333);
        $inv = $this->postJson('/api/finance/invoices', ['student_id' => $this->sid(), 'issued_on' => $this->today, 'lines' => [
            ['fee_item_id' => $f, 'amount_satang' => 100050, 'discount_satang' => 5025], ['label' => 'ค่ากิจกรรม', 'amount_satang' => 33],
        ]])->assertCreated()->json();
        $this->assertSame(100050 - 5025 + 33, $inv['invoice']['total_satang']);
        $this->assertIsInt($inv['invoice']['total_satang']);
        $this->assertSame('ค่าบำรุงการศึกษา LUNCH', $inv['lines'][0]['label']);
        $bad = fn (array $line) => $this->postJson('/api/finance/invoices', ['student_id' => $this->sid(), 'issued_on' => $this->today, 'lines' => [$line]])->assertUnprocessable();
        $bad(['label' => 'x', 'amount_satang' => 100, 'discount_satang' => 101]);
        $bad(['label' => 'x', 'amount_satang' => 10.5]);
        $bad(['label' => 'x', 'amount_satang' => 0]);
        $bad(['label' => 'x', 'amount_satang' => -5]);
    }

    // ---- document numbers --------------------------------------------------------------------------------------

    public function test_number_sequence_is_unique_per_fiscal_year_and_prefix_is_configurable(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) $seen[] = DB::transaction(fn () => Support::nextNumber('receipt', '2026-11-10'));
        $this->assertCount(200, array_unique($seen));
        $this->assertSame('RC-2570-000001', $seen[0]);
        $this->assertSame('RC-2570-000200', $seen[199]);
        $this->assertSame('RC-2569-000001', DB::transaction(fn () => Support::nextNumber('receipt', '2026-09-30')), 'fiscal year rolls on 1 October');
        $this->assertSame('INV-2570-000001', DB::transaction(fn () => Support::nextNumber('invoice', '2026-10-01')));
        Settings::put('finance.receipt_prefix', 'TTW');
        $this->assertSame('TTW-2570-000201', DB::transaction(fn () => Support::nextNumber('receipt', '2026-12-01')));
        $this->assertSame(1, DB::table('fin_counters')->where('scope', 'receipt')->where('fiscal_year', 2570)->count());
        $this->assertSame(2570, Support::fiscalYear('2026-10-01'));
        $this->assertSame(2569, Support::fiscalYear('2026-09-30'));
    }

    public function test_api_numbers_never_duplicate_and_a_retry_with_the_same_key_replays_one_receipt(): void
    {
        $this->admin();
        $student = $this->sid();
        $nos = [];
        for ($i = 0; $i < 30; $i++) $nos[] = $this->invoice($student, 1000)['invoice']['invoice_no'];
        $this->assertCount(30, array_unique($nos));
        $inv = $this->invoice($student, 50000)['invoice'];
        $key = ['Idempotency-Key' => 'pay-key-1'];
        $first = $this->pay($inv['id'], 20000, null, [], $key)->assertCreated();
        $retry = $this->pay($inv['id'], 20000, null, [], $key)->assertCreated();
        $retry->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('payment.receipt_no'), $retry->json('payment.receipt_no'));
        $this->assertSame(1, DB::table('fin_payments')->count());
        $this->assertSame(20000, (int) DB::table('fin_invoices')->where('id', $inv['id'])->value('paid_satang'));
        $this->pay($inv['id'], 5000)->assertCreated();
        $rcs = DB::table('fin_payments')->pluck('receipt_no')->all();
        $this->assertCount(2, array_unique($rcs));
        $this->assertSame(1, preg_match('/^RC-\d{4}-\d{6}$/', $rcs[0]));
    }

    public function test_idempotency_cache_keeps_no_student_name_and_a_replay_is_rebuilt(): void
    {
        $this->admin();
        $student = $this->sid();
        $name = (string) DB::table('students')->where('id', $student)->value('name');
        $this->assertNotSame('', $name);
        $mk = ['Idempotency-Key' => 'inv-key-0001'];
        $body = ['student_id' => $student, 'issued_on' => $this->today, 'lines' => [['label' => 'ค่าบำรุง', 'amount_satang' => 30000]]];
        $first = $this->postJson('/api/finance/invoices', $body, $mk)->assertCreated();
        $this->assertSame($name, $first->json('invoice.student_name'));
        $inv = $first->json('invoice');
        $pk = ['Idempotency-Key' => 'pay-key-0002'];
        $pay1 = $this->pay($inv['id'], 10000, null, [], $pk)->assertCreated();
        $store = \Illuminate\Support\Facades\Cache::getStore();
        $dump = json_encode((new \ReflectionProperty($store, 'storage'))->getValue($store), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('idem:', $dump);
        $this->assertStringNotContainsString($name, $dump); // neither the invoice answer nor the receipt snapshot sits in the cache
        $this->assertStringNotContainsString(trim(json_encode($name, JSON_UNESCAPED_UNICODE), '"'), $dump);
        $again = $this->postJson('/api/finance/invoices', $body, $mk)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($inv['invoice_no'], $again->json('invoice.invoice_no'));
        $this->assertSame(1, DB::table('fin_invoices')->count());
        $pay2 = $this->pay($inv['id'], 10000, null, [], $pk)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($pay1->json('payment.receipt_no'), $pay2->json('payment.receipt_no'));
        $this->assertSame(1, DB::table('fin_payments')->count());
    }

    public function test_unique_violation_becomes_409_not_500(): void
    {
        DB::table('fin_periods')->insert(['school_id' => 1, 'period' => '2026-01', 'version' => 1]);
        try {
            Support::guard(fn () => DB::table('fin_periods')->insert(['school_id' => 1, 'period' => '2026-01', 'version' => 1]));
            $this->fail('no exception');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // ---- receipts, voids ---------------------------------------------------------------------------------------

    public function test_void_keeps_the_number_reverses_the_cash_book_and_receipts_are_immutable(): void
    {
        $this->admin();
        $inv = $this->invoice($this->sid(), 100000)['invoice'];
        $p = $this->pay($inv['id'], 100000)->assertCreated()->json();
        $this->assertSame('paid', $p['invoice']['status']);
        $this->assertSame('หนึ่งพันบาทถ้วน', $p['baht_text']);
        $id = $p['payment']['id'];
        $this->assertContains($this->putJson('/api/finance/payments/'.$id, ['amount_satang' => 1])->status(), [404, 405]);
        $this->assertContains($this->deleteJson('/api/finance/payments/'.$id)->status(), [404, 405]);
        $this->postJson('/api/finance/payments/'.$id.'/void', ['version' => 1])->assertUnprocessable();
        $this->postJson('/api/finance/payments/'.$id.'/void', ['version' => 99, 'reason' => 'พิมพ์ผิด'])->assertConflict();
        $void = $this->postJson('/api/finance/payments/'.$id.'/void', ['version' => 1, 'reason' => 'รับเงินผิดคน'])->assertOk()->json();
        $this->assertSame('void', $void['payment']['status']);
        $this->assertSame($p['payment']['receipt_no'], $void['payment']['receipt_no']);
        $this->assertSame('open', $void['invoice']['status']);
        $this->assertSame(0, $void['invoice']['paid_satang']);
        $this->postJson('/api/finance/payments/'.$id.'/void', ['version' => 2, 'reason' => 'ซ้ำ'])->assertUnprocessable();
        $this->assertSame(1, DB::table('fin_payments')->count(), 'row is kept');
        $rows = DB::table('fin_ledger')->where('payment_id', $id)->orderBy('id')->get();
        $this->assertSame(['income', 'reversal'], $rows->pluck('kind')->all(), 'a void is a negative income entry, never an expense');
        $m = $this->getJson('/api/finance/ledger?from='.$this->today.'&to='.$this->today)->assertOk()->json();
        $this->assertSame(0, $m['income_satang']);
        $this->assertSame(0, $m['expense_satang']);
        $this->assertSame(100000, $m['reversal_satang']);
        $this->assertSame(0, $m['closing_satang'] - $m['opening_satang']);
        $csvRows = $this->get('/api/finance/ledger.csv?from='.$this->today.'&to='.$this->today)->assertOk()->streamedContent();
        $this->assertStringContainsString('-1000.00', $csvRows);
        $this->assertStringContainsString('ยกเลิกรายรับ', $csvRows);
        $this->assertSame(0, $rows->sum(fn ($r) => $r->kind === 'income' ? $r->amount_satang : -$r->amount_satang));
        $this->assertTrue(DB::table('audit_events')->where('action', 'finance.payment_voided')->exists());
        $again = $this->pay($inv['id'], 100000)->assertCreated()->json('payment.receipt_no');
        $this->assertNotSame($p['payment']['receipt_no'], $again, 'a new receipt gets a new number');
        $this->deleteJson('/api/finance/ledger/'.$rows[0]->id, ['version' => 1])->assertUnprocessable();
    }

    public function test_invoice_void_rules(): void
    {
        $this->admin();
        $inv = $this->invoice($this->sid(), 5000)['invoice'];
        $this->pay($inv['id'], 1000)->assertCreated();
        $this->postJson('/api/finance/invoices/'.$inv['id'].'/void', ['version' => 2, 'reason' => 'ยกเลิกทั้งใบ'])->assertUnprocessable();
        $other = $this->invoice($this->sid(1, 1), 5000)['invoice'];
        $v = $this->postJson('/api/finance/invoices/'.$other['id'].'/void', ['version' => 1, 'reason' => 'ออกซ้ำ'])->assertOk()->json();
        $this->assertSame('void', $v['invoice']['status']);
        $this->pay($other['id'], 100)->assertUnprocessable();
    }

    public function test_partial_payments_update_status_and_overpayment_is_rejected(): void
    {
        $this->admin();
        $inv = $this->invoice($this->sid(), 100000)['invoice'];
        $this->pay($inv['id'], 100001)->assertUnprocessable();
        $a = $this->pay($inv['id'], 30000, null, ['invoice_version' => 1])->assertCreated()->json();
        $this->assertSame('partial', $a['invoice']['status']);
        $this->assertSame(30000, $a['invoice']['paid_satang']);
        $this->pay($inv['id'], 10, null, ['invoice_version' => 1])->assertConflict();
        $this->pay($inv['id'], 70001)->assertUnprocessable();
        $b = $this->pay($inv['id'], 70000, null, ['method' => 'promptpay', 'reference' => 'REF-FICTIONAL-1'])->assertCreated()->json();
        $this->assertSame('paid', $b['invoice']['status']);
        $this->assertSame('promptpay', $b['payment']['method']);
        $this->pay($inv['id'], 1)->assertUnprocessable();
        $this->pay($inv['id'], 0)->assertUnprocessable();
        $this->pay($inv['id'], 1, null, ['method' => 'cheque'])->assertUnprocessable();
        $this->pay($inv['id'], 1, CarbonImmutable::now('Asia/Bangkok')->addDay()->toDateString())->assertUnprocessable();
        $this->postJson('/api/finance/payments/'.$a['payment']['id'].'/void', ['version' => 1, 'reason' => 'ทดสอบ'])->assertOk()->assertJsonPath('invoice.status', 'partial')->assertJsonPath('invoice.paid_satang', 70000);
    }

    // ---- closed periods ----------------------------------------------------------------------------------------

    public function test_closed_period_rejects_new_and_void_entries_until_reopened_by_admin(): void
    {
        $this->admin();
        $student = $this->sid();
        $when = $this->day(15);
        $period = substr($when, 0, 7);
        $inv = $this->invoice($student, 100000, $when)['invoice'];
        $p = $this->pay($inv['id'], 40000, $when)->assertCreated()->json('payment');
        $this->postJson('/api/finance/periods/'.$period.'/close')->assertOk()->assertJsonPath('period', $period);
        $this->postJson('/api/finance/periods/'.$period.'/close')->assertOk();
        $this->postJson('/api/finance/periods/bad/close')->assertUnprocessable();
        $this->assertSame(1, DB::table('fin_periods')->count());
        $this->postJson('/api/finance/invoices', ['student_id' => $student, 'issued_on' => $when, 'lines' => [['label' => 'x', 'amount_satang' => 100]]])->assertUnprocessable();
        $this->pay($inv['id'], 100, $when)->assertUnprocessable();
        $this->postJson('/api/finance/payments/'.$p['id'].'/void', ['version' => 1, 'reason' => 'ย้อนหลัง'])->assertUnprocessable();
        $this->postJson('/api/finance/ledger', ['entry_date' => $when, 'kind' => 'expense', 'category' => 'วัสดุ', 'amount_satang' => 100, 'description' => 'ซื้อของ'])->assertUnprocessable();
        $this->assertSame('valid', DB::table('fin_payments')->value('status'));
        $other = $this->invoice($this->sid(1, 1), 1000, $this->today)['invoice'];
        $this->pay($other['id'], 100, $this->today)->assertCreated(); // an open period still works
        // an unpaid invoice dated inside the closed period cannot be voided either
        DB::table('fin_periods')->where('period', $period)->update(['closed_at' => null]);
        $old = $this->invoice($this->sid(1, 2), 1000, $when)['invoice'];
        DB::table('fin_periods')->where('period', $period)->update(['closed_at' => now()]);
        $this->postJson('/api/finance/invoices/'.$old['id'].'/void', ['version' => 1, 'reason' => 'ย้อนหลัง'])->assertUnprocessable();
        $this->as($this->user('principal'))->postJson('/api/finance/periods/'.$period.'/reopen')->assertForbidden();
        $this->as($this->user('admin'))->postJson('/api/finance/periods/'.$period.'/reopen')->assertOk()->assertJsonPath('closed_at', null);
        $this->postJson('/api/finance/periods/'.$period.'/reopen')->assertUnprocessable();
        $this->postJson('/api/finance/payments/'.$p['id'].'/void', ['version' => 1, 'reason' => 'เปิดงวดแล้ว'])->assertOk();
        $this->as($this->user('principal'))->postJson('/api/finance/periods/'.$period.'/close')->assertOk();
        $this->getJson('/api/finance/periods')->assertOk()->assertJsonPath('data.0.period', $period);
    }

    // ---- bulk invoicing ----------------------------------------------------------------------------------------

    public function test_bulk_preview_commit_is_idempotent_per_student_and_honours_grade_levels(): void
    {
        $this->admin();
        DB::table('classrooms')->where('id', 1)->update(['grade_level' => 'ป.4']);
        DB::table('classrooms')->where('id', 2)->update(['grade_level' => 'ป.5']);
        $tuition = $this->fee('TUITION', 150000);
        $lunch = $this->fee('LUNCH', 80000, ['grade_levels' => ['ป.4']]);
        $items = [['fee_item_id' => $tuition], ['fee_item_id' => $lunch, 'amount_satang' => 75000]];
        $pv = $this->postJson('/api/finance/invoices/bulk/preview', ['classroom_id' => 1, 'items' => $items])->assertOk()->json();
        $this->assertSame(8, $pv['create_count']);
        $this->assertSame(8 * 225000, $pv['total_satang']);
        $this->assertSame(0, DB::table('fin_invoices')->count(), 'preview writes nothing');
        $this->postJson('/api/finance/invoices/bulk', ['classroom_id' => 1, 'items' => $items, 'issued_on' => $this->today, 'expected_count' => 7])->assertConflict();
        $this->assertSame(0, DB::table('fin_invoices')->count());
        $done = $this->postJson('/api/finance/invoices/bulk', ['classroom_id' => 1, 'items' => $items, 'issued_on' => $this->today, 'due_on' => $this->today, 'expected_count' => 8])->assertCreated()->json();
        $this->assertSame(8, $done['created']);
        $this->assertSame(8, DB::table('fin_invoices')->count());
        $this->assertSame(16, DB::table('fin_invoice_lines')->count());
        $this->assertCount(8, array_unique(DB::table('fin_invoices')->pluck('invoice_no')->all()));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'finance.invoices_generated')->count());
        $again = $this->postJson('/api/finance/invoices/bulk/preview', ['classroom_id' => 1, 'items' => $items])->assertOk()->json();
        $this->assertSame(0, $again['create_count']);
        $this->assertSame(['duplicate'], array_values(array_unique(array_column($again['rows'], 'skip'))));
        $g = $this->postJson('/api/finance/invoices/bulk', ['grade_level' => 'ป.5', 'items' => $items, 'issued_on' => $this->today, 'expected_count' => 8])->assertCreated()->json();
        $this->assertSame(8 * 150000, $g['total_satang'], 'lunch is restricted to ป.4');
        $none = $this->postJson('/api/finance/invoices/bulk/preview', ['classroom_id' => 3, 'items' => [['fee_item_id' => $lunch]]])->assertOk()->json();
        $this->assertSame(0, $none['create_count']);
        $this->assertSame('no_fee', $none['rows'][0]['skip']);
        $this->postJson('/api/finance/invoices/bulk/preview', ['items' => $items])->assertUnprocessable();
        $this->postJson('/api/finance/invoices/bulk/preview', ['classroom_id' => 1, 'items' => [['fee_item_id' => 9999]]])->assertUnprocessable();
        // a closed period refuses bulk generation dated inside it
        $when = $this->day(10);
        $this->postJson('/api/finance/periods/'.substr($when, 0, 7).'/close')->assertOk();
        $this->postJson('/api/finance/invoices/bulk', ['classroom_id' => 3, 'items' => [['fee_item_id' => $tuition]], 'issued_on' => $when, 'expected_count' => 8])->assertUnprocessable();
    }

    // ---- search budget -----------------------------------------------------------------------------------------

    public function test_student_search_stays_within_three_queries_and_escapes_wildcards(): void
    {
        $this->as($this->user('admin'));
        $sid = $this->sid(1, 2);
        $inv = $this->invoice($sid, 70000)['invoice'];
        $this->pay($inv['id'], 20000)->assertCreated();
        $count = function (string $url): array {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $json = $this->getJson($url)->assertOk()->json('data');
            return [$n, $json];
        };
        [$n, $rows] = $count('/api/finance/students?q='.urlencode('DEMO-03'));
        $this->assertLessThanOrEqual(3, $n);
        $this->assertCount(1, $rows);
        $this->assertSame(50000, $rows[0]['balance_satang']);
        $this->assertSame('ป.4/1', $rows[0]['classroom']);
        [$n, $rows] = $count('/api/finance/students?q='.urlencode('DEMO-0'));
        $this->assertLessThanOrEqual(3, $n);
        $this->assertCount(9, $rows);
        [$n, $rows] = $count('/api/finance/students?q='.urlencode('ตัวอย่าง 03'));
        $this->assertLessThanOrEqual(3, $n);
        $this->assertSame('DEMO-03', $rows[0]['code']);
        $this->assertSame([], $this->getJson('/api/finance/students?q='.urlencode('%'))->json('data'));
        $this->assertSame([], $this->getJson('/api/finance/students?q='.urlencode('DEMO_0'))->json('data'));
        $this->getJson('/api/finance/students')->assertUnprocessable();
        // a staff teacher (one extra settings lookup) stays within budget too
        $t = $this->user('teacher');
        Settings::put('finance.staff_user_ids', [$t->id]);
        $this->as($t);
        [$n, $rows] = $count('/api/finance/students?q='.urlencode('DEMO-03'));
        $this->assertLessThanOrEqual(3, $n);
        $this->assertCount(1, $rows);
    }

    // ---- roles -------------------------------------------------------------------------------------------------

    public function test_roles_and_finance_staff_setting(): void
    {
        $teacher = $this->user('teacher');
        $this->getJson('/api/finance/me')->assertUnauthorized();
        $this->as($teacher);
        $this->getJson('/api/finance/me')->assertOk()->assertJsonPath('can_access', false)->assertJsonPath('is_manager', false);
        foreach (['/api/finance/students?q=a', '/api/finance/invoices', '/api/finance/fee-items', '/api/finance/reports/daily', '/api/finance/ledger', '/api/finance/periods'] as $url) $this->getJson($url)->assertForbidden();
        $this->postJson('/api/finance/invoices', [])->assertForbidden();
        $this->getJson('/api/finance/settings')->assertForbidden();
        $this->as($this->user('editor'))->getJson('/api/finance/me')->assertForbidden();

        $this->as($this->user('admin'));
        $this->getJson('/api/finance/settings')->assertOk()->assertJsonPath('invoice_prefix', 'INV')->assertJsonPath('receipt_prefix', 'RC');
        $this->putJson('/api/finance/settings', ['staff_user_ids' => [999999]])->assertUnprocessable();
        $this->putJson('/api/finance/settings', ['receipt_prefix' => 'bad prefix!'])->assertUnprocessable();
        $this->putJson('/api/finance/settings', ['staff_user_ids' => [$teacher->id], 'receipt_prefix' => 'FEE'])->assertOk()->assertJsonPath('staff_user_ids.0', $teacher->id)->assertJsonPath('receipt_prefix', 'FEE');
        $inv = $this->invoice($this->sid(), 1000)['invoice'];

        $this->as($teacher);
        $this->getJson('/api/finance/me')->assertOk()->assertJsonPath('can_access', true)->assertJsonPath('is_manager', false);
        $this->getJson('/api/finance/invoices')->assertOk();
        $paid = $this->pay($inv['id'], 1000)->assertCreated()->json('payment');
        $this->assertStringStartsWith('FEE-', $paid['receipt_no']);
        $this->postJson('/api/finance/fee-items', ['code' => 'X', 'label' => 'x', 'default_amount_satang' => 1])->assertForbidden();
        $this->postJson('/api/finance/periods/2026-01/close')->assertForbidden();
        $this->putJson('/api/finance/settings', ['staff_user_ids' => []])->assertForbidden();
        $this->as($this->user('principal'));
        $this->getJson('/api/finance/invoices')->assertOk();
        $this->postJson('/api/finance/fee-items', ['code' => 'P1', 'label' => 'x', 'default_amount_satang' => 1])->assertCreated();
        $this->putJson('/api/finance/settings', ['staff_user_ids' => []])->assertForbidden();
        $this->assertTrue(DB::table('audit_events')->where('action', 'finance.settings_saved')->exists());
    }

    // ---- scoping -----------------------------------------------------------------------------------------------

    public function test_other_school_data_is_invisible_and_untouchable(): void
    {
        $this->admin();
        $foreign = DB::table('students')->insertGetId(['school_id' => 2, 'classroom_id' => 1, 'code' => 'OTHER-001', 'name' => 'นักเรียนโรงเรียนอื่น']);
        $inv = DB::table('fin_invoices')->insertGetId(['school_id' => 2, 'term_id' => 1, 'student_id' => $foreign, 'invoice_no' => 'INV-2570-000001', 'issued_on' => $this->today, 'status' => 'open', 'total_satang' => 5000, 'paid_satang' => 0, 'version' => 1]);
        $pay = DB::table('fin_payments')->insertGetId(['school_id' => 2, 'invoice_id' => $inv, 'receipt_no' => 'RC-2570-000001', 'paid_on' => $this->today, 'method' => 'cash', 'amount_satang' => 100, 'received_by' => 1, 'status' => 'valid', 'version' => 1]);
        DB::table('fin_fee_items')->insert(['school_id' => 2, 'code' => 'TUITION', 'code_key' => 'tuition', 'label' => 'x', 'default_amount_satang' => 1, 'version' => 1]);
        $this->assertSame([], $this->getJson('/api/finance/students?q=OTHER')->json('data'));
        $this->getJson('/api/finance/students/'.$foreign.'/account')->assertNotFound();
        $this->getJson('/api/finance/invoices/'.$inv)->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/finance/invoices')->json('total'));
        $this->pay($inv, 100)->assertNotFound();
        $this->postJson('/api/finance/payments/'.$pay.'/void', ['version' => 1, 'reason' => 'ทดสอบ'])->assertNotFound();
        $this->getJson('/api/finance/payments/'.$pay.'/receipt')->assertNotFound();
        $this->postJson('/api/finance/invoices/'.$inv.'/void', ['version' => 1, 'reason' => 'ทดสอบ'])->assertNotFound();
        $this->postJson('/api/finance/invoices', ['student_id' => $foreign, 'issued_on' => $this->today, 'lines' => [['label' => 'x', 'amount_satang' => 100]]])->assertNotFound();
        $this->fee('TUITION', 1); // the same code in another school is not a duplicate
        $this->assertCount(1, $this->getJson('/api/finance/fee-items')->json('data'));
        $this->assertSame(0, $this->getJson('/api/finance/reports/daily?date='.$this->today)->json('total_satang'));
        $this->assertSame(5000, (int) DB::table('fin_invoices')->where('id', $inv)->value('total_satang'));
    }

    // ---- fee items, reports, receipts ------------------------------------------------------------------------

    public function test_fee_item_codes_are_unique_case_insensitively_and_versioned(): void
    {
        $this->admin();
        $this->fee('Tuition', 100);
        $this->postJson('/api/finance/fee-items', ['code' => '  tuition ', 'label' => 'ซ้ำ', 'default_amount_satang' => 1])->assertConflict();
        $this->postJson('/api/finance/fee-items', ['code' => 'ค่าอาหาร', 'label' => 'a', 'default_amount_satang' => 1.5])->assertUnprocessable();
        $other = $this->fee('Food', 100);
        $this->putJson('/api/finance/fee-items/'.$other, ['version' => 1, 'code' => 'TUITION'])->assertConflict();
        $this->putJson('/api/finance/fee-items/'.$other, ['version' => 1, 'default_amount_satang' => 25000, 'is_active' => false])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('is_active', false);
        $this->putJson('/api/finance/fee-items/'.$other, ['version' => 1, 'label' => 'stale'])->assertConflict();
        $this->assertCount(1, $this->getJson('/api/finance/fee-items?active=1')->json('data'));
    }

    public function test_daily_summary_cash_book_and_csv_exports(): void
    {
        $this->admin();
        $a = $this->invoice($this->sid(1, 0), 100000)['invoice'];
        $b = $this->invoice($this->sid(1, 1), 50000)['invoice'];
        $this->pay($a['id'], 60000)->assertCreated();
        $this->pay($b['id'], 50000, null, ['method' => 'transfer'])->assertCreated();
        $gone = $this->pay($a['id'], 1000, null, ['method' => 'promptpay'])->assertCreated()->json('payment');
        $this->postJson('/api/finance/payments/'.$gone['id'].'/void', ['version' => 1, 'reason' => 'คีย์ผิด'])->assertOk();
        $d = $this->getJson('/api/finance/reports/daily?date='.$this->today)->assertOk()->json();
        $this->assertSame(60000, $d['methods']['cash']['total_satang']);
        $this->assertSame(50000, $d['methods']['transfer']['total_satang']);
        $this->assertSame(0, $d['methods']['promptpay']['count']);
        $this->assertSame(110000, $d['total_satang']);
        $this->assertSame(['count' => 1, 'total_satang' => 1000], $d['voided']);
        $csv = $this->get('/api/finance/reports/daily.csv?date='.$this->today)->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('600.00', $csv->getContent());
        $this->assertStringContainsString('ยกเลิก', $csv->getContent());
        $this->assertStringContainsString('เงินสด', $csv->getContent());
        $this->assertStringContainsString('โอนเงิน', $csv->getContent());
        $this->assertStringNotContainsString(',cash,', $csv->getContent());
        $this->assertStringNotContainsString(',transfer,', $csv->getContent());
        $this->postJson('/api/finance/ledger', ['entry_date' => $this->today, 'kind' => 'expense', 'category' => 'วัสดุสำนักงาน', 'amount_satang' => 25050, 'description' => '=HYPERLINK("x")', 'document_no' => 'EXP-1'])->assertCreated();
        $l = $this->getJson('/api/finance/ledger?from='.$this->today.'&to='.$this->today)->assertOk()->json();
        $this->assertSame(110000, $l['income_satang'], 'voided receipt nets income instead of inflating both sides');
        $this->assertSame(1000, $l['reversal_satang']);
        $this->assertSame(25050, $l['expense_satang']);
        $this->assertSame(110000 - 25050, $l['closing_satang']);
        $csv = $this->get('/api/finance/ledger.csv?from='.$this->today.'&to='.$this->today)->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('250.50', $csv);
        $manual = DB::table('fin_ledger')->whereNull('payment_id')->first();
        $this->deleteJson('/api/finance/ledger/'.$manual->id, ['version' => 9])->assertConflict();
        $this->deleteJson('/api/finance/ledger/'.$manual->id, ['version' => 1])->assertOk();
        $inv = $this->get('/api/finance/invoices.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($a['invoice_no'], $inv);
        $this->assertStringContainsString('400.00', $inv);
        $this->assertStringContainsString('ชำระบางส่วน', $inv);
        $this->assertStringContainsString('ชำระครบ', $inv);
        $this->assertStringNotContainsString(',partial,', $inv);
        $this->assertStringNotContainsString(',paid,', $inv);
        // a void invoice owes nothing: the outstanding column is 0.00, not total - paid
        $v = $this->invoice($this->sid(1, 2), 200000)['invoice'];
        $this->postJson('/api/finance/invoices/'.$v['id'].'/void', ['version' => 1, 'reason' => 'ออกผิด'])->assertOk();
        $line = collect(preg_split('/\r?\n/', $this->get('/api/finance/invoices.csv')->streamedContent()))->first(fn ($l) => str_contains($l, $v['invoice_no']));
        $this->assertStringContainsString('2000.00,0.00,0.00', $line);
    }

    public function test_account_balance_and_list_include_every_open_invoice_beyond_the_newest_100(): void
    {
        $this->admin();
        $sid = $this->sid();
        $first = $this->invoice($sid, 70000, $this->day(1, 3))['invoice']; // the oldest invoice stays open
        $termId = (int) DB::table('fin_invoices')->where('id', $first['id'])->value('term_id');
        $now = now();
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ['school_id' => 1, 'term_id' => $termId, 'student_id' => $sid, 'invoice_no' => 'BULK-'.$i, 'issued_on' => $this->day(2, 2), 'status' => 'paid',
                'total_satang' => 100, 'paid_satang' => 100, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('fin_invoices')->insert($rows);
        $acc = $this->getJson('/api/finance/students/'.$sid.'/account')->assertOk()->json();
        $this->assertSame(70000, $acc['balance_satang'], 'balance covers the invoice older than the newest 100');
        $this->assertContains($first['id'], array_column($acc['invoices'], 'id'));
        $this->assertCount(101, $acc['invoices'], 'every open invoice + at most 100 history rows');
        $this->assertSame($acc['balance_satang'], $this->getJson('/api/finance/students?q='.urlencode(DB::table('students')->where('id', $sid)->value('code')))->json('data.0.balance_satang'));
    }

    public function test_ensure_indexes_migration_restores_a_dropped_unique_key_and_is_idempotent(): void
    {
        $mig = require base_path('database/migrations/2026_11_10_100001_ensure_unique_indexes_and_key_collation.php');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('fin_payments', 'fin_payments_no'));
        $this->ddl(function () {
            \Illuminate\Support\Facades\Schema::table('fin_payments', fn ($t) => $t->dropUnique('fin_payments_no'));
            \Illuminate\Support\Facades\Schema::table('fin_counters', fn ($t) => $t->dropUnique('fin_counters_scope'));
        });
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasIndex('fin_payments', ['school_id', 'receipt_no'], 'unique'));
        $mig->up();
        $mig->up(); // second run changes nothing and does not fail
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('fin_payments', ['school_id', 'receipt_no'], 'unique'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('fin_counters', ['school_id', 'scope', 'fiscal_year'], 'unique'));
    }

    public function test_ledger_reversal_migration_relabels_legacy_void_rows(): void
    {
        $id = DB::table('fin_ledger')->insertGetId(['school_id' => 1, 'entry_date' => $this->today, 'kind' => 'expense', 'category' => 'ยกเลิกใบเสร็จ', 'amount_satang' => 500, 'description' => 'x', 'payment_id' => 99, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $manual = DB::table('fin_ledger')->insertGetId(['school_id' => 1, 'entry_date' => $this->today, 'kind' => 'expense', 'category' => 'ยกเลิกใบเสร็จ', 'amount_satang' => 500, 'description' => 'manual', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        (require base_path('modules/finance/database/migrations/2026_11_10_100002_finance_ledger_reversal_kind.php'))->up();
        $this->assertSame('reversal', DB::table('fin_ledger')->where('id', $id)->value('kind'));
        $this->assertSame('expense', DB::table('fin_ledger')->where('id', $manual)->value('kind'), 'a hand-typed expense is never touched');
    }

    // ---- review fixes ------------------------------------------------------------------------------------------

    public function test_guard_converts_only_unique_and_deadlock_errors(): void
    {
        $mk = function (string $state, int $code, string $msg) {
            $pdo = new \PDOException($msg);
            $pdo->errorInfo = [$state, $code, $msg];
            return new \Illuminate\Database\QueryException('mariadb', 'insert', [], $pdo);
        };
        $status = function (\Throwable $e) {
            try { Support::guard(function () use ($e) { throw $e; }); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $h) { return $h->getStatusCode(); } catch (\Throwable $o) { return 'raw'; }
            return null;
        };
        $this->assertSame(409, $status($mk('23000', 1062, "Duplicate entry 'x' for key 'a'")));
        $this->assertSame(409, $status($mk('23505', 7, 'duplicate key value')));
        $this->assertSame(409, $status($mk('HY000', 19, 'UNIQUE constraint failed: fin_periods.period')));
        $this->assertSame('raw', $status($mk('HY000', 19, 'NOT NULL constraint failed: fin_periods.period')));
        $this->assertSame('raw', $status($mk('23000', 1048, "Column 'period' cannot be null")));
        $this->assertSame('raw', $status($mk('23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails')));
        $this->assertSame(409, $status($mk('40001', 1213, 'Deadlock found when trying to get lock')));
        $this->assertSame(409, $status($mk('HY000', 1213, 'Deadlock')));
        try { Support::guard(fn () => DB::table('fin_periods')->insert(['school_id' => 1, 'period' => null, 'version' => 1])); $this->fail('no exception'); } catch (\Illuminate\Database\QueryException) { $this->addToAssertionCount(1); }
    }

    public function test_counter_rows_are_created_before_the_transaction_and_ensure_is_idempotent(): void
    {
        $this->admin();
        Support::ensureCounter('receipt', '2026-11-10');
        Support::ensureCounter('receipt', '2026-11-10');
        Support::ensureCounter('bulk');
        $this->assertSame(1, DB::table('fin_counters')->where('scope', 'receipt')->where('fiscal_year', 2570)->count());
        $this->assertSame(1, DB::table('fin_counters')->where('scope', 'bulk')->where('fiscal_year', 0)->count());
        $inv = $this->invoice($this->sid(), 1000)['invoice'];
        $this->assertTrue(DB::table('fin_counters')->where('scope', 'invoice')->exists(), 'created before the invoice number is issued');
        $this->pay($inv['id'], 500)->assertCreated();
        $this->postJson('/api/finance/periods/'.substr($this->day(5), 0, 7).'/close')->assertOk();
        $this->assertSame(1, DB::table('fin_counters')->where('scope', 'period')->count());
    }

    public function test_finance_staff_must_be_teachers_and_prefixes_must_differ(): void
    {
        $editor = $this->user('editor');
        $teacher = $this->user('teacher');
        $principal = $this->user('principal');
        $this->as($this->user('admin'));
        $ids = collect($this->getJson('/api/finance/settings')->assertOk()->json('candidates'))->pluck('id')->all();
        $this->assertContains($teacher->id, $ids);
        $this->assertNotContains($editor->id, $ids);
        $this->assertNotContains($principal->id, $ids);
        $this->putJson('/api/finance/settings', ['staff_user_ids' => [$editor->id]])->assertUnprocessable();
        $this->putJson('/api/finance/settings', ['staff_user_ids' => [$principal->id]])->assertUnprocessable();
        $this->assertSame([], Support::staffIds());
        $this->putJson('/api/finance/settings', ['staff_user_ids' => [$teacher->id]])->assertOk();
        $this->putJson('/api/finance/settings', ['invoice_prefix' => 'ABC', 'receipt_prefix' => 'abc'])->assertUnprocessable();
        $this->putJson('/api/finance/settings', ['receipt_prefix' => 'INV'])->assertUnprocessable(); // invoice prefix defaults to INV
        $this->putJson('/api/finance/settings', ['invoice_prefix' => 'BILL', 'receipt_prefix' => 'REC'])->assertOk();
        $this->assertSame('BILL', Support::prefix('invoice'));
    }

    public function test_invoice_list_is_scoped_to_the_current_term_unless_asked(): void
    {
        $this->admin();
        $student = $this->sid();
        $cur = $this->invoice($student, 1000)['invoice'];
        $term = (array) DB::table('academic_terms')->where('id', 1)->first();
        unset($term['id']);
        foreach (['name_key', 'label_key'] as $k) if (array_key_exists($k, $term)) $term[$k] = $term[$k].'-old';
        $term['year'] = (int) $term['year'] - 1;
        $oldTerm = DB::table('academic_terms')->insertGetId($term);
        $old = DB::table('fin_invoices')->insertGetId(['school_id' => 1, 'term_id' => $oldTerm, 'student_id' => $student, 'invoice_no' => 'INV-2569-000001', 'issued_on' => $this->day(3, 12), 'status' => 'open', 'total_satang' => 700, 'paid_satang' => 0, 'version' => 1]);
        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$cur['id']], $ids('/api/finance/invoices'));
        $this->assertSame([$old], $ids('/api/finance/invoices?term_id='.$oldTerm));
        $this->assertEqualsCanonicalizing([$cur['id'], $old], $ids('/api/finance/invoices?all_terms=1'));
        $this->assertStringNotContainsString('INV-2569-000001', $this->get('/api/finance/invoices.csv')->streamedContent());
        $this->assertStringContainsString('INV-2569-000001', $this->get('/api/finance/invoices.csv?all_terms=1')->streamedContent());
        // the student account page still lists every term
        $this->assertCount(2, $this->getJson('/api/finance/students/'.$student.'/account')->json('invoices'));
    }

    public function test_ledger_and_fee_creation_replay_with_the_same_idempotency_key(): void
    {
        $this->admin();
        $body = ['entry_date' => $this->today, 'kind' => 'expense', 'category' => 'วัสดุ', 'amount_satang' => 1234, 'description' => 'ซื้อกระดาษ'];
        $h = ['Idempotency-Key' => 'ledger-key-1'];
        $a = $this->postJson('/api/finance/ledger', $body, $h)->assertCreated();
        $b = $this->postJson('/api/finance/ledger', $body, $h)->assertCreated();
        $b->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, DB::table('fin_ledger')->whereNull('payment_id')->count());
        $fee = ['code' => 'ACT', 'label' => 'ค่ากิจกรรม', 'default_amount_satang' => 5000];
        $fh = ['Idempotency-Key' => 'fee-key-1'];
        $f1 = $this->postJson('/api/finance/fee-items', $fee, $fh)->assertCreated();
        $f2 = $this->postJson('/api/finance/fee-items', $fee, $fh)->assertCreated();
        $this->assertSame($f1->json('id'), $f2->json('id'));
        $this->assertSame(1, DB::table('fin_fee_items')->where('school_id', 1)->count());
        $this->postJson('/api/finance/fee-items', $fee)->assertConflict(); // a genuinely new request is still a duplicate code
    }

    public function test_bulk_only_skips_the_fee_items_already_billed(): void
    {
        $this->admin();
        $tuition = $this->fee('TUITION', 150000);
        $food = $this->fee('FOOD', 40000);
        $this->postJson('/api/finance/invoices/bulk', ['classroom_id' => 1, 'items' => [['fee_item_id' => $tuition]], 'issued_on' => $this->today, 'expected_count' => 8])->assertCreated();
        $both = [['fee_item_id' => $tuition], ['fee_item_id' => $food]];
        $pv = $this->postJson('/api/finance/invoices/bulk/preview', ['classroom_id' => 1, 'items' => $both])->assertOk()->json();
        $this->assertSame(8, $pv['create_count']);
        $this->assertSame(8 * 40000, $pv['total_satang']);
        $this->assertCount(1, $pv['rows'][0]['lines']);
        $this->assertSame($food, $pv['rows'][0]['lines'][0]['fee_item_id']);
        $this->postJson('/api/finance/invoices/bulk', ['classroom_id' => 1, 'items' => $both, 'issued_on' => $this->today, 'expected_count' => 8])->assertCreated()->assertJsonPath('created', 8);
        $this->assertSame(16, DB::table('fin_invoices')->count());
        $this->assertSame(1, DB::table('fin_invoice_lines')->where('fee_item_id', $tuition)->where('invoice_id', DB::table('fin_invoices')->where('student_id', $this->sid())->orderBy('id')->value('id'))->count());
        $again = $this->postJson('/api/finance/invoices/bulk/preview', ['classroom_id' => 1, 'items' => $both])->assertOk()->json();
        $this->assertSame(0, $again['create_count']);
        $this->assertSame(['duplicate'], array_values(array_unique(array_column($again['rows'], 'skip'))));
    }

    public function test_payment_cannot_be_dated_before_the_invoice(): void
    {
        $this->admin();
        $issued = $this->day(15);
        $inv = $this->invoice($this->sid(), 5000, $issued)['invoice'];
        $this->pay($inv['id'], 100, $this->day(10))->assertUnprocessable();
        $this->assertSame(0, DB::table('fin_payments')->count());
        $this->pay($inv['id'], 100, $issued)->assertCreated();
        $this->pay($inv['id'], 100, $this->day(20))->assertCreated();
    }

    public function test_inactive_classrooms_are_left_out_of_bulk_and_lookups(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('classrooms', 'is_active') || !\Illuminate\Support\Facades\Schema::hasColumn('classrooms', 'grade_level')) $this->markTestSkipped('academic columns missing');
        $this->admin();
        DB::table('classrooms')->whereIn('id', [1, 2])->update(['grade_level' => 'ป.4']);
        $tuition = $this->fee('TUITION', 1000);
        $this->assertSame(16, $this->postJson('/api/finance/invoices/bulk/preview', ['grade_level' => 'ป.4', 'items' => [['fee_item_id' => $tuition]]])->json('create_count'));
        DB::table('classrooms')->where('id', 2)->update(['is_active' => false]);
        $this->assertSame(8, $this->postJson('/api/finance/invoices/bulk/preview', ['grade_level' => 'ป.4', 'items' => [['fee_item_id' => $tuition]]])->json('create_count'));
        $this->assertCount(2, $this->getJson('/api/finance/lookups')->json('classrooms'));
    }

    public function test_printable_receipt_escapes_values_and_marks_void(): void
    {
        $this->admin();
        $theme = ['school_name' => 'โรงเรียนต้นธารวิทยา', 'address' => '99 หมู่ 1 ที่ตั้งสมมติ <i>x</i>'];
        DB::table('cms_themes')->updateOrInsert(['school_id' => 1], ['draft' => json_encode($theme), 'published' => json_encode($theme), 'version' => 1, 'published_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sid = $this->sid();
        DB::table('students')->where('id', $sid)->update(['name' => 'เด็ก <script>alert(1)</script>']);
        $inv = $this->invoice($sid, 12550)['invoice'];
        $p = $this->pay($inv['id'], 12550, null, ['reference' => '<b>ref</b>'])->assertCreated()->json('payment');
        $html = $this->get('/api/finance/payments/'.$p['id'].'/receipt')->assertOk()->getContent();
        $this->assertStringContainsString($p['receipt_no'], $html);
        $this->assertStringContainsString('หนึ่งร้อยยี่สิบห้าบาทห้าสิบสตางค์', $html);
        $this->assertStringContainsString('125.50', $html);
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('<h1>โรงเรียนต้นธารวิทยา</h1>', $html);
        $this->assertStringContainsString('99 หมู่ 1 ที่ตั้งสมมติ &lt;i&gt;x&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('สาน (Saan)', $html);
        $this->assertStringContainsString('เงินสด', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<b>ref</b>', $html);
        $this->assertStringNotContainsString('class="void"', $html);
        $this->postJson('/api/finance/payments/'.$p['id'].'/void', ['version' => 1, 'reason' => 'ทดสอบ'])->assertOk();
        $this->assertStringContainsString('class="void"', $this->get('/api/finance/payments/'.$p['id'].'/receipt')->getContent());
    }

    public function test_lookups_and_work_shell_menu(): void
    {
        $this->admin();
        $l = $this->getJson('/api/finance/lookups')->assertOk()->json();
        $this->assertCount(3, $l['classrooms']);
        $this->assertSame(1, $l['current_term_id']);
        $page = $this->get('/work')->assertOk()->getContent();
        $this->assertStringContainsString('assets/modules/finance/finance.js', $page);
        $this->assertStringContainsString('#finance', $page);
    }

    public function test_account_and_invoice_detail(): void
    {
        $this->admin();
        $sid = $this->sid();
        $inv = $this->invoice($sid, 30000)['invoice'];
        $this->pay($inv['id'], 10000)->assertCreated();
        $acc = $this->getJson('/api/finance/students/'.$sid.'/account')->assertOk()->json();
        $this->assertSame(20000, $acc['balance_satang']);
        $this->assertCount(1, $acc['payments']);
        $d = $this->getJson('/api/finance/invoices/'.$inv['id'])->assertOk()->json();
        $this->assertCount(1, $d['lines']);
        $this->assertSame('partial', $d['invoice']['status']);
        $this->assertSame(1, $this->getJson('/api/finance/invoices?status=partial&student_id='.$sid)->json('total'));
        $this->assertSame(0, $this->getJson('/api/finance/invoices?status=paid')->json('total'));
        $this->getJson('/api/finance/invoices?status=bogus')->assertUnprocessable();
    }

    public function test_printed_receipt_is_never_clipped_and_reprint_uses_the_snapshot(): void
    {
        $this->admin();
        $theme = ['school_name' => 'โรงเรียนเดิม', 'address' => 'ที่อยู่เดิม'];
        DB::table('cms_themes')->updateOrInsert(['school_id' => 1], ['draft' => json_encode($theme), 'published' => json_encode($theme), 'version' => 1, 'published_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sid = $this->sid();
        $inv = $this->invoice($sid, 100000)['invoice'];
        $p = $this->pay($inv['id'], 100000)->assertCreated()->json('payment');
        $room = DB::table('students')->join('classrooms', 'classrooms.id', '=', 'students.classroom_id')->where('students.id', $sid)->value('classrooms.name');
        $this->assertSame('โรงเรียนเดิม', DB::table('fin_payments')->where('id', $p['id'])->value('snap_school_name'));
        // promotion, student rename and a school rename after payment must not rewrite the old receipt
        DB::table('students')->where('id', $sid)->update(['name' => 'ชื่อใหม่หลังจบปี', 'classroom_id' => 2]);
        $new = ['school_name' => 'โรงเรียนชื่อใหม่', 'address' => 'ที่อยู่ใหม่'];
        DB::table('cms_themes')->where('school_id', 1)->update(['published' => json_encode($new)]);
        $html = $this->get('/api/finance/payments/'.$p['id'].'/receipt')->assertOk()->getContent();
        $this->assertStringContainsString('โรงเรียนเดิม', $html);
        $this->assertStringContainsString('ที่อยู่เดิม', $html);
        $this->assertStringNotContainsString('ชื่อใหม่หลังจบปี', $html);
        $this->assertStringNotContainsString('โรงเรียนชื่อใหม่', $html);
        if ($room) $this->assertStringContainsString('ชั้น '.$room, $html);
        // a legacy row without a snapshot falls back to live data
        DB::table('fin_payments')->where('id', $p['id'])->update(['snap_student_name' => null, 'snap_school_name' => null]);
        $this->assertStringContainsString('ชื่อใหม่หลังจบปี', $this->get('/api/finance/payments/'.$p['id'].'/receipt')->getContent());
        // money documents are not clipped: no fixed height together with overflow:hidden in the print CSS
        preg_match('/@media print\{(.*?)\n\}/s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('overflow:hidden', $m[1]);
        $this->assertDoesNotMatchRegularExpression('/\.sheet\{[^}]*[^-]height:148mm/', $m[1]);
        $this->assertStringContainsString('overflow:visible', $m[1]);
    }

    public function test_cash_book_totals_cover_the_whole_range_and_csv_streams_every_row(): void
    {
        $this->admin();
        $rows = [];
        for ($i = 0; $i < 5100; $i++) {
            $rows[] = ['school_id' => 1, 'entry_date' => $this->today, 'kind' => $i % 2 ? 'expense' : 'income', 'category' => 'ทดสอบ', 'amount_satang' => 100, 'description' => 'row '.$i, 'document_no' => null, 'payment_id' => null, 'created_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $c) DB::table('fin_ledger')->insert($c);
        $l = $this->getJson('/api/finance/ledger?from='.$this->today.'&to='.$this->today)->assertOk()->json();
        $this->assertSame(2550 * 100, $l['income_satang']);
        $this->assertSame(2550 * 100, $l['expense_satang']);
        $this->assertSame(5100, $l['total_rows']);
        $this->assertTrue($l['truncated']);
        $this->assertCount(5000, $l['data']);
        $csv = $this->get('/api/finance/ledger.csv?from='.$this->today.'&to='.$this->today)->assertOk();
        $body = $csv->streamedContent();
        $this->assertSame(5100, count(array_filter(preg_split('/\r?\n/', $body))) - 1, 'every row is exported, none cut off');
        $inv = $this->get('/api/finance/invoices.csv')->assertOk();
        $this->assertStringContainsString('text/csv', $inv->headers->get('Content-Type'));
    }

    public function test_cash_book_entry_form_keeps_one_key_and_locks_the_button_while_saving(): void
    {
        $js = file_get_contents(public_path('assets/modules/finance/finance.js'));
        $this->assertStringContainsString('let entryKey = window.SaanCore.uid()', $js);
        $this->assertStringContainsString("idempotencyKey: entryKey, body: {entry_date", $js);
        $this->assertStringContainsString('entryKey = window.SaanCore.uid(); toast', $js, 'the key rotates only after a successful save');
        $this->assertMatchesRegularExpression("/fin-entry'\)\.addEventListener\('submit'.*?btn\.disabled = true;.*?finally \{ if \(btn\) btn\.disabled = false; \}/s", $js);
    }
}
