<?php

use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Deadline;
use App\Models\DefectSheet;
use App\Models\Document;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\OfficeAction;
use App\Models\Response;
use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Lifecycle transition datasets
|--------------------------------------------------------------------------
| Reference: git tag pre-laravel-final-state,
| app/modules/application_intake/local/__init__.py::_ALLOWED_TRANSITIONS
*/
dataset('legal transitions', [
    [ApplicationStatus::DRAFT, ApplicationStatus::FILED],
    [ApplicationStatus::FILED, ApplicationStatus::PUBLISHED],
    [ApplicationStatus::FILED, ApplicationStatus::ACKNOWLEDGED],
    [ApplicationStatus::FILED, ApplicationStatus::EXAMINATION],
    [ApplicationStatus::FILED, ApplicationStatus::DEFECT_SHEET_1],
    [ApplicationStatus::FILED, ApplicationStatus::REJECTED],
    [ApplicationStatus::PUBLISHED, ApplicationStatus::EXAMINATION],
    [ApplicationStatus::PUBLISHED, ApplicationStatus::ACKNOWLEDGED],
    [ApplicationStatus::PUBLISHED, ApplicationStatus::REJECTED],
    [ApplicationStatus::ACKNOWLEDGED, ApplicationStatus::EXAMINATION],
    [ApplicationStatus::ACKNOWLEDGED, ApplicationStatus::DEFECT_SHEET_1],
    [ApplicationStatus::ACKNOWLEDGED, ApplicationStatus::DEFECT_SHEET_2],
    [ApplicationStatus::ACKNOWLEDGED, ApplicationStatus::DEFECT_SHEET_3],
    [ApplicationStatus::ACKNOWLEDGED, ApplicationStatus::GRANTED],
    [ApplicationStatus::ACKNOWLEDGED, ApplicationStatus::REJECTED],
    [ApplicationStatus::DEFECT_SHEET_1, ApplicationStatus::DEFECT_SHEET_2],
    [ApplicationStatus::DEFECT_SHEET_1, ApplicationStatus::ACKNOWLEDGED],
    [ApplicationStatus::DEFECT_SHEET_1, ApplicationStatus::EXAMINATION],
    [ApplicationStatus::DEFECT_SHEET_1, ApplicationStatus::REJECTED],
    [ApplicationStatus::DEFECT_SHEET_2, ApplicationStatus::DEFECT_SHEET_3],
    [ApplicationStatus::DEFECT_SHEET_2, ApplicationStatus::ACKNOWLEDGED],
    [ApplicationStatus::DEFECT_SHEET_2, ApplicationStatus::EXAMINATION],
    [ApplicationStatus::DEFECT_SHEET_2, ApplicationStatus::REJECTED],
    [ApplicationStatus::DEFECT_SHEET_3, ApplicationStatus::ACKNOWLEDGED],
    [ApplicationStatus::DEFECT_SHEET_3, ApplicationStatus::EXAMINATION],
    [ApplicationStatus::DEFECT_SHEET_3, ApplicationStatus::REJECTED],
    [ApplicationStatus::EXAMINATION, ApplicationStatus::GRANTED],
    [ApplicationStatus::EXAMINATION, ApplicationStatus::REJECTED],
    [ApplicationStatus::EXAMINATION, ApplicationStatus::ACKNOWLEDGED],
    [ApplicationStatus::GRANTED, ApplicationStatus::MAINTENANCE],
]);

