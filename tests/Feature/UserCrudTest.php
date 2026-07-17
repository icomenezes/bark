<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_client_cannot_access_edit(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->get("/admin/users/{$client->id}/edit")->assertForbidden();
    }

    public function test_edit_renders_with_plan_options(): void
    {
        $plan = Plan::factory()->create(['name' => 'Básico']);
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($this->admin())->get("/admin/users/{$client->id}/edit")
            ->assertOk()
            ->assertSee('Básico');
    }

    public function test_update_assigns_plan_to_client(): void
    {
        $plan = Plan::factory()->create();
        $client = User::factory()->create(['role' => 'client', 'plan_id' => null]);

        $this->actingAs($this->admin())->patch("/admin/users/{$client->id}", [
            'name' => $client->name,
            'email' => $client->email,
            'whatsapp' => $client->whatsapp,
            'plan_id' => $plan->id,
        ])->assertRedirect(route('admin.users.show', $client));

        $this->assertSame($plan->id, $client->fresh()->plan_id);
    }

    public function test_update_can_unassign_plan(): void
    {
        $plan = Plan::factory()->create();
        $client = User::factory()->create(['role' => 'client', 'plan_id' => $plan->id]);

        $this->actingAs($this->admin())->patch("/admin/users/{$client->id}", [
            'name' => $client->name,
            'email' => $client->email,
            'whatsapp' => $client->whatsapp,
            'plan_id' => '',
        ])->assertRedirect(route('admin.users.show', $client));

        $this->assertNull($client->fresh()->plan_id);
    }

    public function test_update_validates_unique_email(): void
    {
        $other = User::factory()->create(['email' => 'existing@example.com']);
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($this->admin())->patch("/admin/users/{$client->id}", [
            'name' => $client->name,
            'email' => 'existing@example.com',
        ])->assertSessionHasErrors('email');
    }

    public function test_generate_api_token_creates_token_and_shows_it_once(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $response = $this->actingAs($this->admin())->post("/admin/users/{$client->id}/api-token");

        $response->assertRedirect(route('admin.users.edit', $client));
        $response->assertSessionHas('api_token');
        $this->assertSame(1, $client->fresh()->tokens()->count());
    }

    public function test_generate_api_token_revokes_previous_token(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $client->createToken('api');

        $this->actingAs($this->admin())->post("/admin/users/{$client->id}/api-token");

        $this->assertSame(1, $client->fresh()->tokens()->count());
    }

    public function test_revoke_api_token_removes_it(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $client->createToken('api');

        $response = $this->actingAs($this->admin())->delete("/admin/users/{$client->id}/api-token");

        $response->assertRedirect(route('admin.users.edit', $client));
        $this->assertSame(0, $client->fresh()->tokens()->count());
    }

    public function test_client_cannot_generate_api_token(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->post("/admin/users/{$client->id}/api-token")->assertForbidden();
    }

    public function test_edit_shows_active_token_indicator(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $client->createToken('api');

        $this->actingAs($this->admin())->get("/admin/users/{$client->id}/edit")
            ->assertOk()
            ->assertSee('Token ativo');
    }

    public function test_store_sets_envelope_channel_preferences(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/users', [
            'name' => 'Cliente Novo',
            'email' => 'novo@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
            'whatsapp_envelope_enabled' => '1',
            'default_envelope_channel' => 'whatsapp',
        ]);

        $response->assertRedirect();
        $user = User::where('email', 'novo@example.com')->first();
        $this->assertTrue($user->whatsapp_envelope_enabled);
        $this->assertSame('whatsapp', $user->default_envelope_channel);
    }

    public function test_store_forces_email_channel_when_whatsapp_not_enabled(): void
    {
        $this->actingAs($this->admin())->post('/admin/users', [
            'name' => 'Cliente Novo',
            'email' => 'novo2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
            'default_envelope_channel' => 'whatsapp', // deve ser ignorado — checkbox não enviado
        ]);

        $user = User::where('email', 'novo2@example.com')->first();
        $this->assertFalse($user->whatsapp_envelope_enabled);
        $this->assertSame('email', $user->default_envelope_channel);
    }

    public function test_update_toggles_envelope_channel_preferences(): void
    {
        $client = User::factory()->create(['role' => 'client', 'whatsapp_envelope_enabled' => false, 'default_envelope_channel' => 'email']);

        $this->actingAs($this->admin())->patch("/admin/users/{$client->id}", [
            'name' => $client->name,
            'email' => $client->email,
            'whatsapp_envelope_enabled' => '1',
            'default_envelope_channel' => 'whatsapp',
        ]);

        $client->refresh();
        $this->assertTrue($client->whatsapp_envelope_enabled);
        $this->assertSame('whatsapp', $client->default_envelope_channel);
    }
}
