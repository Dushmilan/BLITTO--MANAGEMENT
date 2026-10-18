<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The client filename cannot fit the documents.filename column (HTTP 422). */
final class InvalidDocumentFilename extends RuntimeException {}
