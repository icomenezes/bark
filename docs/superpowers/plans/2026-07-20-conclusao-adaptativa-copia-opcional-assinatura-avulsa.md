# Conclusão adaptativa, cópia assinada opcional e API de assinatura avulsa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajustar a tela de conclusão de assinatura de envelope para não mencionar "quando todos assinarem" num envelope de signatário único; permitir que o remetente opte por não enviar a cópia final assinada a um signatário (caso de promissórias via API); e expor uma API nova para assinar um PDF avulso com um certificado do usuário, sem passar pelo fluxo de envelope.

**Architecture:** Três mudanças independentes no mesmo domínio de assinatura (`app/Http/Controllers/PublicSign`, `app/Services/Envelope`, `app/Jobs/SealEnvelopeJob.php`, `app/Http/Controllers/Api/V1`). A primeira é puramente de apresentação (sem migration). A segunda adiciona uma coluna booleana em `envelope_signers` e um curto-circuito nos dois pontos que enviam a notificação final. A terceira é um controller de API novo que reaproveita o motor de assinatura já usado pela tela `/sign-document` (`PdfSignerService::fromCertificate(...)->signExisting(...)`).

**Tech Stack:** Laravel 13, PHPUnit (`php artisan test`), MySQL local / SQLite `:memory:` em teste, Sanctum (API token), TCPDF/FPDI (geração de PDF em teste).

## Global Constraints

- Usar sempre o PHP do Laragon: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.
- Testes rodam em sqlite `:memory:` via `php artisan test` — nenhuma mudança deve depender do MySQL local.
- Seguir os nomes de evento/rota/namespace já estabelecidos (`Api\V1`, `public.sign.*`) — não introduzir convenções novas.
- Mensagens de erro e de UI ficam em pt-BR, como o resto do projeto (ver CLAUDE.md).
- Não alterar o comportamento do fluxo web `/sign-document` — a API nova é adicional, não uma refatoração dele.
- `send_signed_copy` nunca afeta o e-mail ao remetente (dono do envelope) — só a notificação ao signatário.

---

### Task 1: Mensagem de conclusão adaptativa no link público de assinatura

**Files:**
- Modify: `app/Http/Controllers/PublicSign/SignEnvelopeController.php:83-94` (método `store`)
- Modify: `tests/Feature/PublicSignFlowTest.php:75-90` (`test_link_signer_signs_without_otp`)
- Test: `tests/Feature/PublicSignFlowTest.php` (casos novos)

**Interfaces:**
- Consumes: `EnvelopeSigner::$channel` (`'email'|'whatsapp'`, já existe), `Envelope::allSigned()` (já existe em `app/Models/Envelope.php:54`), `$envelope->signers->count()` (relação já carregada via `$signer->envelope`).
- Produces: método privado `SignEnvelopeController::completionMessage(EnvelopeSigner $signer): array` retornando `['title' => string, 'message' => string]`, usado só dentro do controller.

- [ ] **Step 1: Escrever os testes que falham para os três cenários de mensagem**

Adicionar em `tests/Feature/PublicSignFlowTest.php`, substituindo o assert antigo em `test_link_signer_signs_without_otp` (linha 83-89) e acrescentando dois testes novos:

```php
    public function test_single_signer_envelope_shows_completion_message(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['auth_method' => 'link']);

        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Documento assinado com sucesso')
            ->assertDontSee('Quando todos assinarem');

        Queue::assertPushed(SealEnvelopeJob::class, 1);
    }

    public function test_multi_signer_envelope_mentions_email_when_pending(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $path = "users/{$envelope->user_id}/envelopes/{$envelope->id}/original.pdf";
        Storage::disk('documents')->put($path, '%PDF-1.4 fake');
        $envelope->update(['original_pdf_path' => $path]);

        $first = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'notified', 'auth_method' => 'link', 'channel' => 'email', 'sign_position' => 1,
        ]);
        EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'pending', 'auth_method' => 'link', 'channel' => 'email', 'sign_position' => 2,
        ]);

        $this->post("/sign/{$first->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Assinatura registrada')
            ->assertSee('por e-mail')
            ->assertDontSee('Documento assinado com sucesso');

        Queue::assertNotPushed(SealEnvelopeJob::class);
    }

    public function test_multi_signer_envelope_mentions_whatsapp_when_pending(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $path = "users/{$envelope->user_id}/envelopes/{$envelope->id}/original.pdf";
        Storage::disk('documents')->put($path, '%PDF-1.4 fake');
        $envelope->update(['original_pdf_path' => $path]);

        $first = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'notified', 'auth_method' => 'link', 'channel' => 'whatsapp',
            'whatsapp' => '11999998888', 'sign_position' => 1,
        ]);
        EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'pending', 'auth_method' => 'link', 'channel' => 'email', 'sign_position' => 2,
        ]);

        $this->post("/sign/{$first->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Assinatura registrada')
            ->assertSee('por WhatsApp');
    }
```

