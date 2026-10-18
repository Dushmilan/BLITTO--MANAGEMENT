<?php

namespace App\Domain\Vault;

use RuntimeException;

/**
 * The store could not persist bytes. Surfaced instead of a silent no-op so a
 * document row is never created without its content (HTTP 500).
 */
final class DocumentStoreFailure extends RuntimeException {}
