@extends('client.layout')
@section('title', $envelope->title)

@php
$statusLabels = ['draft' => 'Rascunho', 'sent' => 'Aguardando assinaturas', 'completed' => 'Concluído',
                 'declined' => 'Recusado', 'cancelled' => 'Cancelado', 'expired' => 'Expirado'];
$statusColors = ['draft' => 'bg-gray-100 text-gray-700', 'sent' => 'bg-blue-100 text-blue-700',
                 'completed' => 'bg-green-100 text-green-700', 'declined' => 'bg-red-100 text-red-700',
                 'cancelled' => 'bg-gray-200 text-gray-600', 'expired' => 'bg-yellow-100 text-yellow-700'];
$signerLabels = ['pending' => 'Pendente', 'notified' => 'Convite enviado', 'viewed' => 'Visualizou',
                 'signed' => 'Assinou', 'declined' => 'Recusou'];
$signerColors = ['pending' => 'bg-gray-100 text-gray-700', 'notified' => 'bg-blue-100 text-blue-700',
                 'viewed' => 'bg-yellow-100 text-yellow-700', 'signed' => 'bg-green-100 text-green-700',
                 'declined' => 'bg-red-100 text-red-700'];
$authLabels = ['link' => 'Somente link', 'email_otp' => 'Código por e-mail', 'whatsapp_otp' => 'Código por WhatsApp'];
$channelLabels = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp'];
@endphp