Também trocar, em `test_link_signer_signs_without_otp` (linha 83-89), o assert:

```php
        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Assinatura registrada');
```

por:

```php
        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Documento assinado com sucesso');
```

(esse teste usa `makeSentEnvelope()`, que cria um único signatário — o envelope fica completo assim que ele assina).

- [ ] **Step 2: Rodar os testes para confirmar que falham**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=PublicSignFlowTest
```

Expected: `test_single_signer_envelope_shows_completion_message`, `test_multi_signer_envelope_mentions_email_when_pending`, `test_multi_signer_envelope_mentions_whatsapp_when_pending` FALHAM (mensagem antiga ainda no controller); `test_link_signer_signs_without_otp` também FALHA (assert trocado).

- [ ] **Step 3: Implementar `completionMessage()` no controller**

Em `app/Http/Controllers/PublicSign/SignEnvelopeController.php`, substituir o trecho do método `store()`:

```php
        return view('public.sign.done', [
            'signer' => $signer->fresh(),
            'title' => 'Assinatura registrada!',
            'message' => 'Quando todos assinarem, você receberá o documento final por e-mail.',
        ]);
    }
```

por:

```php
        return view('public.sign.done', array_merge(
            ['signer' => $signer->fresh()],
            $this->completionMessage($signer->fresh())
        ));
    }
```

E adicionar o método privado (na seção "Privados", junto de `findSigner`/`unavailableReason`):

```php
    /** @return array{title: string, message: string} */
    private function completionMessage(EnvelopeSigner $signer): array
    {
        $envelope = $signer->envelope->fresh();

        if ($envelope->allSigned()) {
            return [
                'title' => 'Documento assinado com sucesso!',
                'message' => 'Obrigado por assinar. O documento está concluído.',
            ];
        }

        $channel = $signer->channel === 'whatsapp' ? 'WhatsApp' : 'e-mail';

        return [
            'title' => 'Assinatura registrada!',
            'message' => "Quando todos assinarem, você receberá o documento final por {$channel}.",
        ];
    }
```

- [ ] **Step 4: Rodar os testes e confirmar que passam**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=PublicSignFlowTest
```

Expected: PASS em todos os testes do arquivo.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PublicSign/SignEnvelopeController.php tests/Feature/PublicSignFlowTest.php
git commit -m "fix: mensagem de conclusao correta para envelope com um unico signatario"
```

---

### Task 2: Coluna `send_signed_copy` em `envelope_signers`

**Files:**
- Create: `database/migrations/2026_07_20_000001_add_send_signed_copy_to_envelope_signers_table.php`
- Modify: `app/Models/EnvelopeSigner.php:15-21` (`$fillable`)
- Modify: `database/factories/EnvelopeSignerFactory.php`
- Test: `tests/Feature/EnvelopeModelTest.php` (ou arquivo de teste de model equivalente — checar se existe teste de fillable/default; senão, cobrir via Task 3/4)

**Interfaces:**
- Consumes: nenhuma (task de fundação).
- Produces: `EnvelopeSigner::$send_signed_copy` (bool, default `true`, acessível como atributo Eloquent normal), usado pelas Tasks 3 e 4.

- [ ] **Step 1: Criar a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->boolean('send_signed_copy')->default(true)->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->dropColumn('send_signed_copy');
        });
    }
};
```

