@extends('client.layout')
@section('title', 'Envelopes')

@php
$statusLabels = ['draft' => 'Rascunho', 'sent' => 'Aguardando assinaturas', 'completed' => 'Concluído',
                 'declined' => 'Recusado', 'cancelled' => 'Cancelado', 'expired' => 'Expirado'];
$statusColors = ['draft' => 'bg-gray-100 text-gray-700', 'sent' => 'bg-blue-100 text-blue-700',
                 'completed' => 'bg-green-100 text-green-700', 'declined' => 'bg-red-100 text-red-700',
                 'cancelled' => 'bg-gray-200 text-gray-600', 'expired' => 'bg-yellow-100 text-yellow-700'];
@endphp

@section('content')
<div class="max-w-7xl mx-auto space-y-4">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-white">Envelopes</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                Envie documentos para assinatura eletrônica de múltiplos signatários
            </p>
        </div>
        <a href="{{ route('envelopes.create') }}"
           class="px-4 py-2 rounded text-sm font-medium text-white transition-colors"
           style="background-color: var(--color-primary);">
            + Novo envelope
        </a>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg overflow-hidden">
        @if ($envelopes->isEmpty())
            <p class="text-sm text-gray-500 text-center py-10">Nenhum envelope ainda. Clique em "+ Novo envelope" para começar.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800">
                            <th class="px-4 py-3">Documento</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Assinaturas</th>
                            <th class="px-4 py-3">Criado em</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach ($envelopes as $envelope)
                            <tr class="hover:bg-gray-800/50 transition-colors">
                                <td class="px-4 py-3 text-white">{{ $envelope->title }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$envelope->status] ?? 'bg-gray-100 text-gray-700' }}">
                                        {{ $statusLabels[$envelope->status] ?? $envelope->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-400">{{ $envelope->signed_count }}/{{ $envelope->signers_count }} assinaram</td>
                                <td class="px-4 py-3 text-gray-400">{{ $envelope->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('envelopes.show', $envelope) }}" class="text-blue-400 hover:text-blue-300">ver</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{ $envelopes->links() }}
</div>
@endsection
