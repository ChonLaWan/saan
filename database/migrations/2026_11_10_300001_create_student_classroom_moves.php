<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expand-only: room history written whenever students.classroom_id changes, so attendance can be attributed to the room a student was in on each date.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('student_classroom_moves')) return;
        Schema::create('student_classroom_moves', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('from_classroom_id')->nullable();
            $t->unsignedBigInteger('to_classroom_id')->nullable();
            $t->date('effective_on');
            $t->timestamps();
            $t->index(['school_id', 'student_id', 'effective_on'], 'scm_student_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_classroom_moves');
    }
};