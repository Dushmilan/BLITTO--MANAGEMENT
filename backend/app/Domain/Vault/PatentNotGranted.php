<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The patent is not GRANTED yet (HTTP 403 on download). */
final class PatentNotGranted extends RuntimeException {}
