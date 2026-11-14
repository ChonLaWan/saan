<?php

namespace App\Support\Saan;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Room history of a student. No-op until the student_classroom_moves table exists. */
class ClassroomMoves
{
    public static function record(int $schoolId, int $studentId, ?int $from, ?int $to, ?string $on = null): void
    {
        if ($from === $to || !Schema::hasTable('student_classroom_moves')) return;
        DB::table('student_classroom_moves')->insert(['school_id' => $schoolId, 'student_id' => $studentId, 'from_classroom_id' => $from, 'to_classroom_id' => $to,
            'effective_on' => $on ?? now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Room of a student on a date given its recorded moves (ordered by effective_on, id) and the room it is in now. */
    public static function roomOn(array $moves, ?int $current, string $date): ?int
    {
        foreach ($moves as $m) {
            if ($m['on'] > $date) return $m['from'];
        }
        // No move after $date: the room is the destination of the last recorded move (the real current room),
        // not $current, which for a past term may be a guessed dominant room.
        if ($moves) {
            $last = end($moves);
            return isset($last['to']) ? (int) $last['to'] : $current;
        }
        return $current;
    }
}