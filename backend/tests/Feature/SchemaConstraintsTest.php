<?php

use Illuminate\Support\Facades\Schema;

it('constrains every foreign key column', function (string $table, string $column) {
    $fks = Schema::getForeignKeys($table);

    $columns = collect($fks)->pluck('columns')->flatten()->all();

    expect($columns)->toContain($column);
})->with([
    ['inventors', 'application_id'],
    ['status_history', 'application_id'],
    ['deadlines', 'application_id'],
    ['office_actions', 'application_id'],
    ['responses', 'application_id'],
    ['responses', 'office_action_id'],
    ['documents', 'application_id'],
    ['filing_records', 'application_id'],
    ['defect_sheets', 'application_id'],
    ['audit_logs', 'application_id'],
]);

it('indexes the foreign key columns', function (string $table, string $column) {
    $indexed = collect(Schema::getIndexes($table))
        ->pluck('columns')
        ->flatten()
        ->all();

    expect($indexed)->toContain($column);
})->with([
    ['inventors', 'application_id'],
    ['status_history', 'application_id'],
    ['deadlines', 'application_id'],
    ['office_actions', 'application_id'],
    ['responses', 'application_id'],
    ['responses', 'office_action_id'],
    ['documents', 'application_id'],
    ['audit_logs', 'application_id'],
]);

it('enforces the 1:1 filing record and defect-sheet caps in the schema', function () {
    expect(collect(Schema::getIndexes('filing_records'))->firstWhere('unique', true)['columns'])
        ->toBe(['application_id']);

    $defectUnique = collect(Schema::getIndexes('defect_sheets'))
        ->firstWhere('unique', true);

    expect($defectUnique['columns'])->toBe(['application_id', 'sheet_number']);
});
