<?php

use App\Domain\Authorization\BootstrapAdmin;
use App\Domain\Authorization\InvalidInstitutionEmail;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('creates the bootstrap MD from config when present', function () {
    config([
        'blitto.environment' => 'local',
        'blitto.bootstrap_admin_email' => 'boss@pdn.ac.lk',
        'blitto.bootstrap_admin_password' => 'strong-pass',
    ]);

    app(BootstrapAdmin::class)->ensure();

    $md = User::query()->where('email', 'boss@pdn.ac.lk')->first();

    expect($md)->not->toBeNull()
        ->and($md->role)->toBe('md')
        ->and(Auth::attempt(['email' => 'boss@pdn.ac.lk', 'password' => 'strong-pass']))->toBeTrue();
});

it('does nothing when creds are absent', function () {
    config(['blitto.bootstrap_admin_email' => '', 'blitto.bootstrap_admin_password' => '']);

    app(BootstrapAdmin::class)->ensure();

    expect(User::query()->count())->toBe(0);
});

it('is idempotent', function () {
    config([
        'blitto.environment' => 'local',
        'blitto.bootstrap_admin_email' => 'boss@pdn.ac.lk',
        'blitto.bootstrap_admin_password' => 'strong-pass',
    ]);

    app(BootstrapAdmin::class)->ensure();
    app(BootstrapAdmin::class)->ensure();

    expect(User::query()->where('email', 'boss@pdn.ac.lk')->count())->toBe(1);
});

it('refuses a non-institution bootstrap email', function () {
    config([
        'blitto.environment' => 'local',
        'blitto.bootstrap_admin_email' => 'boss@gmail.com',
        'blitto.bootstrap_admin_password' => 'strong-pass',
    ]);

    expect(fn () => app(BootstrapAdmin::class)->ensure())
        ->toThrow(InvalidInstitutionEmail::class);
});

it('refuses a placeholder bootstrap password outside local', function () {
    config([
        'blitto.environment' => 'production',
        'blitto.bootstrap_admin_email' => 'boss@pdn.ac.lk',
        'blitto.bootstrap_admin_password' => 'password',
    ]);

    expect(fn () => app(BootstrapAdmin::class)->ensure())
        ->toThrow(InvalidArgumentException::class);
});
