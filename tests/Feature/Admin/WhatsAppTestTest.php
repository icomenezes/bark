<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTestTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_guest_cannot_access(): void
    {
        $response = $this->post(route('admin.settings.whatsapp-test'), [
            'phone' => '11999998888',
            'message' => 'teste',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_client_cannot_access(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $response = $this->actingAs($client)->post(route('admin.settings.whatsapp-test'), [
            'phone' => '11999998888',
            'message' => 'teste',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_sees_success_flash_on_successful_send(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.example.com',
            'services.evolution.instance' => 'test-instance',
            'services.evolution.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $response = $this->actingAs($this->admin())->post(route('admin.settings.whatsapp-test'), [
            'phone' => '11999998888',
            'message' => 'Mensagem de teste',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_admin_sees_error_flash_on_failed_send(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.example.com',
            'services.evolution.instance' => 'test-instance',
            'services.evolution.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['message' => 'bad request'], 400)]);

        $response = $this->actingAs($this->admin())->post(route('admin.settings.whatsapp-test'), [
            'phone' => '11999998888',
            'message' => 'Mensagem de teste',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('whatsappTestError');
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.settings.whatsapp-test'), []);

        $response->assertSessionHasErrors(['phone', 'message']);
    }
}