Salvar em `database/migrations/2026_07_20_000001_add_send_signed_copy_to_envelope_signers_table.php`.

- [ ] **Step 2: Adicionar o campo ao `$fillable` e cast do model**

Em `app/Models/EnvelopeSigner.php`, trocar:

```php
    protected $fillable = [
        'envelope_id', 'name', 'email', 'whatsapp', 'cpf', 'channel',
        'auth_method', 'sign_position', 'token', 'status',
        'signature_image_path', 'signature_type',
        'otp_code', 'otp_expires_at', 'otp_attempts',
        'signed_at', 'ip_address', 'user_agent', 'decline_reason',
    ];
```

por:

```php
    protected $fillable = [
        'envelope_id', 'name', 'email', 'whatsapp', 'cpf', 'channel', 'send_signed_copy',
        'auth_method', 'sign_position', 'token', 'status',
        'signature_image_path', 'signature_type',
        'otp_code', 'otp_expires_at', 'otp_attempts',
        'signed_at', 'ip_address', 'user_agent', 'decline_reason',
    ];
```

E no método `casts()` (linha 25-31), adicionar `'send_signed_copy' => 'boolean'`:

```php
    protected function casts(): array
    {
        return [
            'otp_expires_at' => 'datetime',
            'signed_at' => 'datetime',
            'send_signed_copy' => 'boolean',
        ];
    }
```

- [ ] **Step 3: Rodar as migrations em teste para confirmar que a coluna existe**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=EnvelopeServiceCreateTest
```

Expected: PASS (testes existentes continuam passando — a coluna nova tem default `true`, não quebra nada que já existia).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_20_000001_add_send_signed_copy_to_envelope_signers_table.php app/Models/EnvelopeSigner.php
git commit -m "feat: adicionar send_signed_copy a envelope_signers"
```

---

### Task 3: `send_signed_copy` propagado na criação e respeitado no envio final

**Files:**
- Modify: `app/Services/Envelope/EnvelopeService.php:44-54` (método `create`)
- Modify: `app/Services/Envelope/EnvelopeService.php:206-221` (método `notifyCompletion`)
- Modify: `app/Jobs/SealEnvelopeJob.php:83-93` (loop de notificação final)
- Modify: `app/Http/Controllers/Api/V1/EnvelopeApiController.php:40-71` (`store`)
- Test: `tests/Feature/EnvelopeServiceCreateTest.php`, `tests/Feature/SealEnvelopeJobTest.php`, `tests/Feature/Api/EnvelopeApiControllerTest.php`

**Interfaces:**
- Consumes: `EnvelopeSigner::$send_signed_copy` (Task 2).
- Produces: nenhuma interface nova exposta a outras tasks — mudança de comportamento interna.

- [ ] **Step 1: Escrever o teste que falha em `EnvelopeServiceCreateTest`**

Adicionar em `tests/Feature/EnvelopeServiceCreateTest.php`:

```php
    public function test_create_defaults_send_signed_copy_to_true_when_omitted(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $envelope = $this->makeEnvelope($user);

        $this->assertTrue($envelope->signers->first()->send_signed_copy);
    }

    public function test_create_respects_send_signed_copy_false(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $pdf = UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 fake');

        $envelope = app(EnvelopeService::class)->create($user, $pdf, [
            'title' => 'Nota Promissória',
            'signing_order' => 'parallel',
            'signers' => [
                ['name' => 'Ana', 'email' => 'ana@x.com', 'channel' => 'email', 'auth_method' => 'link',
                 'send_signed_copy' => false,
                 'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
            ],
        ]);

        $this->assertFalse($envelope->signers->first()->send_signed_copy);
    }
```

