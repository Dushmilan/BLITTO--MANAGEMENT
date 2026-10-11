<?php

use Illuminate\Support\Facades\Schema;

$tables = [
    'users',
    'applications',
    'inventors',
    'status_history',
    'deadlines',
    'office_actions',
    'responses',
    'documents',
    'filing_records',
    'defect_sheets',
    'notifications',
    'audit_logs',
];

it('provides all 12 domain tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with($tables);
