# Busca, Filtro de Status e Paginação Modernizada em Envelopes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar busca por título e filtro por status (server-side, sem reload de página) à listagem de envelopes, e modernizar visualmente a paginação em todo o projeto.

**Architecture:** `EnvelopeController@index` passa a filtrar por `q` (título, LIKE) e `status` (exato), combinados em AND, preservando o filtro na paginação via `withQueryString()`. A view usa Alpine.js para debounce na busca e fetch para recarregar só um wrapper (tabela+paginação) sem reload de página inteira, interceptando também os cliques nos links de paginação dentro do wrapper. A paginação ganha uma view Blade customizada registrada como default global do Laravel (`Paginator::defaultView()`), afetando automaticamente todas as telas paginadas do projeto.

**Tech Stack:** Laravel 13, Blade, Alpine.js (já carregado no layout), Tailwind CSS, PHPUnit (sqlite `:memory:`).

## Global Constraints

- PHP do Laragon: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` — nunca o XAMPP.
- Testes rodam com `php artisan test` (sqlite `:memory:`).
- UI em pt-BR, código (variáveis, funções, comentários) em inglês — convenção do projeto.
- Cor primária vem de `--color-primary` (CSS var setada no layout a partir de `$settings->primary_color`), não hardcoded.
- Não alterar `->paginate()` (tamanho de página, ordenação) das outras 3 telas afetadas pela paginação global — só o visual dos links muda.
- Filtro de status e busca por título: apenas na tela de envelopes.

---

## File Structure

- **Modify:** `app/Http/Controllers/Client/EnvelopeController.php` — método `index()` ganha filtros `q` e `status`.
- **Modify:** `resources/views/client/envelopes/index.blade.php` — input de busca, dropdown de status, wrapper com `x-data` para fetch/debounce, extrai tabela+paginação para dentro do wrapper.
- **Create:** `resources/views/vendor/pagination/casca.blade.php` — view de paginação customizada (pills), usada como default global.
- **Modify:** `app/Providers/AppServiceProvider.php` — registra `Paginator::defaultView('vendor.pagination.casca')` no `boot()`.
- **Modify:** `tests/Feature/EnvelopeControllerTest.php` — testes para os novos filtros.

---

## Task 1: Filtros de busca e status no controller

**Files:**
- Modify: `app/Http/Controllers/Client/EnvelopeController.php:26-34`
- Test: `tests/Feature/EnvelopeControllerTest.php`

**Interfaces:**
- Consumes: `Envelope` model (já existe, campos `title`, `status`, `user_id`).
- Produces: `EnvelopeController::index(Request $request)` aceita query params `q` (string, busca parcial no título) e `status` (string, um dos 6 valores de status ou vazio/ausente = todos). View recebe as mesmas variáveis de hoje (`$envelopes`) — sem novas variáveis necessárias, pois os filtros são lidos via `request()` na própria view.

- [ ] **Step 1: Escrever os testes que falham**

Adicionar ao final de `tests/Feature/EnvelopeControllerTest.php`, antes do `}` de fechamento da classe:

```php
    // ─── Busca e filtro de status ──────────────────────────────────────────

    public function test_index_filters_by_title_search(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        Envelope::factory()->for($owner)->create(['title' => 'Contrato de Aluguel']);
        Envelope::factory()->for($owner)->create(['title' => 'Termo de Confidencialidade']);

        $response = $this->actingAs($owner)->get('/envelopes?q=Aluguel');

        $response->assertOk()->assertSee('Contrato de Aluguel')->assertDontSee('Termo de Confidencialidade');
    }

    public function test_index_search_is_case_insensitive(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        Envelope::factory()->for($owner)->create(['title' => 'Contrato de Aluguel']);

        $this->actingAs($owner)->get('/envelopes?q=aluguel')
            ->assertOk()->assertSee('Contrato de Aluguel');
    }

    public function test_index_filters_by_status(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        Envelope::factory()->for($owner)->create(['title' => 'Envelope Concluído', 'status' => 'completed']);
        Envelope::factory()->for($owner)->create(['title' => 'Envelope Cancelado', 'status' => 'cancelled']);

        $response = $this->actingAs($owner)->get('/envelopes?status=completed');

        $response->assertOk()->assertSee('Envelope Concluído')->assertDontSee('Envelope Cancelado');
    }

    public function test_index_combines_search_and_status_filters(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        Envelope::factory()->for($owner)->create(['title' => 'Contrato Alfa', 'status' => 'completed']);
        Envelope::factory()->for($owner)->create(['title' => 'Contrato Beta', 'status' => 'cancelled']);
        Envelope::factory()->for($owner)->create(['title' => 'Outro Documento', 'status' => 'completed']);

        $response = $this->actingAs($owner)->get('/envelopes?q=Contrato&status=completed');

        $response->assertOk()
            ->assertSee('Contrato Alfa')
            ->assertDontSee('Contrato Beta')
            ->assertDontSee('Outro Documento');
    }

    public function test_index_without_filters_returns_all_envelopes(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        Envelope::factory()->for($owner)->create(['title' => 'Envelope Um']);
        Envelope::factory()->for($owner)->create(['title' => 'Envelope Dois']);

        $this->actingAs($owner)->get('/envelopes')
            ->assertOk()->assertSee('Envelope Um')->assertSee('Envelope Dois');
    }

    public function test_index_preserves_filters_in_pagination_links(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        Envelope::factory(25)->for($owner)->create(['title' => 'Contrato Recorrente', 'status' => 'completed']);

        $response = $this->actingAs($owner)->get('/envelopes?q=Contrato&status=completed');

        $response->assertOk()->assertSee('q=Contrato', false)->assertSee('status=completed', false);
    }