- [ ] **Step 2: Rodar para confirmar que falha**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=EnvelopeServiceCreateTest
```

Expected: os dois testes novos FALHAM (`send_signed_copy` sempre `true` porque `create()` ainda não lê a chave — na verdade o default `true` da migration faz o primeiro teste passar "por acidente"; o segundo teste, que espera `false`, FALHA).

- [ ] **Step 3: Propagar `send_signed_copy` em `EnvelopeService::create()`**

Em `app/Services/Envelope/EnvelopeService.php`, trocar o bloco (linhas 44-54):

```php
            foreach (array_values($data['signers']) as $i => $s) {
                $signer = $envelope->signers()->create([
                    'name' => $s['name'],
                    'email' => $s['email'] ?? null,
                    'whatsapp' => $s['whatsapp'] ?? null,
                    'channel' => $s['channel'] ?? 'email',
                    'auth_method' => $s['auth_method'],
                    'sign_position' => $i + 1,
                ]);
                $signer->fields()->createMany($s['fields']);
            }
```

por:

```php
            foreach (array_values($data['signers']) as $i => $s) {
                $signer = $envelope->signers()->create([
                    'name' => $s['name'],
                    'email' => $s['email'] ?? null,
                    'whatsapp' => $s['whatsapp'] ?? null,
                    'channel' => $s['channel'] ?? 'email',
                    'auth_method' => $s['auth_method'],
                    'sign_position' => $i + 1,
                    'send_signed_copy' => $s['send_signed_copy'] ?? true,
                ]);
                $signer->fields()->createMany($s['fields']);
            }
```

- [ ] **Step 4: Rodar para confirmar que passa**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=EnvelopeServiceCreateTest
```

Expected: PASS.

- [ ] **Step 5: Escrever o teste que falha para `SealEnvelopeJob` pulando o signatário sem cópia**

Adicionar em `tests/Feature/SealEnvelopeJobTest.php`:

```php
    public function test_skips_completion_email_for_signer_with_send_signed_copy_false(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $this->configureRealPlatformCertificate();
        $envelope = $this->makeSignedEnvelope();
        $envelope->signers()->update(['send_signed_copy' => false]);

        (new SealEnvelopeJob($envelope))->handle(
            app(\App\Services\Envelope\EvidenceReportGenerator::class),
            app(\App\Services\Envelope\EnvelopePdfComposer::class),
            app(\App\Services\Envelope\EnvelopeService::class),
            app(NotificationService::class),
        );

        // só o remetente recebe — o único signatário tem send_signed_copy=false
        Mail::assertSent(EnvelopeCompleted::class, 1);
    }
```

- [ ] **Step 6: Rodar para confirmar que falha**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=SealEnvelopeJobTest
```

Expected: `test_skips_completion_email_for_signer_with_send_signed_copy_false` FALHA (`EnvelopeCompleted` enviado 2 vezes, não 1).

- [ ] **Step 7: Implementar o skip em `SealEnvelopeJob` e `EnvelopeService::notifyCompletion`**

Em `app/Jobs/SealEnvelopeJob.php`, trocar (linhas 84-93):

```php
            foreach ($envelope->signers as $signer) {
                if ($signer->channel === 'whatsapp') {
                    $notification->sendWhatsAppTo($signer->whatsapp,
                        "✅ *Documento assinado* — O documento *{$envelope->title}* foi completado e assinado por todos.\n".
                        'Acesse para download: '.route('public.sign.download', $signer->token)
                    );
                } else {
                    Mail::to($signer->email)->send(new EnvelopeCompleted($envelope, $signer));
                }
            }
```

por:

```php
            foreach ($envelope->signers as $signer) {
                if (! $signer->send_signed_copy) {
                    continue;
                }

                if ($signer->channel === 'whatsapp') {
                    $notification->sendWhatsAppTo($signer->whatsapp,
                        "✅ *Documento assinado* — O documento *{$envelope->title}* foi completado e assinado por todos.\n".
                        'Acesse para download: '.route('public.sign.download', $signer->token)
                    );
                } else {
                    Mail::to($signer->email)->send(new EnvelopeCompleted($envelope, $signer));
                }
            }
