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
}
