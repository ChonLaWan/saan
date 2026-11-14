<?php

namespace App\Support\Saan;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent repair: PHP-normalised *_key columns that sit in a unique index must compare bytewise (utf8mb4_bin) on MySQL/MariaDB.
 * Under the default utf8mb4_unicode_ci, case, accents and Thai tone marks fold together, so two different values collide as duplicates.
 * Module packages are installed after the core migration ran, so this is also called by the module migrations and after every Updater migrate.
 * Expand-only: never drops data; a failure is logged as [WARN] and skipped.
 */
final class KeyCollation
{
    /** table => columns that hold a PHP-normalised key and sit in a unique index */
    public const KEY_COLUMNS = [
        'ld_leave_types' => ['label_key'], 'con_archives' => ['label_key'], 'con_projects' => ['title_key'], 'prj_projects' => ['code_key'],
        'api_clients' => ['name_key'], 'gp_accounts' => ['email_key'],
        'asm_subjects' => ['code_key'], 'bhv_rules' => ['code_key'], 'fin_fee_items' => ['code_key'], 'students' => ['code_key'],
        'exm_sheets' => ['student_code_key'], 'reg_guardians' => ['ref_key'],
        'act_clubs' => ['name_key'], 'per_folders' => ['label_key'], 'mnt_assets' => ['asset_code_key'],
        'psl_payees' => ['code_key'],
    ];

    /** @param array<string,string[]>|null $only table => columns; null = every known key column. @return int columns changed */
    public static function repair(?array $only = null): int
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) return 0; // only MySQL/MariaDB are supported; any other driver is left alone
        $changed = 0;
        foreach ($only ?? self::KEY_COLUMNS as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $col) {
                if (!Schema::hasColumn($table, $col)) continue;
                $info = DB::selectOne('SELECT COLUMN_TYPE AS t, IS_NULLABLE AS n, COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col]);
                if (!$info || $info->c === null || strtolower((string) $info->c) === 'utf8mb4_bin') continue; // already binary (or not a text column)
                if (!preg_match('/^(var)?char\(\d+\)$/i', (string) $info->t)) continue;
                try {
                    DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` %s CHARACTER SET utf8mb4 COLLATE utf8mb4_bin %s', $table, $col, $info->t, strtoupper((string) $info->n) === 'YES' ? 'NULL' : 'NOT NULL'));
                    $changed++;
                } catch (\Throwable $e) {
                    Log::warning('[WARN] collation of '.$table.'.'.$col.' not changed: '.$e->getMessage());
                }
            }
        }
        return $changed;
    }
}