```

Em `app/Services/Envelope/EnvelopeService.php`, trocar o método `notifyCompletion()` (linhas 207-221):

```php
    public function notifyCompletion(Envelope $envelope): void
    {
        Mail::to($envelope->user->email)->send(new \App\Mail\Envelopes\EnvelopeCompleted($envelope));
        foreach ($envelope->signers as $signer) {
            if ($signer->channel === 'whatsapp') {
                $downloadUrl = route('public.sign.document', $signer->token);
                $this->notification->sendWhatsAppTo($signer->whatsapp,
                    "✅ *Documento assinado* — O documento *{$envelope->title}* foi completado e assinado por todos.\n".
                    "Acesse: {$downloadUrl}"
                );
            } else {
                Mail::to($signer->email)->send(new \App\Mail\Envelopes\EnvelopeCompleted($envelope, $signer));
            }
        }
    }
```

por:

```php
    public function notifyCompletion(Envelope $envelope): void
    {
        Mail::to($envelope->user->email)->send(new \App\Mail\Envelopes\EnvelopeCompleted($envelope));
        foreach ($envelope->signers as $signer) {
            if (! $signer->send_signed_copy) {
                continue;
            }

            if ($signer->channel === 'whatsapp') {
                $downloadUrl = route('public.sign.document', $signer->token);
                $this->notification->sendWhatsAppTo($signer->whatsapp,
                    "✅ *Documento assinado* — O documento *{$envelope->title}* foi completado e assinado por todos.\n".
                    "Acesse: {$downloadUrl}"
                );
            } else {
                Mail::to($signer->email)->send(new \App\Mail\Envelopes\EnvelopeCompleted($envelope, $signer));
            }
        }
    }
```

- [ ] **Step 8: Rodar para confirmar que passa**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=SealEnvelopeJobTest
```

Expected: PASS em todos.

- [ ] **Step 9: Escrever o teste que falha para o parâmetro `send_signed_copy` na API**

Adicionar em `tests/Feature/Api/EnvelopeApiControllerTest.php`:

```php
    public function test_send_signed_copy_defaults_to_true(): void
    {
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate();
        $user = $this->userWithPlan();
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/envelopes', $this->validPayload());

        $this->assertTrue(Envelope::first()->signers->first()->send_signed_copy);
    }

    public function test_send_signed_copy_false_is_persisted(): void
    {
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate();
        $user = $this->userWithPlan();
        $token = $user->createToken('api')->plainTextToken;

        $payload = array_merge($this->validPayload(), ['send_signed_copy' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/envelopes', $payload)
            ->assertCreated();

        $this->assertFalse(Envelope::first()->signers->first()->send_signed_copy);
    }
```

- [ ] **Step 10: Rodar para confirmar que falha**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=EnvelopeApiControllerTest
```

Expected: `test_send_signed_copy_false_is_persisted` FALHA (`send_signed_copy` continua `true`, o payload não é lido pelo controller).

- [ ] **Step 11: Aceitar `send_signed_copy` no payload da API**

Em `app/Http/Controllers/Api/V1/EnvelopeApiController.php`, no método `store()`, trocar o bloco de validação (linhas 40-47):

```php
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'signer_name' => ['required', 'string', 'max:255'],
            'signer_email' => ['required', 'email'],
            'signer_whatsapp' => ['nullable', 'string', 'max:20'],
            'pdf_base64' => ['required', 'string'],
        ]);
```

por:

```php
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'signer_name' => ['required', 'string', 'max:255'],
            'signer_email' => ['required', 'email'],
            'signer_whatsapp' => ['nullable', 'string', 'max:20'],
            'send_signed_copy' => ['nullable', 'boolean'],
            'pdf_base64' => ['required', 'string'],
        ]);
```

E no array `signers` passado a `$this->envelopes->create(...)` (linhas 60-70), trocar:

```php
                'signers' => [
                    [
                        'name' => $request->input('signer_name'),
                        'email' => $request->input('signer_email'),
                        'whatsapp' => $request->input('signer_whatsapp'),
                        'auth_method' => 'link',
                        'fields' => [
                            ['page' => $pageCount, 'x' => 350, 'y' => 750, 'w' => 150, 'h' => 50],
                        ],
                    ],
                ],
```

por:

```php
                'signers' => [
                    [
                        'name' => $request->input('signer_name'),
                        'email' => $request->input('signer_email'),
                        'whatsapp' => $request->input('signer_whatsapp'),
                        'auth_method' => 'link',
                        'send_signed_copy' => $request->boolean('send_signed_copy', true),
                        'fields' => [
                            ['page' => $pageCount, 'x' => 350, 'y' => 750, 'w' => 150, 'h' => 50],
                        ],
                    ],
                ],
