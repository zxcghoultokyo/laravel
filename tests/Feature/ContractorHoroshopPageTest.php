<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractorHoroshopPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_horoshop_page_requires_auth(): void
    {
        $response = $this->get('/contractor/horoshop');

        $response->assertRedirect('/contractor/login');
    }

    public function test_horoshop_page_loads_for_authenticated_user(): void
    {
        $this->withSession(['contractor_authenticated' => true]);

        $response = $this->get('/contractor/horoshop');

        $response->assertStatus(200);
        $response->assertSee('Синхронізувати Хорошоп');
    }

    public function test_rozetka_page_still_loads(): void
    {
        $this->withSession(['contractor_authenticated' => true]);

        $response = $this->get('/contractor/rozetka');

        $response->assertStatus(200);
        $response->assertSee('Синхронізувати Розетку');
    }

    public function test_nav_has_both_links(): void
    {
        $this->withSession(['contractor_authenticated' => true]);

        $response = $this->get('/contractor/horoshop');

        $response->assertSee('Розетка');
        $response->assertSee('Хорошоп');
    }
}
