<?php

namespace App\Domain\Notification;

use RuntimeException;

/** Raised when BLITTODB_MAIL_CHANNEL names an adapter that is not implemented. */
final class UnsupportedMailChannel extends RuntimeException {}
