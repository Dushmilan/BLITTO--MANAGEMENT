<?php

it('provides app settings with parity defaults', function () {
    expect(config('blitto.environment'))->toBe('local')
        ->and(config('blitto.allowed_email_domains'))->toBe('pdn.ac.lk')
        ->and(config('blitto.nipo_follow_up_interval_hours'))->toBe(24)
        ->and(config('blitto.document_store'))->toBe('local')
        ->and(config('blitto.storage'))->toBe('memory')
        ->and(config('blitto.sqlite_path'))->toBe('dev-data/state.sqlite3');
});

it('reads settings from BLITTODB_-prefixed env vars', function () {
    putenv('BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS=6');
    $_ENV['BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS'] = '6';

    $this->refreshApplication();

    expect(config('blitto.nipo_follow_up_interval_hours'))->toBe(6);
});

it('defaults the mail channel to laravel and reads the env override', function () {
    expect(config('blitto.mail_channel'))->toBe('laravel');

    putenv('BLITTODB_MAIL_CHANNEL=array');
    $_ENV['BLITTODB_MAIL_CHANNEL'] = 'array';
    $this->refreshApplication();

    expect(config('blitto.mail_channel'))->toBe('array');

    putenv('BLITTODB_MAIL_CHANNEL');
    unset($_ENV['BLITTODB_MAIL_CHANNEL']);
});

afterEach(function () {
    putenv('BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS');
    unset($_ENV['BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS']);
});
