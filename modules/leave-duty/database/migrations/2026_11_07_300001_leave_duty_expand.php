<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// leave-duty: expand-only. Existing leave_requests / duties rows keep working (legacy status 'pending' is read as 'submitted').
return new class extends Migration {
    private function fiscal(string $date): int
    {
        $y = (int) substr($date, 0, 4);
        return ((int) substr($date, 5, 2) >= 10 ? $y + 1 : $y) + 543;
    }

    public function up(): void
    {
        $add = fn (string $table, string $col, Closure $def) => Schema::hasColumn($table, $col) ?: Schema::table($table, fn (Blueprint $t) => $def($t));

        $add('leave_requests', 'version', fn ($t) => $t->unsignedInteger('version')->default(1));
        $add('leave_requests', 'half_day', fn ($t) => $t->string('half_day', 2)->nullable());
        $add('leave_requests', 'days', fn ($t) => $t->decimal('days', 4, 1)->default(0));
        $add('leave_requests', 'fiscal_year', fn ($t) => $t->unsignedSmallInteger('fiscal_year')->nullable());
        $add('leave_requests', 'attachment_path', fn ($t) => $t->string('attachment_path', 255)->nullable());
        $add('leave_requests', 'attachment_name', fn ($t) => $t->string('attachment_name', 160)->nullable());
        $add('leave_requests', 'attachment_bytes', fn ($t) => $t->unsignedBigInteger('attachment_bytes')->default(0));
        $add('leave_requests', 'decision_note', fn ($t) => $t->string('decision_note', 500)->nullable());
        $add('leave_requests', 'cancel_reason', fn ($t) => $t->string('cancel_reason', 500)->nullable());
        $add('leave_requests', 'submitted_at', fn ($t) => $t->timestamp('submitted_at')->nullable());
        $add('leave_requests', 'withdrawn_at', fn ($t) => $t->timestamp('withdrawn_at')->nullable());
        $add('leave_requests', 'cancel_requested_at', fn ($t) => $t->timestamp('cancel_requested_at')->nullable());
        $add('leave_requests', 'cancelled_at', fn ($t) => $t->timestamp('cancelled_at')->nullable());
        $add('leave_requests', 'resubmitted_from', fn ($t) => $t->unsignedBigInteger('resubmitted_from')->nullable());

        $add('duties', 'version', fn ($t) => $t->unsignedInteger('version')->default(1));
        $add('duties', 'kind', fn ($t) => $t->string('kind', 20)->default('other'));
        $add('duties', 'cancelled_reason', fn ($t) => $t->string('cancelled_reason', 300)->nullable());
        $add('duties', 'replaced_by_teacher_id', fn ($t) => $t->unsignedBigInteger('replaced_by_teacher_id')->nullable());

        if (!Schema::hasTable('ld_leave_types')) {
            Schema::create('ld_leave_types', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->string('code', 30);
                $t->string('label', 120);
                $t->string('label_key', 160);
                $t->decimal('yearly_quota_days', 5, 1)->nullable();
                $t->decimal('needs_attachment_after_days', 4, 1)->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['school_id', 'code']);
                $t->unique(['school_id', 'label_key']);
            });
        }

        if (!Schema::hasTable('ld_duty_swaps')) {
            Schema::create('ld_duty_swaps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('duty_id');
                $t->unsignedBigInteger('from_teacher_id');
                $t->unsignedBigInteger('to_teacher_id');
                $t->string('status', 12)->default('requested');
                // equals duty_id while the swap is open (requested/accepted), NULL once closed: the unique index allows one open swap per duty
                $t->unsignedBigInteger('open_duty_id')->nullable();
                $t->string('note', 300)->nullable();
                $t->string('decision_note', 300)->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique('open_duty_id');
                $t->index(['school_id', 'status']);
                $t->index(['school_id', 'from_teacher_id']);
                $t->index(['school_id', 'to_teacher_id']);
            });
        }

        // backfill derived columns of legacy leave rows (weekdays only: the holiday table may not exist yet)
        foreach (DB::table('leave_requests')->whereNull('fiscal_year')->orderBy('id')->get(['id', 'starts_on', 'ends_on']) as $r) {
            $d = new DateTimeImmutable((string) $r->starts_on);
            $end = new DateTimeImmutable((string) $r->ends_on);
            $n = 0;
            for ($i = 0; $d <= $end && $i < 400; $i++, $d = $d->modify('+1 day')) if ((int) $d->format('N') < 6) $n++;
            DB::table('leave_requests')->where('id', $r->id)->update(['fiscal_year' => $this->fiscal((string) $r->starts_on), 'days' => $n]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: expand-only module (disable_policy keep-data); leave types and swap history must not be dropped by a rollback.
    }
};
