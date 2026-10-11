<?php

namespace App\Domain\Lifecycle;

use RuntimeException;

/**
 * Raised when a status change violates the prosecution lifecycle
 * (e.g. DRAFT -> GRANTED). Mapped to HTTP 422 by the API layer.
 */
class IllegalTransition extends RuntimeException {}
