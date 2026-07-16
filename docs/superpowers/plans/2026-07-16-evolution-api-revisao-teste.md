# Revisão Evolution API + Tela de Teste — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corrigir a normalização de número de telefone no `WhatsAppService` (fixo sem DDI não recebia o `55`) e adicionar uma tela de teste manual em `/admin/settings` para disparar mensagens de teste e ver o resultado na hora.

**Architecture:** `WhatsAppService::send()` passa a delegar para um novo método público `sendWithDetails()` que retorna `array{ok: bool, error: ?string}`, preservando a assinatura `bool` de `send()` para todo o código existente (`NotificationService`). Um novo endpoint `POST /admin/settings/whatsapp-test` chama `sendWithDetails()` diretamente e devolve o resultado via flash session.

**Tech Stack:** Laravel 13, `Illuminate\Support\Facades\Http`, Blade, Tailwind (dark theme já usado em `admin/settings/edit.blade.php`).

## Global Constraints

- UI em pt-BR; código em inglês
- Não muda `NotificationService` nem os pontos de disparo automático (`boasVindas`, convites, OTP, lembrete)
- Não adiciona suporte a mídia/PDF — só texto, como hoje
- Testes rodam em sqlite `:memory:` via `php artisan test`
- PHP do Laragon: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`

---

### Task 1: Corrigir normalização de número e extrair `sendWithDetails()`

**Files:**
- Modify: `app/Services/WhatsAppService.php`
- Test: `tests/Unit/WhatsAppServiceTest.php` (novo)

**Interfaces:**
- Consumes: nada novo (mesma config `services.evolution.*`)
- Produces: `WhatsAppService::sendWithDetails(string $phone, string $message): array` retornando `['ok' => bool, 'error' => ?string]`; `WhatsAppService::send()` mantém assinatura `(string $phone, string $message): bool` inalterada para os callers existentes

- [ ] **Step 1: Escrever o teste de normalização de número (falhando)**

Criar `tests/Unit/WhatsAppServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution.url' => 'https://evolution.example.com',
            'services.evolution.instance' => 'test-instance',
            'services.evolution.key' => 'test-key',
        ]);
    }

    public function test_adds_ddi_to_11_digit_mobile_number(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        (new WhatsAppService)->send('11999998888', 'oi');

        Http::assertSent(function ($request) {
            return $request['number'] === '5511999998888';
        });
    }

    public function test_adds_ddi_to_10_digit_landline_number(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        (new WhatsAppService)->send('1133334444', 'oi');

        Http::assertSent(function ($request) {
            return $request['number'] === '551133334444';
        });
    }

    public function test_does_not_duplicate_ddi_already_present(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        (new WhatsAppService)->send('5511999998888', 'oi');

        Http::assertSent(function ($request) {
            return $request['number'] === '5511999998888';
        });
    }

    public function test_send_with_details_returns_error_on_http_failure(): void
    {
        Http::fake(['*' => Http::response(['message' => 'invalid instance'], 400)]);

        $result = (new WhatsAppService)->sendWithDetails('11999998888', 'oi');

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    public function test_send_with_details_returns_ok_on_success(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = (new WhatsAppService)->sendWithDetails('11999998888', 'oi');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function test_send_with_details_returns_error_when_not_configured(): void
    {
        config(['services.evolution.url' => '']);

        $result = (new WhatsAppService)->sendWithDetails('11999998888', 'oi');

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }
}
```

- [ ] **Step 2: Rodar o teste para confirmar que falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/WhatsAppServiceTest.php`
Expected: FAIL — `sendWithDetails` não existe, e o teste de 10 dígitos falha (número enviado seria `1133334444` sem DDI)

- [ ] **Step 3: Reescrever `WhatsAppService`**

Substituir o conteúdo de `app/Services/WhatsAppService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $baseUrl;
    private string $instance;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.evolution.url', '') ?? '', '/');
        $this->instance = config('services.evolution.instance', '') ?? '';
        $this->apiKey   = config('services.evolution.key', '') ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl && $this->instance && $this->apiKey;
    }

    public function send(string $phone, string $message): bool
    {
        return $this->sendWithDetails($phone, $message)['ok'];
    }

    /** @return array{ok: bool, error: ?string} */
    public function sendWithDetails(string $phone, string $message): array
    {
        if (! $this->isConfigured()) {
            Log::warning('WhatsApp: Evolution API não configurada.');
            return ['ok' => false, 'error' => 'Evolution API não configurada (URL, instância ou chave ausentes).'];
        }

        $number = $this->normalizeNumber($phone);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['apikey' => $this->apiKey])
                ->post("{$this->baseUrl}/message/sendText/{$this->instance}", [
                    'number'  => $number,
                    'text'    => $message,
                    'delay'   => 0,
                ]);

            if ($response->successful()) {
                return ['ok' => true, 'error' => null];
            }

            Log::error('WhatsApp falhou', ['status' => $response->status(), 'body' => $response->body()]);
            return ['ok' => false, 'error' => "HTTP {$response->status()}: {$response->body()}"];
        } catch (\Throwable $e) {
            Log::error('WhatsApp exception: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Normaliza para o formato aceito pela Evolution API: DDI 55 + DDD + número. */
    private function normalizeNumber(string $phone): string
    {
        $number = preg_replace('/\D/', '', $phone);

        // Celular sem DDI (11 dígitos: DDD + 9 + 8 dígitos) ou fixo sem DDI (10 dígitos: DDD + 8 dígitos)
        if (strlen($number) === 11 || strlen($number) === 10) {
            $number = '55' . $number;
        }

        return $number;
    }
}
```

- [ ] **Step 4: Rodar o teste para confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/WhatsAppServiceTest.php`
Expected: PASS (6 testes)

- [ ] **Step 5: Rodar a suíte completa para garantir que `NotificationService` (que usa `send()`) continua funcionando**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/WhatsAppService.php tests/Unit/WhatsAppServiceTest.php
git commit -m "fix: normalizar DDI tambem para numeros fixos de 10 digitos na Evolution API"
```

---

### Task 2: Endpoint de teste manual

**Files:**
- Modify: `app/Http/Controllers/Admin/SettingController.php`
- Modify: `routes/web.php:33` (adicionar rota logo após a de `settings.update`)
- Test: `tests/Feature/Admin/WhatsAppTestTest.php` (novo)

**Interfaces:**
- Consumes: `WhatsAppService::sendWithDetails()` (Task 1)
- Produces: rota nomeada `admin.settings.whatsapp-test` (POST), redireciona de volta com `success` ou `error` na sessão flash

- [ ] **Step 1: Escrever o teste de feature (falhando)**

Criar `tests/Feature/Admin/WhatsAppTestTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTestTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_guest_cannot_access(): void
    {
        $response = $this->post(route('admin.settings.whatsapp-test'), [
            'phone' => '11999998888',
            'message' => 'teste',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_client_cannot_access(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $response = $this->actingAs($client)->post(route('admin.settings.whatsapp-test'), [
            'phone' => '11999998888',
            'message' => 'teste',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_sees_success_flash_on_successful_send(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.example.com',
            'services.evolution.instance' => 'test-instance',
            'services.evolution.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $response = $this->actingAs($this->admin())->post(route('admin.settings.whatsapp-test'), [
            'phone' => '11999998888',
            'message' => 'Mensagem de teste',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_admin_sees_error_flash_on_failed_send(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.example.com',
            'services.evolution.instance' => 'test-instance',
            'services.evolution.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['message' => 'bad request'], 400)]);

        $response = $this->actingAs($this->admin())->post(route('admin.settings.whatsapp-test'), [
            'phone' => '11999998888',
            'message' => 'Mensagem de teste',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('whatsappTestError');
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.settings.whatsapp-test'), []);

        $response->assertSessionHasErrors(['phone', 'message']);
    }
}
```

- [ ] **Step 2: Rodar o teste para confirmar que falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/Admin/WhatsAppTestTest.php`
Expected: FAIL — rota `admin.settings.whatsapp-test` não existe

- [ ] **Step 3: Adicionar a rota**

Em `routes/web.php`, logo após a linha 33 (`Route::patch('settings', ...)`):

```php
    Route::post('settings/whatsapp-test', [SettingController::class, 'testWhatsApp'])->name('settings.whatsapp-test');
```

- [ ] **Step 4: Adicionar o método no controller**

Em `app/Http/Controllers/Admin/SettingController.php`, adicionar o import e o método:

```php
use App\Services\WhatsAppService;
```

(junto aos demais `use` no topo)

```php
    public function testWhatsApp(Request $request, WhatsAppService $whatsapp)
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $result = $whatsapp->sendWithDetails($request->string('phone'), $request->string('message'));

        if ($result['ok']) {
            return back()->with('success', 'Mensagem de teste enviada com sucesso.');
        }

        return back()->with('whatsappTestError', $result['error']);
    }
