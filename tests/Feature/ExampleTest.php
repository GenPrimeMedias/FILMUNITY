<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guest_home_redirects_to_filmunity_login(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }
}
