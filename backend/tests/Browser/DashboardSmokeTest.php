<?php

use App\Models\Application;
use Laravel\Dusk\Browser;

test('staff can change a status from the patent page', function () {
    // A fixture of its own, so the smoke does not depend on the seeded state.
    $application = Application::query()->create([
        'title' => 'Dusk Status Fixture',
        'status' => 'DRAFT',
        'inventor_email' => 'user-a@pdn.ac.lk',
    ]);

    $this->browse(function (Browser $browser) use ($application) {
        $browser->visit('/login')
            ->type('email', 'md@pdn.ac.lk')
            ->type('password', 'mdpw-123')
            ->press('Sign in')
            ->waitForLocation('/admin')
            ->visit('/admin/patents/'.$application->id)
            ->assertSee('Dusk Status Fixture')
            ->select('new_status', 'FILED')
            ->type('reason', 'Filed during the smoke suite')
            ->press('Change status')
            ->waitForText('Status updated to FILED.')
            ->assertSee('Filed during the smoke suite')
            ->assertSee('FILED')
            ->press('Sign out')
            ->waitForLocation('/login');
    });

    expect($application->fresh()->status)->toBe('FILED');
});

test('staff nav reaches the patents table', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/login')
            ->type('email', 'md@pdn.ac.lk')
            ->type('password', 'mdpw-123')
            ->press('Sign in')
            ->waitForLocation('/admin')
            ->clickLink('Patents')
            ->waitForLocation('/admin/patents')
            ->assertSee('New patent')
            ->assertSee('Solar Desalination Membrane')
            ->press('Sign out')
            ->waitForLocation('/login');
    });
});
