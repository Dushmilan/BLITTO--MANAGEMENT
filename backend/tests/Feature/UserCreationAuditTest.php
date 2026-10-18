<?php

use App\Domain\Authorization\BootstrapAdmin;
use App\Models\AuditLog;
use App\Models\User;

it('records create_user when an MD registers a director', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => 'new.director@pdn.ac.lk',
        'role' => 'director',
        'password' => 'secret-password',
    ])->assertOk();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('create_user')
        ->and($log->user_id)->toBe($md->id)
        ->and($log->application_id)->toBeNull()
        ->and($log->ip_address)->toBe('127.0.0.1');
});

it('records a create_user row for the bootstrap admin with no actor', function () {
    config([
        'blitto.bootstrap_admin_email' => 'root@pdn.ac.lk',
        'blitto.bootstrap_admin_password' => 'bootstrap-password',
    ]);

    app(BootstrapAdmin::class)->ensure();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('create_user')
        ->and($log->user_id)->toBeNull()
        ->and($log->ip_address)->toBeNull();
});