```

- [ ] **Step 2: Rodar os testes para confirmar que falham**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=EnvelopeControllerTest`
Expected: as 6 novas asserções falham (`test_index_filters_by_title_search`, `test_index_search_is_case_insensitive`, `test_index_filters_by_status`, `test_index_combines_search_and_status_filters` falham por não filtrar; `test_index_without_filters_returns_all_envelopes` já passa hoje; `test_index_preserves_filters_in_pagination_links` falha porque não há `withQueryString()`/links de paginação renderizados sem os query params — confirme que pelo menos as 4 primeiras falham antes de prosseguir).

- [ ] **Step 3: Implementar o filtro no controller**

Editar `app/Http/Controllers/Client/EnvelopeController.php`, substituindo o método `index()` atual:

```php
    public function index(Request $request)
    {
        $envelopes = Envelope::where('user_id', auth()->id())
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->withCount(['signers', 'signers as signed_count' => fn ($q) => $q->where('status', 'signed')])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('client.envelopes.index', compact('envelopes'));
    }
```

O parâmetro `Request $request` precisa ser adicionado à assinatura do método (hoje é `index()` sem parâmetros). `Illuminate\Http\Request` já está importado no topo do arquivo (usado em `store()`).

- [ ] **Step 4: Rodar os testes para confirmar que passam**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=EnvelopeControllerTest`
Expected: PASS em todos os testes da classe, incluindo os 6 novos.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Client/EnvelopeController.php tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: filtro de busca por titulo e status na listagem de envelopes"
```

---

## Task 2: Paginação customizada global

**Files:**
- Create: `resources/views/vendor/pagination/casca.blade.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: instância de `Illuminate\Pagination\LengthAwarePaginator` (a mesma variável `$paginator` que o Laravel injeta em qualquer view de paginação — usada como em qualquer view custom de `->links()`).
- Produces: nenhuma interface nova para outras tasks — é uma view standalone renderizada automaticamente por `{{ $envelopes->links() }}` (e qualquer outro `->links()` do projeto) a partir do momento em que é registrada como default.

- [ ] **Step 1: Criar a view de paginação customizada**

Criar `resources/views/vendor/pagination/casca.blade.php`:

```blade
@if ($paginator->hasPages())
    <nav class="flex flex-col items-center gap-3 py-2" role="navigation" aria-label="Paginação">
        <div class="flex items-center gap-1">
            {{-- Anterior --}}
            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-full text-gray-600 cursor-not-allowed opacity-50">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}"
                   class="inline-flex items-center justify-center w-9 h-9 rounded-full text-gray-400 hover:text-white hover:bg-gray-800 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
            @endif

            {{-- Números --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="inline-flex items-center justify-center w-9 h-9 text-sm text-gray-600">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="inline-flex items-center justify-center w-9 h-9 rounded-full text-sm font-medium text-white"
                                  style="background-color: var(--color-primary);">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}"
                               class="inline-flex items-center justify-center w-9 h-9 rounded-full text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition-colors">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Próxima --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}"
                   class="inline-flex items-center justify-center w-9 h-9 rounded-full text-gray-400 hover:text-white hover:bg-gray-800 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            @else
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-full text-gray-600 cursor-not-allowed opacity-50">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </span>
            @endif
        </div>

        <p class="text-xs text-gray-500">
            Mostrando {{ $paginator->firstItem() }} a {{ $paginator->lastItem() }} de {{ $paginator->total() }} resultados
        </p>
    </nav>
