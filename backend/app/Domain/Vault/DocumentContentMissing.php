<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The store no longer holds this document's bytes (HTTP 404). */
final class DocumentContentMissing extends RuntimeException {}
