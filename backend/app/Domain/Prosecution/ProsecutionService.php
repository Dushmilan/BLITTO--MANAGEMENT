<?php

namespace App\Domain\Prosecution;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Docketing\DeadlineType;
use App\Domain\Docketing\DocketingService;
use App\Models\Application;
use App\Models\OfficeAction;
use App\Models\Response;
use App\Models\User;
use Illuminate\Support\Carbon as SupportCarbon;

/**
 * Prosecution — port of pre-laravel-final-state
 * `app/modules/prosecution/local/__init__.py`. The docketing module stays
 * the sole authority on deadlines (invariant 4), so this service asks it
 * to create the response deadline instead of writing one itself.
 */
final class ProsecutionService
{
    /** Calendar days a director has to answer an office action. */
    public const int RESPONSE_WINDOW_DAYS = 90;

    public function __construct(
        private readonly DocketingService $docketing,
        private readonly AuditRecorder $audit,
    ) {}

    public function receiveOfficeAction(
        Application $application,
        OfficeActionKind $kind,
        string $body,
        User $actor,
        ?string $ipAddress = null,
    ): OfficeAction {
        $officeAction = OfficeAction::query()->create([
            'application_id' => $application->id,
            'kind' => $kind->value,
            'body' => $body,
        ]);

        // An allowance needs no response; everything else auto-dockets one.
        if ($kind !== OfficeActionKind::ALLOWANCE) {
            $this->docketing->addDeadline(
                $application,
                DeadlineType::RESPONSE,
                SupportCarbon::now()->addDays(self::RESPONSE_WINDOW_DAYS),
                $actor,
                $ipAddress,
            );
        }

        $this->audit->record(
            'receive_office_action:'.$kind->value,
            actor: $actor,
            application: $application,
            ipAddress: $ipAddress,
        );

        return $officeAction;
    }

    /**
     * @throws UnknownOfficeAction when the office action belongs to another application
     */
    public function fileResponse(Application $application, OfficeAction $officeAction, string $body): Response
    {
        if ($officeAction->application_id !== $application->id) {
            throw new UnknownOfficeAction($officeAction->id);
        }

        return Response::query()->create([
            'application_id' => $application->id,
            'office_action_id' => $officeAction->id,
            'body' => $body,
        ]);
    }
}
