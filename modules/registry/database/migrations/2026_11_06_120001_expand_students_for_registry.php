<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// registry: expand-only change of the core students table (name, code and classroom_id keep working).
return new class extends Migration {
    public function up(): void
    {
        // each column is checked on its own, so a column added earlier by core or another module never hides the rest
        $cols = [
            'prefix' => fn (Blueprint $t) => $t->string('prefix', 30)->nullable(),
            'first_name' => fn (Blueprint $t) => $t->string('first_name', 120)->nullable(),
            'last_name' => fn (Blueprint $t) => $t->string('last_name', 120)->nullable(),
            'nickname' => fn (Blueprint $t) => $t->string('nickname', 60)->nullable(),
            'sex' => fn (Blueprint $t) => $t->string('sex', 1)->nullable(),
            'birth_date' => fn (Blueprint $t) => $t->date('birth_date')->nullable(),
            'birth_year_be' => fn (Blueprint $t) => $t->unsignedSmallInteger('birth_year_be')->nullable(),
            'nationality_status' => fn (Blueprint $t) => $t->string('nationality_status', 16)->default('thai'),
            'preferred_language' => fn (Blueprint $t) => $t->string('preferred_language', 40)->nullable(),
            'needs_interpreter' => fn (Blueprint $t) => $t->boolean('needs_interpreter')->default(false),
            'status' => fn (Blueprint $t) => $t->string('status', 20)->default('studying'),
            'admitted_on' => fn (Blueprint $t) => $t->date('admitted_on')->nullable(),
            'admission_type' => fn (Blueprint $t) => $t->string('admission_type', 30)->nullable(),
            'previous_school' => fn (Blueprint $t) => $t->string('previous_school', 200)->nullable(),
            'roll_number' => fn (Blueprint $t) => $t->unsignedSmallInteger('roll_number')->nullable(),
            'external_ref' => fn (Blueprint $t) => $t->string('external_ref', 60)->nullable(),
            'code_key' => fn (Blueprint $t) => $t->string('code_key', 80)->nullable(),
            'version' => fn (Blueprint $t) => $t->unsignedInteger('version')->default(1),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ];
        foreach ($cols as $name => $add) {
            if (!Schema::hasColumn('students', $name)) Schema::table('students', fn (Blueprint $t) => $add($t));
        }
        $indexes = [
            'students_school_status' => [['school_id', 'status'], false],
            'students_school_class' => [['school_id', 'classroom_id'], false],
            'students_school_extref' => [['school_id', 'external_ref'], false],
            // case-insensitive uniqueness is done in code (mb_strtolower) and not by DB collation
            'students_school_codekey' => [['school_id', 'code_key'], true],
        ];
        $have = array_column(Schema::getIndexes('students'), 'name');
        foreach ($indexes as $name => [$columns, $unique]) {
            if (in_array($name, $have, true)) continue;
            Schema::table('students', fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
        }        Schema::table('students', function (Blueprint $t) {
            $t->unsignedBigInteger('classroom_id')->nullable()->change();
        });

        DB::table('students')->where('version', '<', 1)->update(['version' => 1]);
        $seen = [];
        foreach (DB::table('students')->whereNull('code_key')->orderBy('id')->get(['id', 'school_id', 'code']) as $row) {
            $key = mb_strtolower(trim((string) $row->code));
            if ($key === '' || isset($seen[$row->school_id.'|'.$key])
                || DB::table('students')->where('school_id', $row->school_id)->where('code_key', $key)->exists()) continue;
            $seen[$row->school_id.'|'.$key] = true;
            DB::table('students')->where('id', $row->id)->update(['code_key' => $key]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: student registry data must not be dropped by a rollback.
    }
};
