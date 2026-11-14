<?php

namespace Saan\Modules\ApiGateway;

/** The scope catalogue. Clients get assignable scopes; ME_READ is only ever minted by the student SSO flow. */
class Scopes
{
    public const ME_READ = 'me:read';
    public const SSO = 'sso:student';

    public const ASSIGNABLE = [
        'students:read:basic' => 'รายชื่อนักเรียน: รหัส ชื่อที่แสดง ห้องเรียน สถานะ',
        'students:read:contact' => 'ช่องทางติดต่อผู้ปกครองเฉพาะที่ผู้ปกครองอนุญาตให้แชร์ (ไม่มีเลขประจำตัวใด ๆ) ต้องเลือกร่วมกับรายชื่อนักเรียน',
        'classrooms:read' => 'ห้องเรียน',
        'teachers:read:basic' => 'ครู: ชื่อและกลุ่มสาระ',
        'courses:read' => 'รายวิชาที่เปิดสอน (ต้องมีโมดูลวัดผล)',
        'attendance:read:summary' => 'สรุปจำนวนการมาเรียนรายห้อง (ไม่ระบุตัวนักเรียน)',
        self::SSO => 'ให้นักเรียนเข้าสู่ระบบแอปนี้ด้วยรหัสนักเรียนและ PIN',
    ];

    /** @return string[] */
    public static function assignable(): array
    {
        return array_keys(self::ASSIGNABLE);
    }

    /** Scopes a token may really use: its own, still granted to the client (SSO tokens need the SSO grant). */
    public static function effective(array $tokenScopes, array $clientScopes): array
    {
        $allowed = $clientScopes;
        if (in_array(self::SSO, $clientScopes, true)) $allowed[] = self::ME_READ;
        return array_values(array_intersect($tokenScopes, $allowed));
    }
}
