<?php

namespace App\Support\Saan;

use DomainException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Current academic term: setting academic.current_term_id, else config school.term_id, else latest term of the school. */
class TermScope
{
    public static function current(): ?object
    {
        $school = (int) config('school.school_id');
        $find = fn ($id) => $id ? DB::table('academic_terms')->where('school_id', $school)->where('id', (int) $id)->first() : null;
        $setting = Settings::get('academic.current_term_id');
        if (is_numeric($setting) && ($row = $find($setting))) return $row;
        if (($row = $find(config('school.term_id')))) return $row;
        return DB::table('academic_terms')->where('school_id', $school)->orderByDesc('year')->orderByDesc('term')->orderByDesc('id')->first();
    }

    public static function currentId(): ?int
    {
        $row = self::current();
        return $row ? (int) $row->id : null;
    }

    public static function apply(Builder|EloquentBuilder $q, string $column = 'term_id', ?int $termId = null): Builder|EloquentBuilder
    {
        $id = $termId ?? self::currentId();
        if ($id === null) throw new DomainException('ยังไม่ได้ตั้งภาคเรียน');
        return $q->where($column, $id);
    }
}