<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_client_cannot_access_plans(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->get('/admin/plans')->assertForbidden();
    }

    public function test_index_lists_plans(): void
    {
        Plan::factory()->create(['name' => 'Básico']);

        $this->actingAs($this->admin())->get('/admin/plans')
            ->assertOk()
            ->assertSee('Básico');
    }

    public function test_store_creates_plan(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/plans', [
            'name' => 'Premium',
            'max_pdfs_per_month' => 100,
            'max_envelopes_per_month' => 30,
        ]);

        $response->assertRedirect(route('admin.plans.index'));
        $this->assertDatabaseHas('plans', [
            'name' => 'Premium',
            'max_pdfs_per_month' => 100,
            'max_envelopes_per_month' => 30,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())->post('/admin/plans', [])
            ->assertSessionHasErrors(['name', 'max_pdfs_per_month', 'max_envelopes_per_month']);
    }

    public function test_update_changes_plan_limits(): void
    {
        $plan = Plan::factory()->create(['max_pdfs_per_month' => 10]);

        $this->actingAs($this->admin())->patch("/admin/plans/{$plan->id}", [
            'name' => $plan->name,
            'max_pdfs_per_month' => 50,
            'max_envelopes_per_month' => 20,
        ])->assertRedirect(route('admin.plans.index'));

        $this->assertSame(50, $plan->fresh()->max_pdfs_per_month);
    }

    public function test_destroy_removes_plan_and_unassigns_users(): void
    {
        $plan = Plan::factory()->create();
        $user = User::factory()->create(['role' => 'client', 'plan_id' => $plan->id]);

        $this->actingAs($this->admin())->delete("/admin/plans/{$plan->id}")
            ->assertRedirect(route('admin.plans.index'));

        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
        $this->assertNull($user->fresh()->plan_id);
    }
}
