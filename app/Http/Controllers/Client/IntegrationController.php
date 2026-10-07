<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\WebhookUrl;
use App\Services\Webhook\WebhookSender;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Webhook dos envelopes criados pela API: URL, segredo, envio de teste e últimas entregas. */
class IntegrationController extends Controller
{
    public function __construct(private WebhookSender $sender) {}

    public function edit(Request $request)
    {
        $user = $this->apiUser($request);

        return view('client.integration.edit', [
            'user' => $user,
            'deliveries' => $user->webhookDeliveries()->with('envelope:id,title')->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $this->apiUser($request);
        $data = $request->validate(['webhook_url' => ['nullable', 'string', new WebhookUrl]]);

        $user->webhook_url = $data['webhook_url'] ?? null;
        if ($user->webhook_url && ! $user->webhook_secret) {
            $user->webhook_secret = $this->newSecret();
        }
        $user->save();

        return back()->with('success', $user->webhook_url ? 'URL do webhook salva.' : 'Webhook desativado.');
    }

    public function regenerateSecret(Request $request)
    {
        $user = $this->apiUser($request);
        $user->forceFill(['webhook_secret' => $this->newSecret()])->save();

        return back()->with('success', 'Novo segredo gerado. Atualize-o no seu sistema.');
    }

    public function test(Request $request)
    {
        $user = $this->apiUser($request);
        if (! $user->webhook_url) {
            return back()->with('error', 'Cadastre a URL antes de enviar um teste.');
        }

        $eventId = (string) Str::uuid();
        $delivery = $this->sender->send($user, $eventId, 'test', [
            'id' => $eventId,
            'event' => 'test',
            'occurred_at' => now()->toIso8601String(),
            'envelope' => null,
        ], null, attempt: 1);

        if ($delivery->successful()) {
            return back()->with('success', "Teste entregue (HTTP {$delivery->response_status}).");
        }

        $reason = $delivery->response_status ? "HTTP {$delivery->response_status}" : $delivery->error;

        return back()->with('error', "O teste falhou: {$reason}");
    }

    /** O webhook só vale para envelopes da API: sem token, a tela não existe. */
    private function apiUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->tokens()->exists(), 404);

        return $user;
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
