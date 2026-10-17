<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Unauthorized',
            'email' => 'unauthorized@example.test',
            'password' => 'NotARealAccount123!',
            'password_confirmation' => 'NotARealAccount123!',
        ])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.test']);
    }
}
