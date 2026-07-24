<?php

namespace Tests\Feature;

use App\Models\SavedSigner;
use App\Models\SignerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedSignerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_signer_can_belong_to_multiple_groups(): void
    {
        $signer = SavedSigner::factory()->create();
        $groupA = SignerGroup::factory()->create(['user_id' => $signer->user_id]);
        $groupB = SignerGroup::factory()->create(['user_id' => $signer->user_id]);

        $signer->groups()->attach([$groupA->id, $groupB->id]);

        $this->assertCount(2, $signer->fresh()->groups);
    }

    public function test_a_group_can_have_multiple_signers(): void
    {
        $group = SignerGroup::factory()->create();
        $signerA = SavedSigner::factory()->create(['user_id' => $group->user_id]);
        $signerB = SavedSigner::factory()->create(['user_id' => $group->user_id]);

        $group->members()->attach([$signerA->id, $signerB->id]);

        $this->assertCount(2, $group->fresh()->members);
    }
}
