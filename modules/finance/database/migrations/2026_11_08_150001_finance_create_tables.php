<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// finance: fee items, invoices, receipts (payments), cash book, closed periods, document counters. All money is integer satang.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fin_fee_items')) Schema::create('fin_fee_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('code', 40); $t->string('code_key', 80); $t->string('label', 200);
            $t->unsignedBigInteger('default_amount_satang')->default(0);
            $t->unsignedBigInteger('term_id')->nullable(); $t->json('grade_levels')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'code_key'], 'fin_fee_items_code');
        });
        if (!Schema::hasTable('fin_invoices')) Schema::create('fin_invoices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('term_id'); $t->unsignedBigInteger('student_id');
            $t->string('invoice_no', 40); $t->date('issued_on'); $t->date('due_on')->nullable();
            $t->string('status', 8)->default('open');
            $t->unsignedBigInteger('total_satang')->default(0); $t->unsignedBigInteger('paid_satang')->default(0);
            $t->string('void_reason', 300)->nullable(); $t->unsignedBigInteger('voided_by')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'invoice_no'], 'fin_invoices_no');
            $t->index(['school_id', 'student_id', 'status'], 'fin_invoices_student');
            $t->index(['school_id', 'term_id', 'status'], 'fin_invoices_term');
            $t->index(['school_id', 'issued_on'], 'fin_invoices_issued');
        });
        if (!Schema::hasTable('fin_invoice_lines')) Schema::create('fin_invoice_lines', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('invoice_id'); $t->unsignedBigInteger('fee_item_id')->nullable();
            $t->string('label', 200); $t->unsignedBigInteger('amount_satang'); $t->unsignedBigInteger('discount_satang')->default(0);
            $t->index('invoice_id', 'fin_lines_invoice');
        });
        if (!Schema::hasTable('fin_payments')) Schema::create('fin_payments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('invoice_id');
            $t->string('receipt_no', 40); $t->date('paid_on'); $t->string('method', 10);
            $t->unsignedBigInteger('amount_satang'); $t->string('reference', 120)->nullable();
            $t->unsignedBigInteger('received_by'); $t->string('received_by_name', 160)->nullable();
            $t->string('status', 8)->default('valid');
            $t->string('void_reason', 300)->nullable(); $t->unsignedBigInteger('voided_by')->nullable(); $t->timestamp('voided_at')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'receipt_no'], 'fin_payments_no');
            $t->index(['school_id', 'paid_on'], 'fin_payments_day');
            $t->index('invoice_id', 'fin_payments_invoice');
        });
        if (!Schema::hasTable('fin_ledger')) Schema::create('fin_ledger', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->date('entry_date'); $t->string('kind', 8);
            $t->string('category', 120); $t->unsignedBigInteger('amount_satang'); $t->string('description', 300);
            $t->string('document_no', 60)->nullable(); $t->unsignedBigInteger('payment_id')->nullable();
            $t->unsignedBigInteger('created_by'); $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'entry_date'], 'fin_ledger_date');
            $t->index('payment_id', 'fin_ledger_payment');
        });
        if (!Schema::hasTable('fin_periods')) Schema::create('fin_periods', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('period', 7);
            $t->timestamp('closed_at')->nullable(); $t->unsignedBigInteger('closed_by')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'period'], 'fin_periods_period');
        });
        if (!Schema::hasTable('fin_counters')) Schema::create('fin_counters', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('scope', 12); $t->unsignedSmallInteger('fiscal_year');
            $t->unsignedBigInteger('last_value')->default(0);
            $t->unique(['school_id', 'scope', 'fiscal_year'], 'fin_counters_scope');
        });
    }

    public function down(): void
    {
        // Intentionally empty: financial records must not be dropped by a rollback.
    }
};
