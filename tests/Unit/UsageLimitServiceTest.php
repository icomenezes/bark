<?php

namespace Tests\Unit;

use App\Models\AccessLog;
use App\Models\Envelope;
use App\Models\Plan;
use App\Models\User;
use App\Services\UsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): UsageLimitService
    {
        return new UsageLimitService;
    }

    public function test_user_without_plan_cannot_sign_pdf(): void
    {
        $user = User::factory()->create(['plan_id' => null]);

        $result = $this->service()->canSignPdf($user);

        $this->assertFalse($result['allowed']);
        $this->assertNotNull($result['reason']);
    }

    public function test_user_without_plan_cannot_create_envelope(): void
    {
        $user = User::factory()->create(['plan_id' => null]);

        $result = $this->service()->canCreateEnvelope($user);

        $this->assertFalse($result['allowed']);
        $this->assertNotNull($result['reason']);
    }

    public function test_user_with_plan_under_limit_can_sign_pdf(): void
    {
        $plan = Plan::factory()->create(['max_pdfs_per_month' => 5]);
        $user = User::factory()->create(['plan_id' => $plan->id]);

        $result = $this->service()->canSignPdf($user);

        $this->assertTrue($result['allowed']);
    }

    public function test_user_at_pdf_limit_is_blocked(): void
    {
        $plan = Plan::factory()->create(['max_pdfs_per_month' => 2]);
        $user = User::factory()->create(['plan_id' => $plan->id]);

        AccessLog::factory()->count(2)->create([
            'user_id' => $user->id,
            'event' => 'document_signed',
            'created_at' => now(),
        ]);

        $result = $this->service()->canSignPdf($user);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('limite', $result['reason']);
    }

    public function test_pdf_count_from_previous_month_does_not_count(): void
    {
        $plan = Plan::factory()->create(['max_pdfs_per_month' => 1]);
        $user = User::factory()->create(['plan_id' => $plan->id]);

        AccessLog::factory()->create([
            'user_id' => $user->id,
            'event' => 'document_signed',
            'created_at' => now()->subMonth(),
        ]);

        $result = $this->service()->canSignPdf($user);

        $this->assertTrue($result['allowed']);
    }

    public function test_user_at_envelope_limit_is_blocked(): void
    {
        $plan = Plan::factory()->create(['max_envelopes_per_month' => 1]);
        $user = User::factory()->create(['plan_id' => $plan->id]);

        Envelope::factory()->for($user)->create(['created_at' => now()]);

        $result = $this->service()->canCreateEnvelope($user);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('limite', $result['reason']);
    }

    public function test_envelope_count_from_previous_month_does_not_count(): void
    {
        $plan = Plan::factory()->create(['max_envelopes_per_month' => 1]);
        $user = User::factory()->create(['plan_id' => $plan->id]);

        Envelope::factory()->for($user)->create(['created_at' => now()->subMonth()]);

        $result = $this->service()->canCreateEnvelope($user);

        $this->assertTrue($result['allowed']);
    }
}
