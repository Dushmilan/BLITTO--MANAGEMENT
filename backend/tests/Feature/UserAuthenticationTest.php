<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('authenticates against the password_hash column', function () {
    $user = User::factory()->withPassword('s3cret-pw')->create([
        'email' => 'ada@pdn.ac.lk',
        'role' => 'user',
    ]);

    expect($user->getAuthPasswordName())->toBe('password_hash')
        ->and(Auth::attempt(['email' => 'ada@pdn.ac.lk', 'password' => 's3cret-pw']))->toBeTrue()
        ->and(Auth::attempt(['email' => 'ada@pdn.ac.lk', 'password' => 'wrong']))->toBeFalse();
});

it('persists factory users with real schema columns', function () {
    $user = User::factory()->md()->create();

    expect($user->exists)->toBeTrue()
        ->and($user->role)->toBe('md')
        ->and($user->email)->toEndWith('@pdn.ac.lk');
});
