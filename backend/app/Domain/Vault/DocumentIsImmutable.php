<?php

namespace App\Domain\Vault;

use RuntimeException;

/**
 * Raised when a filed document would be modified
 * (CONTEXT.md invariant 3 — immutability of filed documents).
 */
class DocumentIsImmutable extends RuntimeException {}
