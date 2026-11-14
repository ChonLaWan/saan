<?php

namespace Saan\Modules\LearningLinks\Http;

use Illuminate\Http\Request;
use Saan\Modules\LearningLinks\Links;

/** /learn/{classroom}: public page for students. A short classroom code unlocks titles and links only; no student data is read here. */
class PublicController
{
    private const GENERIC = 'รหัสไม่ถูกต้องหรือหมดอายุ ลองถามครูประจำชั้นอีกครั้ง';

    private function page(string $view, array $data, int $status = 200)
    {
        return response()->view('learning-links::'.$view, $data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function valid(Request $r, int $id): ?object
    {
        $a = Links::q('lrn_access')->where('classroom_id', $id)->first();
        if (!$a || strtotime((string) $a->expires_at) <= time()) return null;
        $grant = $r->session()->get('lrn.grant.'.$id);
        return is_string($grant) && hash_equals((string) $a->code_hash, $grant) ? $a : null;
    }

    private function unlocked(int $id)
    {
        // S2: the public edition has no classrooms table content, only the rooms with an unexpired code (pub_learning_rooms: id and name)
        $room = Links::q(\App\Support\Saan\Edition::isPublic() ? 'pub_learning_rooms' : 'classrooms')->where('id', $id)->value('name');
        return $this->page('public', ['room' => $room, 'links' => Links::publicLinks($id), 'error' => null, 'id' => $id]);
    }

    public function show(Request $r, int $id)
    {
        if ($this->valid($r, $id)) return $this->unlocked($id);
        return $this->page('public', ['room' => null, 'links' => null, 'error' => null, 'id' => $id]);
    }

    public function unlock(Request $r, int $id)
    {
        $code = (string) $r->input('code', '');
        $a = Links::q('lrn_access')->where('classroom_id', $id)->first();
        // compare against a dummy hash when there is no code, so unknown rooms and wrong codes take the same path
        $stored = $a ? (string) $a->code_hash : str_repeat('0', 64);
        $ok = Links::codeMatches($stored, $id, $code) && $a && strtotime((string) $a->expires_at) > time() && strlen(Links::normaliseCode($code)) === 8;
        if (!$ok) return $this->page('public', ['room' => null, 'links' => null, 'error' => self::GENERIC, 'id' => $id], 422);
        $r->session()->put('lrn.grant.'.$id, $stored);
        return $this->unlocked($id);
    }
}
