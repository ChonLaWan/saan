<?php

namespace App\Support\Saan;

/**
 * Keeps modules whose existence of a record is itself confidential out of the generic audit feeds (the staff dashboard feed and /api/site/audit).
 * Complaints: who opened, assigned or named an accused in a case, and when, would tell the person complained about that a case exists. The
 * story of a case lives in cmp_actions behind the module's own access rules; audit_events keeps the rows (for forensics) but no feed lists them.
 */
final class AuditFeed
{
    public const HIDDEN_ENTITIES = ['cmp_cases', 'cmp_accused', 'cmp_notes', 'cmp_files', 'cmp_actions'];
    public const HIDDEN_ACTION_PREFIXES = ['complaints.'];

    /** @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $q a query on audit_events */
    public static function visible($q)
    {
        $q->whereNotIn('entity', self::HIDDEN_ENTITIES);
        foreach (self::HIDDEN_ACTION_PREFIXES as $p) $q->where('action', 'not like', $p.'%');
        return $q;
    }
}
