<?php

use App\Domain\Authorization\DuplicateEmail;
use App\Domain\Authorization\InvalidInstitutionEmail;
use App\Domain\Authorization\Role;
use App\Domain\Authorization\UserDirectory;
use App\Models\User;

it('requires authentication to register', function () {
    $this->postJson('/auth/register', ['email' => 'new@pdn.ac.lk'])->assertUnauthorized();
});

it('lets a user self-register the user role', function () {
    $actor = User::factory()->create(['email' => 'actor@pdn.ac.lk']);

    $this->actingAs($actor)->postJson('/auth/register', [
        'email' => 'newbie@pdn.ac.lk', 'role' => 'user', 'password' => 'pw',
    ])->assertOk();

    expect(User::query()->where('email', 'newbie@pdn.ac.lk')->exists())->toBeTrue();
});

it('forbids users and directors from minting staff roles', function (string $state) {
    $actor = User::factory()->{$state}()->create();

    $this->actingAs($actor)->postJson('/auth/register', [
        'email' => 'staff@pdn.ac.lk', 'role' => 'director',
    ])->assertForbidden();

    // Even a director cannot mint an md.
    $this->actingAs($actor)->postJson('/auth/register', [
        'email' => 'boss@pdn.ac.lk', 'role' => 'md',
    ])->assertForbidden();
})->with(['user', 'director']);

it('lets the MD mint director and md roles', function () {
    $md = User::factory()->md()->create();

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => 'dir2@pdn.ac.lk', 'role' => 'director', 'password' => 'pw',
    ])->assertOk();

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => 'md2@pdn.ac.lk', 'role' => 'md', 'password' => 'pw',
    ])->assertOk();
});

it('rejects a non-institution email with 422', function () {
    $md = User::factory()->md()->create();

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => 'x@gmail.com', 'role' => 'user',
    ])->assertStatus(422);
});

it('rejects a duplicate case-insensitively with 409', function () {
    $md = User::factory()->md()->create();
    User::factory()->create(['email' => 'taken@pdn.ac.lk']);

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => 'TAKEN@PDN.AC.LK', 'role' => 'user', 'password' => 'pw',
    ])->assertStatus(409);
});

it('normalizes the stored email and hashes the password', function () {
    $md = User::factory()->md()->create();

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => '  Mixed@PDN.AC.LK ', 'role' => 'user', 'password' => 's3cret-pw',
    ])->assertOk();

    $user = User::query()->where('email', 'mixed@pdn.ac.lk')->firstOrFail();
    expect($user->password_hash)->not->toBe('s3cret-pw');
});

it('rejects a password longer than 72 bytes with 422', function () {
    $md = User::factory()->md()->create();

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => 'long@pdn.ac.lk', 'role' => 'user', 'password' => str_repeat('x', 73),
    ])->assertStatus(422);
});

it('registers through the directory and looks users up by normalized email', function () {
    $directory = app(UserDirectory::class);

    $user = $directory->register('  Ada@PDN.AC.LK ', Role::USER, 'pw');

    expect($user->email)->toBe('ada@pdn.ac.lk')
        ->and($directory->findByEmail('ADA@pdn.ac.lk')?->id)->toBe($user->id)
        ->and($directory->all()->pluck('email'))->toContain('ada@pdn.ac.lk');
});

it('throws domain errors for a non-institution or duplicate email', function () {
    $directory = app(UserDirectory::class);

    expect(fn () => $directory->register('x@gmail.com', Role::USER))
        ->toThrow(InvalidInstitutionEmail::class);

    $directory->register('ada@pdn.ac.lk', Role::USER);

    expect(fn () => $directory->register('ADA@PDN.AC.LK', Role::USER))
        ->toThrow(DuplicateEmail::class);
});

it('throws when the directory is asked to hash a password over 72 bytes', function () {
    $directory = app(UserDirectory::class);

    expect(fn () => $directory->register('ada@pdn.ac.lk', Role::USER, str_repeat('x', 73)))
        ->toThrow(InvalidArgumentException::class);
});

it('defaults the role to user and does not leak the password hash', function () {
    $md = User::factory()->md()->create();

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => 'default@pdn.ac.lk', 'password' => 'pw',
    ])
        ->assertOk()
        ->assertJsonPath('role', 'user')
        ->assertJsonMissingPath('password_hash');
});
