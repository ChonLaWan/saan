<?php

namespace App\Domain;

/** A self-update step that cannot go on: $reason is a stable code for the page and the tests, the message is Thai text for the administrator. */
class SelfUpdateException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'error', public readonly int $http = 422)
    {
        parent::__construct($message);
    }
}
