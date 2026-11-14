<?php

namespace App\Support\Saan;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Replays the first result of a mutation when the client retries with the same Idempotency-Key header. */
class Idempotency
{
    /**
     * $rebuild (optional) is for responses that carry sensitive data (health profile, guardian phones): only status, type and the
     * request fingerprint are cached, never the body. A replay calls $rebuild() to produce a fresh body through the normal
     * authorised and audited read path, so the cache table never holds a plaintext copy.
     */
    public static function run(Request $r, Closure $fn, ?Closure $rebuild = null): mixed
    {
        $key = trim((string) $r->header('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > 80) return $fn();
        $name = 'idem:'.sha1((int) $r->user()?->id.'|'.$r->method().'|'.$r->path().'|'.(int) config('school.school_id').'|'.$key);
        // the key is bound to the exact request: a reused key with another body must not silently replay the first result
        $fingerprint = hash('sha256', $r->method().'|'.$r->path().'|'.($r->getContent() ?: json_encode($r->post()).'|'.implode(',', array_map(fn ($f) => is_array($f) ? count($f) : $f->getSize().':'.$f->getClientOriginalName(), $r->allFiles()))));
        $replay = function (array $hit) use ($fingerprint, $rebuild) {
            if (isset($hit['hash']) && !hash_equals((string) $hit['hash'], $fingerprint)) abort(422, 'Idempotency-Key ถูกใช้ซ้ำกับข้อมูลที่ต่างจากเดิม กรุณาลองส่งใหม่');
            if (!empty($hit['rebuild']) && $rebuild) {
                $fresh = $rebuild();
                if (!$fresh instanceof Response) $fresh = response()->json($fresh, (int) $hit['status']);
                $fresh->setStatusCode((int) $hit['status']);
                $fresh->headers->set('Idempotent-Replayed', 'true');
                return $fresh;
            }
            return self::restore($hit);
        };
        if (($hit = Cache::get($name)) !== null) return $replay($hit);
        try {
            return Cache::lock($name.':lock', 300)->block(5, function () use ($name, $fn, $replay, $fingerprint, $rebuild) {
                if (($hit = Cache::get($name)) !== null) return $replay($hit);
                $result = $fn();
                // normalise to a Response so the cached pack is plain scalars (cache.serializable_classes is false)
                if (!$result instanceof Response) $result = response()->json($result);
                if ($result->isSuccessful()) {
                    $pack = self::pack($result) + ['hash' => $fingerprint];
                    if ($rebuild) $pack = ['status' => $pack['status'], 'body' => '', 'type' => $pack['type'], 'hash' => $fingerprint, 'rebuild' => true];
                    Cache::put($name, $pack, now()->addDay());
                }
                return $result;
            });
        } catch (LockTimeoutException) {
            abort(409, 'คำขอเดิมกำลังประมวลผลอยู่ กรุณารอสักครู่');
        }
    }

    private static function pack(Response $result): array
    {
        return ['status' => $result->getStatusCode(), 'body' => (string) $result->getContent(), 'type' => $result->headers->get('Content-Type')];
    }

    private static function restore(array $hit): mixed
    {
        $headers = ['Idempotent-Replayed' => 'true'] + ($hit['type'] ? ['Content-Type' => $hit['type']] : []);
        return new \Illuminate\Http\Response($hit['body'], $hit['status'], $headers);
    }
}