dataset('illegal transitions', [
    [ApplicationStatus::DRAFT, ApplicationStatus::GRANTED],
    [ApplicationStatus::DRAFT, ApplicationStatus::EXAMINATION],
    [ApplicationStatus::DRAFT, ApplicationStatus::REJECTED],
    [ApplicationStatus::DRAFT, ApplicationStatus::MAINTENANCE],
    [ApplicationStatus::FILED, ApplicationStatus::DRAFT],
    [ApplicationStatus::FILED, ApplicationStatus::GRANTED],
    [ApplicationStatus::PUBLISHED, ApplicationStatus::FILED],
    [ApplicationStatus::PUBLISHED, ApplicationStatus::GRANTED],
    [ApplicationStatus::ACKNOWLEDGED, ApplicationStatus::FILED],
    [ApplicationStatus::DEFECT_SHEET_3, ApplicationStatus::DEFECT_SHEET_1],
    [ApplicationStatus::EXAMINATION, ApplicationStatus::FILED],
    [ApplicationStatus::GRANTED, ApplicationStatus::DRAFT],
    [ApplicationStatus::GRANTED, ApplicationStatus::EXAMINATION],
    [ApplicationStatus::REJECTED, ApplicationStatus::FILED],
    [ApplicationStatus::REJECTED, ApplicationStatus::GRANTED],
    [ApplicationStatus::MAINTENANCE, ApplicationStatus::GRANTED],
    [ApplicationStatus::MAINTENANCE, ApplicationStatus::REJECTED],
]);

/*
|--------------------------------------------------------------------------
| Domain model persistence dataset
|--------------------------------------------------------------------------
| One entry per table: [model class, attributes builder, table name].
| Attribute builders receive the parent Application for FK columns;
| time columns the models auto-fill (CREATED_AT mapping) are omitted.
*/
dataset('domain models', [
    'users' => [User::class, fn () => [
        'email' => 'inventor@pdn.ac.lk',
        'role' => 'user',
        'password_hash' => 'bcrypt-hash',
    ], 'users'],
    'applications' => [Application::class, fn () => [
        'title' => 'A persistence round-trip',
    ], 'applications'],
    'inventors' => [Inventor::class, fn (Application $app) => [
        'application_id' => $app->id,
        'inventor_name' => 'Ada Lovelace',
        'inventor_email' => 'ada@pdn.ac.lk',
    ], 'inventors'],
    'status_history' => [StatusHistory::class, fn (Application $app) => [
        'application_id' => $app->id,
        'new_status' => 'FILED',
        'changed_by' => 'director@pdn.ac.lk',
        'reason' => 'Filing receipt recorded',
    ], 'status_history'],
    'deadlines' => [Deadline::class, fn (Application $app) => [
        'application_id' => $app->id,
        'type' => 'RESPONSE',
        'due_date' => '2026-11-01 00:00:00',
    ], 'deadlines'],
    'office_actions' => [OfficeAction::class, fn (Application $app) => [
        'application_id' => $app->id,
        'kind' => 'REJECTION',
        'body' => 'Claims lack novelty over D1.',
    ], 'office_actions'],
    'responses' => [Response::class, fn (Application $app) => [
        'application_id' => $app->id,
        'office_action_id' => OfficeAction::query()->create([
            'application_id' => $app->id,
            'kind' => 'OBJECTION',
            'body' => 'Parent action',
        ])->id,
        'body' => 'Amended claims attached.',
    ], 'responses'],
    'documents' => [Document::class, fn (Application $app) => [
        'application_id' => $app->id,
        'filename' => 'specification.pdf',
        'content_ref' => 'encrypted-blob-ref',
        'uploaded_by' => 'director@pdn.ac.lk',
    ], 'documents'],
    'filing_records' => [FilingRecord::class, fn (Application $app) => [
        'application_id' => $app->id,
    ], 'filing_records'],
    'defect_sheets' => [DefectSheet::class, fn (Application $app) => [
        'application_id' => $app->id,
        'sheet_number' => 1,
        'description' => 'Claims exceed original disclosure.',
    ], 'defect_sheets'],
    'notifications' => [Notification::class, fn () => [
        'recipient_email' => 'inventor@pdn.ac.lk',
        'subject' => 'Status changed',
        'body' => 'Your application moved to EXAMINATION.',
    ], 'notifications'],
    'audit_logs' => [AuditLog::class, fn (Application $app) => [
        'application_id' => $app->id,
        'action' => 'application.status_changed',
    ], 'audit_logs'],
]);
