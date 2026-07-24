# Tela de edição de signatário/grupo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir editar um signatário salvo e editar um grupo (nome + adicionar/remover membros) a partir da tela `/signatarios`, usando os endpoints `PATCH` que já existem no backend.

**Architecture:** Duas rotas `GET` novas + dois métodos de controller novos (`edit`, `editGroup`) que retornam duas views novas (`edit.blade.php`, `edit-group.blade.php`) com formulário pré-preenchido apontando para as rotas `PATCH` já existentes. A `index.blade.php` ganha um link "editar" por linha. Nenhuma mudança em model/migration/service.

**Tech Stack:** Laravel 13, Blade, sem JS novo (forms tradicionais, mesmo padrão da tela atual).

## Global Constraints

- Autorização: todo acesso a um `SavedSigner`/`SignerGroup` deve verificar `user_id === auth()->id()` (usar os métodos privados `authorizeOwner()`/`authorizeOwnerGroup()` já existentes em `SignerDirectoryController`).
- Não alterar `SignerDirectoryService`, models ou migrations — a lógica de update já está correta.
- Seguir o estilo visual/Tailwind já usado em `resources/views/client/signers/index.blade.php` (fundo `bg-gray-900`, bordas `border-gray-800`, inputs `bg-gray-800 border-gray-700 text-white text-sm`, botão primário com `style="background-color: var(--color-primary);"`).
- Rotas em português (`signatarios/...`), nomes de rota em inglês (`signers.*`), consistente com o padrão já usado.

---

### Task 1: Rotas e métodos `edit`/`editGroup` no controller

**Files:**
- Modify: `routes/web.php` (bloco de rotas de signers, linhas 67-74)
- Modify: `app/Http/Controllers/Client/SignerDirectoryController.php`
- Test: `tests/Feature/SignerDirectoryControllerTest.php`

**Interfaces:**
- Consumes: `SignerDirectoryController::authorizeOwner(SavedSigner $signer): void` (já existe, linha 96-99), `authorizeOwnerGroup(SignerGroup $group): void` (já existe, linha 101-104).
- Produces: rota nomeada `signers.edit` (GET `signatarios/{savedSigner}/editar`), rota nomeada `signers.groups.edit` (GET `signatarios/grupos/{signerGroup}/editar`); views `client.signers.edit` (recebe `$savedSigner`) e `client.signers.edit-group` (recebe `$signerGroup`, `$signers`) — usadas na Task 2.

- [ ] **Step 1: Escrever os testes de feature que falham**

Adicionar ao final de `tests/Feature/SignerDirectoryControllerTest.php`, antes do último `}`:

```php
    public function test_edit_signer_shows_owned_signer(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Bruna Lima']);

        $this->actingAs($user)->get("/signatarios/{$signer->id}/editar")
            ->assertOk()->assertSee('Bruna Lima');
    }

    public function test_cannot_edit_another_users_signer(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)->get("/signatarios/{$signer->id}/editar")->assertForbidden();
    }

    public function test_edit_group_shows_owned_group_with_members_selected(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Carlos Souza']);
        $group = SignerGroup::factory()->create(['user_id' => $user->id, 'name' => 'Diretoria']);
        $group->members()->sync([$signer->id]);

        $this->actingAs($user)->get("/signatarios/grupos/{$group->id}/editar")
            ->assertOk()->assertSee('Diretoria')->assertSee('Carlos Souza');
    }

    public function test_cannot_edit_another_users_group(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $group = SignerGroup::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)->get("/signatarios/grupos/{$group->id}/editar")->assertForbidden();
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SignerDirectoryControllerTest`
Expected: FAIL nos 4 testes novos (rota não existe / 404), os 6 testes antigos continuam passando.

- [ ] **Step 3: Adicionar as rotas**

Em `routes/web.php`, localizar o bloco (linhas 67-74):

```php
    Route::get('signatarios', [SignerDirectoryController::class, 'index'])->name('signers.index');
    Route::post('signatarios', [SignerDirectoryController::class, 'store'])->name('signers.store');
    Route::get('signatarios/buscar', [SignerDirectoryController::class, 'search'])->name('signers.search');
    Route::patch('signatarios/{savedSigner}', [SignerDirectoryController::class, 'update'])->name('signers.update');
    Route::delete('signatarios/{savedSigner}', [SignerDirectoryController::class, 'destroy'])->name('signers.destroy');
    Route::post('signatarios/grupos', [SignerDirectoryController::class, 'storeGroup'])->name('signers.groups.store');
    Route::patch('signatarios/grupos/{signerGroup}', [SignerDirectoryController::class, 'updateGroup'])->name('signers.groups.update');
    Route::delete('signatarios/grupos/{signerGroup}', [SignerDirectoryController::class, 'destroyGroup'])->name('signers.groups.destroy');
```

