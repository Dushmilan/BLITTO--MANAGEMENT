<?php

use App\Domain\Docketing\DeadlineStatus;

it('has no soft deadline states — only OPEN, MET, MISSED', function () {
    $names = array_map(fn (DeadlineStatus $status) => $status->name, DeadlineStatus::cases());

    expect($names)->toBe(['OPEN', 'MET', 'MISSED']);
});