@section('content')
<div class="max-w-7xl mx-auto space-y-4">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold text-white">{{ $envelope->title }}</h1>
                <span class="inline-block px-2 py-0.5 rounded-md text-xs font-medium {{ $statusColors[$envelope->status] ?? '' }}">
                    {{ $statusLabels[$envelope->status] ?? $envelope->status }}
                </span>
            </div>
            <p class="text-xs text-gray-500 mt-1">
                Criado em {{ $envelope->created_at->format('d/m/Y H:i') }}
                @if ($envelope->expires_at) · expira em {{ $envelope->expires_at->format('d/m/Y') }} @endif
                @if ($envelope->completed_at) · concluído em {{ $envelope->completed_at->format('d/m/Y H:i') }} @endif
                · SHA-256: <span title="{{ $envelope->sha256_original }}" class="font-mono">{{ substr($envelope->sha256_original, 0, 16) }}…</span>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($envelope->status === 'sent')
                <form method="POST" action="{{ route('envelopes.remind', $envelope) }}">@csrf
                    <button class="px-3 py-2 rounded text-sm bg-gray-800 text-gray-300 hover:bg-gray-700 transition-colors">
                        Reenviar convite
                    </button>
                </form>
                <form method="POST" action="{{ route('envelopes.cancel', $envelope) }}"
                      onsubmit="return confirm('Cancelar este envelope? Os links de assinatura serão desativados.')">@csrf
                    <button class="px-3 py-2 rounded text-sm bg-red-900/50 text-red-300 hover:bg-red-900 transition-colors">
                        Cancelar envelope
                    </button>
                </form>
            @endif
            @if ($canReseal)
                <form method="POST" action="{{ route('envelopes.reseal', $envelope) }}">@csrf
                    <button class="px-3 py-2 rounded text-sm bg-yellow-900/50 text-yellow-300 hover:bg-yellow-900 transition-colors">
                        Reprocessar lacre
                    </button>
                </form>
            @endif
            @if ($envelope->status === 'completed')
                <a href="{{ route('envelopes.download', $envelope) }}"
                   class="px-4 py-2 rounded text-sm font-medium text-white transition-colors"
                   style="background-color: var(--color-primary);">
                    Baixar PDF assinado
                </a>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    @if ($envelope->message)
        <div class="bg-gray-900 border border-gray-800 rounded-lg p-4">
            <p class="text-xs text-gray-500 uppercase mb-1">Mensagem aos signatários</p>
            <p class="text-sm text-gray-300">{{ $envelope->message }}</p>
        </div>
    @endif

    <div class="grid md:grid-cols-2 gap-4">
        @foreach ($envelope->signers as $signer)
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4 space-y-1">
                <div class="flex items-center justify-between">
                    <p class="text-white font-medium">{{ $signer->sign_position }}. {{ $signer->name }}</p>
                    <span class="inline-block px-2 py-0.5 rounded-md text-xs font-medium {{ $signerColors[$signer->status] ?? '' }}">
                        {{ $signerLabels[$signer->status] ?? $signer->status }}
                    </span>
                </div>
                <p class="text-xs text-gray-400">{{ $signer->email ?? $signer->whatsapp }}</p>
                <p class="text-xs text-gray-500">Canal: {{ $channelLabels[$signer->channel] ?? $signer->channel }} · Autenticação: {{ $authLabels[$signer->auth_method] ?? $signer->auth_method }}</p>
                @if ($signer->status === 'signed')
                    <p class="text-xs text-gray-500">
                        Assinou em {{ $signer->signed_at?->format('d/m/Y H:i:s') }}
                        @if ($signer->ip_address) · IP {{ $signer->ip_address }} @endif
                    </p>
                @elseif ($signer->status === 'declined' && $signer->decline_reason)
                    <p class="text-xs text-red-400">Motivo: {{ $signer->decline_reason }}</p>
                @endif

                @if ($signer->canSign() && ! $signer->isCpfLocked())
                    {{-- O token é a credencial de assinatura: exibido para copiar e repassar,
                         nunca como <a href> — abrir o link a partir daqui registraria o acesso
                         do remetente como se fosse do signatário. --}}
                    @php $signLink = route('public.sign.show', $signer->token); @endphp
                    <div class="mt-2 pt-2 border-t border-gray-800" x-data="{ copied: false }">
                        <p class="text-xs text-gray-500 mb-1">Link de assinatura</p>
                        <div class="flex items-center gap-2">
                            <input type="text" readonly value="{{ $signLink }}" x-ref="link"
                                   @focus="$refs.link.select()"
                                   class="flex-1 min-w-0 bg-gray-950 border border-gray-800 rounded px-2 py-1 text-xs text-gray-400 font-mono">
                            <button type="button"
                                    @click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 2000)"
                                    class="shrink-0 px-2.5 py-1 rounded text-xs bg-gray-800 text-gray-300 hover:bg-gray-700 transition-colors">
                                <span x-text="copied ? 'Copiado!' : 'Copiar link'">Copiar link</span>
                            </button>
                        </div>
                        <p class="text-xs text-gray-600 mt-1">
                            Quem abrir este link assina. Não abra para conferir — repasse ao signatário.
                        </p>
                    </div>
                @endif

                @if ($signer->isCpfLocked())
                    <div class="mt-2 pt-2 border-t border-gray-800 space-y-1.5">
                        <p class="text-xs text-red-400">
                            Link bloqueado após {{ \App\Models\EnvelopeSigner::MAX_CPF_ATTEMPTS }} tentativas
                            com CPF diferente do informado no envio.
                        </p>
                        <form method="POST" action="{{ route('envelopes.signers.unlock-cpf', [$envelope, $signer]) }}">
                            @csrf
                            <button type="submit" class="px-2.5 py-1 rounded text-xs bg-gray-800 text-gray-300 hover:bg-gray-700 transition-colors">
                                Desbloquear
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg overflow-hidden">
        <p class="text-xs text-gray-500 uppercase px-4 pt-4">Trilha de eventos</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm mt-2">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800">
                        <th class="px-4 py-2">Data/hora</th>
                        <th class="px-4 py-2">Evento</th>
                        <th class="px-4 py-2">Participante</th>
                        <th class="px-4 py-2">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach ($envelope->events as $event)
                        <tr>
                            <td class="px-4 py-2 text-gray-400 whitespace-nowrap">{{ $event->created_at->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-2 text-gray-300">{{ $event->event }}</td>
                            <td class="px-4 py-2 text-gray-400">{{ $event->signer?->name ?? 'Sistema' }}</td>
                            <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $event->ip_address }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
