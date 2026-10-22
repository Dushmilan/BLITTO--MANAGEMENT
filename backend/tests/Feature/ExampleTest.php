<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` became a role-aware redirect in L6; the old 200 view is gone.
     */
    public function test_the_root_redirects_a_guest_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
