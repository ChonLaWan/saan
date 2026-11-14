<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('school_id')->default(1);
            $t->string('role')->default('teacher');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->unsignedBigInteger('classroom_id')->nullable();
        });
        Schema::create('academic_terms', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('term'); $t->date('starts_on'); $t->date('ends_on');
            $t->unique(['school_id','year','term']);
        });
        Schema::create('teachers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name'); $t->string('subject');
        });
        Schema::create('classrooms', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name');
            $t->foreignId('homeroom_teacher_id')->constrained('teachers');
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('classroom_id')->constrained();
            $t->string('code'); $t->string('name'); $t->unique(['school_id','code']);
        });
        Schema::create('bell_periods', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedTinyInteger('number');
            $t->string('starts_at',5); $t->string('ends_at',5); $t->unique(['school_id','number']);
        });
        Schema::create('lessons', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('term_id')->constrained('academic_terms');
            $t->foreignId('teacher_id')->constrained(); $t->foreignId('classroom_id')->constrained();
            $t->foreignId('period_id')->constrained('bell_periods'); $t->unsignedTinyInteger('weekday');
            $t->string('room'); $t->string('subject'); $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['term_id','weekday','period_id','teacher_id'],'lesson_teacher_slot');
            $t->unique(['term_id','weekday','period_id','classroom_id'],'lesson_class_slot');
            $t->unique(['term_id','weekday','period_id','room'],'lesson_room_slot');
        });
        Schema::create('duties', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('teacher_id')->constrained();
            $t->date('date'); $t->string('starts_at',5); $t->string('ends_at',5);
            $t->string('location'); $t->string('status')->default('assigned'); $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id','teacher_id','date']);
        });
        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('teacher_id')->constrained();
            $t->date('starts_on'); $t->date('ends_on'); $t->string('type'); $t->string('reason',500);
            $t->string('status')->default('pending'); $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable(); $t->timestamps();
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('student_id')->constrained();
            $t->date('date'); $t->string('status'); $t->foreignId('recorded_by')->constrained('users'); $t->timestamps();
            $t->unique(['school_id','student_id','date']);
        });
        Schema::create('grade_entries', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('term_id')->constrained('academic_terms');
            $t->foreignId('student_id')->constrained(); $t->string('subject');
            $t->unsignedInteger('continuous_centi'); $t->unsignedInteger('exam_centi');
            $t->string('policy_version'); $t->foreignId('recorded_by')->constrained('users'); $t->timestamps();
            $t->unique(['term_id','student_id','subject']);
        });
        Schema::create('posts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('title',180); $t->text('body');
            $t->string('status')->default('draft'); $t->foreignId('author_id')->constrained('users');
            $t->timestamp('published_at')->nullable(); $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->foreignId('actor_id')->constrained('users');
            $t->string('action'); $t->string('entity'); $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('detail'); $t->timestamp('created_at');
        });
    }
    public function down(): void {
        foreach (['audit_events','posts','grade_entries','attendances','leave_requests','duties','lessons','bell_periods','students','classrooms','teachers','academic_terms'] as $name) Schema::dropIfExists($name);
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn(['school_id','role','teacher_id','classroom_id']));
    }
};
