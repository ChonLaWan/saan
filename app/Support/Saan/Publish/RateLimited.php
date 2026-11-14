<?php

namespace App\Support\Saan\Publish;

use RuntimeException;

/** The public side answered 429: wait Retry-After seconds, this is not a failure of the batch. */
final class RateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('ฝั่งรับจำกัดความถี่ รอ '.$retryAfter.' วินาที');
    }
}
