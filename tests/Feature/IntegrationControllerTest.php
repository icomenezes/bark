<?php

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesWebhookDns;
use Tests\TestCase;

class IntegrationControllerTest extends TestCase
{
    use FakesWebhookDns, RefreshDatabase;

    private const URL = 'https://ponto.example.com/webhooks/assinador';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWebhookDns([
            'ponto.example.com' => ['93.184.216.34'],
            'outro.example.com' => ['93.184.216.35'],
        ]);
    }

    public function test_rejects_a_domain_that_resolves_to_an_internal_address(): void
    {
        $this->fakeWebhookDns(['interno.example.com' => ['192.168.0.10']]);
        $user = $this->apiClient();

        $this->actingAs($user)->patch('/integration', ['webhook_url' => 'https://interno.example.com/hook'])
            ->assertSessionHasErrors('webhook_url');

        $this->assertNull($user->fresh()->webhook_url);
    }

    private function apiClient(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'client'], $attributes));
        $user->createToken('api');

        return $user;
    }

    public function test_requires_login(): void
    {
        $this->get('/integration')->assertRedirect(route('login'));
    }

    public function test_accounts_without_api_token_get_404_and_no_menu_item(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->actingAs($user)->get('/integration')->assertNotFound();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee(route('integration.edit'));
    }

    public function test_accounts_with_api_token_see_the_page_and_the_menu_item(): void
    {
        $user = $this->apiClient();

        $this->actingAs($user)->get('/dashboard')->assertSee(route('integration.edit'));
        $this->actingAs($user)->get('/integration')->assertOk()->assertSee('Webhook');
    }

    public function test_saving_the_url_generates_a_secret_once(): void
    {
        $user = $this->apiClient();

        $this->actingAs($user)->patch('/integration', ['webhook_url' => self::URL])
            ->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(self::URL, $user->webhook_url);
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{40}$/', $user->webhook_secret);

        $secret = $user->webhook_secret;
        $this->actingAs($user)->patch('/integration', ['webhook_url' => 'https://outro.example.com/hook']);
        $this->assertSame($secret, $user->fresh()->webhook_secret);

        $this->actingAs($user)->get('/integration')->assertSee($secret);
    }

    public function test_saving_an_empty_url_disables_the_webhook(): void
    {
        $user = $this->apiClient(['webhook_url' => self::URL]);

        $this->actingAs($user)->patch('/integration', ['webhook_url' => ''])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->webhook_url);
    }

    public function test_rejects_an_invalid_url(): void
    {
        $user = $this->apiClient();

        $this->actingAs($user)->patch('/integration', ['webhook_url' => 'http://ponto.example.com/hook'])
            ->assertSessionHasErrors('webhook_url');

        $this->assertNull($user->fresh()->webhook_url);
    }

    public function test_regenerating_the_secret_replaces_it(): void
    {
        $user = $this->apiClient(['webhook_url' => self::URL]);
        $user->forceFill(['webhook_secret' => 'whsec_antigo'])->save();

        $this->actingAs($user)->post('/integration/secret')->assertRedirect();

        $this->assertNotSame('whsec_antigo', $user->fresh()->webhook_secret);
        $this->assertStringStartsWith('whsec_', $user->fresh()->webhook_secret);
    }

    public function test_send_test_posts_a_signed_test_event(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $user = $this->apiClient(['webhook_url' => self::URL]);
        $user->forceFill(['webhook_secret' => 'whsec_segredo'])->save();

        $this->actingAs($user)->post('/integration/test')
            ->assertRedirect()->assertSessionHas('success');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $timestamp = $request->header('X-Webhook-Timestamp')[0];

            return $body['event'] === 'test'
                && $body['envelope'] === null
                && $request->header('X-Webhook-Signature')[0]
                    === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_segredo');
        });
        $this->assertDatabaseHas('webhook_deliveries', [
            'user_id' => $user->id, 'event' => 'test', 'envelope_id' => null, 'response_status' => 204,
        ]);
    }

    public function test_send_test_reports_a_failure(): void
    {
        Http::fake([self::URL => Http::response('', 500)]);
        $user = $this->apiClient(['webhook_url' => self::URL]);

        $this->actingAs($user)->post('/integration/test')->assertSessionHas('error');
    }

    public function test_send_test_without_url_sends_nothing(): void
    {
        Http::fake();
        $user = $this->apiClient();

        $this->actingAs($user)->post('/integration/test')->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_lists_only_the_accounts_own_deliveries(): void
    {
        $user = $this->apiClient(['webhook_url' => self::URL]);
        $envelope = Envelope::factory()->for($user)->create(['title' => 'Folha de ponto setembro']);
        WebhookDelivery::create([
            'user_id' => $user->id, 'envelope_id' => $envelope->id, 'event_id' => 'a', 'event' => 'envelope.signed',
            'url' => self::URL, 'attempt' => 1, 'response_status' => 200,
        ]);
        $other = $this->apiClient();
        $otherEnvelope = Envelope::factory()->for($other)->create(['title' => 'Envelope de outra conta']);
        WebhookDelivery::create([
            'user_id' => $other->id, 'envelope_id' => $otherEnvelope->id, 'event_id' => 'b', 'event' => 'envelope.signed',
            'url' => self::URL, 'attempt' => 1, 'response_status' => 200,
        ]);

        $this->actingAs($user)->get('/integration')
            ->assertSee('Folha de ponto setembro')
            ->assertDontSee('Envelope de outra conta');
    }
}
