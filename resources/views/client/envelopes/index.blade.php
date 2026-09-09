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
<div class="max-w-7xl mx-auto space-y-4"
     x-data="envelopesFilter(@js(route('envelopes.index')), @js(request('q', '')), @js(request('status', '')))">

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

    <div class="flex flex-wrap items-center gap-3">
        <div class="relative flex-1 min-w-[220px]">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
            </svg>
            <input type="text" name="q" x-model="query" @input="onQueryInput" value="{{ request('q', '') }}"
                   placeholder="Buscar por título do documento..."
                   class="w-full pl-9 pr-3 py-2 bg-gray-800 border border-gray-700 rounded-md text-sm text-white placeholder-gray-500 focus:outline-none focus:border-gray-500">
        </div>
        <div>
            <select name="status" x-model="status" @change="onStatusChange"
                    class="bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-white focus:outline-none focus:border-gray-500">
                <option value="" @selected(request('status', '') === '')>Todos os status</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <p x-show="error" x-cloak class="text-sm text-red-400">
        Não foi possível atualizar a lista. Tente novamente.
    </p>

    @include('client.envelopes.partials.table', ['envelopes' => $envelopes, 'statusLabels' => $statusLabels, 'statusColors' => $statusColors])
</div>

@push('scripts')
<script>
    function envelopesFilter(baseUrl, initialQuery, initialStatus) {
        return {
            query: initialQuery,
            status: initialStatus,
            error: false,
            debounceTimer: null,

            onQueryInput() {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => this.fetchResults(), 400);
            },

            onStatusChange() {
                this.fetchResults();
            },

            fetchResults(url = null, pushHistory = true) {
                const target = url ?? this.buildUrl();

                fetch(target, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((response) => {
                        if (!response.ok) throw new Error(response.status);
                        return response.text();
                    })
                    .then((html) => {
                        const parsed = new DOMParser().parseFromString(html, 'text/html');
                        const newWrapper = parsed.getElementById('envelopes-table-wrapper');
                        if (newWrapper) {
                            document.getElementById('envelopes-table-wrapper').innerHTML = newWrapper.innerHTML;
                            if (pushHistory) {
                                window.history.pushState({}, '', target);
                            }
                            this.error = false;
                        } else {
                            this.error = true;
                        }
                    })
                    .catch(() => { this.error = true; });
            },

            syncFromLocation() {
                const params = new URLSearchParams(window.location.search);
                this.query = params.get('q') ?? '';
                this.status = params.get('status') ?? '';
                this.fetchResults(window.location.href, false);
            },

            buildUrl() {
                const params = new URLSearchParams();
                if (this.query) params.set('q', this.query);
                if (this.status) params.set('status', this.status);
                const qs = params.toString();
                return qs ? `${baseUrl}?${qs}` : baseUrl;
            },

            init() {
                document.getElementById('envelopes-table-wrapper').addEventListener('click', (event) => {
                    const link = event.target.closest('nav[role="navigation"] a[href]');
                    if (!link) return;
                    event.preventDefault();
                    this.fetchResults(link.getAttribute('href'));
                });
                window.addEventListener('popstate', () => this.syncFromLocation());
            },
        };
    }
</script>
@endpush
@endsection
