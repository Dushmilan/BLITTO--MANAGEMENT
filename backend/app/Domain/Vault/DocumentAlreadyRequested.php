<?php

namespace App\Domain\Vault;

use RuntimeException;

/** This user already has an open document request for the patent (HTTP 429). */
final class DocumentAlreadyRequested extends RuntimeException {}
