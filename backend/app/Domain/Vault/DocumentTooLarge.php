<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The upload exceeds the 10 MiB cap (HTTP 413). */
final class DocumentTooLarge extends RuntimeException {}
