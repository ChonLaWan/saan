<?php

namespace Saan\Modules\Constructionism;

use App\Support\Saan\Mutation;

/** One reflection (text + rubric levels per criterion) per member of a project. Entered by the teacher. */
class Reflections
{
    public static function shape(object $r, array $students): array
    {
        $scores = json_decode((string) $r->rubric, true);
        return ['id' => (int) $r->id, 'student_id' => (int) $r->student_id, 'student_name' => $students[$r->student_id]['name'] ?? '(ไม่พบนักเรียน)', 'text' => $r->text,
            'rubric' => is_array($scores) ? $scores : [], 'version' => (int) $r->version];
    }

    private static function fields(array $d): array
    {
        $text = Pbl::text($d['text'] ?? null, 'text', 4000);
        $rubric = Rubric::scores($d['rubric'] ?? null);
        if ($text === null && $rubric === null) Pbl::invalid('text', 'กรอกการสะท้อนคิดหรือให้ระดับรูบริกอย่างน้อยหนึ่งอย่าง');
        return ['text' => $text, 'rubric' => $rubric];
    }

    public static function add(int $projectId, array $d, ?object $user): array
    {
        $p = Pbl::writable($projectId, $user);
        $sid = (int) $d['student_id'];
        if (!Pbl::q('con_members')->where('project_id', $projectId)->where('student_id', $sid)->exists()) Pbl::invalid('student_id', 'นักเรียนคนนี้ไม่ได้เป็นสมาชิกโครงงาน');
        if (Pbl::q('con_reflections')->where('project_id', $projectId)->where('student_id', $sid)->exists()) abort(409, 'นักเรียนคนนี้มีการสะท้อนคิดในโครงงานนี้แล้ว ให้แก้ไขรายการเดิม');
        $f = self::fields($d);
        Pbl::guard(fn () => Mutation::insert('con_reflections', $f + ['project_id' => $projectId, 'student_id' => $sid], 'pbl.reflection.add', ['project_id' => $projectId, 'student_id' => $sid]));
        return Pbl::detail($p, $user);
    }

    public static function update(int $id, array $d, ?object $user): array
    {
        [$row, $p] = Pbl::childOf('con_reflections', $id, $user);
        // a partial update keeps the fields the request did not send
        if (!array_key_exists('text', $d)) $d['text'] = $row->text;
        if (!array_key_exists('rubric', $d)) $d['rubric'] = json_decode((string) $row->rubric, true);
        Pbl::safe(fn () => Mutation::update('con_reflections', $id, (int) $d['version'], self::fields($d), 'pbl.reflection.update'));
        return Pbl::detail($p, $user);
    }

    public static function remove(int $id, int $version, ?object $user): array
    {
        [, $p] = Pbl::childOf('con_reflections', $id, $user);
        Pbl::safe(fn () => Mutation::delete('con_reflections', $id, $version, 'pbl.reflection.remove'));
        return Pbl::detail($p, $user);
    }
}
