<?php

namespace App\Domain\Filing;

use RuntimeException;

/**
 * Raised when a defect sheet may not be recorded (bad number, repeat, cap).
 * The message is the reference string; the API layer renders it as HTTP 422.
 */
final class DefectSheetRejected extends RuntimeException {}
