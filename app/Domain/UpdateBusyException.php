<?php

namespace App\Domain;

/** Another update or recovery holds the updater lock. */
class UpdateBusyException extends \RuntimeException
{
}
