<?php

use Laravel\Dusk\Browser;

test('a guest is redirected to the login page', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/admin')
            ->waitForLocation('/login')
            ->assertPathIs('/login')
            ->assertSee('Welcome back');
    });
});

test('an md signs in, sees the dashboard, and signs out', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/login')
            ->type('email', 'md@pdn.ac.lk')
            ->type('password', 'mdpw-123')
            ->press('Sign in')
            ->waitForLocation('/admin')
            // Content is asserted by DashboardSmokeTest and the Pest suite; this
            // smoke proves the session flow itself.
            ->press('Sign out')
            ->waitForLocation('/login')
            ->assertSee('Welcome back');
    });
});
