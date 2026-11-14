<?php

namespace Saan\Modules\Surveys;

use RuntimeException;

/** A business-rule refusal on the public side (duplicate, closed, full ...). Rendered as a notice page or JSON with this status. */
class SurveyRejected extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $reason = 'rejected', public readonly ?string $certificateNo = null)
    {
        parent::__construct($message, $status);
    }
}
