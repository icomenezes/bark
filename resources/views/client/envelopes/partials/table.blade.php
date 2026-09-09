<div id="envelopes-table-wrapper">
    <div class="bg-gray-900 border border-gray-800 rounded-lg overflow-hidden">
        @if ($envelopes->isEmpty())
            <p class="text-sm text-gray-500 text-center py-10">
                @if (request('q') || request('status'))
                    Nenhum envelope encontrado para os filtros aplicados.
                @else
                    Nenhum envelope ainda. Clique em "+ Novo envelope" para começar.
                @endif
            </p>
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
                                    <span class="inline-block px-2 py-0.5 rounded-md text-xs font-medium {{ $statusColors[$envelope->status] ?? 'bg-gray-100 text-gray-700' }}">
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
