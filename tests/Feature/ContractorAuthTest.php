<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ContractorAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.contractor.username' => 'testcontractor',
            'services.contractor.password_hash' => Hash::make('testpass123'),
        ]);
    }

    public function test_login_page_is_accessible(): void
    {
        $response = $this->get('/contractor/login');
        $response->assertStatus(200);
        $response->assertSee('Contractor Panel');
    }

    public function test_protected_route_redirects_to_login(): void
    {
        $response = $this->get('/contractor/rozetka');
        $response->assertRedirect('/contractor/login');
    }

    public function test_login_with_valid_credentials(): void
    {
        $response = $this->post('/contractor/login', [
            'username' => 'testcontractor',
            'password' => 'testpass123',
        ]);

        $response->assertRedirect(route('contractor.rozetka.index'));
        $this->assertTrue(session('contractor_authenticated'));
    }

    public function test_login_with_invalid_credentials(): void
    {
        $response = $this->post('/contractor/login', [
            'username' => 'testcontractor',
            'password' => 'wrongpass',
        ]);

        $response->assertSessionHasErrors('credentials');
        $this->assertNull(session('contractor_authenticated'));
    }

    public function test_logout_clears_session(): void
    {
        $this->withSession(['contractor_authenticated' => true, 'contractor_username' => 'testcontractor']);

        $response = $this->post('/contractor/logout');
        $response->assertRedirect(route('contractor.login'));
        $this->assertNull(session('contractor_authenticated'));
    }

    public function test_authenticated_user_can_access_rozetka(): void
    {
        $this->withSession(['contractor_authenticated' => true]);

        $response = $this->get('/contractor/rozetka');
        $response->assertStatus(200);
    }

    public function test_login_redirects_if_already_authenticated(): void
    {
        $this->withSession(['contractor_authenticated' => true]);

        $response = $this->get('/contractor/login');
        $response->assertRedirect(route('contractor.rozetka.index'));
    }
}
