<?php

namespace App\Domain\Notification;

/**
 * Outbound mail seam. Mirrors the reference `EmailProviderAdapter.send`.
 * Implementations may throw; NotificationService decides what a failure means.
 */
interface MailChannel
{
    public function send(string $to, string $subject, string $body): void;
}
