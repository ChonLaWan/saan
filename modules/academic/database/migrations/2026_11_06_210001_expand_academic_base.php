<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// academic: expand-only. Adds version/label/kind/grade columns, makes classrooms.homeroom_teacher_id nullable,
// adds normalised name_key / title_key columns (case/space-insensitive uniqueness is computed in code and backed by a unique index),
// and creates acad_calendar_days. Existing rows keep working: new columns are nullable or have defaults.
// Re-runnable: every column and index is checked first, because DDL is not transactional on MySQL/MariaDB.
return new class extends Migration {
    private const PREFIXES = ['นางสาว', 'น.ส.', 'นาย', 'นาง', 'ว่าที่ร.ต.', 'ว่าที่ ร.ต.', 'ผศ.ดร.', 'ผศ.', 'รศ.', 'ศ.', 'ดร.', 'อาจารย์', 'ครู', 'Mr.', 'Mrs.', 'Miss', 'Ms.'];

    private function key(string $v): string
    {
        if (class_exists(\Normalizer::class)) $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        return mb_strtolower((string) preg_replace('/\s+/u', '', $v));
    }

    /** Same identity as Academic::teacherKey: the name without its title. */
    private function teacherKey(string $name): string
    {
        $left = trim((string) preg_replace('/\s+/u', ' ', $name));
        $list = self::PREFIXES;
        usort($list, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($list as $p) {
            if (mb_stripos($left, $p) === 0) { $rest = trim(mb_substr($left, mb_strlen($p))); if ($rest !== '') $left = $rest; break; }
        }
        return mb_substr($this->key($left), 0, 200);
    }

    /** utf8mb4_bin on MySQL/MariaDB so the unique index never folds case or accents on its own. */
    private function bin(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function keyColumn(Blueprint $t, string $col, int $len)
    {
        $c = $t->string($col, $len)->nullable();
        if ($this->bin()) $c->collation('utf8mb4_bin');
        return $c;
    }

    public function up(): void
    {
        $add = fn (string $table, string $col, Closure $def) => Schema::hasColumn($table, $col) ?: Schema::table($table, fn (Blueprint $t) => $def($t));

        $add('academic_terms', 'label', fn ($t) => $t->string('label', 60)->nullable());
        $add('academic_terms', 'version', fn ($t) => $t->unsignedInteger('version')->default(1));

        $add('bell_periods', 'version', fn ($t) => $t->unsignedInteger('version')->default(1));
        $add('bell_periods', 'kind', fn ($t) => $t->string('kind', 12)->default('class'));

        // classrooms: homeroom teacher becomes optional so a school can create rooms before it has teachers
        Schema::table('classrooms', fn (Blueprint $t) => $t->unsignedBigInteger('homeroom_teacher_id')->nullable()->change());
        $add('classrooms', 'grade_level', fn ($t) => $t->string('grade_level', 20)->nullable());
        $add('classrooms', 'section', fn ($t) => $t->unsignedSmallInteger('section')->nullable());
        $add('classrooms', 'name_key', fn ($t) => $this->keyColumn($t, 'name_key', 120));
        $add('classrooms', 'version', fn ($t) => $t->unsignedInteger('version')->default(1));
        $add('classrooms', 'is_active', fn ($t) => $t->boolean('is_active')->default(true));

        $add('teachers', 'prefix', fn ($t) => $t->string('prefix', 30)->nullable());
        $add('teachers', 'first_name', fn ($t) => $t->string('first_name', 70)->nullable());
        $add('teachers', 'last_name', fn ($t) => $t->string('last_name', 80)->nullable());
        $add('teachers', 'subject_group', fn ($t) => $t->string('subject_group', 120)->nullable());
        $add('teachers', 'user_id', fn ($t) => $t->unsignedBigInteger('user_id')->nullable());
        $add('teachers', 'version', fn ($t) => $t->unsignedInteger('version')->default(1));
        $add('teachers', 'is_active', fn ($t) => $t->boolean('is_active')->default(true));
        $add('teachers', 'name_key', fn ($t) => $this->keyColumn($t, 'name_key', 200));

        // a re-run after a partial failure may find the columns without the binary collation
        if ($this->bin()) {
            DB::statement('ALTER TABLE classrooms MODIFY name_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
            DB::statement('ALTER TABLE teachers MODIFY name_key VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
        }

        // backfill before the unique indexes exist; collisions after normalisation get a ~id suffix instead of failing the migration
        if (!Schema::hasIndex('classrooms', 'classrooms_school_name_key')) {
            $seen = [];
            foreach (DB::table('classrooms')->orderBy('id')->get(['id', 'school_id', 'name', 'name_key', 'grade_level']) as $r) {
                $k = mb_substr($this->key((string) $r->name), 0, 110);
                if (isset($seen[$r->school_id.'|'.$k])) $k = mb_substr($k, 0, 100).'~'.$r->id;
                $seen[$r->school_id.'|'.$k] = true;
                $set = ['name_key' => $k];
                if ($r->grade_level === null && preg_match('~^\s*(\S+?)\s*/\s*(\d{1,3})\s*$~u', (string) $r->name, $m)) { $set['grade_level'] = mb_substr($m[1], 0, 20); $set['section'] = (int) $m[2]; }
                DB::table('classrooms')->where('id', $r->id)->update($set);
            }
        }
        if (!Schema::hasIndex('teachers', 'teachers_school_name_uq')) {
            $seen = [];
            foreach (DB::table('teachers')->orderBy('id')->get(['id', 'school_id', 'name']) as $r) {
                $k = $this->teacherKey((string) $r->name);
                if (isset($seen[$r->school_id.'|'.$k])) $k = mb_substr($k, 0, 190).'~'.$r->id;
                $seen[$r->school_id.'|'.$k] = true;
                DB::table('teachers')->where('id', $r->id)->update(['name_key' => $k]);
            }
        }
        DB::table('academic_terms')->whereNull('version')->update(['version' => 1]);
        DB::table('bell_periods')->whereNull('version')->update(['version' => 1]);
        DB::table('classrooms')->whereNull('version')->update(['version' => 1]);
        DB::table('teachers')->whereNull('version')->update(['version' => 1]);

        if (!Schema::hasIndex('classrooms', 'classrooms_school_name_key')) {
            Schema::table('classrooms', fn (Blueprint $t) => $t->unique(['school_id', 'name_key'], 'classrooms_school_name_key'));
        }
        if (Schema::hasIndex('teachers', 'teachers_school_name_key')) { // earlier non-unique draft of this index
            Schema::table('teachers', fn (Blueprint $t) => $t->dropIndex('teachers_school_name_key'));
        }
        if (!Schema::hasIndex('teachers', 'teachers_school_name_uq')) {
            Schema::table('teachers', fn (Blueprint $t) => $t->unique(['school_id', 'name_key'], 'teachers_school_name_uq'));
        }
        if (!Schema::hasIndex('teachers', 'teachers_user_id')) {
            Schema::table('teachers', fn (Blueprint $t) => $t->index('user_id', 'teachers_user_id'));
        }

        if (!Schema::hasTable('acad_calendar_days')) {
            Schema::create('acad_calendar_days', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->date('date');
                $t->string('kind', 12);
                $t->string('title', 160);
                $this->keyColumn($t, 'title_key', 160);
                $t->unsignedBigInteger('term_id')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->index(['school_id', 'date']);
            });
        }
        $add('acad_calendar_days', 'title_key', fn ($t) => $this->keyColumn($t, 'title_key', 160));
        if (!Schema::hasIndex('acad_calendar_days', 'acad_calendar_day_title_key')) {
            $seen = [];
            foreach (DB::table('acad_calendar_days')->orderBy('id')->get(['id', 'school_id', 'date', 'title']) as $r) {
                $k = mb_substr($this->key((string) $r->title), 0, 160); // same length as Academic::dayValues (a newer migration re-keys rows backfilled at 150)
                if (isset($seen[$r->school_id.'|'.$r->date.'|'.$k])) $k = mb_substr($k, 0, 140).'~'.$r->id;
                $seen[$r->school_id.'|'.$r->date.'|'.$k] = true;
                DB::table('acad_calendar_days')->where('id', $r->id)->update(['title_key' => $k]);
            }
            if (Schema::hasIndex('acad_calendar_days', 'acad_calendar_day_title')) { // earlier draft keyed on the raw title (collation dependent)
                Schema::table('acad_calendar_days', fn (Blueprint $t) => $t->dropUnique('acad_calendar_day_title'));
            }
            Schema::table('acad_calendar_days', fn (Blueprint $t) => $t->unique(['school_id', 'date', 'title_key'], 'acad_calendar_day_title_key'));
        }
    }

    public function down(): void
    {
        // Intentionally empty: base school data must not be dropped by a rollback.
    }
};
