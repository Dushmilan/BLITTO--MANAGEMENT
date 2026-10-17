<?php

namespace App\Domain\ApplicationIntake;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Authorization\ApplicationVisibility;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Application intake — port of pre-laravel-final-state
 * `app/modules/application_intake/local/__init__.py`. Owns the application
 * aggregate: shell creation, field edits, visibility-scoped listing and the
 * status trail. Status *transitions* belong to the lifecycle domain.
 */
final class ApplicationIntakeService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @throws ApplicationNotFound
     */
    public function findOrFail(string $applicationId): Application
    {
        return Application::query()->find($applicationId)
            ?? throw new ApplicationNotFound($applicationId);
    }

    public function createShell(Disclosure $disclosure, User $actor, ?string $ipAddress = null): Application
    {
        $inventors = $disclosure->resolvedInventors();
        $main = $inventors[0];

        $application = Application::query()->create([
            'title' => $disclosure->title,
            'inventor_name' => $main['inventor_name'],
            'inventor_email' => $main['inventor_email'],
            'technology_area' => $disclosure->technologyArea,
            'status' => ApplicationStatus::DRAFT->value,
        ]);

        foreach ($inventors as $inventor) {
            Inventor::query()->create([
                'application_id' => $application->id,
                'inventor_name' => $inventor['inventor_name'],
                'inventor_email' => $inventor['inventor_email'],
            ]);
        }

        $this->audit->record('create_application', actor: $actor, application: $application, ipAddress: $ipAddress);

        return $application;
    }

    public function updateFields(Application $application, ?string $title, ?string $technologyArea): Application
    {
        if ($title !== null) {
            $application->title = $title;
        }

        if ($technologyArea !== null) {
            $application->technology_area = $technologyArea;
        }

        $application->save();

        return $application;
    }

    /**
     * @return Collection<int, Application>
     */
    public function listVisibleTo(User $viewer): Collection
    {
        $query = Application::query()->orderBy('created_at')->orderBy('id');

        return ApplicationVisibility::visibleTo($query, $viewer)->get();
    }

    /**
     * @return Collection<int, StatusHistory>
     */
    public function history(Application $application): Collection
    {
        return StatusHistory::query()
            ->where('application_id', $application->id)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get();
    }
}
