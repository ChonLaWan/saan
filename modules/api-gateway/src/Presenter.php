<?php

namespace Saan\Modules\ApiGateway;

/**
 * The only place that decides which fields leave the school through the API. Every output array is built
 * key by key from a whitelist, so a new column in the database can never leak by accident.
 * Never exposed by any scope: birth date, national/passport/G numbers, documents, health, address, phone numbers, PINs.
 */
class Presenter
{
    public static function has(array $scopes, string $scope): bool
    {
        return in_array($scope, $scopes, true);
    }

    public static function displayName(object $row): string
    {
        $first = trim((string) ($row->first_name ?? ''));
        if ($first === '') return trim((string) ($row->name ?? ''));
        // the Thai prefix is glued to the first name ("เด็กชายสมชาย"); the last name follows after a space
        $name = trim((string) ($row->prefix ?? '')).$first;
        $last = trim((string) ($row->last_name ?? ''));
        return $last !== '' ? $name.' '.$last : $name;
    }

    private static function classroom(?int $id, ?string $name): ?array
    {
        return $id ? ['id' => $id, 'name' => (string) $name] : null;
    }

    /** students:read:basic fields; students:read:contact adds the consented guardian contact list. */
    public static function student(object $row, array $scopes, ?array $contact = null): array
    {
        $out = [];
        if (self::has($scopes, 'students:read:basic')) {
            $out = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'display_name' => self::displayName($row),
                'classroom' => self::classroom($row->classroom_id ? (int) $row->classroom_id : null, $row->classroom_name ?? null),
                'status' => (string) ($row->status ?? 'studying'),
            ];
        }
        if (self::has($scopes, 'students:read:contact')) {
            $out['id'] ??= (int) $row->id;
            $out['contact'] = $contact ?? [];
        }
        return $out;
    }

    /** One consented guardian contact. Phone numbers are encrypted at rest in the registry and are not part of any scope. */
    public static function guardian(object $row): array
    {
        return [
            'display_name' => trim((string) ($row->display_name ?? '')),
            'relationship' => (string) ($row->relationship ?? 'other'),
            'is_primary' => (bool) ($row->is_primary ?? false),
            'email' => ($row->email ?? null) !== null && $row->email !== '' ? (string) $row->email : null,
        ];
    }

    /** /api/v1/me for a signed-in student: the basic profile only. */
    public static function me(object $row): array
    {
        return self::student($row, ['students:read:basic']);
    }

    public static function classroomRow(object $row, ?object $teacher = null): array
    {
        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'grade_level' => ($row->grade_level ?? null) !== null ? (string) $row->grade_level : null,
            'homeroom_teacher' => $teacher ? ['id' => (int) $teacher->id, 'display_name' => (string) $teacher->name] : null,
        ];
    }

    public static function teacher(object $row): array
    {
        return ['id' => (int) $row->id, 'display_name' => (string) $row->name, 'subject' => ($row->subject ?? null) !== null ? (string) $row->subject : null];
    }

    public static function course(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'term_id' => (int) $row->term_id,
            'subject' => ['code' => (string) $row->subject_code, 'name' => (string) $row->subject_name],
            'classroom' => self::classroom((int) $row->classroom_id, $row->classroom_name ?? null),
            'teacher' => $row->teacher_id ? ['id' => (int) $row->teacher_id, 'display_name' => (string) ($row->teacher_name ?? '')] : null,
            'status' => (string) $row->status,
        ];
    }

    /** @param array<string,int> $counts status => number of attendance rows */
    public static function attendance(int $classroomId, string $name, string $from, string $to, array $counts, int $daysChecked = 0): array
    {
        ksort($counts);
        return ['classroom' => ['id' => $classroomId, 'name' => $name], 'from' => $from, 'to' => $to, 'days_checked' => $daysChecked, 'counts' => $counts, 'total' => array_sum($counts)];
    }
}
