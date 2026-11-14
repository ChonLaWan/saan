<?php

namespace App\Support\Saan;

use RuntimeException;

/** Optimistic-lock failure. Rendered globally as HTTP 409 JSON (see bootstrap/app.php). */
class VersionConflict extends RuntimeException
{
    public function __construct(public readonly ?array $current = null, string $message = 'ข้อมูลถูกแก้ไขโดยผู้อื่นแล้ว กรุณาโหลดใหม่')
    {
        parent::__construct($message, 409);
    }
}