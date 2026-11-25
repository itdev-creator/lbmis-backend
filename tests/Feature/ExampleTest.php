<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_sends_visitors_to_the_dashboard(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect('/dashboard');
    }

    public function test_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
