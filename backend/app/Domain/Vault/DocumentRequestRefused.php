<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The request cannot be honoured in the patent's current state (HTTP 409). */
final class DocumentRequestRefused extends RuntimeException {}
