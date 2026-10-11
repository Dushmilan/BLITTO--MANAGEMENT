<?php

namespace App\Domain\Docketing;

use RuntimeException;

/**
 * Raised when a deadline status change violates deadline integrity
 * (CONTEXT.md invariant 1 — no soft deadlines).
 */
class IllegalDeadlineChange extends RuntimeException {}
