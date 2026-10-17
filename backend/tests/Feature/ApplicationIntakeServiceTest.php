<?php

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\ApplicationIntake\ApplicationNotFound;
use App\Domain\ApplicationIntake\Disclosure;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\User;

it('creates a draft application shell and denormalises the first inventor', function () {
    $director = User::factory()->director()->create();

    $application = app(ApplicationIntakeService::class)->createShell(
        new Disclosure(
            title: 'Solar Widget',
            inventorName: 'A. Inventor',
            inventorEmail: 'ada@pdn.ac.lk',
            technologyArea: 'Solar',
        ),
        $director,
    );

    expect($application->status)->toBe('DRAFT')
        ->and($application->title)->toBe('Solar Widget')
        ->and($application->inventor_name)->toBe('A. Inventor')
        ->and($application->inventor_email)->toBe('ada@pdn.ac.lk')
        ->and($application->technology_area)->toBe('Solar')
        ->and($application->application_number)->toBeNull()
        ->and($application->nipo_reference)->toBeNull()
        ->and(Inventor::query()->count())->toBe(1);
});

it('uses the inventors list as the source of truth and normalises their emails', function () {
    $director = User::factory()->director()->create();

    $application = app(ApplicationIntakeService::class)->createShell(
        Disclosure::fromArray([
            'title' => 'Joint Patent',
            'inventors' => [
                ['inventor_name' => 'Alice', 'inventor_email' => 'ALICE@pdn.ac.lk '],
                ['inventor_name' => 'Bob', 'inventor_email' => 'bob@pdn.ac.lk'],
            ],
        ]),
        $director,
    );

    expect($application->inventor_name)->toBe('Alice')
        ->and($application->inventor_email)->toBe('alice@pdn.ac.lk')
        ->and(Inventor::query()->count())->toBe(2);
});

it('writes a create_application audit entry', function () {
    $director = User::factory()->director()->create();

    $application = app(ApplicationIntakeService::class)->createShell(
        new Disclosure(title: 'Audited', inventorName: 'A', inventorEmail: 'a@pdn.ac.lk'),
        $director,
        ipAddress: '10.0.0.9',
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'create_application',
        'user_id' => $director->id,
        'application_id' => $application->id,
        'ip_address' => '10.0.0.9',
    ]);
});

it('updates only the editable fields', function () {
    $application = Application::query()->create(['title' => 'Old', 'technology_area' => 'Old Tech']);

    app(ApplicationIntakeService::class)->updateFields($application, 'New', 'New Tech');

    expect($application->refresh()->title)->toBe('New')
        ->and($application->technology_area)->toBe('New Tech')
        ->and($application->status)->toBe('DRAFT');
});

it('leaves a field untouched when null is passed', function () {
    $application = Application::query()->create(['title' => 'Keep', 'technology_area' => 'Keep Tech']);

    app(ApplicationIntakeService::class)->updateFields($application, null, 'Only Tech');

    expect($application->refresh()->title)->toBe('Keep')
        ->and($application->technology_area)->toBe('Only Tech');
});

it('lists every application for staff and only own applications for a user', function () {
    $service = app(ApplicationIntakeService::class);
    Application::query()->create(['title' => 'A', 'inventor_email' => 'ada@pdn.ac.lk']);
    Application::query()->create(['title' => 'B', 'inventor_email' => 'bob@pdn.ac.lk']);

    $user = User::factory()->create(['email' => 'ada@pdn.ac.lk']);
    $director = User::factory()->director()->create();

    $visible = $service->listVisibleTo($user);

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->title)->toBe('A')
        ->and($service->listVisibleTo($director))->toHaveCount(2);
});

it('returns the status history in change order', function () {
    $application = Application::query()->create(['title' => 'Trail']);
    $lifecycle = new LifecycleService;

    $lifecycle->changeStatus($application, ApplicationStatus::FILED, changedBy: 'director@pdn.ac.lk', reason: 'Filed');
    $this->travel(1)->minutes();
    $lifecycle->changeStatus($application, ApplicationStatus::EXAMINATION, changedBy: 'md@pdn.ac.lk', reason: 'Examined');

    expect(app(ApplicationIntakeService::class)->history($application)->pluck('new_status')->all())
        ->toBe(['FILED', 'EXAMINATION']);
});

it('throws a not-found exception for an unknown application', function () {
    app(ApplicationIntakeService::class)->findOrFail('missing-id');
})->throws(ApplicationNotFound::class, 'Application missing-id not found');