E adicionar duas linhas (a rota `buscar` deve continuar antes de `{savedSigner}` para não colidir; `signatarios/{savedSigner}/editar` não colide porque tem um segmento a mais):

```php
    Route::get('signatarios', [SignerDirectoryController::class, 'index'])->name('signers.index');
    Route::post('signatarios', [SignerDirectoryController::class, 'store'])->name('signers.store');
    Route::get('signatarios/buscar', [SignerDirectoryController::class, 'search'])->name('signers.search');
    Route::get('signatarios/{savedSigner}/editar', [SignerDirectoryController::class, 'edit'])->name('signers.edit');
    Route::patch('signatarios/{savedSigner}', [SignerDirectoryController::class, 'update'])->name('signers.update');
    Route::delete('signatarios/{savedSigner}', [SignerDirectoryController::class, 'destroy'])->name('signers.destroy');
    Route::post('signatarios/grupos', [SignerDirectoryController::class, 'storeGroup'])->name('signers.groups.store');
    Route::get('signatarios/grupos/{signerGroup}/editar', [SignerDirectoryController::class, 'editGroup'])->name('signers.groups.edit');
    Route::patch('signatarios/grupos/{signerGroup}', [SignerDirectoryController::class, 'updateGroup'])->name('signers.groups.update');
    Route::delete('signatarios/grupos/{signerGroup}', [SignerDirectoryController::class, 'destroyGroup'])->name('signers.groups.destroy');
```

- [ ] **Step 4: Adicionar os métodos no controller**

Em `app/Http/Controllers/Client/SignerDirectoryController.php`, adicionar o método `edit` logo após `store()` (antes de `update()`, linha 31):

```php
    public function edit(SavedSigner $savedSigner)
    {
        $this->authorizeOwner($savedSigner);

        return view('client.signers.edit', ['savedSigner' => $savedSigner]);
    }

```

E adicionar o método `editGroup` logo após `storeGroup()` (antes de `updateGroup()`, linha 67):

```php
    public function editGroup(SignerGroup $signerGroup)
    {
        $this->authorizeOwnerGroup($signerGroup);
        $signers = auth()->user()->savedSigners()->orderBy('name')->get();

        return view('client.signers.edit-group', ['signerGroup' => $signerGroup, 'signers' => $signers]);
    }

```

- [ ] **Step 5: Criar views mínimas para os testes passarem**

Criar `resources/views/client/signers/edit.blade.php`:

