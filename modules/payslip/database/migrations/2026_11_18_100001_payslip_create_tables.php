<?php

use App\Support\Saan\KeyCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// payslip (internal only): payees (staff account <-> payee code, national id, bank account), pay periods, slips, staged imports and 50 tawi certificates.
// Every amount, bank account and national id is encrypted (*_enc, 'enc1:' + Crypt). Plain columns hold only what lists need: period, payee, status.
// Slips never reference users by a foreign key: deleting a staff account must not delete payroll records. Expand-only.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('psl_payees')) Schema::create('psl_payees', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('user_id')->nullable();          // the staff account that may read these slips; no FK on purpose
            $t->string('name', 200);
            $t->string('payee_code', 40); $t->string('code_key', 80);
            $t->text('nid_enc')->nullable(); $t->string('nid_hash', 64)->nullable(); $t->string('nid_last4', 4)->nullable();
            $t->string('bank_name', 80)->nullable(); $t->text('bank_enc')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'code_key'], 'psl_payees_code');
            $t->unique(['school_id', 'user_id'], 'psl_payees_user');
            $t->index(['school_id', 'nid_hash'], 'psl_payees_nid');
        });
        if (!Schema::hasTable('psl_periods')) Schema::create('psl_periods', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->unsignedSmallInteger('year'); $t->unsignedTinyInteger('month'); $t->string('label', 120); $t->date('pay_date');
            $t->string('status', 10)->default('draft');            // draft | published | cancelled
            $t->text('totals_enc')->nullable();                     // encrypted {gross, deduct, net, slips}
            $t->unsignedBigInteger('published_by')->nullable(); $t->timestamp('published_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->string('cancel_reason', 300)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'year', 'month', 'label'], 'psl_periods_label');
            $t->index(['school_id', 'status', 'pay_date'], 'psl_periods_status');
        });
        if (!Schema::hasTable('psl_slips')) Schema::create('psl_slips', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('period_id'); $t->unsignedBigInteger('payee_id');
            $t->string('kind', 6)->default('data');                 // data | pdf
            $t->string('status', 8)->default('active');             // active | voided
            $t->unsignedBigInteger('active_key')->nullable();       // = payee_id while active, null once voided: unique(period, active_key) allows one live slip per payee
            $t->longText('data_enc')->nullable();                   // encrypted JSON of items and totals (kind data)
            $t->string('file_path', 255)->nullable(); $t->unsignedBigInteger('file_size')->default(0); // encrypted PDF on the private disk (kind pdf)
            $t->unsignedBigInteger('replaces_id')->nullable();
            $t->string('void_reason', 300)->nullable(); $t->unsignedBigInteger('voided_by')->nullable(); $t->timestamp('voided_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['period_id', 'active_key'], 'psl_slips_live');
            $t->index(['school_id', 'payee_id', 'status'], 'psl_slips_payee');
            $t->index('period_id', 'psl_slips_period');
        });
        if (!Schema::hasTable('psl_imports')) Schema::create('psl_imports', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('period_id'); $t->unsignedBigInteger('created_by');
            $t->string('status', 10)->default('staged');            // staged | confirmed
            $t->unsignedInteger('rows_total')->default(0); $t->unsignedInteger('rows_ok')->default(0);
            $t->longText('payload_enc');                            // encrypted accepted rows, problems and totals
            $t->char('file_sha256', 64);
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->index(['school_id', 'period_id', 'status'], 'psl_imports_period');
        });
        if (!Schema::hasTable('psl_certs')) Schema::create('psl_certs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedSmallInteger('year'); $t->unsignedBigInteger('payee_id');
            $t->string('status', 8)->default('issued');             // issued | void
            $t->longText('data_enc');                               // encrypted {gross, tax, sso, pf, slips}
            $t->char('src_sig', 64);                                // fingerprint of the slips it was built from (stale check)
            $t->unsignedBigInteger('issued_by')->nullable(); $t->timestamp('issued_at')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
            $t->unique(['school_id', 'year', 'payee_id'], 'psl_certs_year');
            $t->index(['school_id', 'payee_id'], 'psl_certs_payee');
        });
        KeyCollation::repair(['psl_payees' => ['code_key']]);
    }

    public function down(): void
    {
        // Intentionally empty: payroll records must not be dropped by a rollback.
    }
};
