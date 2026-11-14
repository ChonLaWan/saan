<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\ApiGateway\Net;
use Tests\TestCase;

class ApiGatewayModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.demo' => false, 'school.local_only' => false]);
        $this->seed();
    }

    private function staff(string $role): static
    {
        config(['school.demo' => true]); // demo users only work on localhost with demo on; tests run from 127.0.0.1
        return $this->actingAs(User::where('role', $role)->first());
    }

    /** @return array{0:object,1:string} client row and its plain secret */
    private function client(array $scopes, array $extra = []): array
    {
        $secret = 'secret-'.bin2hex(random_bytes(8));
        $id = DB::table('api_clients')->insertGetId($extra + [
            'school_id' => 1, 'name' => 'App '.bin2hex(random_bytes(3)), 'name_key' => 'app '.bin2hex(random_bytes(3)),
            'client_id' => 'cl_'.bin2hex(random_bytes(6)), 'secret_hash' => Hash::make($secret), 'scopes' => json_encode($scopes),
            'allowed_ips' => null, 'redirect_uris' => json_encode(['https://learn.example.org/callback']), 'rate_per_minute' => 120,
            'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [DB::table('api_clients')->find($id), $secret];
    }

    private function basic(object $c, string $secret): array
    {
        return ['Authorization' => 'Basic '.base64_encode($c->client_id.':'.$secret)];
    }

    private function bearer(object $c, string $secret, array $extra = []): string
    {
        return $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'] + $extra, $this->basic($c, $secret))->assertOk()->json('access_token');
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    // ---- client_credentials -------------------------------------------------------------------------------------

    public function test_client_credentials_flow_basic_and_post_and_logging(): void
    {
        [$c, $s] = $this->client(['students:read:basic', 'classrooms:read']);
        $res = $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, $s))->assertOk()
            ->assertJsonPath('token_type', 'Bearer')->assertJsonPath('expires_in', 3600)->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $token = $res->json('access_token');
        $this->assertSame(64, strlen(DB::table('api_tokens')->value('token_hash')));
        $this->assertNotSame($token, DB::table('api_tokens')->value('token_hash')); // only the hash is stored

        $post = $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials', 'client_id' => $c->client_id, 'client_secret' => $s, 'scope' => 'classrooms:read'])->assertOk();
        $this->assertSame('classrooms:read', $post->json('scope'));

        $list = $this->getJson('/api/v1/students?limit=5', $this->auth($token))->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame('DEMO-01', $list->json('data.0.code'));
        $this->assertNotNull($list->json('next_cursor'));
        $this->getJson('/api/v1/classrooms', $this->auth($token))->assertOk()->assertJsonCount(3, 'data');
        $this->assertGreaterThanOrEqual(2, DB::table('api_request_log')->where('client_id', $c->id)->count());
        $this->assertSame('api/v1/students', DB::table('api_request_log')->where('client_id', $c->id)->where('route', 'api/v1/students')->value('route'));
        $this->assertNotNull(DB::table('api_clients')->where('id', $c->id)->value('last_used_at'));
        // wrong grant type, scope not granted
        $this->postJson('/api/v1/token', ['grant_type' => 'password'], $this->basic($c, $s))->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials', 'scope' => 'teachers:read:basic'], $this->basic($c, $s))->assertStatus(400)->assertJsonPath('error', 'invalid_scope');
    }

    public function test_wrong_secret_unknown_client_and_inactive_client_get_the_same_401(): void
    {
        [$c, $s] = $this->client(['students:read:basic']);
        $bad = $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, 'nope'))->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        $this->assertNotNull($bad->headers->get('WWW-Authenticate'));
        $unknown = $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], ['Authorization' => 'Basic '.base64_encode('cl_missing:nope')])->assertStatus(401);
        $this->assertSame($bad->json(), $unknown->json());
        DB::table('api_clients')->where('id', $c->id)->update(['is_active' => false]);
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, $s))->assertStatus(401);
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'])->assertStatus(401);
        $this->assertSame(0, DB::table('api_tokens')->count());
        $this->getJson('/api/v1/students')->assertStatus(401);
        $this->getJson('/api/v1/students', $this->auth('saan_'.str_repeat('a', 64)))->assertStatus(401)->assertJsonPath('error', 'invalid_token');
    }

    public function test_repeated_wrong_secrets_are_throttled(): void
    {
        [$c] = $this->client(['students:read:basic']);
        for ($i = 0; $i < 10; $i++) $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, 'nope'.$i))->assertStatus(401);
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, 'nope'))->assertStatus(429)->assertHeader('Retry-After');
        // the 401s are logged, the rate-limited requests are not: repeating them must not add log rows
        $logged = DB::table('api_request_log')->where('status', 401)->count();
        $this->assertSame(10, $logged);
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, 'nope'))->assertStatus(429);
        $this->assertSame(0, DB::table('api_request_log')->where('status', 429)->count());
        $this->assertSame($logged, DB::table('api_request_log')->count());
    }

    // ---- scopes and field filtering -----------------------------------------------------------------------------

    public function test_scope_filters_fields_and_forbidden_data_never_leaks(): void
    {
        DB::table('students')->where('id', 1)->update(['birth_date' => '2015-03-04', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'prefix' => 'เด็กชาย', 'nickname' => 'ชาย', 'external_ref' => 'SECRET-REF-9']);
        [$c, $s] = $this->client(['students:read:basic']);
        $token = $this->bearer($c, $s);
        $body = $this->getJson('/api/v1/students?classroom=1', $this->auth($token))->assertOk();
        $row = $body->json('data.0');
        $this->assertSame(['classroom', 'code', 'display_name', 'id', 'status'], collect(array_keys($row))->sort()->values()->all());
        $this->assertSame('เด็กชายสมชาย ใจดี', $row['display_name']);
        $this->assertSame(['id' => 1, 'name' => 'ป.4/1'], $row['classroom']);
        $raw = $body->getContent();
        foreach (['2015-03-04', 'birth_date', 'SECRET-REF-9', 'external_ref', 'nickname', 'pin', 'school_id', 'code_key', 'contact'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, $forbidden);
        }
        // other scopes are refused, not silently empty
        $this->getJson('/api/v1/classrooms', $this->auth($token))->assertStatus(403)->assertJsonPath('error', 'insufficient_scope');
        $this->getJson('/api/v1/teachers', $this->auth($token))->assertStatus(403);
        $this->getJson('/api/v1/me', $this->auth($token))->assertStatus(403);
        // client scopes shrink after the token was minted -> token loses them at once
        DB::table('api_clients')->where('id', $c->id)->update(['scopes' => json_encode(['classrooms:read'])]);
        $this->getJson('/api/v1/students', $this->auth($token))->assertStatus(403);
    }

    public function test_contact_scope_adds_only_consented_guardian_fields(): void
    {
        [$c, $s] = $this->client(['students:read:basic', 'students:read:contact']);
        $token = $this->bearer($c, $s);
        // registry has no consent flag yet: contact is present but empty, nothing is exposed
        $row = $this->getJson('/api/v1/students?classroom=1&limit=1', $this->auth($token))->assertOk()->json('data.0');
        $this->assertSame([], $row['contact']);

        $this->ddl(fn () => Schema::table('reg_student_guardians', fn ($t) => $t->boolean('share_contact_with_apps')->default(false)));
        $g1 = DB::table('reg_guardians')->insertGetId(['school_id' => 1, 'display_name' => 'สมหญิง ใจดี', 'phone_enc' => 'ENC-SECRET-PHONE', 'email' => 'mom@example.org', 'created_at' => now(), 'updated_at' => now()]);
        $g2 = DB::table('reg_guardians')->insertGetId(['school_id' => 1, 'display_name' => 'ไม่ยินยอม ลับ', 'email' => 'private@example.org', 'created_at' => now(), 'updated_at' => now()]);
        $link = ['school_id' => 1, 'student_id' => 1, 'relationship' => 'mother', 'is_primary' => 1, 'created_at' => now(), 'updated_at' => now()];
        DB::table('reg_student_guardians')->insert($link + ['guardian_id' => $g1, 'share_contact_with_apps' => 1]);
        DB::table('reg_student_guardians')->insert(array_merge($link, ['guardian_id' => $g2, 'share_contact_with_apps' => 0, 'is_primary' => 0]));
        $res = $this->getJson('/api/v1/students?classroom=1&limit=1', $this->auth($token))->assertOk();
        $this->assertSame([['display_name' => 'สมหญิง ใจดี', 'relationship' => 'mother', 'is_primary' => true, 'email' => 'mom@example.org']], $res->json('data.0.contact'));
        foreach (['ENC-SECRET-PHONE', 'phone', 'private@example.org', 'ไม่ยินยอม'] as $leak) $this->assertStringNotContainsString($leak, $res->getContent());
        // a basic-only token never gets the contact key
        [$c2, $s2] = $this->client(['students:read:basic']);
        $this->assertArrayNotHasKey('contact', $this->getJson('/api/v1/students?limit=1', $this->auth($this->bearer($c2, $s2)))->json('data.0'));
    }

    public function test_data_of_switched_off_modules_is_not_served_to_third_party_apps(): void
    {
        [$c, $s] = $this->client(['students:read:basic', 'courses:read', 'attendance:read:summary']);
        $h = $this->auth($this->bearer($c, $s));
        $this->getJson('/api/v1/attendance/summary?from=2026-11-01&to=2026-11-30', $h)->assertOk();
        $this->getJson('/api/v1/courses', $h)->assertOk();
        config(['saan.modules.disable' => 'attendance,assessment']);
        app(\App\Support\Modules\ModuleRegistry::class)->flush();
        $this->getJson('/api/v1/attendance/summary?from=2026-11-01&to=2026-11-30', $h)->assertStatus(404)->assertJsonPath('error', 'not_available');
        $this->getJson('/api/v1/courses', $h)->assertStatus(404)->assertJsonPath('error', 'not_available');
        $this->getJson('/api/v1/students?limit=1', $h)->assertOk(); // other resources keep working
    }
    public function test_other_resources_and_cursor_pagination(): void
    {
        [$c, $s] = $this->client(['students:read:basic', 'classrooms:read', 'teachers:read:basic', 'courses:read', 'attendance:read:summary']);
        $h = $this->auth($this->bearer($c, $s));
        $ids = [];
        $cursor = null;
        do {
            $r = $this->getJson('/api/v1/students?limit=10'.($cursor ? '&cursor='.$cursor : ''), $h)->assertOk();
            $ids = array_merge($ids, array_column($r->json('data'), 'id'));
            $cursor = $r->json('next_cursor');
        } while ($cursor);
        $this->assertSame(range(1, 24), $ids);
        $this->getJson('/api/v1/students?cursor=garbage', $h)->assertStatus(422);
        $this->getJson('/api/v1/students?status=bogus', $h)->assertStatus(422);
        $this->getJson('/api/v1/students?term=999', $h)->assertStatus(404);
        $this->getJson('/api/v1/students?term=1&classroom=2', $h)->assertOk()->assertJsonCount(8, 'data');
        $this->getJson('/api/v1/students?classroom=ป.5/1', $h)->assertOk()->assertJsonCount(8, 'data');

        $t = $this->getJson('/api/v1/teachers', $h)->assertOk();
        $this->assertSame(['display_name', 'id', 'subject'], collect(array_keys($t->json('data.0')))->sort()->values()->all());
        $this->getJson('/api/v1/courses', $h)->assertOk()->assertJsonPath('data', []);

        $att = $this->getJson('/api/v1/attendance/summary?from=2026-11-01&to=2026-11-30&classroom=1', $h)->assertOk();
        $this->assertSame(['absent' => 1, 'present' => 7], $att->json('data.0.counts'));
        $this->assertSame(8, $att->json('data.0.total'));
        $this->assertStringNotContainsString('student', $att->getContent()); // aggregates only
        $this->getJson('/api/v1/attendance/summary?from=2026-12-01&to=2026-11-01', $h)->assertStatus(422);
    }

    // ---- rate limit, ip allowlist, revoke ------------------------------------------------------------------------

    public function test_rate_limit_per_client_returns_429(): void
    {
        [$c, $s] = $this->client(['classrooms:read'], ['rate_per_minute' => 3]);
        [$other, $os] = $this->client(['classrooms:read'], ['rate_per_minute' => 3]);
        $h = $this->auth($this->bearer($c, $s));
        for ($i = 0; $i < 3; $i++) $this->getJson('/api/v1/classrooms', $h)->assertOk();
        $limited = $this->getJson('/api/v1/classrooms', $h)->assertStatus(429)->assertJsonPath('error', 'rate_limited');
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertSame('0', $limited->headers->get('X-RateLimit-Remaining'));
        $this->assertSame(1, DB::table('api_request_log')->where('client_id', $c->id)->where('status', 429)->count());
        // another client has its own budget
        $this->getJson('/api/v1/classrooms', $this->auth($this->bearer($other, $os)))->assertOk();
    }

    public function test_ip_allowlist_applies_to_token_and_api_calls(): void
    {
        [$c, $s] = $this->client(['classrooms:read'], ['allowed_ips' => json_encode(['10.0.0.0/24', '2001:db8::1'])]);
        $inside = ['REMOTE_ADDR' => '10.0.0.77'];
        $token = $this->withServerVariables($inside)->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, $s))->assertOk()->json('access_token');
        $this->withServerVariables($inside)->getJson('/api/v1/classrooms', $this->auth($token))->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.5.5'])->getJson('/api/v1/classrooms', $this->auth($token))->assertStatus(403)->assertJsonPath('error', 'access_denied');
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.5.5'])->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, $s))->assertStatus(403);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->getJson('/api/v1/classrooms', $this->auth($token))->assertOk();

        $this->assertTrue(Net::ipAllowed(['10.1.2.0/23'], '10.1.3.9'));
        $this->assertFalse(Net::ipAllowed(['10.1.2.0/23'], '10.1.4.1'));
        $this->assertTrue(Net::ipAllowed(['203.0.113.5'], '203.0.113.5'));
        $this->assertFalse(Net::ipAllowed(['203.0.113.5'], '203.0.113.6'));
        $this->assertTrue(Net::ipAllowed(null, '1.2.3.4'));
        $this->assertFalse(Net::ipAllowed(['10.0.0.0/8'], '2001:db8::1'));
    }

    public function test_revoked_expired_and_rotated_tokens_stop_working(): void
    {
        [$c, $s] = $this->client(['classrooms:read']);
        $expired = $this->bearer($c, $s);
        DB::table('api_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/classrooms', $this->auth($expired))->assertStatus(401);

        $a = $this->bearer($c, $s);
        $this->getJson('/api/v1/classrooms', $this->auth($a))->assertOk();
        $this->staff('admin')->postJson('/api/api-gateway/clients/'.$c->id.'/rotate', ['version' => 1])->assertOk();
        $this->getJson('/api/v1/classrooms', $this->auth($a))->assertStatus(401);

        $new = DB::table('api_clients')->find($c->id);
        $this->assertSame(2, (int) $new->version);
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, $s))->assertStatus(401); // old secret is dead

        // revoke: client disabled and its live tokens die; a stale version is a 409
        DB::table('api_clients')->where('id', $c->id)->update(['secret_hash' => Hash::make($s)]);
        $b = $this->bearer($c, $s);
        $this->postJson('/api/api-gateway/clients/'.$c->id.'/revoke', ['version' => 1])->assertStatus(409);
        $this->postJson('/api/api-gateway/clients/'.$c->id.'/revoke', ['version' => 2])->assertOk()->assertJsonPath('client.is_active', false);
        $this->getJson('/api/v1/classrooms', $this->auth($b))->assertStatus(401);
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($c, $s))->assertStatus(401);
        $this->assertSame(0, DB::table('api_tokens')->where('client_id', $c->id)->whereNull('revoked_at')->count());
    }

    // ---- student SSO (authorization code + PKCE) -----------------------------------------------------------------

    private function verifier(): array
    {
        $v = bin2hex(random_bytes(32));
        return [$v, rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=')];
    }

    private function pin(int $studentId): string
    {
        $pin = $this->staff('admin')->postJson('/api/api-gateway/pins/students/'.$studentId.'/reset')->assertOk()->json('data.0.pin');
        $this->settled($studentId);
        return $pin;
    }

    /** A slip PIN is temporary (must_change) and the student portal makes the student change it; these tests are about the sign-in itself, so the PIN counts as settled. */
    private function settled(int $studentId): void
    {
        DB::table('api_student_pins')->where('student_id', $studentId)->update(['must_change' => false]);
    }

    private function authorizeQuery(object $c, string $challenge, array $over = []): array
    {
        return $over + ['response_type' => 'code', 'client_id' => $c->client_id, 'redirect_uri' => 'https://learn.example.org/callback', 'state' => 'st-123',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
    }

    private function signIn(object $c, string $challenge, string $code, string $pin): \Illuminate\Testing\TestResponse
    {
        return $this->post('/oauth/authorize', $this->authorizeQuery($c, $challenge) + ['student_code' => $code, 'pin' => $pin]);
    }

    private function codeFrom($response): string
    {
        $loc = $response->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://learn.example.org/callback?', $loc);
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('st-123', $q['state']);
        return $q['code'];
    }

    public function test_pkce_flow_me_profile_wrong_verifier_and_code_reuse(): void
    {
        DB::table('students')->where('id', 1)->update(['birth_date' => '2015-03-04', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี']);
        [$c, $s] = $this->client(['sso:student']);
        $pin = $this->pin(1);
        [$verifier, $challenge] = $this->verifier();

        $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($c, $challenge)))->assertOk()->assertSee('รหัสนักเรียน')->assertSee('name="pin"', false);
        $code = $this->codeFrom($this->signIn($c, $challenge, 'DEMO-01', $pin));
        $this->assertSame(1, DB::table('api_sso_codes')->count());
        $this->assertSame(64, strlen(DB::table('api_sso_codes')->value('code_hash')));

        $tok = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://learn.example.org/callback', 'code_verifier' => $verifier], $this->basic($c, $s))->assertOk();
        $this->assertSame('me:read', $tok->json('scope'));
        $me = $this->getJson('/api/v1/me', $this->auth($tok->json('access_token')))->assertOk();
        $this->assertSame(['classroom', 'code', 'display_name', 'id', 'status'], collect(array_keys($me->json('data')))->sort()->values()->all());
        $this->assertSame('สมชาย ใจดี', $me->json('data.display_name'));
        $this->assertStringNotContainsString('2015-03-04', $me->getContent());
        // an SSO token reaches nothing but /me
        $this->getJson('/api/v1/students', $this->auth($tok->json('access_token')))->assertStatus(403);

        // reuse of the same code fails and revokes what it produced
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://learn.example.org/callback', 'code_verifier' => $verifier], $this->basic($c, $s))
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->getJson('/api/v1/me', $this->auth($tok->json('access_token')))->assertStatus(401);

        // wrong verifier fails, and the code is spent even if the right verifier follows
        [$v2, $challenge2] = $this->verifier();
        $code2 = $this->codeFrom($this->signIn($c, $challenge2, 'DEMO-01', $pin));
        $body = ['grant_type' => 'authorization_code', 'code' => $code2, 'redirect_uri' => 'https://learn.example.org/callback'];
        $this->postJson('/oauth/token', ['code_verifier' => bin2hex(random_bytes(32))] + $body, $this->basic($c, $s))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->postJson('/oauth/token', ['code_verifier' => $v2] + $body, $this->basic($c, $s))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        // a code belongs to the client that received it
        [$other, $os] = $this->client(['sso:student']);
        [$v3, $ch3] = $this->verifier();
        $code3 = $this->codeFrom($this->signIn($c, $ch3, 'DEMO-01', $pin));
        $this->postJson('/oauth/token', ['code' => $code3, 'code_verifier' => $v3] + $body, $this->basic($other, $os))->assertStatus(400);
        // redirect_uri must match the one used at authorize
        [$v4, $ch4] = $this->verifier();
        $code4 = $this->codeFrom($this->signIn($c, $ch4, 'DEMO-01', $pin));
        $this->postJson('/oauth/token', ['code' => $code4, 'redirect_uri' => 'https://evil.example.org/cb', 'code_verifier' => $v4] + $body, $this->basic($c, $s))->assertStatus(400);
        // expired code
        [$v5, $ch5] = $this->verifier();
        $code5 = $this->codeFrom($this->signIn($c, $ch5, 'DEMO-01', $pin));
        DB::table('api_sso_codes')->where('code_hash', hash('sha256', $code5))->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/oauth/token', ['code' => $code5, 'code_verifier' => $v5] + $body, $this->basic($c, $s))->assertStatus(400);
        // and a client without the SSO scope cannot exchange codes
        [$plain, $ps] = $this->client(['classrooms:read']);
        $this->postJson('/oauth/token', ['code' => $code, 'code_verifier' => $verifier] + $body, $this->basic($plain, $ps))->assertStatus(400)->assertJsonPath('error', 'unauthorized_client');

        // a student who left gets no profile, and a PIN reset revokes the student's live sessions
        [$v6, $ch6] = $this->verifier();
        $code6 = $this->codeFrom($this->signIn($c, $ch6, 'DEMO-01', $pin));
        $t6 = $this->postJson('/oauth/token', ['code' => $code6, 'code_verifier' => $v6] + $body, $this->basic($c, $s))->assertOk()->json('access_token');
        $this->getJson('/api/v1/me', $this->auth($t6))->assertOk();
        DB::table('students')->where('id', 1)->update(['status' => 'left']);
        $this->getJson('/api/v1/me', $this->auth($t6))->assertStatus(401);
        DB::table('students')->where('id', 1)->update(['status' => 'studying']);
        $this->getJson('/api/v1/me', $this->auth($t6))->assertOk();
        $this->pin(1);
        $this->getJson('/api/v1/me', $this->auth($t6))->assertStatus(401);
    }

    public function test_authorize_rejects_bad_clients_redirects_and_missing_pkce(): void
    {
        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        // unknown redirect_uri / client: error page, never a redirect
        $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($c, $challenge, ['redirect_uri' => 'https://evil.example.org/cb'])))->assertStatus(400)->assertHeaderMissing('Location');
        $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($c, $challenge, ['client_id' => 'cl_nope'])))->assertStatus(400);
        // missing / plain PKCE: redirect back with an error
        $res = $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($c, $challenge, ['code_challenge_method' => 'plain'])));
        $this->assertStringContainsString('error=invalid_request', $res->assertRedirect()->headers->get('Location'));
        $res = $this->get('/oauth/authorize?'.http_build_query(array_diff_key($this->authorizeQuery($c, $challenge), ['code_challenge' => 1])));
        $this->assertStringContainsString('error=invalid_request', $res->assertRedirect()->headers->get('Location'));
        // inactive client
        DB::table('api_clients')->where('id', $c->id)->update(['is_active' => false]);
        $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($c, $challenge)))->assertStatus(400);
    }

    public function test_birth_date_alone_never_signs_in_and_pin_lockout_works(): void
    {
        DB::table('students')->where('id', 1)->update(['birth_date' => '2015-03-04']);
        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        $pin = $this->pin(1);
        $wrong = $pin === '000001' ? '000002' : '000001';

        // no PIN issued for student 2, and a birth date is not a credential
        $this->signIn($c, $challenge, 'DEMO-02', '20150304')->assertStatus(401);
        $this->signIn($c, $challenge, 'DEMO-01', '20150304')->assertStatus(401);
        $this->signIn($c, $challenge, 'NO-SUCH', $pin)->assertStatus(401);
        $this->signIn($c, $challenge, 'DEMO-01', $wrong)->assertStatus(401); // failed tries so far for student 1: birth date, wrong

        // one network is capped at MAX_PER_NETWORK wrong tries per student: it can never reach the lock on its own
        $clear = fn () => \Illuminate\Support\Facades\RateLimiter::clear('oauth-login-ip:127.0.0.1'); // per-IP limiter is tested separately
        $clear();
        foreach ([3, 4, 5] as $n) $this->signIn($c, $challenge, 'DEMO-01', $wrong)->assertStatus(401);
        $this->assertSame(\Saan\Modules\ApiGateway\Pins::MAX_PER_NETWORK, (int) DB::table('api_student_pins')->where('student_id', 1)->value('failed_count'));
        $this->signIn($c, $challenge, 'DEMO-01', $wrong)->assertStatus(401); // refused before touching the student's counters
        $this->signIn($c, $challenge, 'DEMO-01', $pin)->assertStatus(401);    // this network is blocked for this student, even with the right PIN
        $this->assertSame(\Saan\Modules\ApiGateway\Pins::MAX_PER_NETWORK, (int) DB::table('api_student_pins')->where('student_id', 1)->value('failed_count'));
        $this->assertNull(DB::table('api_student_pins')->where('student_id', 1)->value('locked_until'));
        // a student on another network is not locked out by that traffic
        config(['school.demo' => false]);
        $from = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $clear();
        // wrong tries from other networks (all networks together) reach MAX_FAILED: the PIN locks and even the right PIN is refused
        foreach (range(1, 7) as $n) $from('198.51.100.'.$n)->post('/oauth/authorize', $this->authorizeQuery($c, $challenge) + ['student_code' => 'DEMO-01', 'pin' => $wrong])->assertStatus(401);
        $row = DB::table('api_student_pins')->where('student_id', 1)->first();
        $this->assertSame(\Saan\Modules\ApiGateway\Pins::MAX_FAILED, (int) $row->failed_count);
        $this->assertNotNull($row->locked_until);
        $this->assertSame(1, (int) $row->lock_count);
        $from('198.51.100.9')->post('/oauth/authorize', $this->authorizeQuery($c, $challenge) + ['student_code' => 'DEMO-01', 'pin' => $pin])->assertStatus(401);
        $this->assertSame(\Saan\Modules\ApiGateway\Pins::MAX_FAILED, (int) DB::table('api_student_pins')->where('student_id', 1)->value('failed_count')); // locked: no more attempts counted

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        // the homeroom teacher (teacher 1 = class 1) resets: new PIN works, old PIN does not
        $teacher = User::where('role', 'teacher')->first();
        config(['school.demo' => true]);
        $new = $this->actingAs($teacher)->postJson('/api/api-gateway/pins/students/1/reset')->assertOk()->json('data.0.pin');
        $this->settled(1);
        $this->assertNull(DB::table('api_student_pins')->where('student_id', 1)->value('locked_until'));
        if ($pin !== $new) $this->signIn($c, $challenge, 'DEMO-01', $pin)->assertStatus(401);
        $clear();
        $this->codeFrom($this->signIn($c, $challenge, 'demo-01', $new)); // code is case-insensitive
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('failed_count'));

        // lock expires on its own
        DB::table('api_student_pins')->where('student_id', 1)->update(['failed_count' => 5, 'locked_until' => now()->subMinute()]);
        $clear();
        $this->codeFrom($this->signIn($c, $challenge, 'DEMO-01', $new));
    }

    public function test_sign_in_page_is_throttled_per_ip(): void
    {
        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        // layer 1: per IP + student code (10 wrong tries in 5 minutes), other codes from the same IP are unaffected
        for ($i = 0; $i < 10; $i++) $this->signIn($c, $challenge, 'NOPE-A', '123456')->assertStatus(401);
        $this->signIn($c, $challenge, 'NOPE-A', '123456')->assertStatus(429);
        $this->signIn($c, $challenge, 'nope-a ', '123456')->assertStatus(429); // same key after normalisation
        for ($i = 0; $i < 30; $i++) $this->signIn($c, $challenge, 'NOPE-'.$i, '123456')->assertStatus(401); // a shared school NAT is not locked out
        // layer 2: a much higher ceiling per IP
        $limiter = \Illuminate\Support\Facades\RateLimiter::class;
        for ($i = 0; $i < \Saan\Modules\ApiGateway\Http\OAuthController::LOGIN_PER_IP_5_MIN; $i++) $limiter::hit('oauth-login-ip:127.0.0.1', 300);
        $this->signIn($c, $challenge, 'NOPE-FRESH', '123456')->assertStatus(429);
    }

    public function test_many_code_exchanges_from_one_server_ip_are_not_throttled(): void
    {
        [$c, $s] = $this->client(['sso:student'], ['rate_per_minute' => 120]);
        $now = now();
        for ($i = 0; $i < 40; $i++) {
            [$v, $ch] = $this->verifier();
            $code = bin2hex(random_bytes(32));
            DB::table('api_sso_codes')->insert(['client_id' => $c->id, 'code_hash' => hash('sha256', $code), 'user_kind' => 'student', 'subject_id' => 1,
                'redirect_uri' => 'https://learn.example.org/callback', 'code_challenge' => $ch, 'expires_at' => $now->copy()->addMinute(), 'created_at' => $now]);
            $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://learn.example.org/callback', 'code_verifier' => $v], $this->basic($c, $s))->assertOk();
        }
        // machine tokens keep the tighter per-client cap; failed secrets still hit the per-IP limiter
        [$m, $ms] = $this->client(['classrooms:read']);
        for ($i = 0; $i < 30; $i++) $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($m, $ms))->assertOk();
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($m, $ms))->assertStatus(429);
    }

    public function test_attendance_summary_counts_confirmed_class_days_as_present_for_everyone_without_an_exception(): void
    {
        [$c, $s] = $this->client(['attendance:read:summary']);
        $h = $this->auth($this->bearer($c, $s));
        DB::table('attendances')->where('student_id', '>', 0)->delete();
        $size = DB::table('students')->where('classroom_id', 1)->where('status', 'studying')->count();
        DB::table('att_class_days')->insert(['school_id' => 1, 'classroom_id' => 1, 'date' => '2026-11-03', 'confirmed_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('att_class_days')->insert(['school_id' => 1, 'classroom_id' => 1, 'date' => '2026-11-04', 'confirmed_at' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $one = DB::table('students')->where('classroom_id', 1)->orderBy('id')->first();
        DB::table('attendances')->insert(['school_id' => 1, 'student_id' => $one->id, 'date' => '2026-11-03', 'status' => 'absent', 'recorded_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $res = $this->getJson('/api/v1/attendance/summary?from=2026-11-01&to=2026-11-30&classroom=1', $h)->assertOk();
        $this->assertSame(1, $res->json('data.0.days_checked'));
        $this->assertSame($size - 1, $res->json('data.0.counts.present'));
        $this->assertSame(1, $res->json('data.0.counts.absent'));
        $this->assertSame($size, $res->json('data.0.total'));
        // a confirmed day with no exception rows at all is a full class
        DB::table('att_class_days')->insert(['school_id' => 1, 'classroom_id' => 1, 'date' => '2026-11-05', 'confirmed_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $res = $this->getJson('/api/v1/attendance/summary?from=2026-11-01&to=2026-11-30&classroom=1', $h)->assertOk();
        $this->assertSame(2 * $size - 1, $res->json('data.0.counts.present'));
        $this->assertSame(2, $res->json('data.0.days_checked'));
    }

    public function test_term_filter_ignores_deleted_lessons_and_me_rejects_students_who_left(): void
    {
        if (!Schema::hasColumn('lessons', 'deleted_at')) $this->markTestSkipped('timetable not installed');
        [$c, $s] = $this->client(['students:read:basic']);
        $h = $this->auth($this->bearer($c, $s));
        $this->getJson('/api/v1/students?term=1&classroom=2', $h)->assertOk()->assertJsonCount(8, 'data');
        DB::table('lessons')->where('classroom_id', 2)->update(['deleted_at' => now()]);
        $this->getJson('/api/v1/students?term=1&classroom=2', $h)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_attendance_history_uses_the_recorded_room_and_roster_size_of_that_day(): void
    {
        [$c, $s] = $this->client(['attendance:read:summary']);
        $h = $this->auth($this->bearer($c, $s));
        DB::table('attendances')->where('student_id', '>', 0)->delete();
        $mover = DB::table('students')->where('classroom_id', 1)->orderBy('id')->first();
        // the day was confirmed for a roster of 10; the student marked absent then sat in room 1 but has since moved to room 2 (and another has left)
        DB::table('att_class_days')->insert(['school_id' => 1, 'classroom_id' => 1, 'date' => '2026-11-03', 'confirmed_at' => now(), 'student_count' => 10, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendances')->insert(['school_id' => 1, 'student_id' => $mover->id, 'classroom_id' => 1, 'date' => '2026-11-03', 'status' => 'absent', 'recorded_by' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('students')->where('id', $mover->id)->update(['classroom_id' => 2, 'status' => 'left']);
        $res = $this->getJson('/api/v1/attendance/summary?from=2026-11-01&to=2026-11-30&classroom=1', $h)->assertOk();
        $this->assertSame(1, $res->json('data.0.counts.absent'));      // still under the room it was recorded in, though the student left
        $this->assertSame(9, $res->json('data.0.counts.present'));     // 10 on that day minus the absence, not today's class size
        $this->assertSame(0, count($this->getJson('/api/v1/attendance/summary?from=2026-11-01&to=2026-11-30&classroom=2', $h)->assertOk()->json('data')));
    }

    public function test_students_term_filter_only_serves_the_current_term(): void
    {
        [$c, $s] = $this->client(['students:read:basic']);
        $h = $this->auth($this->bearer($c, $s));
        $current = \App\Support\Saan\TermScope::current()->id;
        $this->getJson('/api/v1/students?term='.$current, $h)->assertOk();
        $past = (array) DB::table('academic_terms')->where('id', $current)->first();
        unset($past['id']);
        foreach (['is_current', 'current'] as $flag) if (array_key_exists($flag, $past)) $past[$flag] = false;
        if (isset($past['starts_on'])) { $past['starts_on'] = '2020-01-01'; $past['ends_on'] = '2020-06-30'; }
        foreach (['label', 'name'] as $k) if (isset($past[$k]) && is_string($past[$k])) $past[$k] = 'old-'.$past[$k];
        if (isset($past['year'])) $past['year'] = (int) $past['year'] - 5;
        $oldId = DB::table('academic_terms')->insertGetId($past);
        $this->assertSame($current, \App\Support\Saan\TermScope::current()->id);
        $this->getJson('/api/v1/students?term='.$oldId, $h)->assertStatus(422)->assertJsonPath('error', 'invalid_request');
    }

    public function test_oauth_state_is_echoed_unchanged_or_refused_never_truncated(): void
    {
        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        $long = str_repeat('a', 700); // longer than the old 200 character cut
        $loc = $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($c, $challenge, ['state' => $long])))->assertOk()->getContent();
        $this->assertStringContainsString($long, $loc);
        $res = $this->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($c, $challenge, ['state' => str_repeat('b', 2000)])))->assertRedirect();
        parse_str((string) parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('invalid_request', $q['error']);
        $this->assertArrayNotHasKey('state', $q);
    }

    public function test_contact_scope_requires_basic_scope(): void
    {
        $this->staff('admin')->postJson('/api/api-gateway/clients', ['name' => 'Contact only', 'scopes' => ['students:read:contact']])->assertStatus(422)->assertJsonValidationErrors('scopes');
        $this->staff('admin')->postJson('/api/api-gateway/clients', ['name' => 'Contact ok', 'scopes' => ['students:read:contact', 'students:read:basic']])->assertStatus(201);
    }

    public function test_blocked_anonymous_callers_are_not_logged_and_migration_uses_datetime(): void
    {
        for ($i = 0; $i < 60; $i++) $this->getJson('/api/v1/classrooms', ['Authorization' => 'Bearer '.str_repeat('x', 30)])->assertStatus(401);
        $logged = DB::table('api_request_log')->count();
        $this->getJson('/api/v1/classrooms', ['Authorization' => 'Bearer '.str_repeat('x', 30)])->assertStatus(429);
        $this->getJson('/api/v1/classrooms', ['Authorization' => 'Bearer '.str_repeat('x', 30)])->assertStatus(429);
        $this->assertSame($logged, DB::table('api_request_log')->count());
        foreach ([['api_tokens', 'expires_at'], ['api_request_log', 'at'], ['api_sso_codes', 'expires_at']] as [$t, $col]) {
            $this->assertStringContainsString('datetime', strtolower(Schema::getColumnType($t, $col)));
        }
    }

    public function test_pin_admin_endpoints_scope_by_homeroom_and_never_store_or_audit_the_pin(): void
    {
        $teacher = User::where('role', 'teacher')->first(); // homeroom class 1
        config(['school.demo' => true]);
        $this->actingAs($teacher)->postJson('/api/api-gateway/pins/classrooms/2/issue')->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/api-gateway/pins/students/9/reset')->assertForbidden(); // student 9 is class 2
        $this->actingAs($teacher)->getJson('/api/api-gateway/pins/classrooms/2')->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/api-gateway/pins/classrooms')->assertOk()->assertJsonCount(1, 'data');

        $issued = $this->actingAs($teacher)->postJson('/api/api-gateway/pins/classrooms/1/issue')->assertOk()->assertHeader('Cache-Control');
        $this->assertCount(8, $issued->json('data'));
        $pins = array_column($issued->json('data'), 'pin');
        foreach ($pins as $p) $this->assertMatchesRegularExpression('/^\d{6}$/', $p);
        $this->assertSame(8, DB::table('api_student_pins')->count());
        foreach ($pins as $p) {
            $this->assertStringNotContainsString($p, json_encode(DB::table('api_student_pins')->get()));
            $this->assertStringNotContainsString('"'.$p.'"', json_encode(DB::table('audit_events')->get()));
        }
        $this->assertGreaterThanOrEqual(8, DB::table('audit_events')->where('action', 'api_pin.issue')->count());
        // only_missing leaves existing PINs alone
        $again = $this->actingAs($teacher)->postJson('/api/api-gateway/pins/classrooms/1/issue', ['only_missing' => true])->assertOk();
        $this->assertCount(0, $again->json('data'));
        $roster = $this->actingAs($teacher)->getJson('/api/api-gateway/pins/classrooms/1')->assertOk();
        $this->assertTrue($roster->json('data.0.has_pin'));
        $this->assertStringNotContainsString('pin_hash', $roster->getContent());
        // admin may do any class; editor-like roles may not
        $this->staff('admin')->postJson('/api/api-gateway/pins/classrooms/2/issue', ['student_ids' => [9, 10]])->assertOk()->assertJsonCount(2, 'data');
    }

    // ---- school scoping ------------------------------------------------------------------------------------------

    public function test_everything_is_scoped_to_the_configured_school(): void
    {
        DB::table('teachers')->insert(['id' => 90, 'school_id' => 2, 'name' => 'ครูโรงเรียนอื่น', 'subject' => 'x']);
        DB::table('classrooms')->insert(['id' => 90, 'school_id' => 2, 'name' => 'ห้องโรงเรียนอื่น', 'homeroom_teacher_id' => 90]);
        DB::table('students')->insert(['id' => 90, 'school_id' => 2, 'classroom_id' => 90, 'code' => 'S2-001', 'name' => 'นักเรียนโรงเรียนอื่น']);
        [$c, $s] = $this->client(['students:read:basic', 'classrooms:read', 'teachers:read:basic', 'sso:student']);
        $h = $this->auth($this->bearer($c, $s));
        $this->assertStringNotContainsString('S2-001', $this->getJson('/api/v1/students?limit=500', $h)->getContent());
        $this->assertStringNotContainsString('ห้องโรงเรียนอื่น', $this->getJson('/api/v1/classrooms', $h)->getContent());
        $this->assertStringNotContainsString('ครูโรงเรียนอื่น', $this->getJson('/api/v1/teachers', $h)->getContent());
        $this->getJson('/api/v1/students?classroom=90', $h)->assertOk()->assertJsonCount(0, 'data');

        // a client of another school cannot get a token or use one that exists
        [$foreign, $fs] = $this->client(['students:read:basic'], ['school_id' => 2]);
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials'], $this->basic($foreign, $fs))->assertStatus(401);
        DB::table('api_tokens')->insert(['client_id' => $foreign->id, 'token_hash' => hash('sha256', 'saan_foreign'.str_repeat('0', 30)), 'scopes' => json_encode(['students:read:basic']), 'expires_at' => now()->addHour(), 'created_at' => now()]);
        $this->getJson('/api/v1/students', $this->auth('saan_foreign'.str_repeat('0', 30)))->assertStatus(401);

        // a student of another school cannot sign in, even with a PIN row
        DB::table('api_student_pins')->insert(['school_id' => 2, 'student_id' => 90, 'pin_hash' => Hash::make('x'), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        [, $ch] = $this->verifier();
        $this->signIn($c, $ch, 'S2-001', '123456')->assertStatus(401);
        // staff screens
        $this->staff('admin')->getJson('/api/api-gateway/clients')->assertOk()->assertJsonCount(1, 'data');
    }

    // ---- admin page backend --------------------------------------------------------------------------------------

    public function test_admin_client_lifecycle_secret_once_conflicts_and_usage(): void
    {
        $this->staff('teacher')->getJson('/api/api-gateway/clients')->assertForbidden();
        $this->staff('principal')->postJson('/api/api-gateway/clients', [])->assertForbidden();
        $this->staff('admin');

        $made = $this->postJson('/api/api-gateway/clients', ['name' => 'แอปเรียนออนไลน์', 'scopes' => ['students:read:basic', 'sso:student'],
            'allowed_ips' => ['203.0.113.0/24'], 'redirect_uris' => ['https://learn.example.org/cb'], 'rate_per_minute' => 60])->assertCreated();
        $secret = $made->json('client_secret');
        $this->assertSame(64, strlen($secret));
        $cl = $made->json('client');
        $this->assertArrayNotHasKey('secret_hash', $cl);
        $this->assertTrue(Hash::check($secret, DB::table('api_clients')->where('id', $cl['id'])->value('secret_hash')));
        $this->assertSame('api_client.create', DB::table('audit_events')->latest('id')->value('action'));
        $this->assertStringNotContainsString($secret, json_encode(DB::table('audit_events')->get()));
        $list = $this->getJson('/api/api-gateway/clients')->assertOk();
        $this->assertStringNotContainsString($secret, $list->getContent());
        $this->assertStringNotContainsString('secret_hash', $list->getContent());

        // name uniqueness ignores case and spacing
        $this->postJson('/api/api-gateway/clients', ['name' => 'Learn  App', 'scopes' => ['classrooms:read']])->assertCreated();
        $this->postJson('/api/api-gateway/clients', ['name' => ' learn app ', 'scopes' => ['classrooms:read']])->assertStatus(409);

        // validation: unknown scope, bad ip, bad redirect, sso without redirect
        $this->postJson('/api/api-gateway/clients', ['name' => 'X1', 'scopes' => ['db:select']])->assertUnprocessable();
        $this->postJson('/api/api-gateway/clients', ['name' => 'X2', 'scopes' => ['classrooms:read'], 'allowed_ips' => ['999.1.1.1']])->assertUnprocessable();
        $this->postJson('/api/api-gateway/clients', ['name' => 'X3', 'scopes' => ['sso:student'], 'redirect_uris' => ['http://evil.example.org/cb']])->assertUnprocessable();
        $this->postJson('/api/api-gateway/clients', ['name' => 'X4', 'scopes' => ['sso:student'], 'redirect_uris' => ['javascript:alert(1)']])->assertUnprocessable();
        $this->postJson('/api/api-gateway/clients', ['name' => 'X5', 'scopes' => ['sso:student']])->assertUnprocessable();
        $this->postJson('/api/api-gateway/clients', ['name' => 'X6', 'scopes' => ['sso:student'], 'redirect_uris' => ['http://localhost:3000/cb']])->assertCreated();

        // update with version, stale version -> 409, rename onto another client -> 409
        $edit = ['name' => 'แอปเรียนออนไลน์ 2', 'scopes' => ['students:read:basic'], 'allowed_ips' => [], 'redirect_uris' => [], 'rate_per_minute' => 30];
        $this->putJson('/api/api-gateway/clients/'.$cl['id'], $edit + ['version' => 1])->assertOk()->assertJsonPath('client.version', 2)->assertJsonPath('client.allowed_ips', []);
        $this->putJson('/api/api-gateway/clients/'.$cl['id'], $edit + ['version' => 1])->assertStatus(409)->assertJsonStructure(['message', 'current']);
        $this->assertStringNotContainsString('secret_hash', $this->putJson('/api/api-gateway/clients/'.$cl['id'], $edit + ['version' => 1])->getContent());
        $this->putJson('/api/api-gateway/clients/'.$cl['id'], ['name' => 'learn app'] + $edit + ['version' => 2])->assertStatus(409);

        // scopes catalogue and the 7-day usage table
        $this->getJson('/api/api-gateway/scopes')->assertOk()->assertJsonFragment(['key' => 'students:read:basic']);
        DB::table('api_request_log')->insert([
            ['client_id' => $cl['id'], 'route' => 'api/v1/students', 'status' => 200, 'ms' => 5, 'at' => now()],
            ['client_id' => $cl['id'], 'route' => 'api/v1/students', 'status' => 429, 'ms' => 1, 'at' => now()],
            ['client_id' => $cl['id'], 'route' => 'api/v1/students', 'status' => 200, 'ms' => 5, 'at' => now()->subDays(3)],
            ['client_id' => $cl['id'], 'route' => 'api/v1/students', 'status' => 200, 'ms' => 5, 'at' => now()->subDays(10)],
        ]);
        $usage = $this->getJson('/api/api-gateway/usage')->assertOk();
        $this->assertCount(7, $usage->json('days'));
        $mine = collect($usage->json('data'))->firstWhere('id', $cl['id']);
        $this->assertSame(3, array_sum(array_column($mine['days'], 'calls')));
        $this->assertSame(1, array_sum(array_column($mine['days'], 'errors')));
        $this->assertSame(2, $mine['days'][6]['calls']);
    }

    public function test_ipv6_rate_keys_use_the_64_prefix(): void
    {
        $k = fn (?string $ip) => \App\Support\Saan\NetKey::rate($ip);
        $this->assertSame($k('2001:db8:1:2:aaaa:bbbb:cccc:dddd'), $k('2001:db8:1:2::1'));
        $this->assertNotSame($k('2001:db8:1:2::1'), $k('2001:db8:1:3::1'));
        $this->assertSame('203.0.113.9', $k('203.0.113.9'));
        $this->assertSame('203.0.113.9', $k('::ffff:203.0.113.9'));
        $this->assertSame('unknown', $k(null));
        // a single /64 shares one sign-in budget: rotating the host part does not reset the per-IP ceiling
        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        for ($i = 0; $i < \Saan\Modules\ApiGateway\Http\OAuthController::LOGIN_PER_IP_5_MIN; $i++) \Illuminate\Support\Facades\RateLimiter::hit('oauth-login-ip:'.$k('2001:db8:aa:bb::1'), 300);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:aa:bb:1234:5678:9abc:def0'])
            ->post('/oauth/authorize', $this->authorizeQuery($c, $challenge) + ['student_code' => 'DEMO-01', 'pin' => '123456'])->assertStatus(429);
    }

    public function test_pin_lock_escalates_and_never_becomes_permanent(): void
    {
        $pins = \Saan\Modules\ApiGateway\Pins::class;
        $this->assertSame([30, 60, 120, 240, 480, 960, 1440], array_map(fn ($n) => $pins::lockMinutes($n), [1, 2, 3, 4, 5, 6, 7]));
        $this->assertSame(1440, $pins::lockMinutes(20));

        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        $pin = $this->pin(1);
        $wrong = $pin === '000001' ? '000002' : '000001';
        config(['school.demo' => false]); // demo mode refuses non-local addresses
        $guess = function (string $ip, string $p) use ($c, $challenge) {
            return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/oauth/authorize', $this->authorizeQuery($c, $challenge) + ['student_code' => 'DEMO-01', 'pin' => $p]);
        };
        $minutes = [];
        for ($round = 1; $round <= 5; $round++) {
            for ($i = 0; $i < $pins::MAX_FAILED; $i++) $guess('198.51.100.'.($i + 1), $wrong)->assertStatus(401);
            $row = DB::table('api_student_pins')->where('student_id', 1)->first();
            $this->assertSame($round, (int) $row->lock_count);
            $minutes[] = (int) round((strtotime((string) $row->locked_until) - time()) / 60);
            // the lock expires on its own until the last round
            DB::table('api_student_pins')->where('student_id', 1)->update(['locked_until' => now()->subMinute()]);
            \Illuminate\Support\Facades\RateLimiter::clear('oauth-login-school:1');
            \Illuminate\Support\Facades\RateLimiter::clear('oauth-login-client:'.$c->id);
            foreach (range(1, $pins::MAX_FAILED) as $n) \Illuminate\Support\Facades\RateLimiter::clear('oauth-login:'.sha1('198.51.100.'.$n.'|demo-01'));
            foreach (range(1, $pins::MAX_FAILED) as $n) \Illuminate\Support\Facades\RateLimiter::clear('oauth-login-ip:198.51.100.'.$n);
            foreach (range(1, $pins::MAX_FAILED) as $n) \Illuminate\Support\Facades\RateLimiter::clear('oauth-login-netfail:198.51.100.'.$n);
        }
        $this->assertEqualsWithDelta([30, 60, 120, 240, 480], $minutes, 1);
        //         // an expired lock frees the PIN again (never permanent): the right PIN works after the 5th lock expired
        $this->codeFrom($guess('198.51.100.50', $pin));
        DB::table('api_student_pins')->where('student_id', 1)->update(['lock_count' => 5, 'failed_count' => 0, 'locked_until' => null]);
$this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']); // staff calls are local (demo users)
        $roster = $this->staff('admin')->getJson('/api/api-gateway/pins/classrooms/1')->assertOk()->json('data');
        $this->assertFalse(collect($roster)->firstWhere('id', 1)['needs_reset']);
        $new = $this->postJson('/api/api-gateway/pins/students/1/reset')->assertOk()->json('data.0.pin');
        $this->settled(1);
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('lock_count'));
        config(['school.demo' => false]);
        $this->codeFrom($guess('198.51.100.51', $new));
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('lock_count'));
    }

        public function test_failure_floods_per_client_and_school_do_not_block_clean_networks(): void
    {
        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        $limiter = \Illuminate\Support\Facades\RateLimiter::class;
        $pin = $this->pin(1);
        config(['school.demo' => false]);
        $from = fn (string $ip, string $code, string $p) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/oauth/authorize', $this->authorizeQuery($c, $challenge) + ['student_code' => $code, 'pin' => $p]);
        for ($i = 0; $i < \Saan\Modules\ApiGateway\Http\OAuthController::LOGIN_FAILS_PER_CLIENT_5_MIN; $i++) $limiter::hit('oauth-login-client:'.$c->id, 300);
        for ($i = 0; $i < \Saan\Modules\ApiGateway\Http\OAuthController::LOGIN_FAILS_PER_SCHOOL_5_MIN; $i++) $limiter::hit('oauth-login-school:1', 300);
        // a network with a clean record still signs in with the right PIN (the flood cannot become a denial of service)
        $this->codeFrom($from('203.0.113.77', 'DEMO-01', $pin));
        // a network that has already failed repeatedly is refused while the client/school is under flood
        for ($i = 0; $i < \Saan\Modules\ApiGateway\Http\OAuthController::HOT_NETWORK_MIN_FAILS; $i++) $limiter::hit('oauth-login-netfail:203.0.113.78', 300);
        $from('203.0.113.78', 'NOPE-Z', '123456')->assertStatus(429);
        // ...but a hot network with the right PIN still signs in, and its wrong tries no longer feed the client/school counters
        $before = $limiter::attempts('oauth-login-client:'.$c->id);
        $from('203.0.113.78', 'NOPE-Y', '123456')->assertStatus(429);
        $this->assertSame($before, $limiter::attempts('oauth-login-client:'.$c->id));
        $this->codeFrom($from('203.0.113.78', 'DEMO-01', $pin));
        // without the flood the same network only gets 401
        $limiter::clear('oauth-login-client:'.$c->id);
        $limiter::clear('oauth-login-school:1');
        $from('203.0.113.78', 'NOPE-Z', '123456')->assertStatus(401);
    }

    public function test_hot_network_gets_one_check_in_flight_and_never_sleeps(): void
    {
        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        $limiter = \Illuminate\Support\Facades\RateLimiter::class;
        $pin = $this->pin(1);
        config(['school.demo' => false]);
        $from = fn (string $ip, string $code, string $p) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/oauth/authorize', $this->authorizeQuery($c, $challenge) + ['student_code' => $code, 'pin' => $p]);
        for ($i = 0; $i < \Saan\Modules\ApiGateway\Http\OAuthController::LOGIN_FAILS_PER_CLIENT_5_MIN; $i++) $limiter::hit('oauth-login-client:'.$c->id, 300);
        for ($i = 0; $i < \Saan\Modules\ApiGateway\Http\OAuthController::HOT_NETWORK_MIN_FAILS; $i++) $limiter::hit('oauth-login-netfail:203.0.113.90', 300);
        $net = \App\Support\Saan\NetKey::rate('203.0.113.90');
        $held = \Illuminate\Support\Facades\Cache::lock('oauth-hot-slot:'.sha1($net), 30);
        $this->assertTrue($held->get());
        $from('203.0.113.90', 'DEMO-01', $pin)->assertStatus(429); // parallel request from the same hot network is refused at once, even with the right PIN
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('failed_count'));
        $held->release();
        $this->codeFrom($from('203.0.113.90', 'DEMO-01', $pin)); // slot free: the right PIN signs in, and the slot is released afterwards
        $again = \Illuminate\Support\Facades\Cache::lock('oauth-hot-slot:'.sha1($net), 5);
        $this->assertTrue($again->get());
        $again->release();
    }

public function test_token_issue_budget_is_separate_per_grant(): void
    {
        [$c, $s] = $this->client(['sso:student', 'classrooms:read'], ['rate_per_minute' => 120]);
        $limiter = \Illuminate\Support\Facades\RateLimiter::class;
        // a class signing in through SSO burns the authorization_code budget...
        for ($i = 0; $i < 120; $i++) $limiter::hit('api-token-client:'.$c->id.':authorization_code', 60);
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => str_repeat('a', 64), 'redirect_uri' => 'https://learn.example.org/callback', 'code_verifier' => str_repeat('a', 43)], $this->basic($c, $s))->assertStatus(429);
        // ...but the app server's client_credentials sync is untouched
        $this->postJson('/api/v1/token', ['grant_type' => 'client_credentials', 'scope' => 'classrooms:read'], $this->basic($c, $s))->assertOk();
    }

    public function test_deactivating_a_client_through_update_revokes_its_tokens_for_good(): void
    {
        [$c, $secret] = $this->client(['classrooms:read']);
        $token = $this->bearer($c, $secret);
        $this->getJson('/api/v1/classrooms', $this->auth($token))->assertOk();
        $edit = ['name' => 'App ปิดใช้งาน', 'scopes' => ['classrooms:read'], 'allowed_ips' => [], 'redirect_uris' => [], 'rate_per_minute' => 120];
        $this->staff('admin')->putJson('/api/api-gateway/clients/'.$c->id, $edit + ['version' => 1, 'is_active' => false])->assertOk()->assertJsonPath('client.is_active', false);
        $this->assertNotNull(DB::table('api_tokens')->where('client_id', $c->id)->value('revoked_at'));
        $this->putJson('/api/api-gateway/clients/'.$c->id, $edit + ['version' => 2, 'is_active' => true])->assertOk();
        // re-enabled inside the 1 hour TTL: the old (possibly leaked) bearer must still be dead
        $this->getJson('/api/v1/classrooms', $this->auth($token))->assertStatus(401);
        $this->getJson('/api/v1/classrooms', $this->auth($this->bearer($c, $secret)))->assertOk();
    }

    public function test_work_menu_roles_and_assets(): void
    {
        $this->assertContains('api-gateway', array_column(app(\App\Support\Modules\ModuleRegistry::class)->summary(), 'id'));
        $this->staff('admin')->get('/work')->assertOk()->assertSee('assets/modules/api-gateway/api-gateway.js', false)
            ->assertSee('เชื่อมต่อแอปภายนอก (API)')->assertSee('PIN เข้าแอปเรียนของนักเรียน');
        $this->staff('teacher')->get('/work')->assertOk()->assertSee('PIN เข้าแอปเรียนของนักเรียน')->assertDontSee('เชื่อมต่อแอปภายนอก (API)');
    }

    public function test_machine_routes_skip_csrf_and_session_and_prune_command_cleans_old_rows(): void
    {
        $excluded = fn (string $uri) => collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri)->excludedMiddleware();
        foreach (['api/v1/token', 'oauth/token', 'api/v1/students', 'api/v1/me'] as $uri) {
            $this->assertContains(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, $excluded($uri), $uri);
            $this->assertContains(\Illuminate\Session\Middleware\StartSession::class, $excluded($uri), $uri);
        }
        $this->assertNotContains(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, $excluded('oauth/authorize')); // browser form keeps CSRF
        $this->assertNotContains(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, $excluded('api/api-gateway/clients'));

        [$c] = $this->client(['classrooms:read']);
        DB::table('api_request_log')->insert([
            ['client_id' => $c->id, 'route' => 'a', 'status' => 200, 'ms' => 1, 'at' => now()->subDays(31)],
            ['client_id' => $c->id, 'route' => 'b', 'status' => 200, 'ms' => 1, 'at' => now()->subDays(29)],
        ]);
        DB::table('api_tokens')->insert(['client_id' => $c->id, 'token_hash' => str_repeat('a', 64), 'scopes' => '[]', 'expires_at' => now()->subDays(9), 'created_at' => now()->subDays(10)]);
        DB::table('api_tokens')->insert(['client_id' => $c->id, 'token_hash' => str_repeat('b', 64), 'scopes' => '[]', 'expires_at' => now()->addHour(), 'created_at' => now()]);
        $this->artisan('saan:api-prune --dry-run')->assertSuccessful();
        $this->assertSame(2, DB::table('api_request_log')->count());
        $this->artisan('saan:api-prune')->assertSuccessful();
        $this->assertSame(['b'], DB::table('api_request_log')->pluck('route')->all());
        $this->assertSame([str_repeat('b', 64)], DB::table('api_tokens')->pluck('token_hash')->all());
    }

    public function test_oauth_shares_the_student_secret_store_with_the_portal(): void
    {
        [$c] = $this->client(['sso:student']);
        [, $challenge] = $this->verifier();
        $pin = $this->staff('admin')->postJson('/api/api-gateway/pins/students/1/reset')->assertOk()->json('data.0.pin'); // temporary: must_change
        // the temporary secret opens nothing here: it must first be replaced in the student portal; a correct secret is not a failure
        $res = $this->signIn($c, $challenge, 'DEMO-01', $pin)->assertStatus(403)->assertSee('ต้องเปลี่ยน');
        $this->assertStringNotContainsString('code=', (string) $res->headers->get('Location'));
        $this->assertSame(0, DB::table('api_sso_codes')->count());
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('failed_count'));
        // the student changes it in the portal (a password, not a 6 digit PIN): the learning-app sign-in accepts that secret, and the old one no longer works
        $this->post('/student/login', ['code' => 'DEMO-01', 'secret' => $pin]);
        $this->postJson('/student/api/secret', ['current' => $pin, 'new' => 'quiet-forest-9'])->assertOk();
        $this->codeFrom($this->signIn($c, $challenge, 'DEMO-01', 'quiet-forest-9'));
        $this->signIn($c, $challenge, 'DEMO-01', $pin)->assertStatus(401);
        // PIN of 8 digits chosen in the portal works as well
        $this->postJson('/student/api/secret', ['current' => 'quiet-forest-9', 'new' => '48271593'])->assertOk();
        $this->codeFrom($this->signIn($c, $challenge, 'DEMO-01', '48271593'));
        $this->assertSame(0, (int) DB::table('api_student_pins')->where('student_id', 1)->value('failed_count'));
    }
}
