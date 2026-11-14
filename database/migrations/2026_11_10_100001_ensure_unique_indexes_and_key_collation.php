<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Expand-only repair, safe to run any number of times.
 *
 * 1. Unique keys. Laravel creates unique/index keys with separate ALTER TABLE statements after CREATE TABLE and DDL is not transactional,
 *    so a run killed in between leaves a table without its unique key while the "if (!hasTable)" guard skips it on the next run.
 *    Every unique key that protects an invariant (receipt numbers, counters, one response per person, ...) is re-checked here by columns.
 *    When existing rows already break a key it is logged and skipped (never deletes data); fix the duplicates and migrate again.
 * 2. Collation of the PHP-normalised *_key columns: utf8mb4_bin on MySQL/MariaDB so the unique index does not fold accents (Cafe = Cafe).
 */
return new class extends Migration {
    /** table => [index name => columns] */
    private const UNIQUES = [
        'system_updates' => [],
        'fin_fee_items' => ['fin_fee_items_code' => ['school_id', 'code_key']],
        'fin_invoices' => ['fin_invoices_no' => ['school_id', 'invoice_no']],
        'fin_payments' => ['fin_payments_no' => ['school_id', 'receipt_no']],
        'fin_periods' => ['fin_periods_period' => ['school_id', 'period']],
        'fin_counters' => ['fin_counters_scope' => ['school_id', 'scope', 'fiscal_year']],
        'doc_books' => ['doc_books_kind_year' => ['school_id', 'kind', 'year_be']],
        'doc_documents' => ['doc_documents_book_regno' => ['book_id', 'reg_no']],
        'doc_routes' => ['doc_routes_doc_user' => ['document_id', 'to_user_id']],
        'srv_surveys' => ['srv_surveys_school_id_slug_unique' => ['school_id', 'slug']],
        'srv_responses' => ['srv_responses_certificate_no_unique' => ['certificate_no'], 'srv_responses_survey_id_respondent_key_unique' => ['survey_id', 'respondent_key']],
        'srv_roster' => ['srv_roster_survey_id_code_hash_unique' => ['survey_id', 'code_hash']],
        'srv_pending' => ['srv_pending_survey_id_respondent_key_unique' => ['survey_id', 'respondent_key']],
        'gp_accounts' => ['gp_accounts_guardian' => ['school_id', 'guardian_id'], 'gp_accounts_emailkey' => ['school_id', 'email_key']],
        'gp_links' => ['gp_links_token' => ['token_hash']],
        'gp_acks' => ['gp_acks_once' => ['school_id', 'guardian_id', 'student_id', 'kind', 'ref_key']],
        'api_clients' => ['api_clients_client_id_unique' => ['client_id'], 'api_clients_school_name_key' => ['school_id', 'name_key']],
        'api_tokens' => ['api_tokens_token_hash_unique' => ['token_hash']],
        'api_sso_codes' => ['api_sso_codes_code_hash_unique' => ['code_hash']],
        'api_student_pins' => ['api_student_pins_student_id_unique' => ['student_id']],
        'exm_sheets' => ['exm_sheets_exam_code' => ['exam_id', 'student_code_key']],
        'asm_subjects' => ['asm_subjects_school_codekey' => ['school_id', 'code_key']],
        'asm_courses' => ['asm_courses_term_subject_class' => ['term_id', 'subject_id', 'classroom_id']],
        'asm_scores' => ['asm_scores_cell' => ['course_id', 'student_id', 'component_key']],
        'bhv_rules' => ['bhv_rules_school_codekey' => ['school_id', 'code_key']],
        'per_profiles' => ['per_profiles_teacher' => ['school_id', 'teacher_id']],
        'per_folders' => ['per_folders_code' => ['school_id', 'code'], 'per_folders_label' => ['school_id', 'label_key']],
        'per_files' => ['per_files_dedupe' => ['school_id', 'teacher_id', 'dedupe_key']],
        'ld_leave_types' => ['ld_leave_types_school_id_code_unique' => ['school_id', 'code'], 'ld_leave_types_school_id_label_key_unique' => ['school_id', 'label_key']],
        'ld_duty_swaps' => ['ld_duty_swaps_open_duty_id_unique' => ['open_duty_id']],
        'con_archives' => ['con_archives_school_label' => ['school_id', 'label_key']],
    ];

    /** table => columns that hold a PHP-normalised key and sit in a unique index */
    private const KEY_COLUMNS = [
        'ld_leave_types' => ['label_key'], 'con_archives' => ['label_key'], 'api_clients' => ['name_key'], 'gp_accounts' => ['email_key'],
        'asm_subjects' => ['code_key'], 'bhv_rules' => ['code_key'], 'fin_fee_items' => ['code_key'], 'students' => ['code_key'],
        'exm_sheets' => ['student_code_key'], 'reg_guardians' => ['ref_key'],
    ];

    public function up(): void
    {
        $this->uniques();
        $this->collations();
    }

    private function uniques(): void
    {
        foreach (self::UNIQUES as $table => $indexes) {
            if (!Schema::hasTable($table)) continue;
            if ($table === 'system_updates') {
                $this->ensureIndex($table, 'system_updates_package_id_created_at_index', ['package_id', 'created_at'], false);
                continue;
            }
            foreach ($indexes as $name => $cols) $this->ensureIndex($table, $name, $cols, true);
        }
    }

    private function ensureIndex(string $table, string $name, array $cols, bool $unique): void
    {
        foreach ($cols as $c) if (!Schema::hasColumn($table, $c)) return;
        if (Schema::hasIndex($table, $cols, $unique ? 'unique' : null) || Schema::hasIndex($table, $name)) return;
        try {
            Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($cols, $name) : $t->index($cols, $name));
        } catch (\Throwable $e) {
            Log::warning('[WARN] ensure index '.$table.'.'.$name.' skipped: '.$e->getMessage());
        }
    }

    private function collations(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) return; // only MySQL/MariaDB are supported; any other driver is left alone
        foreach (self::KEY_COLUMNS as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $col) {
                if (!Schema::hasColumn($table, $col)) continue;
                $info = DB::selectOne('SELECT COLUMN_TYPE AS t, IS_NULLABLE AS n, COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col]);
                if (!$info || $info->c === null || strtolower((string) $info->c) === 'utf8mb4_bin') continue; // already binary (or not a text column)
                if (!preg_match('/^(var)?char\(\d+\)$/i', (string) $info->t)) continue;
                try {
                    DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` %s CHARACTER SET utf8mb4 COLLATE utf8mb4_bin %s', $table, $col, $info->t, strtoupper((string) $info->n) === 'YES' ? 'NULL' : 'NOT NULL'));
                } catch (\Throwable $e) {
                    Log::warning('[WARN] collation of '.$table.'.'.$col.' not changed: '.$e->getMessage());
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: expand-only (keys and collations are never removed by a rollback).
    }
};
