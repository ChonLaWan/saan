<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// activities (M18): clubs, student-council election, activity certificates. Expand-only; no foreign keys to other modules' tables.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('act_clubs')) {
            Schema::create('act_clubs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('term_id');
                $t->string('name', 160);
                $t->string('name_key', 160); // mb_strtolower + trim + collapsed spaces, computed in code
                $t->unsignedBigInteger('advisor_teacher_id')->nullable();
                $t->unsignedSmallInteger('capacity')->nullable(); // null = unlimited
                $t->json('grade_levels')->nullable();
                $t->text('description')->nullable();
                $t->boolean('is_open')->default(true);
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['school_id', 'term_id', 'name_key'], 'act_clubs_name_key');
                $t->index(['school_id', 'term_id'], 'act_clubs_term');
                $t->index('advisor_teacher_id', 'act_clubs_advisor');
            });
            // binary collation: uniqueness is decided by name_key as computed in code, never by the server's case/accent folding
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE act_clubs MODIFY name_key VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
            }
        }
        if (!Schema::hasTable('act_members')) {
            Schema::create('act_members', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('club_id');
                $t->unsignedBigInteger('term_id');
                $t->unsignedBigInteger('student_id');
                $t->string('status', 10)->default('enrolled'); // enrolled | waitlist | left
                $t->string('active_key', 40)->nullable(); // "<term>:<student>" while enrolled/waitlist, null once left: one active club per student per term
                $t->string('result', 4)->nullable(); // ผ | มผ
                $t->boolean('result_manual')->default(false);
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['club_id', 'student_id'], 'act_members_club_student');
                $t->unique('active_key', 'act_members_active');
                $t->index(['school_id', 'club_id', 'status'], 'act_members_club');
            });
        }
        if (!Schema::hasTable('act_sessions')) {
            Schema::create('act_sessions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('club_id');
                $t->date('date');
                $t->string('topic', 200)->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['club_id', 'date'], 'act_sessions_club_date');
            });
        }
        if (!Schema::hasTable('act_attendance')) {
            Schema::create('act_attendance', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('session_id');
                $t->unsignedBigInteger('student_id');
                $t->boolean('present')->default(true);
                $t->unique(['session_id', 'student_id'], 'act_attendance_session_student');
                $t->index('student_id', 'act_attendance_student');
            });
        }
        if (!Schema::hasTable('act_elections')) {
            Schema::create('act_elections', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->string('title', 200);
                $t->dateTime('opens_at');
                $t->dateTime('closes_at');
                $t->json('eligible_grade_levels')->nullable(); // empty = every grade
                $t->string('status', 10)->default('draft'); // draft | open | closed
                $t->string('key_secret', 64)->nullable(); // HMAC key for voter_key; wiped on close so ballots can never be re-linked
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->index(['school_id', 'status'], 'act_elections_status');
            });
        }
        if (!Schema::hasTable('act_candidates')) {
            Schema::create('act_candidates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('election_id');
                $t->unsignedSmallInteger('number');
                $t->unsignedBigInteger('student_id');
                $t->string('slogan', 300)->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['election_id', 'number'], 'act_candidates_number');
                $t->unique(['election_id', 'student_id'], 'act_candidates_student');
            });
        }
        if (!Schema::hasTable('act_ballots')) {
            // Secret ballot: no student_id, no sequential id (random primary key) and only the calendar day of casting, so neither
            // insertion order nor timestamps can be matched with act_voters. voter_key only prevents a second vote.
            Schema::create('act_ballots', function (Blueprint $t) {
                $t->char('id', 32)->primary();
                $t->unsignedBigInteger('election_id');
                $t->char('voter_key', 64);
                $t->unsignedBigInteger('candidate_id')->nullable(); // null = abstain
                $t->date('cast_at');
                $t->unique(['election_id', 'voter_key'], 'act_ballots_voter');
                $t->index(['election_id', 'candidate_id'], 'act_ballots_candidate');
            });
        }
        if (!Schema::hasTable('act_voters')) {
            Schema::create('act_voters', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('election_id');
                $t->unsignedBigInteger('student_id');
                $t->unsignedBigInteger('classroom_id')->nullable();
                $t->dateTime('voted_at');
                $t->unique(['election_id', 'student_id'], 'act_voters_student');
                $t->index(['election_id', 'classroom_id'], 'act_voters_classroom');
            });
        }
        if (!Schema::hasTable('act_counters')) {
            Schema::create('act_counters', function (Blueprint $t) {
                $t->unsignedBigInteger('school_id');
                $t->unsignedSmallInteger('year');
                $t->unsignedInteger('last')->default(0);
                $t->primary(['school_id', 'year']);
            });
        }
        if (!Schema::hasTable('act_certificates')) {
            Schema::create('act_certificates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('club_id');
                $t->unsignedBigInteger('member_id');
                $t->unsignedBigInteger('student_id');
                $t->string('number', 40);
                $t->json('snapshot'); // wording frozen at issue time
                $t->timestamp('issued_at')->nullable();
                $t->timestamps();
                $t->unique(['school_id', 'number'], 'act_certificates_number');
                $t->unique('member_id', 'act_certificates_member');
                $t->index(['school_id', 'club_id'], 'act_certificates_club');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: issued certificates and cast ballots must survive a rollback.
    }
};