```

- [ ] **Step 12: Rodar para confirmar que passa**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=EnvelopeApiControllerTest
```

Expected: PASS em todos.

- [ ] **Step 13: Commit**

```bash
git add app/Services/Envelope/EnvelopeService.php app/Jobs/SealEnvelopeJob.php app/Http/Controllers/Api/V1/EnvelopeApiController.php tests/Feature/EnvelopeServiceCreateTest.php tests/Feature/SealEnvelopeJobTest.php tests/Feature/Api/EnvelopeApiControllerTest.php
git commit -m "feat: parametro send_signed_copy na API de envelopes"
```

---

### Task 4: API de assinatura avulsa (`POST /api/v1/sign-document`)

**Files:**
- Create: `app/Http/Controllers/Api/V1/SignDocumentApiController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/SignDocumentApiControllerTest.php`

**Interfaces:**
- Consumes: `PdfSignerService::fromCertificate(Certificate $certificate): static` e `->signExisting(string $pdfPath, bool $initialAllPages, array $position, bool $useTsa): string` (já existem, `app/Services/Pdf/PdfSignerService.php:31` e `:124`); `PdfSignerService::moveToDisk(string $localRelativePath, string $targetDisk, string $targetRelativePath): string` (`:102`); `PdfSignerService::engine(): string` (`:92`); `AccessLogService::log(User $user, string $event, array $meta = []): void` (`app/Services/AccessLogService.php:14`); `UsageLimitService::canSignPdf(User $user): array` (`app/Services/UsageLimitService.php:12`, retorna `['allowed' => bool, 'reason' => ?string]`); `User::certificates(): HasMany`, `User::signingCertificate(): BelongsTo` (`app/Models/User.php:68,73`); `Certificate::isExpired(): bool` (`app/Models/Certificate.php:39`).
- Produces: rota `POST /api/v1/sign-document`, resposta JSON `{"status": "signed", "download_url": string}` ou `{"message": string}` com HTTP 422.

- [ ] **Step 1: Escrever os testes que falham**

