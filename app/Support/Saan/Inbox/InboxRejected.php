<?php

namespace App\Support\Saan\Inbox;

use RuntimeException;

/** A refusal that is final for this envelope or submission (bad data, unknown kind, too big, not paired). $status is an HTTP-like code for the caller. */
class InboxRejected extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $reason = 'rejected')
    {
        parent::__construct($message, $status);
    }
}
