<?php

use App\Models\Application;

it('returns 422 when an illegal status transition is requested over HTTP', function () {
    $application = Application::query()->create(['title' => 'Illegal jump']);

    $this->postJson("/applications/{$application->id}/status", [
        'new_status' => 'GRANTED',
        'changed_by' => 'md@pdn.ac.lk',
    ])->assertStatus(422);
});
