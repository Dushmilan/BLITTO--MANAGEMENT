<?php

namespace App\Domain\Notification;

use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

/**
 * Production adapter: hands the message to Laravel's mailer, so the transport
 * (log, smtp, array) stays a MAIL_MAILER configuration concern.
 */
final class LaravelMailChannel implements MailChannel
{
    public function send(string $to, string $subject, string $body): void
    {
        Mail::to($to)->send(new NotificationMail($subject, $body));
    }
}
