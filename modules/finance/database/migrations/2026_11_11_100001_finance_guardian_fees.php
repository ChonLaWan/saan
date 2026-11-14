<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// finance F3 (internal only): school receiving accounts, per fee item / invoice line account choice, guardian payment claims with slips.
// Expand-only. Slip and QR files live on the private disk; the slip table keeps a sha256 of the uploaded bytes (dup_key is unique per school
// and is set to NULL when a claim is withdrawn or rejected so the guardian can send a corrected claim with the same file).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fin_payment_accounts')) Schema::create('fin_payment_accounts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('bank_code', 12); $t->string('bank_name', 80);
            $t->string('account_name', 160); $t->string('account_number', 20); $t->string('branch', 120)->nullable();
            $t->string('qr_path', 255)->nullable(); $t->string('qr_mime', 40)->nullable(); $t->unsignedInteger('qr_size')->nullable();
            $t->boolean('is_active')->default(true); $t->unsignedSmallInteger('sort_order')->default(0);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'bank_code', 'account_number'], 'fin_pay_accounts_unique');
            $t->index(['school_id', 'is_active', 'sort_order'], 'fin_pay_accounts_list');
        });
        if (Schema::hasTable('fin_fee_items') && !Schema::hasColumn('fin_fee_items', 'account_ids')) Schema::table('fin_fee_items', fn (Blueprint $t) => $t->json('account_ids')->nullable());
        if (Schema::hasTable('fin_invoice_lines') && !Schema::hasColumn('fin_invoice_lines', 'account_ids')) Schema::table('fin_invoice_lines', fn (Blueprint $t) => $t->json('account_ids')->nullable());
        if (!Schema::hasTable('fin_payment_claims')) Schema::create('fin_payment_claims', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('invoice_id'); $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('guardian_id')->nullable(); $t->unsignedBigInteger('entered_by')->nullable();
            $t->string('source', 8)->default('guardian'); // guardian | staff
            $t->unsignedBigInteger('amount_satang'); $t->unsignedBigInteger('approved_satang')->nullable();
            $t->dateTime('transferred_at'); $t->unsignedBigInteger('account_id')->nullable();
            $t->string('status', 10)->default('pending'); // pending | approved | rejected | withdrawn
            $t->string('reject_reason', 300)->nullable(); $t->unsignedBigInteger('decided_by')->nullable(); $t->dateTime('decided_at')->nullable();
            $t->unsignedBigInteger('payment_id')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'status', 'created_at'], 'fin_claims_queue');
            $t->index(['school_id', 'invoice_id'], 'fin_claims_invoice');
            $t->index(['school_id', 'guardian_id', 'created_at'], 'fin_claims_guardian');
            $t->index(['school_id', 'student_id'], 'fin_claims_student');
        });
        if (!Schema::hasTable('fin_claim_slips')) Schema::create('fin_claim_slips', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('claim_id');
            $t->string('path', 255); $t->string('mime', 40); $t->unsignedInteger('size');
            $t->char('sha256', 64); $t->char('dup_key', 64)->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'dup_key'], 'fin_slips_dup');
            $t->index('claim_id', 'fin_slips_claim');
        });
        // fast student lookup by classroom (finance search and the per-classroom outstanding summary)
        if (Schema::hasTable('students') && !Schema::hasIndex('students', 'students_school_room')) Schema::table('students', fn (Blueprint $t) => $t->index(['school_id', 'classroom_id'], 'students_school_room'));
    }

    public function down(): void
    {
        // Intentionally empty: financial records must not be dropped by a rollback.
    }
};
