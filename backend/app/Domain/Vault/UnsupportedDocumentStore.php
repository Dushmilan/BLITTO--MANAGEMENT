<?php

namespace App\Domain\Vault;

use RuntimeException;

/**
 * Raised when BLITTODB_DOCUMENT_STORE names an adapter that does not exist.
 */
final class UnsupportedDocumentStore extends RuntimeException {}