Criar `tests/Feature/Api/SignDocumentApiControllerTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Certificate;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeneratesPfx;
use Tests\TestCase;

class SignDocumentApiControllerTest extends TestCase
{
    use GeneratesPfx, RefreshDatabase;

    private function makeSourcePdfBase64(): string
    {
        $pdf = new \TCPDF;
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Documento avulso de teste');
        $path = tempnam(sys_get_temp_dir(), 'src_').'.pdf';
        $pdf->Output($path, 'F');

        $content = base64_encode(file_get_contents($path));
        @unlink($path);

        return $content;
    }

    private function userWithPlan(): User
    {
        $plan = Plan::factory()->create();

        return User::factory()->create(['role' => 'client', 'plan_id' => $plan->id]);
    }

    /** Certificado REAL via controller de certificados, próprio do usuário. */
    private function attachRealCertificate(User $user, bool $asDefault = true): Certificate
    {
        $this->actingAs($user)->post('/certificates', [
            'description' => 'Cert de teste',
            'pfx' => new UploadedFile($this->generatePfx('secret'), 'cert.pfx', 'application/octet-stream', null, true),
            'password' => 'secret',
        ]);
        auth()->logout();

        $certificate = Certificate::where('user_id', $user->id)->latest('id')->first();
        if ($asDefault) {
            $user->update(['signing_certificate_id' => $certificate->id]);
        }

        return $certificate;
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/sign-document', ['pdf_base64' => $this->makeSourcePdfBase64()])
            ->assertUnauthorized();
    }

    public function test_signs_using_users_default_certificate(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $user = $this->userWithPlan();
        $this->attachRealCertificate($user);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', ['pdf_base64' => $this->makeSourcePdfBase64()]);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'download_url']);
        $response->assertJson(['status' => 'signed']);
        $this->assertNotNull($response->json('download_url'));
    }

    public function test_signs_using_explicit_certificate_id(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $user = $this->userWithPlan();
        // certificado padrão diferente do que será usado explicitamente
        $this->attachRealCertificate($user, asDefault: true);
        $explicit = $this->attachRealCertificate($user, asDefault: false);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', [
                'pdf_base64' => $this->makeSourcePdfBase64(),
                'certificate_id' => $explicit->id,
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'signed']);
    }

    public function test_rejects_certificate_belonging_to_another_user(): void
    {
        $user = $this->userWithPlan();
        $other = $this->userWithPlan();
        $othersCertificate = $this->attachRealCertificate($other);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', [
                'pdf_base64' => $this->makeSourcePdfBase64(),
                'certificate_id' => $othersCertificate->id,
            ]);

        $response->assertUnprocessable();
    }

    public function test_requires_a_certificate_when_none_configured(): void
    {
        $user = $this->userWithPlan();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', ['pdf_base64' => $this->makeSourcePdfBase64()]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('certificado', $response->json('message'));
    }

    public function test_rejects_invalid_base64_pdf(): void
    {
        $user = $this->userWithPlan();
        $this->attachRealCertificate($user);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', ['pdf_base64' => base64_encode('not a pdf')]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['pdf_base64']);
    }

    public function test_blocked_when_monthly_pdf_limit_reached(): void
    {
        $plan = Plan::factory()->create(['max_pdfs_per_month' => 0]);
        $user = User::factory()->create(['role' => 'client', 'plan_id' => $plan->id]);
        $this->attachRealCertificate($user);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', ['pdf_base64' => $this->makeSourcePdfBase64()]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('limite', $response->json('message'));
    }

    public function test_accepts_custom_field_position(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $user = $this->userWithPlan();
        $this->attachRealCertificate($user);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', [
                'pdf_base64' => $this->makeSourcePdfBase64(),
                'field' => ['page' => 1, 'x' => 50, 'y' => 100, 'w' => 80, 'h' => 30],
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'signed']);
    }
}
```

- [ ] **Step 2: Rodar para confirmar que falha**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=SignDocumentApiControllerTest
```

Expected: FAIL em todos (404 — rota e controller ainda não existem).

- [ ] **Step 3: Criar o controller**

Criar `app/Http/Controllers/Api/V1/SignDocumentApiController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\User;
use App\Services\AccessLogService;
use App\Services\Pdf\PdfSignerService;
use App\Services\UsageLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Tcpdf\Fpdi;

class SignDocumentApiController extends Controller
{
    public function __construct(
        private AccessLogService $accessLog,
        private UsageLimitService $usageLimit,
    ) {}

    public function store(Request $request)
    {
        $user = $request->user();

        $usage = $this->usageLimit->canSignPdf($user);
        if (! $usage['allowed']) {
            return $this->unprocessable($usage['reason']);
        }

        $request->validate([
            'pdf_base64' => ['required', 'string'],
            'certificate_id' => ['nullable', 'integer'],
            'field' => ['nullable', 'array'],
            'field.page' => ['nullable', 'integer', 'min:1'],
            'field.x' => ['nullable', 'numeric', 'min:0'],
            'field.y' => ['nullable', 'numeric', 'min:0'],
            'field.w' => ['nullable', 'numeric', 'min:1'],
            'field.h' => ['nullable', 'numeric', 'min:1'],
        ]);

        $certificate = $this->resolveCertificate($user, $request->input('certificate_id'));
        if ($certificate instanceof \Illuminate\Http\JsonResponse) {
            return $certificate;
        }

        $pdfPath = $this->decodeBase64Pdf($request->input('pdf_base64'));

        try {
            $pageCount = (new Fpdi)->setSourceFile($pdfPath);
            $position = $this->resolvePosition($request->input('field', []), $pageCount);

            $signer = PdfSignerService::fromCertificate($certificate);
            $relative = $signer->signExisting($pdfPath, initialAllPages: false, position: $position, useTsa: false);

            $targetPath = "users/{$user->id}/signed/".basename($relative);
            $signer->moveToDisk($relative, 'documents', $targetPath);

            $this->accessLog->log($user, 'document_signed', [
                'certificate_id' => $certificate->id,
                'certificate_description' => $certificate->description,
                'engine' => $signer->engine(),
                'file' => basename($targetPath),
                'original_name' => 'documento.pdf',
                'source' => 'api',
            ]);
        } catch (\RuntimeException $e) {
            return $this->unprocessable($e->getMessage());
        } finally {
            @unlink($pdfPath);
        }

        $disk = Storage::disk('documents');

        return response()->json([
            'status' => 'signed',
            'download_url' => $disk->temporaryUrl($targetPath, now()->addMinutes(5), [
                'ResponseContentDisposition' => 'attachment; filename="documento-assinado.pdf"',
            ]),
        ]);
    }

