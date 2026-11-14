<?php

namespace Saan\Modules\LeaveDuty;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Saan\Modules\LeaveDuty\Notifications\LdNotification;

/** Recipient lookup + safe sending. A mail failure never breaks the business transition that already committed. */
class Notify
{
    public const APPROVER_ROLES = ['admin', 'principal'];

    /** Active accounts of this school linked to the teacher (users.teacher_id). */
    public static function teacherUsers(int ...$teacherIds)
    {
        if (!$teacherIds) return collect();
        return User::where('school_id', (int) config('school.school_id'))->where('is_active', true)->whereIn('teacher_id', $teacherIds)->get();
    }

    public static function approvers(?int $exceptTeacherId = null)
    {
        return User::where('school_id', (int) config('school.school_id'))->where('is_active', true)->whereIn('role', self::APPROVER_ROLES)
            ->when($exceptTeacherId, fn ($q) => $q->where(fn ($w) => $w->whereNull('teacher_id')->orWhere('teacher_id', '!=', $exceptTeacherId)))->get();
    }

    /** @param iterable $users @param string[] $lines */
    public static function send(iterable $users, string $event, string $subject, array $lines, string $hash = 'leave'): void
    {
        $seen = [];
        foreach ($users as $u) {
            if (!$u || isset($seen[$u->id]) || trim((string) $u->email) === '') continue;
            $seen[$u->id] = $u;
        }
        if (!$seen) return;
        try {
            Notification::send(array_values($seen), new LdNotification($event, $subject, $lines, $hash));
        } catch (\Throwable $e) {
            Log::warning('[WARN] leave-duty notification failed: '.$e->getMessage(), ['event' => $event]);
        }
    }
}
