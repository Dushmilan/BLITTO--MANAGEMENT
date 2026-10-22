<?php

use Laravel\Dusk\Browser;

test('dark mode persists across a reload', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/login')
            ->type('email', 'md@pdn.ac.lk')
            ->type('password', 'mdpw-123')
            ->press('Sign in')
            ->waitForLocation('/admin')
            ->press('Dark mode')
            ->waitForText('Light mode')
            ->assertScript("document.documentElement.classList.contains('dark')");

        $browser->refresh()->assertScript("document.documentElement.classList.contains('dark')");

        $browser->press('Light mode')
            ->waitForText('Dark mode')
            ->assertScript("! document.documentElement.classList.contains('dark')")
            ->press('Sign out')
            ->waitForLocation('/login');
    });
});
