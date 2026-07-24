<?php

namespace Tests\Feature;

use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignerDirectoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_the_users_own_signers_and_groups(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Meu Contato']);
        SavedSigner::factory()->create(['user_id' => $other->id, 'name' => 'Contato Alheio']);

        $this->actingAs($user)->get('/signatarios')
            ->assertOk()->assertSee('Meu Contato')->assertDontSee('Contato Alheio');
    }

    public function test_store_creates_a_signer(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->actingAs($user)->post('/signatarios', [
            'name' => 'Ana', 'channel' => 'email', 'email' => 'ana@x.com', 'auth_method' => 'link',
        ])->assertRedirect();

        $this->assertDatabaseHas('saved_signers', ['user_id' => $user->id, 'name' => 'Ana']);
    }

    public function test_cannot_update_another_users_signer(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)->patch("/signatarios/{$signer->id}", [
            'name' => 'Hackeado', 'channel' => 'email', 'email' => 'x@x.com', 'auth_method' => 'link',
        ])->assertForbidden();
    }

    public function test_cannot_delete_another_users_group(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $group = SignerGroup::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)->delete("/signatarios/grupos/{$group->id}")->assertForbidden();
        $this->assertDatabaseHas('signer_groups', ['id' => $group->id]);
    }

    public function test_search_returns_json_of_matching_signers(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Carlos Souza']);

        $response = $this->actingAs($user)->get('/signatarios/buscar?q=carlos');

        $response->assertOk()->assertJsonFragment(['name' => 'Carlos Souza']);
    }

    public function test_store_group_with_members(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/signatarios/grupos', [
            'name' => 'Diretoria', 'members' => [$signer->id],
        ])->assertRedirect();

        $group = SignerGroup::where('name', 'Diretoria')->first();
        $this->assertNotNull($group);
        $this->assertCount(1, $group->members);
    }

    public function test_edit_signer_shows_owned_signer(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Bruna Lima']);

        $this->actingAs($user)->get("/signatarios/{$signer->id}/editar")
            ->assertOk()->assertSee('Bruna Lima');
    }

    public function test_cannot_edit_another_users_signer(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)->get("/signatarios/{$signer->id}/editar")->assertForbidden();
    }

    public function test_edit_group_shows_owned_group_with_members_selected(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Carlos Souza']);
        $group = SignerGroup::factory()->create(['user_id' => $user->id, 'name' => 'Diretoria']);
        $group->members()->sync([$signer->id]);

        $this->actingAs($user)->get("/signatarios/grupos/{$group->id}/editar")
            ->assertOk()->assertSee('Diretoria')->assertSee('Carlos Souza');
    }

    public function test_cannot_edit_another_users_group(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $group = SignerGroup::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)->get("/signatarios/grupos/{$group->id}/editar")->assertForbidden();
    }
}
