<?php

namespace Tests\Feature;

use App\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireEnvelopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_only_overdue_sent_envelopes(): void
    {
        $overdue = Envelope::factory()->create(['status' => 'sent', 'expires_at' => now()->subDay()]);
        $future = Envelope::factory()->create(['status' => 'sent', 'expires_at' => now()->addDay()]);
        $completed = Envelope::factory()->create(['status' => 'completed', 'expires_at' => now()->subDay()]);

        $this->artisan('envelopes:expire')->assertSuccessful();

        $this->assertSame('expired', $overdue->fresh()->status);
        $this->assertSame('sent', $future->fresh()->status);
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertTrue($overdue->events()->where('event', 'expired')->exists());
    }
}
