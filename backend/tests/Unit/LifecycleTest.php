<?php

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\IllegalTransition;
use App\Domain\Lifecycle\LifecycleService;

it('rejects an illegal DRAFT to GRANTED jump', function () {
    $lifecycle = new LifecycleService;

    $lifecycle->assertAllowed(ApplicationStatus::DRAFT, ApplicationStatus::GRANTED);
})->throws(IllegalTransition::class);

it('allows the legal prosecution chain to GRANTED', function () {
    $lifecycle = new LifecycleService;

    $chain = [
        ApplicationStatus::FILED,
        ApplicationStatus::ACKNOWLEDGED,
        ApplicationStatus::EXAMINATION,
        ApplicationStatus::GRANTED,
    ];

    $current = ApplicationStatus::DRAFT;
    foreach ($chain as $expectedNext) {
        $lifecycle->assertAllowed($current, $expectedNext);
        $current = $expectedNext;
    }

    expect($current)->toBe(ApplicationStatus::GRANTED);
});

it('allows a lifecycle transition', function (ApplicationStatus $from, ApplicationStatus $to) {
    expect(fn () => (new LifecycleService)->assertAllowed($from, $to))
        ->not->toThrow(IllegalTransition::class);
})->with('legal transitions');

it('rejects a lifecycle transition', function (ApplicationStatus $from, ApplicationStatus $to) {
    (new LifecycleService)->assertAllowed($from, $to);
})->throws(IllegalTransition::class)->with('illegal transitions');

it('allows same-status re-entry (idempotent)', function (ApplicationStatus $status) {
    expect(fn () => (new LifecycleService)->assertAllowed($status, $status))
        ->not->toThrow(IllegalTransition::class);
})->with(ApplicationStatus::cases());
