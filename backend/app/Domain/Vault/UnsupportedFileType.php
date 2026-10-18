<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The uploaded extension is outside the allow-list (HTTP 422). */
final class UnsupportedFileType extends RuntimeException {}
