<?php

use App\Models\AuditLog;
use App\Models\User;

it('records auth:login and auth:logout with the actor and the ip', function () {
    $user = User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 's3cret-pw'])->assertOk();
    $this->postJson('/auth/logout')->assertNoContent();

    $rows = AuditLog::query()->orderBy('timestamp')->get();

    expect($rows->pluck('action')->all())->toBe(['auth:login', 'auth:logout'])
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and($rows->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1']);
});

it('writes no audit row for a failed login attempt', function () {
    User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 'wrong'])
        ->assertUnauthorized();

    expect(AuditLog::query()->count())->toBe(0);
});
