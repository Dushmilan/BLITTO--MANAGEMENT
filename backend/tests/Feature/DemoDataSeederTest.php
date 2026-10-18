<?php

use App\Models\Application;
use App\Models\Deadline;
use App\Models\Notification;
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

it('restores deadlines, notifications and audit rows with their timestamps', function () {
    $this->seed(DemoDataSeeder::class);

    $deadline = Deadline::query()->sole();

    expect($deadline->id)->toBe('88888888-8888-4888-8888-888888888888')
        ->and($deadline->type)->toBe('RESPONSE')
        ->and($deadline->status)->toBe('OPEN')
        ->and($deadline->due_date->toIso8601ZuluString())->toBe('2026-12-08T08:56:40Z');

    $notification = Notification::query()->sole();

    expect($notification->id)->toBe('66666666-6666-4666-8666-666666666666')
        ->and($notification->recipient_email)->toBe('user-a@pdn.ac.lk')
        ->and($notification->subject)->toContain('GRANTED — download ready')
        ->and($notification->sent_at->toIso8601ZuluString())->toBe('2026-09-09T08:56:40Z');

    $this->assertDatabaseHas('audit_logs', [
        'id' => '77777777-7777-4777-8777-777777777777',
        'user_id' => 'md',
        'application_id' => '33333333-3333-4333-8333-333333333333',
        'action' => 'store_document',
        'ip_address' => null,
    ]);
});

it('imports a snapshot that omits optional sections', function () {
    $minimal = [
        'version' => 1,
        'authorization' => [
            'users' => [
                '11111111-1111-4111-8111-111111111111' => [
                    'id' => '11111111-1111-4111-8111-111111111111',
                    'email' => 'md@pdn.ac.lk', 'role' => 'md', 'user_code' => null,
                ],
            ],
            'passwords' => ['11111111-1111-4111-8111-111111111111' => 'mdpw-123'],
        ],
        'application_intake' => ['store' => [], 'history' => []],
    ];

    file_put_contents(base_path('tests/Fixtures/demo-snapshot-minimal.json'), json_encode($minimal));
    config(['blitto.demo_snapshot_path' => base_path('tests/Fixtures/demo-snapshot-minimal.json')]);

    try {
        $this->seed(DemoDataSeeder::class);

        expect(User::query()->count())->toBe(1)
            ->and(Deadline::query()->count())->toBe(0)
            ->and(Notification::query()->count())->toBe(0);
    } finally {
        unlink(base_path('tests/Fixtures/demo-snapshot-minimal.json'));
    }
});
