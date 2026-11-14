<?php

namespace App\Support\Saan;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned, school-scoped, audited writes. The table must have id, school_id and version columns.
 * Audit decision: audit_events.actor_id is a non-null FK to users, so when nobody is signed in
 * (CLI, queue) the audit row is skipped instead of failing the write. Web routes always have a user.
 * insert() sets version=1 only when the table has a version column; update/delete require it.
 * VersionConflict::current has password/token/secret/hash columns removed before it reaches the client.
 */
class Mutation
{
    private const PROTECTED = ['id', 'school_id', 'version', 'created_at'];
    private const CONFLICT_COLUMNS = ['gp_accounts' => ['id', 'guardian_id', 'is_active', 'version']];
    private static array $columns = [];

    public static function update(string $table, int $id, int $expectedVersion, array $changes, string $action, array $detail = []): object
    {
        self::table($table);
        return DB::transaction(function () use ($table, $id, $expectedVersion, $changes, $action, $detail) {
            $set = self::encode(array_diff_key($changes, array_flip(self::PROTECTED)));
            $oldRoom = $table === 'students' && array_key_exists('classroom_id', $changes) ? self::scoped($table, $id)->value('classroom_id') : false;
            if (self::has($table, 'updated_at')) $set['updated_at'] = now();
            $set['version'] = DB::raw('version + 1');
            $rows = self::scoped($table, $id)->where('version', $expectedVersion)->update($set);
            if ($rows === 0) self::fail($table, $id);
            if ($oldRoom !== false) ClassroomMoves::record((int) config('school.school_id'), $id, $oldRoom === null ? null : (int) $oldRoom, $changes['classroom_id'] === null ? null : (int) $changes['classroom_id']);
            self::audit($action, $table, $id, $detail);
            return self::scoped($table, $id)->first();
        });
    }

    public static function insert(string $table, array $values, string $action, array $detail = []): object
    {
        self::table($table);
        return DB::transaction(function () use ($table, $values, $action, $detail) {
            $row = self::encode(array_diff_key($values, array_flip(['id', 'version'])));
            $row['school_id'] = (int) config('school.school_id');
            if (self::has($table, 'version')) $row['version'] = 1;
            if (self::has($table, 'created_at') && !isset($row['created_at'])) $row['created_at'] = now();
            if (self::has($table, 'updated_at') && !isset($row['updated_at'])) $row['updated_at'] = now();
            $id = (int) DB::table($table)->insertGetId($row);
            self::audit($action, $table, $id, $detail);
            return self::scoped($table, $id)->first();
        });
    }

    public static function delete(string $table, int $id, int $expectedVersion, string $action): void
    {
        self::table($table);
        DB::transaction(function () use ($table, $id, $expectedVersion, $action) {
            if (self::scoped($table, $id)->where('version', $expectedVersion)->delete() === 0) self::fail($table, $id);
            self::audit($action, $table, $id);
        });
    }

    private static int $noActor = 0;

    /** Runs $fn with auditing that never names an actor (anonymous intake): the guard is not touched, so a session-backed user cannot come back mid-way. */
    public static function withoutActor(callable $fn): mixed
    {
        self::$noActor++;
        try { return $fn(); } finally { self::$noActor--; }
    }

    public static function audit(string $action, string $entity, ?int $entityId, array $detail = []): void
    {
        if (self::$noActor > 0) return;
        $actor = auth()->id();
        if ($actor === null) return; // see class note: actor_id is non-null
        DB::table('audit_events')->insert([
            'school_id' => (int) config('school.school_id'), 'actor_id' => $actor, 'action' => $action,
            'entity' => $entity, 'entity_id' => $entityId,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }

    private static function fail(string $table, int $id): never
    {
        // locking read = latest committed row, not the REPEATABLE READ snapshot of an outer transaction (else the client retries a stale version forever)
        $current = self::scoped($table, $id)->lockForUpdate()->first();
        if (!$current) abort(404);
        $row = (array) $current;
        // tables whose rows carry credential identifiers (login e-mail, hashes) expose only these columns in a 409
        if (isset(self::CONFLICT_COLUMNS[$table])) $row = array_intersect_key($row, array_flip(self::CONFLICT_COLUMNS[$table]));
        throw new VersionConflict(self::strip($row));
    }

    /** Remove secret-looking columns before the row reaches the browser in the 409 body. */
    private static function strip(array $row): array
    {
        foreach (array_keys($row) as $k) if (preg_match('/(^|_)(password|token|secret|hash)$/i', (string) $k)) unset($row[$k]);
        return $row;
    }

    private static function scoped(string $table, int $id)
    {
        return DB::table($table)->where('id', $id)->where('school_id', (int) config('school.school_id'));
    }

    private static function encode(array $values): array
    {
        foreach ($values as $k => $v) {
            if ($v instanceof \DateTimeInterface) $values[$k] = ($v instanceof \Carbon\CarbonInterface ? $v->copy()->setTimezone(config('app.timezone')) : $v)->format('Y-m-d H:i:s'); // json_encode would store "\"2026-..Z\"", which strict MariaDB rejects
            elseif ($v instanceof \BackedEnum) $values[$k] = $v->value;
            elseif ($v instanceof \Illuminate\Contracts\Database\Query\Expression) continue; // DB::raw passes through untouched
            elseif (is_array($v) || is_object($v)) $values[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return $values;
    }

    private static function table(string $table): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table)) throw new \InvalidArgumentException('ชื่อตารางไม่ถูกต้อง');
    }

    private static function has(string $table, string $column): bool
    {
        return self::$columns[$table][$column] ??= Schema::hasColumn($table, $column);
    }
}