<?php

namespace App\Support\Saan;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Declarations of what the internal edition may copy to the public edition (S1 declares, S3 publishes).
 *
 * Deny by default: only the tables and columns listed here are ever exported, and a few tables can never be listed at all.
 * Sources: config/saan_projection.php (core website tables) and "public_projection" in the module.json of every active module.
 *
 * One entry:
 *   table    target table (same schema in the public database, or a pub_* table for a derived entry)
 *   where    optional {column: scalar|null | {op, value}}; ops = < <= > >= != in not_in null not_null; value tokens "@now" and "@today"
 *   filter   optional named filter that S3 implements in code because it is not a plain column test (see NAMED_FILTERS)
 *   derived  optional name of a vetted extractor (see DERIVED); then "table" must be a pub_* table and the source tables are never named here
 *   columns  {column: rule}; a column that is not listed is NOT exported. rule = keep | null | const:<value> | mask_name | scrub_answer_keys (questions JSON without the answer key)
 */
final class Projection
{
    public const RULES_HELP = 'keep | null | const:<value> | mask_name | scrub_answer_keys';
    public const OPS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'null', 'not_null'];
    public const TOKENS = ['@now', '@today'];
    /** Filters that S3 implements; the declaration only names them. */
    public const NAMED_FILTERS = [
        'published_media' => 'cms_media referenced by published content only (posts, site settings, themes, ITA items, directory photos)',
        'public_categories' => 'cms_categories except the news category of a page (group hub) that is not published: its label carries the page title (same rule as Hubs::draftNewsCategories)',
        'ita_published_topics' => 'ita_topics of published fiscal years',
        'ita_published_items' => 'ita_items of published topics',
        'learning_public_links' => 'lrn_links that Links::publicLinks can show: school-wide rows and rows of classrooms with an unexpired access code, term_id null or the current term (course-only or other-term rows never leave the internal side)',
        'current_term' => 'academic_terms: the current term only (same rule as TermScope::current)',
        'directory_teachers' => 'teachers that have a per_profiles row shown in the public directory',
        'hub_categories' => 'cms_hub_categories of published hubs that are not hidden and whose parent (if any) is not hidden (same rule as Hubs::visibleCategoryIds)',
        'hub_documents' => 'cms_hub_documents that are published, in a visible category of a published hub, with a file or an https link (their files travel with published_media; internal notes and download counts never leave)',
        'directory_profiles' =>'per_profiles rows of exactly the teachers that directory_teachers selects (shown profile and active teacher): profiles and photos of inactive teachers never leave',
    ];
    /** Vetted extractors (S3) for data that lives only in forbidden tables; the public form is a pub_* table with masked fields. */
    public const DERIVED = [
        'certificate_verify' => 'certificate number + masked holder name + survey title + issued date',
        'notice_masked_rows' => 'pub_notice_rows: one row per notice row, cells = JSON of exactly what NoticeRepository::mask(list, rows) returns for published, not expired lists: only the columns that list enables, name masked by the list name_mode. Mode full (owner approved 2026-11-06, F9) is published as full cells ONLY while full_name_until is in the future and Turnstile is configured, with full_name_until on the row; after it the same rows are sent as first_initial with full_name_until null, and the public side masks them itself (receiver, renderer, scheduled prune)',
        'learning_rooms' => 'classrooms (id, school_id, name only) that have an unexpired lrn_access row',
        'popular_posts' => 'pub_popular_posts: one row per published news post that has views = post id and three totals (last 7 days, last 30 days, all time); counts only, never a day, a visitor or a cache entry (cms_post_views itself is forbidden)',
        'complaint_status' => 'pub_complaint_status: one row per complaint case = receipt number, HMAC of the tracking code (HashKey::publicUnlock), category label, status, received/status dates, public reply; never a name, contact, detail, assignee, accused or note',
        'complaint_reports' => 'pub_complaint_reports: one row per PUBLISHED annual complaint report = fiscal year, counts-only snapshot (rebuilt from whitelisted numbers), published date',
    ];
    /** saan_settings rows that may be exported (key => reason). Site settings travel inside cms_site_settings. */
    public const SETTINGS_KEY_WHITELIST = [
        'modules.enabled' => 'which optional modules the admin switched on',
        'modules.disabled' => 'which modules the admin switched off (the public edition must not serve a module the school turned off)',
    ];

    /** Tables (or "prefix_*" patterns) that must never be exported, whatever a declaration says. */
    private const FORBIDDEN = [
        'users', 'password_reset_tokens', 'password_invite_tokens', 'sessions', 'students', 'reg_*', 'hlth_*', 'bhv_*', 'attendances', 'att_*', 'asm_*', 'grade_entries',
        'fin_*', 'doc_*', 'per_files', 'per_folders', 'ld_*', 'leave_requests', 'duties', 'gp_*', 'api_*', 'exm_*', 'mnt_*', 'prj_*', 'act_*', 'con_*',
        'srv_responses', 'srv_roster', 'srv_pending', 'srv_counters', 'audit_events', 'saan_settings', 'system_updates', 'jobs', 'job_batches',
        'failed_jobs', 'cache', 'cache_locks', 'migrations', 'cms_messages', 'cms_post_revisions', 'cms_import_items', 'cms_redirects',
        'school_mail_settings', 'rpt_*', 'student_classroom_moves', 'cmp_*', 'stu_*', 'psl_*',
        // raw names of notice rows (the public copy is the derived pub_notice_rows), and the machinery of the split itself: queues, keys, counters, envelopes
        'cms_post_views', // per-day view counts of news posts (F5a): counted on the public side and sent back as aggregates; only the derived pub_popular_posts leaves
        'cms_hub_downloads', // per-day download counts of hub documents: counted on the public side and sent back as aggregates, never exported
        'notice_rows', 'saan_inbox_*', 'saan_pairing_*', 'pub_outbox', 'pub_sent_rows', 'pub_sync_state', 'pub_inbox', 'pub_inbox_*',
    ];

    /** Rules that a declaration must use for a column, whoever writes the declaration (table => column => rule). */
    public const PINNED = [
        'srv_surveys' => ['questions' => 'scrub_answer_keys'],
        'pub_certificates' => ['holder_name' => 'mask_name'],
    ];

    /** Columns that can be exported only as null/const: people, authorship, contact data, credentials, drafts. */
    private const NEVER_KEEP = [
        'author_id', 'created_by', 'updated_by', 'deleted_by', 'reviewed_by', 'uploaded_by', 'scheduled_by', 'published_by', 'actor_id', 'user_id',
        'draft_payload', 'draft', 'email', 'phone', 'mobile', 'national_id', 'citizen_id', 'id_card', 'birth_date', 'birthdate',
        'ip_hash', 'ip_address', 'user_agent', 'respondent_key', 'remember_token', 'encrypted_payload',
    ];
    private const NEVER_KEEP_PATTERN = '/(password|passwd|secret|api_key|(^|_)token$)/';

    /** @return list<string> tables and "prefix_*" patterns that must never be exported */
    public static function forbiddenTables(): array
    {
        return self::FORBIDDEN;
    }

    public static function isForbiddenTable(string $table): bool
    {
        foreach (self::FORBIDDEN as $f) {
            if ($f === $table) return true;
            if (str_ends_with($f, '*') && str_starts_with($table, substr($f, 0, -1))) return true;
        }
        return false;
    }

    /** Columns that may only be exported as null or const. */
    public static function neverKeepColumns(): array
    {
        return self::NEVER_KEEP;
    }

    /**
     * Core + active modules, validated and normalised. $schema: true = also check tables/columns against the database,
     * false = structure only, null = check only when the database has been migrated. $includeInactive: also the modules that are switched off
     * (the receiving side accepts what any module declares and decides separately which modules it serves).
     * @return list<array{source:string,table:string,where:list<array{column:string,op:string,value:mixed}>,filter:?string,derived:?string,columns:array<string,string>}>
     */
    public static function declared(?bool $schema = null, bool $includeInactive = false): array
    {
        $out = [];
        $seen = [];
        $sources = ['core' => (array) config('saan_projection', [])];
        foreach (app(ModuleRegistry::class)->all() as $id => $m) {
            if (!empty($m['public_projection']) && ($m['edition'] ?? '') === 'internal') throw new RuntimeException("projection: โมดูล $id เป็นของระบบภายในเท่านั้น จึงประกาศ public_projection ไม่ได้");
            if (($m['active'] || $includeInactive) && !empty($m['public_projection'])) $sources[$id] = (array) $m['public_projection'];
        }
        foreach ($sources as $source => $entries) {
            foreach (self::validate($entries, $source, $schema) as $e) {
                if (isset($seen[$e['table']])) throw new RuntimeException("projection: ตาราง {$e['table']} ประกาศซ้ำ ({$seen[$e['table']]} และ $source)");
                $seen[$e['table']] = $source;
                $out[] = $e;
            }
        }
        return $out;
    }

    /** Validates raw entries of one source. Throws RuntimeException naming the source, table and problem. */
    public static function validate(array $entries, string $source, ?bool $schema = null): array
    {
        if ($schema === null) {
            try { $schema = Schema::hasTable('migrations') && Schema::hasTable('posts'); } catch (\Throwable) { $schema = false; }
        }
        $out = [];
        foreach ($entries as $i => $e) {
            $at = "projection[$source#$i]";
            if (!is_array($e)) throw new RuntimeException("$at: ต้องเป็น object");
            $table = $e['table'] ?? null;
            if (!is_string($table) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $table)) throw new RuntimeException("$at: ชื่อตารางไม่ถูกต้อง");
            $at = "projection[$source:$table]";
            $derived = $e['derived'] ?? null;
            $filter = $e['filter'] ?? null;
            if ($derived !== null && (!is_string($derived) || !isset(self::DERIVED[$derived]))) throw new RuntimeException("$at: derived ไม่รู้จัก");
            if ($filter !== null && (!is_string($filter) || !isset(self::NAMED_FILTERS[$filter]))) throw new RuntimeException("$at: filter ไม่รู้จัก");
            if ($derived !== null && !str_starts_with($table, 'pub_')) throw new RuntimeException("$at: entry แบบ derived ต้องส่งไปตาราง pub_*");
            if ($derived === null && self::isForbiddenTable($table) && !self::settingsAllowed($table, $e)) throw new RuntimeException("$at: ตารางนี้ห้ามส่งออกเด็ดขาด");
            $cols = $e['columns'] ?? null;
            if (!is_array($cols) || $cols === [] || array_is_list($cols)) throw new RuntimeException("$at: columns ต้องเป็น object {คอลัมน์: กติกา} อย่างน้อยหนึ่งคอลัมน์");
            $rules = [];
            foreach ($cols as $c => $rule) {
                if (!is_string($c) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $c)) throw new RuntimeException("$at: ชื่อคอลัมน์ไม่ถูกต้อง");
                if (!is_string($rule) || !preg_match('/^(keep|null|mask_name|scrub_answer_keys|const:.{0,200})$/Ds', $rule)) throw new RuntimeException("$at.$c: กติกาไม่ถูกต้อง (".self::RULES_HELP.')');
                if ($rule !== 'null' && !str_starts_with($rule, 'const:') && (in_array($c, self::NEVER_KEEP, true) || preg_match(self::NEVER_KEEP_PATTERN, $c))) throw new RuntimeException("$at.$c: คอลัมน์นี้ส่งออกได้เฉพาะ null หรือ const");
                if (isset(self::PINNED[$table][$c]) && $rule !== self::PINNED[$table][$c]) throw new RuntimeException("$at.$c: คอลัมน์นี้ต้องใช้กติกา ".self::PINNED[$table][$c]);
                $rules[$c] = $rule;
            }
            $where = self::where($e['where'] ?? [], $at);
            if ($schema && $derived === null) self::checkSchema($table, $rules, $where, $at);
            $out[] = ['source' => $source, 'table' => $table, 'where' => $where, 'filter' => $filter, 'derived' => $derived, 'columns' => $rules];
        }
        return $out;
    }

    /** saan_settings is the one forbidden table with an exception: only whitelisted keys, and the declaration must pin them. */
    private static function settingsAllowed(string $table, array $e): bool
    {
        if ($table !== 'saan_settings' || self::SETTINGS_KEY_WHITELIST === []) return false;
        $w = $e['where']['key'] ?? null;
        $keys = is_array($w) ? ($w['op'] ?? null) === 'in' ? (array) ($w['value'] ?? []) : [] : [$w];
        return $keys !== [] && !array_diff($keys, array_keys(self::SETTINGS_KEY_WHITELIST));
    }

    private static function where(mixed $raw, string $at): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) throw new RuntimeException("$at: where ต้องเป็น object");
        $out = [];
        foreach ($raw as $c => $cond) {
            if (!is_string($c) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $c)) throw new RuntimeException("$at: where มีชื่อคอลัมน์ไม่ถูกต้อง");
            if (is_array($cond)) {
                $op = $cond['op'] ?? null;
                if (!is_string($op) || !in_array($op, self::OPS, true)) throw new RuntimeException("$at.where.$c: op ไม่ถูกต้อง");
                $value = $cond['value'] ?? null;
            } else {
                [$op, $value] = $cond === null ? ['null', null] : ['=', $cond];
            }
            if (in_array($op, ['null', 'not_null'], true)) $value = null;
            elseif (in_array($op, ['in', 'not_in'], true)) {
                if (!is_array($value) || $value === [] || array_filter($value, fn ($v) => !is_scalar($v))) throw new RuntimeException("$at.where.$c: in ต้องมีรายการค่า");
            } elseif (!is_scalar($value)) throw new RuntimeException("$at.where.$c: ต้องมีค่า");
            elseif (is_string($value) && str_starts_with($value, '@') && !in_array($value, self::TOKENS, true)) throw new RuntimeException("$at.where.$c: token $value ไม่รู้จัก");
            $out[] = ['column' => $c, 'op' => $op, 'value' => $value];
        }
        return $out;
    }

    private static function checkSchema(string $table, array $rules, array $where, string $at): void
    {
        if (!Schema::hasTable($table)) throw new RuntimeException("$at: ไม่พบตารางนี้ในฐานข้อมูล");
        $have = [];
        $hasDefault = [];
        foreach (Schema::getColumns($table) as $col) {
            $have[$col['name']] = (bool) $col['nullable'];
            if ($col['default'] !== null) $hasDefault[$col['name']] = true; // has a database default: may be left out
        }
        // every NOT NULL column without a default must be listed, otherwise S3's insert into the public table would fail at run time
        foreach ($have as $c => $nullable) {
            if (!$nullable && !isset($hasDefault[$c]) && !array_key_exists($c, $rules)) throw new RuntimeException("$at.$c: คอลัมน์ NOT NULL ที่ไม่มีค่าเริ่มต้นต้องมีกติกา (keep, const:<ค่า>)");
        }
        foreach ($rules as $c => $rule) {
            if (!array_key_exists($c, $have)) throw new RuntimeException("$at.$c: ไม่พบคอลัมน์นี้ในตาราง");
            if ($rule === 'null' && !$have[$c]) throw new RuntimeException("$at.$c: คอลัมน์นี้ห้ามเป็น NULL ใช้ const:<ค่า> แทน");
        }
        foreach ($where as $w) if (!array_key_exists($w['column'], $have)) throw new RuntimeException("$at.where.{$w['column']}: ไม่พบคอลัมน์นี้ในตาราง");
    }
}
