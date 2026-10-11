<?php

use Tests\Support\DeadlineWriteDetector;

it('detects writes to deadlines outside the docketing domain', function (string $snippet, bool $isWrite) {
    expect(DeadlineWriteDetector::isWrite($snippet))->toBe($isWrite);
})->with([
    'eloquent create' => ['Deadline::create([...])', true],
    'eloquent upsert' => ['Deadline::upsert($rows, ["id"], ["status"]);', true],
    'new model' => ['return new Deadline(["type" => "RESPONSE"]);', true],
    'relation create' => ['$application->deadlines()->create(["due_date" => "2026-12-01"]);', true],
    'query builder table' => ["DB::table('deadlines')->insert(\$row);", true],
    'model save via variable' => ['$deadline->save();', true],
    'model delete via variable' => ['$deadline->delete();', true],
    'query read' => ['Deadline::query()->where("status", "OPEN")->get();', false],
    'find read' => ['Deadline::find($id);', false],
    'relation definition' => ['return $this->hasMany(Deadline::class);', false],
    'property read' => ['return $deadline->status;', false],
]);
