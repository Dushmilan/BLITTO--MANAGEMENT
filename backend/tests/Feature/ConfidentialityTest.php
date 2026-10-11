<?php

use App\Domain\Authorization\ApplicationVisibility;
use App\Models\Application;
use App\Models\User;

it('restricts users to their own applications while staff see the full portfolio', function () {
    $adaApplication = Application::query()->create([
        'title' => 'Ada application',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    $alanApplication = Application::query()->create([
        'title' => 'Alan application',
        'inventor_email' => 'alan@pdn.ac.lk',
    ]);

    $ada = User::query()->create([
        'email' => 'ada@pdn.ac.lk',
        'role' => 'user',
        'password_hash' => 'irrelevant-hash',
    ]);
    $director = User::query()->create([
        'email' => 'director@pdn.ac.lk',
        'role' => 'director',
        'password_hash' => 'irrelevant-hash',
    ]);
    $md = User::query()->create([
        'email' => 'md@pdn.ac.lk',
        'role' => 'md',
        'password_hash' => 'irrelevant-hash',
    ]);

    $visibleIds = fn (User $viewer) => ApplicationVisibility::visibleTo(Application::query(), $viewer)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $staffPortfolio = $visibleIds($director);

    expect($visibleIds($ada))->toBe([$adaApplication->id])
        ->and($staffPortfolio)->toContain($adaApplication->id, $alanApplication->id)
        ->and($visibleIds($md))->toBe($staffPortfolio)
        ->and($staffPortfolio)->toHaveCount(2);
});
