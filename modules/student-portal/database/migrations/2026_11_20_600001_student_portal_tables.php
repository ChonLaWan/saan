<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// student-portal (F8): the student sign-in reuses the api-gateway PIN store (api_student_pins: bcrypt of an HMAC digest, failed_count, locked_until, lock_count)
// and adds what the portal needs next to it: a temporary-secret flag, auth_version (a reset or change ends every session), last sign-in.
// stu_events audits what a student does (a student is not a users row, so audit_events cannot hold it). Expand-only, no foreign keys.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('api_student_pins')) {
            $add = fn (string $col, Closure $def) => Schema::hasColumn('api_student_pins', $col) ?: Schema::table('api_student_pins', fn (Blueprint $t) => $def($t));
            $fresh = !Schema::hasColumn('api_student_pins', 'must_change');
            $add('must_change', fn ($t) => $t->boolean('must_change')->default(false));
            // PINs that existed before the portal were printed on slips: treat them as temporary, so an old slip is not a permanent password for grades
            if ($fresh) DB::table('api_student_pins')->update(['must_change' => true]);
            $add('auth_version', fn ($t) => $t->unsignedInteger('auth_version')->default(1));
            $add('secret_kind', fn ($t) => $t->string('secret_kind', 10)->default('pin'));
            $add('last_login_at', fn ($t) => $t->timestamp('last_login_at')->nullable());
            $add('changed_at', fn ($t) => $t->timestamp('changed_at')->nullable());
        }
        if (!Schema::hasTable('stu_events')) Schema::create('stu_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id')->nullable();
            $t->string('action', 40); $t->text('detail')->nullable(); $t->char('ip_hash', 64)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['school_id', 'student_id', 'created_at'], 'stu_events_student');
        });
    }

    public function down(): void
    {
        // Intentionally empty: expand-only; audit history must not be dropped by a rollback.
    }
};
