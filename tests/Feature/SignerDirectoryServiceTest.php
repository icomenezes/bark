<?php

namespace Tests\Feature;

use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Models\User;
use App\Services\SignerDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SignerDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SignerDirectoryService
    {
        return app(SignerDirectoryService::class);
    }

    public function test_creates_signer_with_email_channel(): void
    {
        $user = User::factory()->create();

        $signer = $this->service()->createSigner($user, [
            'name' => 'Ana', 'channel' => 'email', 'email' => 'ana@x.com', 'auth_method' => 'link',
        ]);

        $this->assertSame($user->id, $signer->user_id);
        $this->assertDatabaseCount('saved_signers', 1);
    }

    public function test_rejects_whatsapp_channel_with_email_otp(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->createSigner($user, [
            'name' => 'Ana', 'channel' => 'whatsapp', 'whatsapp' => '11999998888', 'auth_method' => 'email_otp',
        ]);
    }

    public function test_enforces_max_100_signers_per_user(): void
    {
        $user = User::factory()->create();
        SavedSigner::factory()->count(100)->create(['user_id' => $user->id]);

        $this->expectException(ValidationException::class);

        $this->service()->createSigner($user, [
            'name' => 'Extra', 'channel' => 'email', 'email' => 'extra@x.com', 'auth_method' => 'link',
        ]);
    }

    public function test_enforces_max_20_groups_per_user(): void
    {
        $user = User::factory()->create();
        SignerGroup::factory()->count(20)->create(['user_id' => $user->id]);

        $this->expectException(ValidationException::class);

        $this->service()->createGroup($user, 'Grupo extra');
    }

    public function test_update_group_members_replaces_the_set(): void
    {
        $user = User::factory()->create();
        $group = SignerGroup::factory()->create(['user_id' => $user->id]);
        $a = SavedSigner::factory()->create(['user_id' => $user->id]);
        $b = SavedSigner::factory()->create(['user_id' => $user->id]);
        $group->members()->attach($a->id);

        $this->service()->updateGroup($group, 'Renomeado', [$b->id]);

        $this->assertSame('Renomeado', $group->fresh()->name);
        $this->assertEquals([$b->id], $group->fresh()->members->pluck('id')->all());
    }

    public function test_delete_group_does_not_delete_its_signers(): void
    {
        $user = User::factory()->create();
        $group = SignerGroup::factory()->create(['user_id' => $user->id]);
        $signer = SavedSigner::factory()->create(['user_id' => $user->id]);
        $group->members()->attach($signer->id);

        $this->service()->deleteGroup($group);

        $this->assertDatabaseMissing('signer_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('saved_signers', ['id' => $signer->id]);
    }

    public function test_search_only_returns_the_users_own_signers(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Maria Ana']);
        SavedSigner::factory()->create(['user_id' => $other->id, 'name' => 'Ana de Outro']);

        $results = $this->service()->search($user, 'ana');

        $this->assertCount(1, $results);
        $this->assertSame('Maria Ana', $results->first()->name);
    }
}
