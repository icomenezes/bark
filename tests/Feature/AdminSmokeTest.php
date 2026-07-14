<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_admin_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['/admin', '/admin/users', '/admin/users/create', '/admin/access-logs', '/admin/settings'] as $url) {
            $this->actingAs($admin)->get($url)->assertStatus(200);
        }
    }

    public function test_client_cannot_access_admin(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->get('/admin')->assertStatus(403);
    }

    public function test_client_dashboard_renders(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->get('/dashboard')->assertStatus(200);
    }

    public function test_admin_is_redirected_from_client_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    }

    public function test_public_register_creates_client(): void
    {
        $response = $this->postJson('/api/register', [
            'name'     => 'Lead Teste',
            'email'    => 'lead@example.com',
            'password' => 'senha12345',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'lead@example.com', 'role' => 'client']);
    }

    public function test_heartbeat_updates_active_session(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)
            ->post('/heartbeat')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
