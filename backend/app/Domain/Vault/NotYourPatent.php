<?php

namespace App\Domain\Vault;

use RuntimeException;

/** A plain user asked for a patent they do not own (HTTP 403). */
final class NotYourPatent extends RuntimeException {}
