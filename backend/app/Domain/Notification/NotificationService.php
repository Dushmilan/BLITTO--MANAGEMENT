<?php

namespace App\Domain\Notification;

use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notification writer — port of pre-laravel-final-state
 * `app/modules/notification/local/__init__.py`. Persists rows in the
 * `notifications` table; mail delivery and the NIPO scheduler arrive at L5.
 */
final class NotificationService
{
    /** @var array<string, string> */
    private const array STATUS_SUBJECTS = [
        'DRAFT' => 'Patent {ref} drafted',
        'FILED' => 'Patent {ref} filed to NIPO',
        'PUBLISHED' => 'Patent {ref} published',
        'EXAMINATION' => 'Patent {ref} under examination',
        'GRANTED' => 'Patent {ref} GRANTED — download ready',
        'REJECTED' => 'Patent {ref} update: rejected',
        'MAINTENANCE' => 'Patent {ref} in maintenance',
    ];

    /** @var array<string, string> */
    private const array STATUS_BODIES = [
        'DRAFT' => 'The patent application {ref} is now: DRAFT.',
        'FILED' => 'The patent application {ref} has been filed to NIPO. Ref: {ref}.',
        'PUBLISHED' => 'The patent application {ref} is now published.',
        'EXAMINATION' => 'The patent application {ref} is now: EXAMINATION. No action needed.',
        'GRANTED' => 'Good news — patent {ref} is now GRANTED. You can download the patent document from your BLITTO portal.',
        'REJECTED' => 'The patent application {ref} was rejected. Please contact BLITTO for next steps.',
        'MAINTENANCE' => 'The patent {ref} is now in maintenance. Watch for fee deadlines.',
    ];

    /** GRANTED with no document uploaded yet (reference `document_available=False`). */
    private const string GRANTED_PENDING_SUBJECT = 'Patent {ref} GRANTED — document pending';

    private const string GRANTED_PENDING_BODY = 'Good news — patent {ref} is now GRANTED. The document is pending upload by BLITTO; you can request it from your portal and we will notify you when it is ready to download.';

    public function __construct(private readonly MailChannel $channel) {}

    public function send(string $recipientEmail, string $subject, string $body, string $kind = 'status_change'): Notification
    {
        // The row is the durable record; it is written before delivery so a
        // mail failure can never lose the notification.
        $notification = Notification::query()->create([
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'body' => $body,
            'kind' => $kind,
        ]);

        // Best effort by design: a broken mailer must not fail the action that
        // produced the notification.
        try {
            $this->channel->send($recipientEmail, $subject, $body);
        } catch (Throwable $exception) {
            Log::warning('Notification mail delivery failed', [
                'notification_id' => $notification->id,
                'recipient_email' => $recipientEmail,
                'kind' => $kind,
                'error' => $exception->getMessage(),
            ]);
        }

        return $notification;
    }

    /**
     * Notify every inventor of an application about a status change.
     *
     * @return int the number of notifications written
     */
    public function notifyStatusChange(
        Application $application,
        ApplicationStatus $status,
        bool $documentAvailable = true,
    ): int {
        if ($status === ApplicationStatus::GRANTED && ! $documentAvailable) {
            $subject = str_replace('{ref}', $application->id, self::GRANTED_PENDING_SUBJECT);
            $body = str_replace('{ref}', $application->id, self::GRANTED_PENDING_BODY);
        } else {
            $subject = str_replace('{ref}', $application->id, self::STATUS_SUBJECTS[$status->value] ?? 'Patent {ref} status changed');
            $body = str_replace('{ref}', $application->id, self::STATUS_BODIES[$status->value] ?? 'The patent application {ref} is now: '.$status->value.'.');
        }

        $sent = 0;

        foreach ($this->recipients($application) as $email) {
            $this->send($email, $subject, $body);
            $sent++;
        }

        return $sent;
    }

    /**
     * @return int the number of notifications written
     */
    public function notifyInventors(Application $application, string $subject, string $body, string $kind = 'filing'): int
    {
        $sent = 0;

        foreach ($this->recipients($application) as $email) {
            $this->send($email, $subject, $body, $kind);
            $sent++;
        }

        return $sent;
    }

    /**
     * The inventor table is the normalised source; the denormalised
     * `applications.inventor_email` is the fallback for rows created
     * without inventor records (seeds, direct model writes).
     *
     * @return list<string>
     */
    public function recipients(Application $application): array
    {
        $emails = Inventor::query()
            ->where('application_id', $application->id)
            ->pluck('inventor_email')
            ->all();

        if ($emails === []) {
            $emails = [$application->inventor_email];
        }

        $emails = array_filter($emails, static fn (string $email): bool => trim($email) !== '');

        return array_values(array_unique($emails));
    }
}
