<?php

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\ArrayMailChannel;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\Notification;

it('writes a status-change notification using the reference template', function () {
    $application = Application::query()->create([
        'title' => 'Solar Widget',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    (new NotificationService(new ArrayMailChannel))->notifyStatusChange($application, ApplicationStatus::FILED);

    $notification = Notification::query()->sole();

    expect($notification->recipient_email)->toBe('ada@pdn.ac.lk')
        ->and($notification->subject)->toBe("Patent {$application->id} filed to NIPO")
        ->and($notification->body)->toBe("The patent application {$application->id} has been filed to NIPO. Ref: {$application->id}.")
        ->and($notification->kind)->toBe('status_change');
});

it('falls back to a generic template for statuses with no wording of their own', function () {
    $application = Application::query()->create([
        'title' => 'Defective',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    (new NotificationService(new ArrayMailChannel))->notifyStatusChange($application, ApplicationStatus::ACKNOWLEDGED);

    $notification = Notification::query()->sole();

    expect($notification->subject)->toBe("Patent {$application->id} status changed")
        ->and($notification->body)->toBe("The patent application {$application->id} is now: ACKNOWLEDGED.");
});

it('notifies every distinct inventor and skips blanks', function () {
    $application = Application::query()->create([
        'title' => 'Joint work',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    foreach (['ada@pdn.ac.lk', 'bob@pdn.ac.lk', 'ada@pdn.ac.lk', '  '] as $email) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $email,
        ]);
    }

    $sent = (new NotificationService(new ArrayMailChannel))->notifyInventors($application, 'Patent Filed', 'Filed.');

    expect($sent)->toBe(2)
        ->and(Notification::query()->orderBy('recipient_email')->pluck('recipient_email')->all())
        ->toBe(['ada@pdn.ac.lk', 'bob@pdn.ac.lk'])
        ->and(Notification::query()->first()->kind)->toBe('filing');
});

it('falls back to the denormalised inventor email when no inventor rows exist', function () {
    $application = Application::query()->create([
        'title' => 'Solo',
        'inventor_email' => 'solo@pdn.ac.lk',
    ]);

    expect((new NotificationService(new ArrayMailChannel))->recipients($application))->toBe(['solo@pdn.ac.lk']);
});
