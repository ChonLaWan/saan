<?php

namespace Saan\Modules\Attendance;

use RuntimeException;

/** Thrown before anything is written when a sheet save is based on stale rows or a stale class-day version. Rendered as HTTP 409. */
class SheetConflict extends RuntimeException
{
    public function __construct(public readonly array $payload)
    {
        parent::__construct($payload['message'] ?? 'ข้อมูลถูกแก้ไขโดยผู้อื่นแล้ว', 409);
    }
}
