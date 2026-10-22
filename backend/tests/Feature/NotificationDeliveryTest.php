<?php

use App\Domain\Notification\MailChannel;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// Task 4 ruling: phpunit.xml does not force BLITTODB_MAIL_CHANNEL, so a test
// that wants the in-memory adapter asks for it.
beforeEach(function () {
    config(['blitto.mail_channel' => 'array']);
});

it('persists the notification and delivers it through the mail channel', function () {
    $channel = app(MailChannel::class);

    app(NotificationService::class)->send('ada@pdn.ac.lk', 'Patent filed', 'Body text');

    $notification = Notification::query()->sole();

    expect($notification->recipient_email)->toBe('ada@pdn.ac.lk')
        ->and($notification->kind)->toBe('status_change')
        ->and($channel->messages())->toBe([[
            'to' => 'ada@pdn.ac.lk',
            'subject' => 'Patent filed',
            'body' => 'Body text',
        ]]);
});

it('keeps the row and lets the action succeed when delivery fails', function () {
    Log::spy();

    $this->app->instance(MailChannel::class, new class implements MailChannel
    {
        public function send(string $to, string $subject, string $body): void
        {
            throw new RuntimeException('smtp is on fire');
        }
    });

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Notified', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed receipt recorded',
    ])->assertNoContent();

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::query()->sole()->recipient_email)->toBe('ada@pdn.ac.lk');

    Log::shouldHaveReceived('warning')->once();
});

it('records a notification with a blank recipient instead of failing', function () {
    app(NotificationService::class)->send('', 'Subject', 'Body');

    expect(Notification::query()->sole()->recipient_email)->toBe('');
});
