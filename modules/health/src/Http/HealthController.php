<?php

namespace Saan\Modules\Health\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Saan\Modules\Health\Measurements;

/** Roles are enforced by route middleware (admin, principal, teacher); classroom access = homeroom only (TeacherScope::homeroomIds). */
class HealthController
{
    public const BLOOD = ['A', 'B', 'AB', 'O', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    public const PROFILE_FIELDS = ['blood_group', 'allergies', 'chronic_conditions', 'medications', 'emergency_note'];

    public function __construct(private Measurements $m) {}

    /** Existence (404) and the homeroom check (403) are enforced inside Measurements::classAndStudents() in one query. */
    private function classroom(int $id): int
    {
        return $id;
    }

    private function round(Request $r): int
    {
        $round = $r->query('round', 1);
        if (!is_numeric($round) || !in_array((int) $round, Measurements::ROUNDS, true)) abort(422, 'รอบการวัดต้องเป็น 1 ถึง 4');
        return (int) $round;
    }

    private static function noStore(JsonResponse $r): JsonResponse
    {
        return $r->header('Cache-Control', 'private, no-store');
    }

    public function classrooms()
    {
        $ids = TeacherScope::homeroomIds();
        $rows = Measurements::sortClasses(Measurements::activeClassrooms($ids)->all());
        return self::noStore(response()->json(['data' => array_map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name], $rows), 'rounds' => Measurements::ROUNDS]));
    }

    public function grid(Request $r, int $id)
    {
        $round = $this->round($r);
        return self::noStore(response()->json($this->m->grid($this->classroom($id), $round)));
    }

    public function save(Request $r, int $id)
    {
        $cid = $this->classroom($id);
        $today = now(config('app.timezone', 'Asia/Bangkok'))->toDateString();
        $d = $r->validate([
            'round' => ['required', 'integer', Rule::in(Measurements::ROUNDS)],
            'measured_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'rows' => ['required', 'array', 'min:1', 'max:'.Measurements::MAX_ROWS],
            'rows.*' => ['array:student_id,weight_kg,height_cm,version'],
            'rows.*.student_id' => ['required', 'integer', 'min:1', 'distinct'],
            'rows.*.weight_kg' => ['nullable', 'numeric', 'between:'.Measurements::WEIGHT_MIN.','.Measurements::WEIGHT_MAX],
            'rows.*.height_cm' => ['nullable', 'numeric', 'between:'.Measurements::HEIGHT_MIN.','.Measurements::HEIGHT_MAX],
            'rows.*.version' => ['nullable', 'integer', 'min:0'],
        ], [
            'rows.max' => 'บันทึกได้ครั้งละไม่เกิน '.Measurements::MAX_ROWS.' คน',
            'rows.*.weight_kg.between' => 'น้ำหนักต้องอยู่ระหว่าง '.Measurements::WEIGHT_MIN.' ถึง '.Measurements::WEIGHT_MAX.' กก.',
            'rows.*.weight_kg.numeric' => 'น้ำหนักต้องเป็นตัวเลข',
            'rows.*.height_cm.between' => 'ส่วนสูงต้องอยู่ระหว่าง '.Measurements::HEIGHT_MIN.' ถึง '.Measurements::HEIGHT_MAX.' ซม.',
            'rows.*.height_cm.numeric' => 'ส่วนสูงต้องเป็นตัวเลข',
            'rows.*.student_id.distinct' => 'มีนักเรียนซ้ำในตาราง',
            'measured_on.before_or_equal' => 'วันที่วัดต้องไม่เป็นวันในอนาคต',
        ]);

        return Idempotency::run($r, function () use ($cid, $d) {
            $res = $this->m->save($cid, (int) $d['round'], $d['measured_on'], $d['rows']);
            if (isset($res['conflicts'])) {
                $msg = !empty($res['race']) ? 'มีผู้อื่นบันทึกพร้อมกัน กรุณาโหลดใหม่' : 'มีนักเรียน '.count($res['conflicts']).' คนที่ถูกแก้ไขโดยผู้อื่น ยังไม่ได้บันทึกอะไรเลย';
                return response()->json(['message' => $msg, 'current' => $res['conflicts'], 'conflicts' => $res['conflicts']], 409);
            }
            return response()->json(['round' => (int) $d['round'], 'measured_on' => $d['measured_on']] + $res);
        });
    }

    public function summary(Request $r, int $id)
    {
        $round = $this->round($r);
        return self::noStore(response()->json($this->m->summary($this->classroom($id), $round)));
    }

    public function overview(Request $r)
    {
        $round = $this->round($r);
        return self::noStore(response()->json($this->m->overview($round, TeacherScope::homeroomIds())));
    }

    public function export(Request $r, int $id)
    {
        $round = $this->round($r);
        $cid = $this->classroom($id);
        $body = $this->m->csv($cid, $round);
        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="health-class'.$cid.'-round'.$round.'.csv"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function student(int $id): object
    {
        $s = DB::table('students')->where('school_id', (int) config('school.school_id'))->where('id', $id)->first(['id', 'code', 'name', 'classroom_id', 'status']);
        abort_if($s && !TeacherScope::canSeeStudent($s), 404, 'ไม่พบนักเรียน'); // graduated/transferred pupils are not a teacher's to read
        $ids = TeacherScope::homeroomIds();
        if (!$s || ($ids !== null && !in_array((int) $s->classroom_id, $ids, true))) abort(403, 'ไม่มีสิทธิ์ดูข้อมูลสุขภาพของนักเรียนคนนี้');
        return $s;
    }

    private function profileRow(int $studentId): ?object
    {
        return DB::table('hlth_profiles')->where('school_id', (int) config('school.school_id'))->where('student_id', $studentId)->first();
    }

    private static function profileJson(object $s, ?object $p): array
    {
        $out = ['student' => ['id' => (int) $s->id, 'code' => (string) $s->code, 'name' => (string) $s->name, 'classroom_id' => $s->classroom_id === null ? null : (int) $s->classroom_id],
            'profile' => ['version' => $p ? (int) $p->version : 0]];
        foreach (self::PROFILE_FIELDS as $f) $out['profile'][$f] = in_array($f, \Saan\Modules\Health\ProfileCrypt::FIELDS, true) ? \Saan\Modules\Health\ProfileCrypt::decrypt($p->$f ?? null) : ($p->$f ?? null);
        return $out;
    }

    /** Full profile: admin, principal and the homeroom teacher only; every read is audited. */
    public function profile(int $id)
    {
        $s = $this->student($id);
        Mutation::audit('health.profile.read', 'students', (int) $s->id, []);
        return self::noStore(response()->json(self::profileJson($s, $this->profileRow((int) $s->id))));
    }

    public function saveProfile(Request $r, int $id)
    {
        $s = $this->student($id);
        $d = $r->validate([
            'version' => 'required|integer|min:0',
            'blood_group' => ['nullable', Rule::in(self::BLOOD)],
            'allergies' => 'nullable|string|max:2000', 'chronic_conditions' => 'nullable|string|max:2000',
            'medications' => 'nullable|string|max:2000', 'emergency_note' => 'nullable|string|max:2000',
        ]);
        $values = \Saan\Modules\Health\ProfileCrypt::encryptValues(array_intersect_key($d, array_flip(self::PROFILE_FIELDS)));
        return Idempotency::run($r, function () use ($s, $d, $values) {
            $detail = ['fields' => array_keys($values)]; // content is never copied into the audit log
            $existing = $this->profileRow((int) $s->id);
            // A 409 body never carries health content (it would be an unaudited read): version only.
            $conflict = fn () => new \App\Support\Saan\VersionConflict(['version' => (int) ($this->profileRow((int) $s->id)->version ?? 0)]);
            if ((int) $d['version'] === 0) {
                if ($existing) throw $conflict();
                try {
                    $row = Mutation::insert('hlth_profiles', $values + ['student_id' => (int) $s->id, 'updated_by' => auth()->id()], 'health.profile.create', $detail);
                } catch (\Illuminate\Database\QueryException $e) {
                    if (!Measurements::isUniqueViolation($e)) throw $e;
                    throw new \App\Support\Saan\VersionConflict(null);
                }
            } else {
                if (!$existing) abort(404, 'ยังไม่มีแฟ้มสุขภาพ');
                try {
                    $row = Mutation::update('hlth_profiles', (int) $existing->id, (int) $d['version'], $values + ['updated_by' => auth()->id()], 'health.profile.update', $detail);
                } catch (\App\Support\Saan\VersionConflict) {
                    throw $conflict();
                }
            }
            return self::noStore(response()->json(self::profileJson($s, $row)));
        }, function () use ($s) {
            // replay: the profile text is never kept in the idempotency cache, so it is re-read (and audited) like a normal profile read
            Mutation::audit('health.profile.read', 'students', (int) $s->id, []);
            return self::noStore(response()->json(self::profileJson($s, $this->profileRow((int) $s->id))));
        });
    }
}