@endif
```

- [ ] **Step 2: Registrar a view como default global**

Editar `app/Providers/AppServiceProvider.php`, adicionando o import e a chamada no `boot()`:

```php
<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.casca');
        Paginator::defaultSimpleView('vendor.pagination.casca');

        View::composer('*', function ($view) {
            try {
                $view->with('settings', Setting::current());
            } catch (\Throwable) {
                // Tabela ainda não existe durante migrations iniciais
            }
        });
    }
}
```

- [ ] **Step 3: Verificar visualmente que a paginação renderiza em todas as telas afetadas**

Não há teste automatizado de estilo de paginação no projeto (os testes de feature existentes checam presença de dados, não markup de paginação). Verificação manual:

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan serve`

Acessar no browser, logado como client com 25+ envelopes/certificados (ou como admin com 25+ usuários), e confirmar que os pills aparecem corretamente em:
- `/envelopes` (precisa de 21+ envelopes para paginar)
- `/certificates` (precisa de 21+ certificados)
- `/admin/users` (precisa de 21+ usuários)
- `/admin/access-logs` (precisa de 51+ logs)

Se não houver dados suficientes localmente, rodar via tinker para gerar registros de teste temporários, ou pular a verificação visual dessas 3 últimas telas e confirmar apenas via teste automatizado no Task 3 (que cobre `/envelopes` com dados de teste).

- [ ] **Step 4: Rodar a suíte completa de testes para garantir que nada quebrou**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test`
Expected: PASS em toda a suíte (a troca de view de paginação não deve quebrar nenhum teste existente, já que nenhum teste hoje faz assert sobre o HTML específico da paginação padrão do Laravel).

- [ ] **Step 5: Commit**

```bash
git add resources/views/vendor/pagination/casca.blade.php app/Providers/AppServiceProvider.php
git commit -m "feat: paginacao customizada global com pills centralizadas"
```

---

## Task 3: UI de busca, filtro de status e fetch sem reload na tela de envelopes

**Files:**
- Modify: `resources/views/client/envelopes/index.blade.php`
- Test: `tests/Feature/EnvelopeControllerTest.php`

**Interfaces:**
- Consumes: `EnvelopeController::index` (Task 1) já aceita `q` e `status` via querystring; view de paginação customizada (Task 2) já é o default, renderizada por `$envelopes->links()`.
- Produces: nenhuma interface nova para outras tasks — é a última task do plano.

- [ ] **Step 1: Escrever o teste que falida — inputs de busca e status presentes na página**

Adicionar ao final de `tests/Feature/EnvelopeControllerTest.php`, após os testes da Task 1:

```php
    public function test_index_renders_search_input_and_status_filter(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        Envelope::factory()->for($owner)->create(['title' => 'Envelope de Teste']);

        $response = $this->actingAs($owner)->get('/envelopes');

        $response->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('name="status"', false)
            ->assertSee('envelopes-table-wrapper', false);
    }

    public function test_index_shows_empty_state_message_for_search_with_no_results(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        Envelope::factory()->for($owner)->create(['title' => 'Envelope Existente']);

        $this->actingAs($owner)->get('/envelopes?q=NadaAVerComIsso')
            ->assertOk()
            ->assertSee('Nenhum envelope encontrado');
    }
```

- [ ] **Step 2: Rodar os testes para confirmar que falham**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=EnvelopeControllerTest`
Expected: FAIL em `test_index_renders_search_input_and_status_filter` e `test_index_shows_empty_state_message_for_search_with_no_results` (a view atual não tem esses elementos).

- [ ] **Step 3: Reescrever a view com busca, filtro de status e wrapper de fetch**

Substituir o conteúdo completo de `resources/views/client/envelopes/index.blade.php`:

