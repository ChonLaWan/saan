<?php

namespace App\Support\Modules;

use RuntimeException;

/** A refused module action with a Thai message; the code is the HTTP status (404, 409 or 422). */
class ModuleActionException extends RuntimeException {}
