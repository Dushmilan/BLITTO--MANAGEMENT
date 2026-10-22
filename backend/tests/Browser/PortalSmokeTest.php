<?php

use Laravel\Dusk\Browser;

test('an inventor sees their notifications and patents only', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/login')
            ->type('email', 'user-a@pdn.ac.lk')
            ->type('password', 'userapw-123')
            ->press('Sign in')
            ->waitForLocation('/user')
            ->clickLink('Notifications')
            ->waitForLocation('/user/notifications')
            ->assertSee('GRANTED')
            ->assertDontSee('Quantum Encryption Protocol');

        $browser->clickLink('My Patents')
            ->waitForLocation('/user/patents')
            ->assertSee('Solar Desalination Membrane')
            ->assertSee('AI Crop Disease Detection')
            ->assertDontSee('Quantum Encryption Protocol')
            ->press('Sign out')
            ->waitForLocation('/login');
    });
});
