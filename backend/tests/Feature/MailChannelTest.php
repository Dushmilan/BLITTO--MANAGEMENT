<?php

use App\Domain\Notification\ArrayMailChannel;
use App\Domain\Notification\MailChannel;
use App\Domain\Notification\UnsupportedMailChannel;

it('resolves the array adapter from the mail_channel config', function () {
    config(['blitto.mail_channel' => 'array']);
    app()->forgetInstance(MailChannel::class);

    $channel = app(MailChannel::class);

    expect($channel)->toBeInstanceOf(ArrayMailChannel::class);

    $channel->send('ada@pdn.ac.lk', 'Subject', 'Body');

    expect($channel->messages())->toBe([
        ['to' => 'ada@pdn.ac.lk', 'subject' => 'Subject', 'body' => 'Body'],
    ])->and($channel->sentTo('ada@pdn.ac.lk'))->toHaveCount(1)
        ->and($channel->sentTo('bob@pdn.ac.lk'))->toHaveCount(0)
        ->and($channel->lastMessage())->toBe(['to' => 'ada@pdn.ac.lk', 'subject' => 'Subject', 'body' => 'Body']);
});

it('fails loudly for an unknown mail channel', function () {
    config(['blitto.mail_channel' => 'carrier-pigeon']);
    app()->forgetInstance(MailChannel::class);

    expect(fn () => app(MailChannel::class))->toThrow(UnsupportedMailChannel::class);
});
