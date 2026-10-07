<?php

namespace Tests\Feature\Webhook;

use App\Models\Envelope;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Webhook\WebhookSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesWebhookDns;
use Tests\TestCase;

class WebhookSenderTest extends TestCase
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

    public function test_pins_the_connection_to_the_checked_ip(): void
    {
        $options = null;
        Http::fake(function ($request, $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response('', 200);
        });

        app(WebhookSender::class)->send($this->userWithWebhook(), 'evt-1', 'envelope.signed', $this->body(), null, attempt: 1);

        // Sem a trava, o curl resolveria o DNS de novo e poderia cair em outro IP.
        $this->assertSame(['ponto.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE] ?? null);
    }

    public function test_refuses_to_send_when_the_domain_now_resolves_to_an_internal_address(): void
    {
        // Cadastrada com IP público, a URL passou a apontar para a rede interna depois.
        Http::fake();
        $user = $this->userWithWebhook();
        $this->fakeWebhookDns(['ponto.example.com' => ['10.0.0.5']]);

        $delivery = app(WebhookSender::class)->send($user, 'evt-1', 'envelope.signed', $this->body(), null, attempt: 1);

        Http::assertNothingSent();
        $this->assertFalse($delivery->successful());
        $this->assertNull($delivery->response_status);
        $this->assertNotNull($delivery->error);
    }

    private function userWithWebhook(): User
    {
        $user = User::factory()->create(['webhook_url' => self::URL]);
        $user->forceFill(['webhook_secret' => 'whsec_segredo'])->save();

        return $user;
    }

    private function body(): array
    {
        return [
            'id' => 'evt-1',
            'event' => 'envelope.signed',
            'occurred_at' => '2026-10-07T14:32:10-03:00',
            'envelope' => ['id' => 42, 'status' => 'signed'],
        ];
    }

    public function test_posts_signed_json_and_records_the_delivery(): void
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $user = $this->userWithWebhook();
        $envelope = Envelope::factory()->for($user)->create();

        $delivery = app(WebhookSender::class)->send($user, 'evt-1', 'envelope.signed', $this->body(), $envelope, attempt: 2);

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-Webhook-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_segredo');

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && $request->header('Content-Type')[0] === 'application/json'
                && $request->header('X-Webhook-Event')[0] === 'envelope.signed'
                && $request->header('X-Webhook-Id')[0] === 'evt-1'
                && abs(time() - (int) $timestamp) < 5
                && hash_equals($expected, $request->header('X-Webhook-Signature')[0])
                && json_decode($request->body(), true) === $this->body();
        });

        $this->assertTrue($delivery->successful());
        $this->assertDatabaseHas('webhook_deliveries', [
            'user_id' => $user->id,
            'envelope_id' => $envelope->id,
            'event_id' => 'evt-1',
            'event' => 'envelope.signed',
            'url' => self::URL,
            'attempt' => 2,
            'response_status' => 200,
            'error' => null,
        ]);
    }

    public function test_non_2xx_is_recorded_as_failure_without_the_response_body(): void
    {
        Http::fake([self::URL => Http::response('stack trace interno do receptor', 500)]);
        $user = $this->userWithWebhook();

        $delivery = app(WebhookSender::class)->send($user, 'evt-1', 'envelope.signed', $this->body(), null, attempt: 1);

        $this->assertFalse($delivery->successful());
        $this->assertSame(500, $delivery->response_status);
        $this->assertNull($delivery->envelope_id);
        $this->assertStringNotContainsString('stack trace', (string) $delivery->error);
    }

    public function test_connection_failure_is_recorded_as_failure(): void
    {
        Http::fake([self::URL => Http::failedConnection('cURL error 28: Operation timed out')]);
        $user = $this->userWithWebhook();

        $delivery = app(WebhookSender::class)->send($user, 'evt-1', 'envelope.signed', $this->body(), null, attempt: 1);

        $this->assertFalse($delivery->successful());
        $this->assertNull($delivery->response_status);
        // Só a categoria: a mensagem crua do curl (que pode revelar a rede) vai para o log, não para a tela.
        $this->assertSame('Tempo esgotado', $delivery->error);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake([
            self::URL => Http::response('', 302, ['Location' => 'https://outro.example.com/']),
            'https://outro.example.com/*' => Http::response('ok', 200),
        ]);
        $user = $this->userWithWebhook();

        $delivery = app(WebhookSender::class)->send($user, 'evt-1', 'envelope.signed', $this->body(), null, attempt: 1);

        $this->assertFalse($delivery->successful());
        $this->assertSame(302, $delivery->response_status);
        Http::assertSentCount(1);
    }

    public function test_delivery_rows_are_prunable_after_90_days(): void
    {
        $user = $this->userWithWebhook();
        $old = WebhookDelivery::create([
            'user_id' => $user->id, 'event_id' => 'a', 'event' => 'test', 'url' => self::URL, 'attempt' => 1,
        ]);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        $recent = WebhookDelivery::create([
            'user_id' => $user->id, 'event_id' => 'b', 'event' => 'test', 'url' => self::URL, 'attempt' => 1,
        ]);

        $prunable = (new WebhookDelivery)->prunable()->pluck('id')->all();

        $this->assertSame([$old->id], $prunable);
        $this->assertNotContains($recent->id, $prunable);
    }

    public function test_pruning_of_deliveries_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'model:prune')
                && str_contains($event->command, 'WebhookDelivery'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }
}