```blade
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
     x-data="envelopesFilter('{{ route('envelopes.index') }}', '{{ addslashes(request('q', '')) }}', '{{ request('status', '') }}')">

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
            <input type="text" name="q" x-model="query" @input="onQueryInput"
                   placeholder="Buscar por título do documento..."
                   class="w-full pl-9 pr-3 py-2 bg-gray-800 border border-gray-700 rounded-md text-sm text-white placeholder-gray-500 focus:outline-none focus:border-gray-500">
        </div>
        <div>
            <select name="status" x-model="status" @change="onStatusChange"
                    class="bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-white focus:outline-none focus:border-gray-500">
                <option value="">Todos os status</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @include('client.envelopes.partials.table', ['envelopes' => $envelopes, 'statusLabels' => $statusLabels, 'statusColors' => $statusColors])
</div>

@push('scripts')
<script>
    function envelopesFilter(baseUrl, initialQuery, initialStatus) {
        return {
            query: initialQuery,
            status: initialStatus,
            debounceTimer: null,

            onQueryInput() {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => this.fetchResults(), 400);
            },

            onStatusChange() {
                this.fetchResults();
            },

            fetchResults(url = null) {
                const target = url ?? this.buildUrl();

                fetch(target, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((response) => response.text())
                    .then((html) => {
                        const parsed = new DOMParser().parseFromString(html, 'text/html');
                        const newWrapper = parsed.getElementById('envelopes-table-wrapper');
                        if (newWrapper) {
                            document.getElementById('envelopes-table-wrapper').innerHTML = newWrapper.innerHTML;
                            this.bindPaginationLinks();
                        }
                        window.history.pushState({}, '', target);
                    })
                    .catch(() => {});
            },

            buildUrl() {
                const params = new URLSearchParams();
                if (this.query) params.set('q', this.query);
                if (this.status) params.set('status', this.status);
                const qs = params.toString();
                return qs ? `${baseUrl}?${qs}` : baseUrl;
            },

            bindPaginationLinks() {
                document.querySelectorAll('#envelopes-table-wrapper a[href]').forEach((link) => {
                    link.addEventListener('click', (event) => {
                        event.preventDefault();
                        this.fetchResults(link.getAttribute('href'));
                    });
                });
            },

            init() {
                this.bindPaginationLinks();
            },
        };
    }
</script>
@endpush
@endsection
```

- [ ] **Step 4: Extrair a tabela + paginação para uma partial**

Criar `resources/views/client/envelopes/partials/table.blade.php`:

```blade
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
```

O `id="envelopes-table-wrapper"` vive só nesta partial (não na view principal do Step 3) — é o elemento que o `DOMParser` procura via `getElementById('envelopes-table-wrapper')` e substitui a cada fetch.

- [ ] **Step 5: Rodar os testes para confirmar que passam**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=EnvelopeControllerTest`
Expected: PASS em todos os testes da classe (incluindo os das Tasks 1 e 3).

- [ ] **Step 6: Rodar a suíte completa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test`
Expected: PASS em toda a suíte.

- [ ] **Step 7: Verificação manual no browser**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan serve`

Login como client, acessar `/envelopes` e verificar:
- Digitar no campo de busca filtra a lista após ~400ms sem apertar Enter, sem reload de página (Network tab mostra fetch, não navigation).
- Trocar o dropdown de status filtra imediatamente.
- Combinar busca + status filtra por ambos.
- Clicar em um link de página (se houver 21+ envelopes) troca de página sem reload.
- URL do browser reflete `?q=...&status=...` após os filtros (copiar/colar a URL com filtro deve reabrir já filtrado, via carregamento normal da página — SSR).
- Pills de paginação aparecem centralizados, arredondados, com a cor primária no item ativo.

- [ ] **Step 8: Commit**

```bash
git add resources/views/client/envelopes/index.blade.php resources/views/client/envelopes/partials/table.blade.php tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: busca e filtro sem reload na listagem de envelopes"
```

---

## Final Task: Commit de fechamento (se necessário)

Se todas as tasks acima já foram commitadas individualmente (Steps 5/8 de cada task), nenhum commit adicional é necessário. Confirmar com `git status` que a árvore de trabalho está limpa antes de finalizar.

Run: `git status`
Expected: `nothing to commit, working tree clean`
