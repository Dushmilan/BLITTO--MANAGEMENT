<?php

use App\Domain\Notification\LaravelMailChannel;
use App\Domain\Notification\MailChannel;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

it('dispatches a NotificationMail through laravels mail layer', function () {
    Mail::fake();

    (new LaravelMailChannel)->send('ada@pdn.ac.lk', 'Patent filed', "Line one.\nLine two.");

    Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
        return $mail->hasTo('ada@pdn.ac.lk')
            && $mail->subjectLine === 'Patent filed'
            && $mail->bodyText === "Line one.\nLine two."
            && str_contains($mail->render(), 'Line one.')
            && str_contains($mail->render(), 'Line two.');
    });
});

it('binds the laravel adapter from the mail_channel config', function () {
    config(['blitto.mail_channel' => 'laravel']);
    app()->forgetInstance(MailChannel::class);

    expect(app(MailChannel::class))->toBeInstanceOf(LaravelMailChannel::class);
});