    /** @return Certificate|JsonResponse */
    private function resolveCertificate(User $user, ?int $certificateId)
    {
        if ($certificateId !== null) {
            $certificate = $user->certificates()->find($certificateId);
            if ($certificate === null) {
                return $this->unprocessable('Certificado não encontrado ou não pertence a este usuário.');
            }
        } else {
            $certificate = $user->signingCertificate;
            if ($certificate === null) {
                return $this->unprocessable('Nenhum certificado configurado — informe certificate_id ou cadastre um certificado padrão em Certificados.');
            }
        }

        if ($certificate->isExpired()) {
            return $this->unprocessable('Certificado expirado em '.$certificate->expires_at->format('d/m/Y').'.');
        }

        return $certificate;
    }

    /** @return array{page:int,x:float,y:float,w:float,h:float} */
    private function resolvePosition(array $field, int $pageCount): array
    {
        return [
            'page' => min($pageCount, max(1, (int) ($field['page'] ?? $pageCount))),
            'x' => (float) ($field['x'] ?? 350),
            'y' => (float) ($field['y'] ?? 750),
            'w' => (float) ($field['w'] ?? 150),
            'h' => (float) ($field['h'] ?? 50),
        ];
    }

    /** Decodifica o base64 recebido, valida que é um PDF de verdade, e grava em arquivo temporário. */
    private function decodeBase64Pdf(string $base64): string
    {
        $content = base64_decode($base64, true);

        if ($content === false || ! str_starts_with($content, '%PDF-')) {
            throw ValidationException::withMessages([
                'pdf_base64' => 'O arquivo enviado não é um PDF válido.',
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'api_pdf_').'.pdf';
        file_put_contents($path, $content);

        return $path;
    }

    private function unprocessable(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
```

- [ ] **Step 4: Registrar a rota**

Em `routes/api.php`, trocar:

```php
<?php

use App\Http\Controllers\Api\V1\EnvelopeApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('envelopes', [EnvelopeApiController::class, 'store']);
    Route::get('envelopes/{envelope}', [EnvelopeApiController::class, 'show']);
});
```

por:

```php
<?php

use App\Http\Controllers\Api\V1\EnvelopeApiController;
use App\Http\Controllers\Api\V1\SignDocumentApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('envelopes', [EnvelopeApiController::class, 'store']);
    Route::get('envelopes/{envelope}', [EnvelopeApiController::class, 'show']);
    Route::post('sign-document', [SignDocumentApiController::class, 'store']);
});
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test --filter=SignDocumentApiControllerTest
```

Expected: PASS em todos os 8 testes.

- [ ] **Step 6: Rodar a suíte completa para checar que nada quebrou**

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test
```

Expected: PASS em toda a suíte (nenhuma regressão nos testes de `SignDocumentController`, `EnvelopeApiControllerTest`, `PublicSignFlowTest`, `SealEnvelopeJobTest`).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/SignDocumentApiController.php routes/api.php tests/Feature/Api/SignDocumentApiControllerTest.php
git commit -m "feat: API de assinatura de PDF avulso (sem envelope)"
```

---

## Verificação final

- [ ] Rodar a suíte inteira uma última vez:

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
& $php artisan test
```

Expected: todos os testes PASS, incluindo os 4 arquivos tocados (`PublicSignFlowTest`, `EnvelopeServiceCreateTest`, `SealEnvelopeJobTest`, `EnvelopeApiControllerTest`) e o novo `SignDocumentApiControllerTest`.
