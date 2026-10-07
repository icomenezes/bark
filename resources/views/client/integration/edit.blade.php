@extends('client.layout')
@section('title', 'Integração')

@php
    $eventLabels = ['envelope.signed' => 'Assinado', 'envelope.cancelled' => 'Cancelado', 'test' => 'Teste'];
@endphp

@section('content')
<div class="max-w-5xl mx-auto space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-white">Integração</h1>
        <p class="text-xs text-gray-500 mt-0.5">Avisos automáticos para o seu sistema sobre os envelopes criados pela API</p>
    </div>

    {{-- URL --}}
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <div>
            <h2 class="text-sm font-semibold text-white">Webhook</h2>
            <p class="text-xs text-gray-500 mt-1">
                Quando um envelope criado pela API for <strong class="text-gray-300">assinado</strong> ou
                <strong class="text-gray-300">cancelado</strong>, enviamos um <code class="text-gray-300">POST</code>
                com os dados do envelope para esta URL. Deixe em branco para desativar.
            </p>
        </div>
        <form method="POST" action="{{ route('integration.update') }}" class="flex flex-col sm:flex-row gap-3">
            @csrf @method('PATCH')
            <div class="flex-1">
                <input type="url" name="webhook_url" maxlength="2048" placeholder="https://seu-sistema.com.br/webhooks/assinador"
                       value="{{ old('webhook_url', $user->webhook_url) }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white
                              focus:outline-none focus:border-blue-500">
                @error('webhook_url')
                    <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-white self-start"
                    style="background-color: var(--color-primary)">
                Salvar
            </button>
        </form>
    </div>

    @if ($user->webhook_secret)
        {{-- Segredo --}}
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
            <div>
                <h2 class="text-sm font-semibold text-white">Segredo de assinatura</h2>
                <p class="text-xs text-gray-500 mt-1">
                    Cada envio leva o cabeçalho <code class="text-gray-300">X-Webhook-Signature</code>, calculado com este
                    segredo. Use-o no seu sistema para confirmar que o aviso veio daqui.
                </p>
            </div>
            <code class="block bg-gray-950 border border-gray-800 rounded px-3 py-2 text-xs text-white break-all select-all">{{ $user->webhook_secret }}</code>
            <form method="POST" action="{{ route('integration.secret') }}"
                  onsubmit="return confirm('Gerar um novo segredo? O atual deixa de valer na hora, e o seu sistema precisa ser atualizado.')">
                @csrf
                <button type="submit" class="text-xs text-gray-400 hover:text-white underline">Gerar novo segredo</button>
            </form>
        </div>
    @endif

    {{-- Teste --}}
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="flex-1">
            <h2 class="text-sm font-semibold text-white">Enviar teste</h2>
            <p class="text-xs text-gray-500 mt-1">
                Faz agora um <code class="text-gray-300">POST</code> assinado com <code class="text-gray-300">"event": "test"</code>
                para conferir se o seu sistema recebe e valida o aviso.
            </p>
        </div>
        <form method="POST" action="{{ route('integration.test') }}">
            @csrf
            <button type="submit" @disabled(! $user->webhook_url)
                    class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-700 text-white hover:bg-gray-600
                           disabled:opacity-40 disabled:cursor-not-allowed">
                Enviar teste
            </button>
        </form>
    </div>

    {{-- Entregas --}}
    <div class="bg-gray-900 border border-gray-800 rounded-lg">
        <div class="px-5 py-4 border-b border-gray-800">
            <h2 class="text-sm font-semibold text-white">Últimas entregas</h2>
            <p class="text-xs text-gray-500 mt-1">
                Uma linha por tentativa. Se o seu sistema não responder com sucesso, tentamos de novo
                em 1 min, 5 min, 15 min, 1 h e 3 h.
            </p>
        </div>

        @if ($deliveries->isEmpty())
            <p class="px-5 py-6 text-sm text-gray-500">Nenhuma entrega ainda.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 text-left">
                        <tr>
                            <th class="px-5 py-2 font-medium">Data</th>
                            <th class="px-5 py-2 font-medium">Evento</th>
                            <th class="px-5 py-2 font-medium">Envelope</th>
                            <th class="px-5 py-2 font-medium">Tentativa</th>
                            <th class="px-5 py-2 font-medium">Resultado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach ($deliveries as $delivery)
                            <tr>
                                <td class="px-5 py-2 text-gray-400 whitespace-nowrap">{{ $delivery->created_at->format('d/m/Y H:i:s') }}</td>
                                <td class="px-5 py-2 text-gray-300">{{ $eventLabels[$delivery->event] ?? $delivery->event }}</td>
                                <td class="px-5 py-2 text-gray-300">
                                    @if ($delivery->envelope)
                                        #{{ $delivery->envelope->id }} — {{ $delivery->envelope->title }}
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-gray-400">{{ $delivery->attempt }}</td>
                                <td class="px-5 py-2">
                                    @if ($delivery->successful())
                                        <span class="text-green-400">HTTP {{ $delivery->response_status }}</span>
                                    @elseif ($delivery->response_status)
                                        <span class="text-red-400">HTTP {{ $delivery->response_status }}</span>
                                    @else
                                        <span class="text-red-400" title="{{ $delivery->error }}">Sem resposta</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
