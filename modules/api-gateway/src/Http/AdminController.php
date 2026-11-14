<?php

namespace Saan\Modules\ApiGateway\Http;

use App\Support\Saan\Mutation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Saan\Modules\ApiGateway\Gateway;
use Saan\Modules\ApiGateway\Net;
use Saan\Modules\ApiGateway\Scopes;

/**
 * Admin screen backend (#api). Admin role only.
 * The client secret is returned exactly once (create / rotate) and only its hash is stored.
 * Create and rotate deliberately do NOT use Idempotency-Key replay: that would keep the secret in the cache.
 * A retried create hits the unique name (409); a lost rotate response is fixed by rotating again.
 */
class AdminController
{
    public function scopes(): JsonResponse
    {
        return response()->json(['data' => collect(Scopes::ASSIGNABLE)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()]);
    }

    public function index(): JsonResponse
    {
        $rows = DB::table('api_clients')->where('school_id', Gateway::school())->orderBy('id')->get();
        return response()->json(['data' => $rows->map(fn ($row) => $this->present($row))->all()])->header('Cache-Control', 'no-store');
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate($this->rules());
        $this->checkSso($d);
        $key = Net::key($d['name']);
        if ($this->nameTaken($key, null)) return response()->json(['message' => 'มีไคลเอนต์ชื่อนี้แล้ว'], 409);
        $secret = bin2hex(random_bytes(32));
        try {
            $row = Mutation::insert('api_clients', [
                'name' => trim($d['name']), 'name_key' => $key, 'client_id' => 'cl_'.bin2hex(random_bytes(10)),
                'secret_hash' => \Illuminate\Support\Facades\Hash::make($secret),
                'scopes' => array_values(array_unique($d['scopes'])), 'allowed_ips' => $this->list($d['allowed_ips'] ?? []),
                'redirect_uris' => $this->list($d['redirect_uris'] ?? []), 'rate_per_minute' => (int) ($d['rate_per_minute'] ?? 120),
                'is_active' => true,
            ], 'api_client.create', ['name' => trim($d['name']), 'scopes' => $d['scopes']]);
        } catch (\Throwable $e) {
            if (Net::isUniqueViolation($e)) return response()->json(['message' => 'มีไคลเอนต์ชื่อนี้แล้ว'], 409);
            throw $e;
        }
        return response()->json(['client' => $this->present($row), 'client_secret' => $secret], 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'is_active' => 'sometimes|boolean'] + $this->rules());
        $this->checkSso($d);
        $key = Net::key($d['name']);
        if ($this->nameTaken($key, $id)) return response()->json(['message' => 'มีไคลเอนต์ชื่อนี้แล้ว'], 409);
        $changes = [
            'name' => trim($d['name']), 'name_key' => $key, 'scopes' => array_values(array_unique($d['scopes'])),
            'allowed_ips' => $this->list($d['allowed_ips'] ?? []), 'redirect_uris' => $this->list($d['redirect_uris'] ?? []),
            'rate_per_minute' => (int) ($d['rate_per_minute'] ?? 120),
        ];
        if (array_key_exists('is_active', $d)) $changes['is_active'] = (bool) $d['is_active'];
        try {
            $row = DB::transaction(function () use ($id, $d, $changes) {
                $wasActive = (bool) DB::table('api_clients')->where('school_id', Gateway::school())->where('id', $id)->value('is_active');
                $row = Mutation::update('api_clients', $id, (int) $d['version'], $changes, 'api_client.update', ['scopes' => $changes['scopes'], 'is_active' => $changes['is_active'] ?? null]);
                // deactivating (usually because a token leaked) must also revoke: re-enabling later must not revive old bearers
                if ($wasActive && array_key_exists('is_active', $changes) && !$changes['is_active']) Gateway::revokeTokens($id);
                return $row;
            });
        } catch (\Throwable $e) {
            if (Net::isUniqueViolation($e)) return response()->json(['message' => 'มีไคลเอนต์ชื่อนี้แล้ว'], 409);
            throw $e;
        }
        return response()->json(['client' => $this->present($row)]);
    }

    public function rotate(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $secret = bin2hex(random_bytes(32));
        $row = DB::transaction(function () use ($id, $d, $secret) {
            $row = Mutation::update('api_clients', $id, (int) $d['version'], ['secret_hash' => \Illuminate\Support\Facades\Hash::make($secret)], 'api_client.rotate');
            Gateway::revokeTokens($id); // tokens minted with the old secret stop working at once
            return $row;
        });
        return response()->json(['client' => $this->present($row), 'client_secret' => $secret])->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $row = DB::transaction(function () use ($id, $d) {
            $row = Mutation::update('api_clients', $id, (int) $d['version'], ['is_active' => false], 'api_client.revoke');
            Gateway::revokeTokens($id);
            return $row;
        });
        return response()->json(['client' => $this->present($row)]);
    }

    /** Calls per client per day for the last 7 days (including today), total and errors (status 400+). */
    public function usage(): JsonResponse
    {
        $days = [];
        for ($i = 6; $i >= 0; $i--) $days[] = CarbonImmutable::now()->subDays($i)->toDateString();
        $clients = DB::table('api_clients')->where('school_id', Gateway::school())->orderBy('id')->get(['id', 'name', 'client_id']);
        $at = DB::getQueryGrammar()->wrap('at');
        $rows = DB::table('api_request_log')->whereIn('client_id', $clients->pluck('id')->all() ?: [0])->where('at', '>=', $days[0].' 00:00:00')
            ->groupBy('client_id', DB::raw("DATE($at)"))
            ->get(['client_id', DB::raw("DATE($at) as day"), DB::raw('COUNT(*) as calls'), DB::raw('SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) as errors')]);
        $grid = [];
        foreach ($rows as $row) $grid[$row->client_id][substr((string) $row->day, 0, 10)] = [(int) $row->calls, (int) $row->errors];
        $data = $clients->map(fn ($c) => [
            'id' => (int) $c->id, 'name' => $c->name, 'client_id' => $c->client_id,
            'days' => array_map(fn ($d) => ['date' => $d, 'calls' => $grid[$c->id][$d][0] ?? 0, 'errors' => $grid[$c->id][$d][1] ?? 0], $days),
        ])->all();
        return response()->json(['days' => $days, 'data' => $data]);
    }

    private function rules(): array
    {
        $ip = function (string $attr, mixed $value, \Closure $fail) { if (!is_string($value) || !Net::validIpOrCidr($value)) $fail('ที่อยู่ IP หรือ CIDR ไม่ถูกต้อง'); };
        $uri = function (string $attr, mixed $value, \Closure $fail) {
            $p = is_string($value) ? parse_url($value) : false;
            $ok = $p && isset($p['scheme'], $p['host']) && !isset($p['fragment']) && !isset($p['user'])
                && ($p['scheme'] === 'https' || ($p['scheme'] === 'http' && in_array($p['host'], ['localhost', '127.0.0.1'], true)));
            if (!$ok) $fail('redirect URI ต้องเป็น https (หรือ http บน localhost) และไม่มี fragment');
        };
        return [
            'name' => 'required|string|max:120', 'scopes' => 'required|array|min:1|max:20', 'scopes.*' => ['string', Rule::in(Scopes::assignable())],
            'allowed_ips' => 'nullable|array|max:30', 'allowed_ips.*' => ['string', 'max:64', $ip],
            'redirect_uris' => 'nullable|array|max:10', 'redirect_uris.*' => ['string', 'max:500', $uri],
            'rate_per_minute' => 'nullable|integer|min:1|max:6000',
        ];
    }

    private function checkSso(array $d): void
    {
        if (in_array('students:read:contact', $d['scopes'], true) && !in_array('students:read:basic', $d['scopes'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['scopes' => 'scope ช่องทางติดต่อผู้ปกครองต้องเลือกรายชื่อนักเรียน (students:read:basic) ควบคู่กัน']);
        }
        if (in_array(Scopes::SSO, $d['scopes'], true) && empty($d['redirect_uris'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['redirect_uris' => 'scope เข้าสู่ระบบนักเรียนต้องระบุ redirect URI อย่างน้อยหนึ่งรายการ']);
        }
    }

    private function list(array $values): ?array
    {
        $out = array_values(array_unique(array_map(fn ($v) => trim((string) $v), $values)));
        return $out ?: null;
    }

    private function nameTaken(string $key, ?int $exceptId): bool
    {
        $q = DB::table('api_clients')->where('school_id', Gateway::school())->where('name_key', $key);
        if ($exceptId !== null) $q->where('id', '!=', $exceptId);
        return $q->exists();
    }

    /** Never includes secret_hash. */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id, 'name' => $row->name, 'client_id' => $row->client_id, 'scopes' => Gateway::json($row->scopes),
            'allowed_ips' => Gateway::json($row->allowed_ips), 'redirect_uris' => Gateway::json($row->redirect_uris),
            'rate_per_minute' => (int) $row->rate_per_minute, 'is_active' => (bool) $row->is_active,
            'last_used_at' => $row->last_used_at, 'version' => (int) $row->version,
        ];
    }
}
