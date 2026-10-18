<?php

use App\Models\Application;
use App\Models\StatusHistory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['blitto.demo_snapshot_path' => base_path('tests/Fixtures/demo-snapshot.json')]);
});

it('restores the snapshot users with bcrypted passwords', function () {
    $this->seed(DemoDataSeeder::class);

    expect(User::query()->count())->toBe(2);

    $md = User::query()->where('email', 'md@pdn.ac.lk')->sole();

    expect($md->role)->toBe('md')
        ->and(Hash::check('mdpw-123', $md->password_hash))->toBeTrue()
        ->and($md->password_hash)->not->toBe('mdpw-123');

    $inventor = User::query()->where('email', 'user-a@pdn.ac.lk')->sole();

    expect(Hash::check('userapw-123', $inventor->password_hash))->toBeTrue();
});

it('restores applications with their inventors and history', function () {
    $this->seed(DemoDataSeeder::class);

    $application = Application::query()->sole();

    expect($application->id)->toBe('33333333-3333-4333-8333-333333333333')
        ->and($application->title)->toBe('Solar Desalination Membrane')
        ->and($application->status)->toBe('DRAFT')
        ->and($application->inventor_email)->toBe('user-a@pdn.ac.lk')
        ->and($application->created_at->toIso8601ZuluString())->toBe('2026-09-09T08:56:40Z');

    $this->assertDatabaseHas('inventors', [
        'application_id' => $application->id,
        'inventor_email' => 'user-a@pdn.ac.lk',
        'inventor_name' => 'User A',
    ]);

    $history = StatusHistory::query()->sole();

    expect($history->id)->toBe('44444444-4444-4444-8444-444444444444')
        ->and($history->old_status)->toBe('DRAFT')
        ->and($history->new_status)->toBe('GRANTED')
        ->and($history->reason)->toBe('');
});

it('imports the same rows through the default database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Application::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(2);
});

it('aborts with a clear message when the snapshot file is missing', function () {
    config(['blitto.demo_snapshot_path' => base_path('tests/Fixtures/nope.json')]);

    expect(fn () => $this->seed(DemoDataSeeder::class))
        ->toThrow(RuntimeException::class, 'Demo snapshot not found');
});

it('aborts on an unsupported snapshot version', function () {
    config(['blitto.demo_snapshot_path' => base_path('tests/Fixtures/demo-snapshot-v2.json')]);

    file_put_contents(
        base_path('tests/Fixtures/demo-snapshot-v2.json'),
        json_encode(['version' => 2]),
    );

    try {
        expect(fn () => $this->seed(DemoDataSeeder::class))
            ->toThrow(RuntimeException::class, 'Unsupported demo snapshot version');
    } finally {
        unlink(base_path('tests/Fixtures/demo-snapshot-v2.json'));
    }
});
