<?php

use App\Domain\Filing\DefectSheetRejected;
use App\Domain\Filing\FilingWorkflowService;
use App\Domain\Lifecycle\IllegalTransition;
use App\Models\Application;
use App\Models\DefectSheet;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

function filedApplication(): Application
{
    $application = Application::query()->create(['title' => 'Defect Flow', 'inventor_email' => 'ada@pdn.ac.lk']);
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    app(FilingWorkflowService::class)->markFiled($application, User::factory()->director()->create());

    return $application->refresh();
}

it('records a defect sheet, moves the status and updates the filing record', function () {
    $director = User::factory()->director()->create();
    $application = filedApplication();
    Notification::query()->delete();

    $sheet = app(FilingWorkflowService::class)->recordDefectSheet($application, 1, 'Missing signature', $director);

    $reference = substr($application->id, 0, 8);

    expect($application->refresh()->status)->toBe('DEFECT_SHEET_1')
        ->and($sheet->sheet_number)->toBe(1)
        ->and($sheet->description)->toBe('Missing signature')
        ->and($sheet->application_id)->toBe($application->id)
        ->and(FilingRecord::query()->sole()->defect_sheet_count)->toBe(1)
        ->and(Notification::query()->sole()->subject)->toBe('Defect Sheet Received')
        ->and(Notification::query()->sole()->body)
        ->toBe("Defect sheet #1 has been received for your patent application Defect Flow ({$reference}): Missing signature");

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:defect_sheet', 'user_id' => $director->id]);
    $this->assertDatabaseHas('status_history', ['application_id' => $application->id, 'new_status' => 'DEFECT_SHEET_1']);
});

it('allows a later sheet number to be applied directly from ACKNOWLEDGED', function () {
    $md = User::factory()->md()->create();
    $application = filedApplication();
    app(FilingWorkflowService::class)->acknowledgeNipo($application, $md);

    $sheet = app(FilingWorkflowService::class)->recordDefectSheet($application->refresh(), 2, 'Clarity', $md);

    expect($sheet->sheet_number)->toBe(2)
        ->and($application->refresh()->status)->toBe('DEFECT_SHEET_2');
});

it('rejects a sheet number outside 1 to 3', function (int $sheetNumber) {
    $application = filedApplication();

    app(FilingWorkflowService::class)->recordDefectSheet($application, $sheetNumber, 'x', User::factory()->director()->create());
})->throws(DefectSheetRejected::class)->with([0, 4]);

it('rejects a repeated sheet number', function () {
    $director = User::factory()->director()->create();
    $application = filedApplication();
    $service = app(FilingWorkflowService::class);
    $service->recordDefectSheet($application, 1, 'First', $director);
    Notification::query()->delete();

    try {
        $service->recordDefectSheet($application->refresh(), 1, 'Again', $director);
        $this->fail('Expected the duplicate sheet to be rejected.');
    } catch (DefectSheetRejected $exception) {
        expect($exception->getMessage())->toBe("Defect sheet #1 already exists for {$application->id}");
    }

    expect(DefectSheet::query()->count())->toBe(1);
    $this->assertDatabaseCount('notifications', 0);
});

it('lets the transition table reject a sheet number the lifecycle forbids', function () {
    $application = filedApplication();
    app(FilingWorkflowService::class)->recordDefectSheet($application, 1, 'First', User::factory()->director()->create());

    // DEFECT_SHEET_1 may only reach DEFECT_SHEET_2, never DEFECT_SHEET_3.
    app(FilingWorkflowService::class)->recordDefectSheet($application->refresh(), 3, 'Skip', User::factory()->director()->create());
})->throws(IllegalTransition::class);

it('lists the defect sheets of an application in sheet order', function () {
    $director = User::factory()->director()->create();
    $application = filedApplication();
    $service = app(FilingWorkflowService::class);
    $service->recordDefectSheet($application, 1, 'First', $director);
    $service->recordDefectSheet($application->refresh(), 2, 'Second', $director);

    expect($service->defectSheets($application)->pluck('sheet_number')->all())->toBe([1, 2])
        ->and($service->defectSheets(Application::query()->create(['title' => 'Other'])))->toBeEmpty();
});