```

(adicionar como novo método público, após `update()`)

- [ ] **Step 5: Rodar o teste para confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/Admin/WhatsAppTestTest.php`
Expected: PASS (5 testes)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/SettingController.php routes/web.php tests/Feature/Admin/WhatsAppTestTest.php
git commit -m "feat: endpoint de teste manual de envio WhatsApp em admin/settings"
```

---

### Task 3: Card de teste na tela de Configurações

**Files:**
- Modify: `resources/views/admin/settings/edit.blade.php`

**Interfaces:**
- Consumes: rota `admin.settings.whatsapp-test` (Task 2), flash `success`/`whatsappTestError`
- Produces: seção visual, sem interface consumida por outro código

- [ ] **Step 1: Adicionar o card de teste, fora do `<form>` de settings**

Em `resources/views/admin/settings/edit.blade.php`, inserir um novo bloco logo após o `</form>` (linha 135) e antes do `</div>` de fechamento (linha 136):

```blade
    </form>

    {{-- Testar WhatsApp --}}
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-6 space-y-4 mt-6">
        <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Testar WhatsApp</h3>
        <p class="text-xs text-gray-500">Envia uma mensagem de teste via Evolution API para validar a conexão da instância.</p>

        @if (session('whatsappTestError'))
            <div class="bg-red-900/30 border border-red-800 rounded px-3 py-2 text-sm text-red-300">
                Falha ao enviar: {{ session('whatsappTestError') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.whatsapp-test') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1">Número (com DDD)</label>
                <input type="text" name="phone" value="{{ old('phone') }}" placeholder="11999998888"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                @error('phone') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Mensagem</label>
                <textarea name="message" rows="3" placeholder="Mensagem de teste do sistema"
                          class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">{{ old('message') }}</textarea>
                @error('message') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end">
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded text-sm font-medium transition-colors">
                    Enviar teste
                </button>
            </div>
        </form>
    </div>
```

- [ ] **Step 2: Rodar a suíte de testes de admin para garantir que a view renderiza sem erro**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/Admin`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add resources/views/admin/settings/edit.blade.php
git commit -m "feat: card de teste manual de WhatsApp na tela de configuracoes"
```

---

## Verificação manual (pós-implementação)

1. Rodar `php artisan serve`, logar como admin, acessar `/admin/settings`
2. Preencher número e mensagem no card "Testar WhatsApp" e enviar — confirmar mensagem de sucesso/erro aparece
3. Se a Evolution API real estiver configurada (`.env`), confirmar que a mensagem chega de fato no WhatsApp de teste