```blade
@extends('client.layout')
@section('title', 'Editar signatário')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-xl font-semibold text-white">Editar signatário</h1>

    @if ($errors->any())
        <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <form method="POST" action="{{ route('signers.update', $savedSigner) }}" class="grid md:grid-cols-2 gap-3">
            @csrf
            @method('PATCH')
            <input type="text" name="name" placeholder="Nome completo" required maxlength="255"
                   value="{{ old('name', $savedSigner->name) }}"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="channel" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
                <option value="email" @selected(old('channel', $savedSigner->channel) === 'email')>Canal: E-mail</option>
                <option value="whatsapp" @selected(old('channel', $savedSigner->channel) === 'whatsapp')>Canal: WhatsApp</option>
            </select>
            <input type="text" name="email" placeholder="E-mail" maxlength="255"
                   value="{{ old('email', $savedSigner->email) }}"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <input type="text" name="whatsapp" placeholder="WhatsApp (com DDD)" maxlength="20"
                   value="{{ old('whatsapp', $savedSigner->whatsapp) }}"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="auth_method" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
                <option value="link" @selected(old('auth_method', $savedSigner->auth_method) === 'link')>Somente link</option>
                <option value="email_otp" @selected(old('auth_method', $savedSigner->auth_method) === 'email_otp')>Código por e-mail</option>
                <option value="whatsapp_otp" @selected(old('auth_method', $savedSigner->auth_method) === 'whatsapp_otp')>Código por WhatsApp</option>
            </select>
            <div class="md:col-span-2 flex items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white"
                        style="background-color: var(--color-primary);">Salvar</button>
                <a href="{{ route('signers.index') }}" class="text-sm text-gray-400 hover:text-white">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

Criar `resources/views/client/signers/edit-group.blade.php`:

```blade
@extends('client.layout')
@section('title', 'Editar grupo')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-xl font-semibold text-white">Editar grupo</h1>

    @if ($errors->any())
        <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <form method="POST" action="{{ route('signers.groups.update', $signerGroup) }}" class="space-y-3">
            @csrf
            @method('PATCH')
            <input type="text" name="name" placeholder="Nome do grupo" required maxlength="255"
                   value="{{ old('name', $signerGroup->name) }}"
                   class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="members[]" multiple class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm" size="8">
                @foreach ($signers as $signer)
                    <option value="{{ $signer->id }}" @selected($signerGroup->members->contains($signer->id))>{{ $signer->name }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500">Segure Ctrl (ou Cmd no Mac) para selecionar vários signatários.</p>
            <div class="flex items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white"
                        style="background-color: var(--color-primary);">Salvar</button>
                <a href="{{ route('signers.index') }}" class="text-sm text-gray-400 hover:text-white">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Rodar os testes e confirmar que passam**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SignerDirectoryControllerTest`
Expected: PASS em todos os 10 testes (6 antigos + 4 novos).

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/Client/SignerDirectoryController.php resources/views/client/signers/edit.blade.php resources/views/client/signers/edit-group.blade.php tests/Feature/SignerDirectoryControllerTest.php
git commit -m "feat: telas de edicao de signatario e grupo"
```

---

### Task 2: Link "editar" na listagem (`index.blade.php`)

**Files:**
- Modify: `resources/views/client/signers/index.blade.php:50-65` (lista de signatários), `resources/views/client/signers/index.blade.php:87-102` (lista de grupos)

**Interfaces:**
- Consumes: rotas `signers.edit` e `signers.groups.edit` (produzidas na Task 1).
- Produces: nenhuma (última task da feature).

- [ ] **Step 1: Adicionar o link "editar" na lista de signatários**

Em `resources/views/client/signers/index.blade.php`, substituir o bloco (linhas 50-65):

```blade
            @forelse ($signers as $signer)
                <div class="flex items-center justify-between border-b border-gray-800 pb-2 text-sm">
                    <div>
                        <span class="text-white">{{ $signer->name }}</span>
                        <span class="text-gray-500 text-xs ml-2">{{ $signer->email ?? $signer->whatsapp }}</span>
                    </div>
                    <form method="POST" action="{{ route('signers.destroy', $signer) }}"
                          onsubmit="return confirm('Remover este signatário?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-400 hover:text-red-300">remover</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum signatário salvo ainda.</p>
            @endforelse
```

por:

```blade
            @forelse ($signers as $signer)
                <div class="flex items-center justify-between border-b border-gray-800 pb-2 text-sm">
                    <div>
                        <span class="text-white">{{ $signer->name }}</span>
                        <span class="text-gray-500 text-xs ml-2">{{ $signer->email ?? $signer->whatsapp }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('signers.edit', $signer) }}" class="text-xs text-gray-400 hover:text-white">editar</a>
                        <form method="POST" action="{{ route('signers.destroy', $signer) }}"
                              onsubmit="return confirm('Remover este signatário?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-400 hover:text-red-300">remover</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum signatário salvo ainda.</p>
            @endforelse
```

- [ ] **Step 2: Adicionar o link "editar" na lista de grupos**

No mesmo arquivo, substituir o bloco (linhas 87-102):

```blade
            @forelse ($groups as $group)
                <div class="flex items-center justify-between border-b border-gray-800 pb-2 text-sm">
                    <div>
                        <span class="text-white">{{ $group->name }}</span>
                        <span class="text-gray-500 text-xs ml-2">{{ $group->members->count() }} membro(s)</span>
                    </div>
                    <form method="POST" action="{{ route('signers.groups.destroy', $group) }}"
                          onsubmit="return confirm('Remover este grupo?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-400 hover:text-red-300">remover</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum grupo criado ainda.</p>
            @endforelse
```

por:

```blade
            @forelse ($groups as $group)
                <div class="flex items-center justify-between border-b border-gray-800 pb-2 text-sm">
                    <div>
                        <span class="text-white">{{ $group->name }}</span>
                        <span class="text-gray-500 text-xs ml-2">{{ $group->members->count() }} membro(s)</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('signers.groups.edit', $group) }}" class="text-xs text-gray-400 hover:text-white">editar</a>
                        <form method="POST" action="{{ route('signers.groups.destroy', $group) }}"
                              onsubmit="return confirm('Remover este grupo?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-400 hover:text-red-300">remover</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum grupo criado ainda.</p>
            @endforelse
```

- [ ] **Step 3: Verificar manualmente com um teste de feature existente**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SignerDirectoryControllerTest`
Expected: PASS em todos os 10 testes (o `test_index_lists_only_the_users_own_signers_and_groups` continua passando, pois só adicionamos um link, não removemos nada que ele verifica).

- [ ] **Step 4: Rodar a suíte completa de testes do projeto**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test`
Expected: PASS em toda a suíte (nenhuma view/rota de outro módulo referencia essas rotas, então não deve haver regressão).

- [ ] **Step 5: Commit**

```bash
git add resources/views/client/signers/index.blade.php
git commit -m "feat: link editar na listagem de signatarios e grupos"
```
