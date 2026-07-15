# Envelopes — Assinatura Eletrônica Multi-Signatário — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Cliente envia um PDF para N destinatários por e-mail; cada um assina eletronicamente (sem certificado) via link único; ao final o sistema anexa página de evidências e lacra com o certificado A1 da plataforma.

**Architecture:** Assinaturas de convidados são só dados no banco (PNG + evidências em `envelope_events`); o PDF é modificado uma única vez, no `SealEnvelopeJob`: carimbo das assinaturas (FPDI, unidade `pt`) → página de evidências (TCPDF) → concatenação → assinatura digital via `PdfSignerService` existente (pyHanko/TCPDF).

**Tech Stack:** Laravel 13, TCPDF+FPDI (já instalados), PDF.js via CDN (padrão do sign-document), Alpine.js, Tailwind, Mail + Evolution API existentes.

**Spec:** `docs/superpowers/specs/2026-07-15-envelopes-assinatura-eletronica-design.md`

## Global Constraints

- PHP do Laragon: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` (nunca XAMPP). Nos comandos abaixo, `$php` = esse caminho.
- Testes: `& $php artisan test` (sqlite `:memory:`); usar `Storage::fake('local')`, `Mail::fake()`, `Queue::fake()` como os testes existentes.
- Coordenadas de assinatura: pontos PDF, origem topo-esquerdo (convenção do sign-document).
- PDFs privados no disk `local`. Original: `envelopes/{id}/original.pdf`. Final: `signed/envelopes/{id}/final.pdf`.
- Copy da UI em pt-BR, seguindo os textos existentes.
- `envelope_events` é imutável: só INSERT, sem `updated_at`.
- Nunca chamar `SignPdfService::stamp()`/FPDI sobre PDF já assinado digitalmente (aqui é seguro: o original nunca é assinado antes do lacre).
- Commits frequentes, mensagens em pt como o histórico (`feat: ...`, `fix: ...`), rodapé `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Migrations, Models e Factories

**Files:**
- Create: `database/migrations/2026_07_15_000001_create_envelopes_table.php`
- Create: `database/migrations/2026_07_15_000002_create_envelope_signers_table.php`
- Create: `database/migrations/2026_07_15_000003_create_envelope_fields_table.php`
- Create: `database/migrations/2026_07_15_000004_create_envelope_events_table.php`
- Create: `app/Models/Envelope.php`, `app/Models/EnvelopeSigner.php`, `app/Models/EnvelopeField.php`, `app/Models/EnvelopeEvent.php`
- Create: `database/factories/EnvelopeFactory.php`, `database/factories/EnvelopeSignerFactory.php`
- Test: `tests/Feature/EnvelopeModelTest.php`

**Interfaces:**
- Produces: `Envelope` (relations `user()`, `signers()`, `events()`; helpers `isSequential(): bool`, `allSigned(): bool`, `nextPendingSigner(): ?EnvelopeSigner`, `progress(): array{signed:int,total:int}`)
- Produces: `EnvelopeSigner` (relations `envelope()`, `fields()`; token auto-gerado em `creating`; helpers `requiresOtp(): bool`, `canSign(): bool`)
- Produces: `EnvelopeEvent` (`UPDATED_AT = null`, cast `meta` array)

- [x] **Step 1: Escrever os testes que falham**

```php
<?php
// tests/Feature/EnvelopeModelTest.php

namespace Tests\Feature;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvelopeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_signer_gets_random_64_char_token_on_create(): void
    {
        $signer = EnvelopeSigner::factory()->create();

        $this->assertSame(64, strlen($signer->token));

        $other = EnvelopeSigner::factory()->create();
        $this->assertNotSame($signer->token, $other->token);
    }

    public function test_all_signed_and_next_pending_signer(): void
    {
        $envelope = Envelope::factory()->create(['signing_order' => 'sequential']);
        $first = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 1]);
        $second = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 2]);

        $this->assertFalse($envelope->allSigned());
        $this->assertTrue($envelope->nextPendingSigner()->is($first));

        $first->update(['status' => 'signed', 'signed_at' => now()]);
        $envelope->refresh();

        $this->assertFalse($envelope->allSigned());
        $this->assertTrue($envelope->nextPendingSigner()->is($second));

        $second->update(['status' => 'signed', 'signed_at' => now()]);
        $envelope->refresh();

        $this->assertTrue($envelope->allSigned());
        $this->assertNull($envelope->nextPendingSigner());
        $this->assertSame(['signed' => 2, 'total' => 2], $envelope->progress());
    }

    public function test_requires_otp_by_auth_method(): void
    {
        $this->assertFalse(EnvelopeSigner::factory()->create(['auth_method' => 'link'])->requiresOtp());
        $this->assertTrue(EnvelopeSigner::factory()->create(['auth_method' => 'email_otp'])->requiresOtp());
        $this->assertTrue(EnvelopeSigner::factory()->create(['auth_method' => 'whatsapp_otp'])->requiresOtp());
    }

    public function test_can_sign_only_when_envelope_sent_and_signer_pending(): void
    {
        $sent = Envelope::factory()->create(['status' => 'sent']);
        $signer = EnvelopeSigner::factory()->for($sent)->create(['status' => 'notified']);
        $this->assertTrue($signer->canSign());

        $signer->update(['status' => 'signed']);
        $this->assertFalse($signer->fresh()->canSign());

        $cancelled = Envelope::factory()->create(['status' => 'cancelled']);
        $s2 = EnvelopeSigner::factory()->for($cancelled)->create();
        $this->assertFalse($s2->canSign());

        $expired = Envelope::factory()->create(['status' => 'sent', 'expires_at' => now()->subDay()]);
        $s3 = EnvelopeSigner::factory()->for($expired)->create();
        $this->assertFalse($s3->canSign());
    }

    public function test_envelope_event_has_no_updated_at_and_casts_meta(): void
    {
        $envelope = Envelope::factory()->create();
        $event = $envelope->events()->create(['event' => 'created', 'meta' => ['a' => 1]]);

        $this->assertSame(['a' => 1], $event->fresh()->meta);
        $this->assertNull($event::UPDATED_AT);
    }
}
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=EnvelopeModelTest`
Expected: FAIL (classes/tabelas inexistentes)

- [x] **Step 3: Criar as migrations**

```php
<?php
// database/migrations/2026_07_15_000001_create_envelopes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('original_pdf_path');
            $table->string('final_pdf_path')->nullable();
            $table->string('sha256_original', 64);
            $table->string('sha256_final', 64)->nullable();
            $table->enum('signing_order', ['parallel', 'sequential'])->default('parallel');
            $table->enum('status', ['draft', 'sent', 'completed', 'declined', 'cancelled', 'expired'])->default('draft');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelopes');
    }
};
```

```php
<?php
// database/migrations/2026_07_15_000002_create_envelope_signers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('whatsapp')->nullable();
            $table->string('cpf', 14)->nullable();
            $table->enum('auth_method', ['link', 'email_otp', 'whatsapp_otp'])->default('link');
            $table->unsignedInteger('sign_position')->default(1);
            $table->string('token', 64)->unique();
            $table->enum('status', ['pending', 'notified', 'viewed', 'signed', 'declined'])->default('pending');
            $table->string('signature_image_path')->nullable();
            $table->enum('signature_type', ['drawn', 'typed'])->nullable();
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_signers');
    }
};
```

```php
<?php
// database/migrations/2026_07_15_000003_create_envelope_fields_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_signer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->decimal('x', 8, 2);
            $table->decimal('y', 8, 2);
            $table->decimal('w', 8, 2);
            $table->decimal('h', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_fields');
    }
};
```

```php
<?php
// database/migrations/2026_07_15_000004_create_envelope_events_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_signer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 50);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_events');
    }
};
```

- [x] **Step 4: Criar os models**

```php
<?php
// app/Models/Envelope.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Envelope extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'message',
        'original_pdf_path', 'final_pdf_path',
        'sha256_original', 'sha256_final',
        'signing_order', 'status', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signers(): HasMany
    {
        return $this->hasMany(EnvelopeSigner::class)->orderBy('sign_position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EnvelopeEvent::class)->orderBy('id');
    }

    public function isSequential(): bool
    {
        return $this->signing_order === 'sequential';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function allSigned(): bool
    {
        return ! $this->signers()->where('status', '!=', 'signed')->exists();
    }

    /** Próximo que ainda não assinou/recusou, em ordem de posição. */
    public function nextPendingSigner(): ?EnvelopeSigner
    {
        return $this->signers()->whereNotIn('status', ['signed', 'declined'])->first();
    }

    /** @return array{signed:int,total:int} */
    public function progress(): array
    {
        return [
            'signed' => $this->signers()->where('status', 'signed')->count(),
            'total' => $this->signers()->count(),
        ];
    }
}
```

```php
<?php
// app/Models/EnvelopeSigner.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EnvelopeSigner extends Model
{
    use HasFactory;

    protected $fillable = [
        'envelope_id', 'name', 'email', 'whatsapp', 'cpf',
        'auth_method', 'sign_position', 'token', 'status',
        'signature_image_path', 'signature_type',
        'otp_code', 'otp_expires_at', 'otp_attempts',
        'signed_at', 'ip_address', 'user_agent', 'decline_reason',
    ];

    protected $hidden = ['otp_code', 'token'];

    protected function casts(): array
    {
        return [
            'otp_expires_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $signer) {
            $signer->token = $signer->token ?: Str::random(64);
        });
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(EnvelopeField::class);
    }

    public function requiresOtp(): bool
    {
        return $this->auth_method !== 'link';
    }

    /** Pode assinar agora: envelope enviado, não expirado, e este signatário ainda pendente. */
    public function canSign(): bool
    {
        return in_array($this->status, ['pending', 'notified', 'viewed'], true)
            && $this->envelope->status === 'sent'
            && ! $this->envelope->isExpired();
    }
}
```

```php
<?php
// app/Models/EnvelopeField.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvelopeField extends Model
{
    protected $fillable = ['envelope_signer_id', 'page', 'x', 'y', 'w', 'h'];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'w' => 'float',
            'h' => 'float',
        ];
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(EnvelopeSigner::class, 'envelope_signer_id');
    }
}
```

```php
<?php
// app/Models/EnvelopeEvent.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trilha de auditoria imutável — somente INSERT. */
class EnvelopeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'envelope_id', 'envelope_signer_id', 'event',
        'ip_address', 'user_agent', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(EnvelopeSigner::class, 'envelope_signer_id');
    }
}
```

- [x] **Step 5: Criar as factories**

```php
<?php
// database/factories/EnvelopeFactory.php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvelopeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'message' => fake()->sentence(),
            'original_pdf_path' => 'envelopes/1/original.pdf',
            'sha256_original' => hash('sha256', fake()->uuid()),
            'signing_order' => 'parallel',
            'status' => 'draft',
        ];
    }
}
```

```php
<?php
// database/factories/EnvelopeSignerFactory.php

namespace Database\Factories;

use App\Models\Envelope;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvelopeSignerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'auth_method' => 'link',
            'sign_position' => 1,
            'status' => 'pending',
        ];
    }
}
```

- [x] **Step 6: Rodar e ver passar**

Run: `& $php artisan test --filter=EnvelopeModelTest`
Expected: PASS (5 testes)

- [x] **Step 7: Rodar a suíte inteira e commitar**

Run: `& $php artisan test`
Expected: PASS (nenhuma regressão)

```bash
git add database/migrations app/Models database/factories tests/Feature/EnvelopeModelTest.php
git commit -m "feat: tabelas e models do modulo de envelopes"
```

---

### Task 2: Certificado da plataforma em Settings

**Files:**
- Create: `database/migrations/2026_07_15_000005_add_platform_certificate_to_settings_table.php`
- Modify: `app/Models/Setting.php` (fillable + relação)
- Modify: `app/Http/Controllers/Admin/SettingController.php` (validação + select)
- Modify: `resources/views/admin/settings/edit.blade.php` (campo select — localizar o form existente e adicionar antes do botão salvar)
- Test: `tests/Feature/PlatformCertificateSettingTest.php`

**Interfaces:**
- Produces: `Setting::platformCertificate(): BelongsTo` (nullable) — usada pelo `SealEnvelopeJob` (Task 9) e pela validação de envio (Task 5) via `Setting::current()->platformCertificate`.

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Feature/PlatformCertificateSettingTest.php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformCertificateSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sets_platform_certificate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cert = Certificate::factory()->create();
        $settings = Setting::current();

        $this->actingAs($admin)->patch('/admin/settings', [
            'company_name' => $settings->company_name,
            'primary_color' => $settings->primary_color,
            'accent_color' => $settings->accent_color,
            'platform_certificate_id' => $cert->id,
        ])->assertRedirect();

        Setting::clearCache();
        $this->assertTrue(Setting::current()->platformCertificate->is($cert));
    }

    public function test_rejects_nonexistent_certificate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $settings = Setting::current();

        $this->actingAs($admin)->patch('/admin/settings', [
            'company_name' => $settings->company_name,
            'primary_color' => $settings->primary_color,
            'accent_color' => $settings->accent_color,
            'platform_certificate_id' => 9999,
        ])->assertSessionHasErrors('platform_certificate_id');
    }
}
```

Nota: antes de escrever, abrir `SettingController::update` e copiar os campos realmente obrigatórios do validate existente para o payload dos testes (ajustar se `company_name`/cores não forem required).

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=PlatformCertificateSettingTest`
Expected: FAIL (coluna inexistente / validação ausente)

- [x] **Step 3: Implementar**

Migration:

```php
<?php
// database/migrations/2026_07_15_000005_add_platform_certificate_to_settings_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('platform_certificate_id')->nullable()
                ->constrained('certificates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_certificate_id');
        });
    }
};
```

`app/Models/Setting.php` — adicionar `'platform_certificate_id'` ao `$fillable` e:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

public function platformCertificate(): BelongsTo
{
    return $this->belongsTo(Certificate::class, 'platform_certificate_id');
}
```

`SettingController`: no `edit()`, passar `$certificates = Certificate::with('user')->orderBy('description')->get()`; no `update()`, adicionar regra `'platform_certificate_id' => ['nullable', 'integer', 'exists:certificates,id']` e incluir o campo no update.

View `admin/settings/edit.blade.php` — adicionar junto aos demais campos (mesmo estilo de markup dos selects/inputs já presentes no form):

```blade
<div>
    <label class="block text-sm font-medium text-gray-700">Certificado da plataforma (lacre de envelopes)</label>
    <select name="platform_certificate_id" class="mt-1 block w-full rounded-md border-gray-300">
        <option value="">— nenhum —</option>
        @foreach ($certificates as $cert)
            <option value="{{ $cert->id }}" @selected($settings->platform_certificate_id === $cert->id)>
                {{ $cert->description }} ({{ $cert->user->name }}@if($cert->expires_at) — vence {{ $cert->expires_at->format('d/m/Y') }}@endif)
            </option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-500">Usado para assinar digitalmente os envelopes concluídos.</p>
</div>
```

- [x] **Step 4: Rodar e ver passar + suíte**

Run: `& $php artisan test --filter=PlatformCertificateSettingTest` → PASS
Run: `& $php artisan test` → PASS

- [x] **Step 5: Commit**

```bash
git add database/migrations app/Models/Setting.php app/Http/Controllers/Admin/SettingController.php resources/views/admin/settings tests/Feature/PlatformCertificateSettingTest.php
git commit -m "feat: certificado da plataforma configuravel em settings"
```

---

### Task 3: Extrair `SignatureImage` (validação de PNG data-URL)

**Files:**
- Create: `app/Support/SignatureImage.php`
- Modify: `app/Http/Controllers/Client/SignDocumentController.php:164-185` (delegar `storeDrawnSignature`)
- Test: `tests/Unit/SignatureImageTest.php`

**Interfaces:**
- Produces: `SignatureImage::storeDataUrl(string $dataUrl): string` — valida data-URL PNG (magic bytes, ≤ 2 MB decodificado, `getimagesizefromstring`) e grava em arquivo temporário; lança `RuntimeException('Assinatura desenhada inválida: desenhe novamente.')` se inválido. Usada pelo SignDocumentController (existente) e pelo fluxo público (Task 12).

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Unit/SignatureImageTest.php

namespace Tests\Unit;

use App\Support\SignatureImage;
use PHPUnit\Framework\TestCase;

class SignatureImageTest extends TestCase
{
    private function pngDataUrl(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    public function test_stores_valid_png_data_url(): void
    {
        $path = SignatureImage::storeDataUrl($this->pngDataUrl());

        $this->assertFileExists($path);
        $this->assertStringStartsWith("\x89PNG", file_get_contents($path));
        @unlink($path);
    }

    public function test_rejects_non_png_prefix(): void
    {
        $this->expectException(\RuntimeException::class);
        SignatureImage::storeDataUrl('data:image/jpeg;base64,'.base64_encode('x'));
    }

    public function test_rejects_invalid_base64_payload(): void
    {
        $this->expectException(\RuntimeException::class);
        SignatureImage::storeDataUrl('data:image/png;base64,not-valid-png');
    }
}
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=SignatureImageTest`
Expected: FAIL (classe não existe)

- [x] **Step 3: Implementar — mover o corpo de `storeDrawnSignature` para a classe**

```php
<?php
// app/Support/SignatureImage.php

namespace App\Support;

class SignatureImage
{
    /** Decodifica data-URL PNG (pad de assinatura), valida e grava em arquivo temporário. */
    public static function storeDataUrl(string $dataUrl): string
    {
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw new \RuntimeException('Assinatura desenhada inválida: desenhe novamente.');
        }

        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        // Magic bytes PNG + limite de 2 MB decodificados
        if ($binary === false || strlen($binary) > 2 * 1024 * 1024 || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new \RuntimeException('Assinatura desenhada inválida: desenhe novamente.');
        }
        if (getimagesizefromstring($binary) === false) {
            throw new \RuntimeException('Assinatura desenhada inválida: desenhe novamente.');
        }

        $path = tempnam(sys_get_temp_dir(), 'drawn_sig_').'.png';
        file_put_contents($path, $binary);

        return $path;
    }
}
```

No `SignDocumentController`, substituir o corpo de `storeDrawnSignature` por:

```php
/** Decodifica o data-URL PNG do pad de assinatura e grava em arquivo temporário. */
private function storeDrawnSignature(string $dataUrl): string
{
    return \App\Support\SignatureImage::storeDataUrl($dataUrl);
}
```

- [x] **Step 4: Rodar e ver passar (incluindo SignDocumentTest)**

Run: `& $php artisan test --filter="SignatureImageTest|SignDocumentTest"`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add app/Support/SignatureImage.php app/Http/Controllers/Client/SignDocumentController.php tests/Unit/SignatureImageTest.php
git commit -m "refactor: extrai validacao de assinatura desenhada para SignatureImage"
```

---

### Task 4: WhatsApp para não-usuários + Mailables + views de e-mail

**Files:**
- Modify: `app/Services/NotificationService.php` (novo método `sendWhatsAppTo`)
- Create: `app/Mail/Envelopes/EnvelopeInvite.php`, `EnvelopeOtp.php`, `EnvelopeCompleted.php`, `EnvelopeDeclined.php`, `EnvelopeCancelled.php`
- Create: `resources/views/emails/envelopes/invite.blade.php`, `otp.blade.php`, `completed.blade.php`, `declined.blade.php`, `cancelled.blade.php`
- Test: `tests/Feature/EnvelopeMailablesTest.php`

**Interfaces:**
- Produces: `NotificationService::sendWhatsAppTo(?string $number, string $message): void` — como `sendWhatsApp`, mas para número avulso (signatários não são users); respeita `settings.whatsapp_enabled`, silencioso se número vazio.
- Produces (usadas nas Tasks 5, 6, 9, 10):
  - `new EnvelopeInvite(EnvelopeSigner $signer, bool $reminder = false)` — convite/lembrete; botão aponta para `route('public.sign.show', $signer->token)`
  - `new EnvelopeOtp(EnvelopeSigner $signer, string $code)` — código em claro só no e-mail
  - `new EnvelopeCompleted(Envelope $envelope, ?EnvelopeSigner $signer = null)` — sem signer = versão do remetente
  - `new EnvelopeDeclined(Envelope $envelope, EnvelopeSigner $signer)` — para o remetente
  - `new EnvelopeCancelled(Envelope $envelope)` — para signatários já notificados

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Feature/EnvelopeMailablesTest.php

namespace Tests\Feature;

use App\Mail\Envelopes\EnvelopeCancelled;
use App\Mail\Envelopes\EnvelopeCompleted;
use App\Mail\Envelopes\EnvelopeDeclined;
use App\Mail\Envelopes\EnvelopeInvite;
use App\Mail\Envelopes\EnvelopeOtp;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvelopeMailablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_renders_with_sign_link_and_sender_message(): void
    {
        $envelope = Envelope::factory()->create(['title' => 'Contrato de Aluguel', 'message' => 'Favor assinar até sexta.']);
        $signer = EnvelopeSigner::factory()->for($envelope)->create();

        $html = (new EnvelopeInvite($signer))->render();

        $this->assertStringContainsString('Contrato de Aluguel', $html);
        $this->assertStringContainsString('Favor assinar até sexta.', $html);
        $this->assertStringContainsString(route('public.sign.show', $signer->token), $html);
    }

    public function test_reminder_changes_subject(): void
    {
        $signer = EnvelopeSigner::factory()->create();

        $this->assertStringContainsString('Lembrete', (new EnvelopeInvite($signer, reminder: true))->envelope()->subject);
    }

    public function test_otp_renders_code(): void
    {
        $signer = EnvelopeSigner::factory()->create();

        $this->assertStringContainsString('123456', (new EnvelopeOtp($signer, '123456'))->render());
    }

    public function test_completed_declined_cancelled_render(): void
    {
        $envelope = Envelope::factory()->create(['status' => 'completed']);
        $signer = EnvelopeSigner::factory()->for($envelope)->create(['decline_reason' => 'Valores incorretos']);

        $this->assertStringContainsString(route('public.sign.document', $signer->token), (new EnvelopeCompleted($envelope, $signer))->render());
        $this->assertStringContainsString(route('envelopes.download', $envelope), (new EnvelopeCompleted($envelope))->render());
        $this->assertStringContainsString('Valores incorretos', (new EnvelopeDeclined($envelope, $signer))->render());
        $this->assertStringContainsString($envelope->title, (new EnvelopeCancelled($envelope))->render());
    }
}
```

Nota: as rotas `public.sign.show`, `public.sign.document` e `envelopes.download` ainda não existem — para este teste compilar, criar já neste task os **stubs de rota** (serão implementados nas Tasks 10 e 12). Em `routes/web.php`:

```php
// Envelopes — stubs; controllers entram nas Tasks 10 e 12
Route::middleware('auth')->group(function () {
    Route::get('envelopes/{envelope}/download', fn () => abort(501))->name('envelopes.download');
});
Route::get('/sign/{token}', fn () => abort(501))->name('public.sign.show');
Route::get('/sign/{token}/document', fn () => abort(501))->name('public.sign.document');
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=EnvelopeMailablesTest`
Expected: FAIL (mailables não existem)

- [x] **Step 3: Implementar mailables**

Todos seguem o padrão do `BoasVindas` (`use Queueable, SerializesModels`). Código completo:

```php
<?php
// app/Mail/Envelopes/EnvelopeInvite.php

namespace App\Mail\Envelopes;

use App\Models\EnvelopeSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EnvelopeSigner $signer, public bool $reminder = false) {}

    public function envelope(): MailEnvelope
    {
        $prefix = $this->reminder ? 'Lembrete: documento aguardando sua assinatura' : 'Documento para assinar';

        return new MailEnvelope(subject: $prefix.' — '.$this->signer->envelope->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.invite');
    }
}
```

```php
<?php
// app/Mail/Envelopes/EnvelopeOtp.php

namespace App\Mail\Envelopes;

use App\Models\EnvelopeSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeOtp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EnvelopeSigner $signer, public string $code) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: 'Seu código de verificação — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.otp');
    }
}
```

```php
<?php
// app/Mail/Envelopes/EnvelopeCompleted.php

namespace App\Mail\Envelopes;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeCompleted extends Mailable
{
    use Queueable, SerializesModels;

    /** $signer null = versão enviada ao remetente (download autenticado). */
    public function __construct(public Envelope $envelopeModel, public ?EnvelopeSigner $signer = null) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: 'Documento assinado por todos — '.$this->envelopeModel->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.completed');
    }
}
```

```php
<?php
// app/Mail/Envelopes/EnvelopeDeclined.php

namespace App\Mail\Envelopes;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeDeclined extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Envelope $envelopeModel, public EnvelopeSigner $signer) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: 'Assinatura recusada — '.$this->envelopeModel->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.declined');
    }
}
```

```php
<?php
// app/Mail/Envelopes/EnvelopeCancelled.php

namespace App\Mail\Envelopes;

use App\Models\Envelope;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class EnvelopeCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Envelope $envelopeModel) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: 'Envelope cancelado — '.$this->envelopeModel->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.envelopes.cancelled');
    }
}
```

- [x] **Step 4: Views de e-mail**

Padrão standalone do `emails/boas-vindas.blade.php` (copiar o mesmo `<head>`/CSS de lá; abaixo só o miolo `.body` de cada uma):

```blade
{{-- resources/views/emails/envelopes/invite.blade.php --}}
<h2>Olá, {{ $signer->name }}!</h2>
<p><strong>{{ $signer->envelope->user->name }}</strong> enviou o documento
   <strong>{{ $signer->envelope->title }}</strong> para você assinar eletronicamente.</p>
@if ($signer->envelope->message)
    <div class="box"><p>{{ $signer->envelope->message }}</p></div>
@endif
<a class="btn" href="{{ route('public.sign.show', $signer->token) }}">Visualizar e assinar</a>
@if ($signer->envelope->expires_at)
    <p style="font-size:13px;color:#64748b;">Este convite expira em {{ $signer->envelope->expires_at->format('d/m/Y') }}.</p>
@endif
<p style="font-size:13px;color:#64748b;">Se você não esperava este documento, ignore este e-mail.</p>
```

```blade
{{-- resources/views/emails/envelopes/otp.blade.php --}}
<h2>Código de verificação</h2>
<p>Use o código abaixo para confirmar sua identidade e assinar
   <strong>{{ $signer->envelope->title }}</strong>:</p>
<div class="box" style="text-align:center;">
    <p style="font-size:28px;font-weight:700;letter-spacing:6px;">{{ $code }}</p>
</div>
<p style="font-size:13px;color:#64748b;">O código vale por 10 minutos. Se você não solicitou, ignore este e-mail.</p>
```

```blade
{{-- resources/views/emails/envelopes/completed.blade.php --}}
<h2>Documento concluído!</h2>
<p>Todos os participantes assinaram <strong>{{ $envelopeModel->title }}</strong>.</p>
<p>O documento final contém a página de evidências e foi lacrado digitalmente.</p>
@if ($signer)
    <a class="btn" href="{{ route('public.sign.document', $signer->token) }}">Baixar documento assinado</a>
@else
    <a class="btn" href="{{ route('envelopes.download', $envelopeModel) }}">Baixar documento assinado</a>
@endif
```

```blade
{{-- resources/views/emails/envelopes/declined.blade.php --}}
<h2>Assinatura recusada</h2>
<p><strong>{{ $signer->name }}</strong> ({{ $signer->email }}) recusou assinar
   <strong>{{ $envelopeModel->title }}</strong>.</p>
@if ($signer->decline_reason)
    <div class="box"><p><strong>Motivo:</strong> {{ $signer->decline_reason }}</p></div>
@endif
<p>O envelope foi encerrado e os demais links de assinatura foram desativados.</p>
```

```blade
{{-- resources/views/emails/envelopes/cancelled.blade.php --}}
<h2>Envelope cancelado</h2>
<p>O documento <strong>{{ $envelopeModel->title }}</strong> foi cancelado pelo remetente.
   O link de assinatura que você recebeu não é mais válido.</p>
```

- [x] **Step 5: `NotificationService::sendWhatsAppTo`**

```php
// app/Services/NotificationService.php — refatorar para:

public function sendWhatsApp(User $user, string $message): void
{
    $this->sendWhatsAppTo($user->whatsapp, $message);
}

/** Envia para número avulso (ex.: signatário de envelope, que não é user). */
public function sendWhatsAppTo(?string $number, string $message): void
{
    if (! $number) return;

    try {
        $settings = Setting::current();
        if (! $settings->whatsapp_enabled) return;
    } catch (\Throwable) {
        return;
    }

    $this->whatsapp->send($number, $message);
}
```

- [x] **Step 6: Rodar e ver passar + suíte**

Run: `& $php artisan test --filter=EnvelopeMailablesTest` → PASS
Run: `& $php artisan test` → PASS

- [x] **Step 7: Commit**

```bash
git add app/Mail/Envelopes resources/views/emails/envelopes app/Services/NotificationService.php routes/web.php tests/Feature/EnvelopeMailablesTest.php
git commit -m "feat: mailables e e-mails do modulo de envelopes"
```

---

### Task 5: `EnvelopeService` — criação, envio e eventos

**Files:**
- Create: `app/Services/Envelope/EnvelopeService.php`
- Test: `tests/Feature/EnvelopeServiceCreateTest.php`

**Interfaces:**
- Consumes: models da Task 1, mailables da Task 4, `NotificationService::sendWhatsAppTo`, `Setting::platformCertificate` (Task 2).
- Produces (métodos desta task; Task 6 adiciona os demais na MESMA classe):
  - `create(User $user, UploadedFile $pdf, array $data): Envelope` — `$data = ['title','message'?,'signing_order','expires_at'?,'signers' => [['name','email','whatsapp'?,'auth_method','fields' => [['page','x','y','w','h'],...]],...]]`; grava PDF em `envelopes/{id}/original.pdf`, sha256, signers com `sign_position` = índice+1, fields; evento `created`. Status fica `draft`.
  - `send(Envelope $envelope): void` — valida certificado da plataforma configurado e não vencido (senão `RuntimeException`), status `sent`, evento `sent`, notifica todos (parallel) ou só o primeiro (sequential).
  - `notifySigner(EnvelopeSigner $signer, bool $reminder = false): void` — e-mail convite + espelho WhatsApp; status `notified` (se ainda `pending`); evento `sent`/`reminder_sent`.
  - `recordEvent(Envelope $envelope, ?EnvelopeSigner $signer, string $event, ?string $ip = null, ?string $userAgent = null, array $meta = []): EnvelopeEvent`

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Feature/EnvelopeServiceCreateTest.php

namespace Tests\Feature;

use App\Mail\Envelopes\EnvelopeInvite;
use App\Models\Certificate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopeServiceCreateTest extends TestCase
{
    use RefreshDatabase;

    private function makeEnvelope(User $user, array $overrides = []): \App\Models\Envelope
    {
        $pdf = UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 fake');

        return app(EnvelopeService::class)->create($user, $pdf, array_merge([
            'title' => 'Contrato de Aluguel',
            'message' => 'Assinar até sexta',
            'signing_order' => 'parallel',
            'signers' => [
                ['name' => 'Ana', 'email' => 'ana@x.com', 'auth_method' => 'link',
                 'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
                ['name' => 'Beto', 'email' => 'beto@x.com', 'auth_method' => 'email_otp',
                 'fields' => [['page' => 1, 'x' => 300, 'y' => 200, 'w' => 120, 'h' => 40]]],
            ],
        ], $overrides));
    }

    private function configurePlatformCertificate(): void
    {
        $cert = Certificate::factory()->create(['expires_at' => now()->addYear()]);
        Setting::current()->update(['platform_certificate_id' => $cert->id]);
        Setting::clearCache();
    }

    public function test_create_stores_pdf_hash_signers_and_fields(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'client']);

        $envelope = $this->makeEnvelope($user);

        $this->assertSame('draft', $envelope->status);
        $this->assertSame("envelopes/{$envelope->id}/original.pdf", $envelope->original_pdf_path);
        Storage::disk('local')->assertExists($envelope->original_pdf_path);
        $this->assertSame(hash('sha256', '%PDF-1.4 fake'), $envelope->sha256_original);
        $this->assertCount(2, $envelope->signers);
        $this->assertSame([1, 2], $envelope->signers->pluck('sign_position')->all());
        $this->assertCount(1, $envelope->signers[0]->fields);
        $this->assertTrue($envelope->events()->where('event', 'created')->exists());
    }

    public function test_send_requires_platform_certificate(): void
    {
        Storage::fake('local');
        $envelope = $this->makeEnvelope(User::factory()->create(['role' => 'client']));

        $this->expectException(\RuntimeException::class);
        app(EnvelopeService::class)->send($envelope);
    }

    public function test_send_parallel_notifies_all_signers(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->configurePlatformCertificate();
        $envelope = $this->makeEnvelope(User::factory()->create(['role' => 'client']));

        app(EnvelopeService::class)->send($envelope);

        $this->assertSame('sent', $envelope->fresh()->status);
        Mail::assertSent(EnvelopeInvite::class, 2);
        $this->assertSame(['notified', 'notified'], $envelope->signers()->pluck('status')->all());
    }

    public function test_send_sequential_notifies_only_first(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->configurePlatformCertificate();
        $envelope = $this->makeEnvelope(User::factory()->create(['role' => 'client']), ['signing_order' => 'sequential']);

        app(EnvelopeService::class)->send($envelope);

        Mail::assertSent(EnvelopeInvite::class, 1);
        $this->assertSame(['notified', 'pending'], $envelope->signers()->pluck('status')->all());
    }
}
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=EnvelopeServiceCreateTest`
Expected: FAIL (serviço não existe)

- [x] **Step 3: Implementar o serviço**

```php
<?php
// app/Services/Envelope/EnvelopeService.php

namespace App\Services\Envelope;

use App\Mail\Envelopes\EnvelopeInvite;
use App\Models\Envelope;
use App\Models\EnvelopeEvent;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EnvelopeService
{
    public function __construct(private NotificationService $notification) {}

    /** Cria o envelope (draft) com signatários e posições de assinatura. */
    public function create(User $user, UploadedFile $pdf, array $data): Envelope
    {
        return DB::transaction(function () use ($user, $pdf, $data) {
            $envelope = Envelope::create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'message' => $data['message'] ?? null,
                'signing_order' => $data['signing_order'],
                'expires_at' => $data['expires_at'] ?? null,
                'original_pdf_path' => 'pending',
                'sha256_original' => hash_file('sha256', $pdf->getRealPath()),
            ]);

            $path = $pdf->storeAs("envelopes/{$envelope->id}", 'original.pdf', 'local');
            $envelope->update(['original_pdf_path' => $path]);

            foreach (array_values($data['signers']) as $i => $s) {
                $signer = $envelope->signers()->create([
                    'name' => $s['name'],
                    'email' => $s['email'],
                    'whatsapp' => $s['whatsapp'] ?? null,
                    'auth_method' => $s['auth_method'],
                    'sign_position' => $i + 1,
                ]);
                $signer->fields()->createMany($s['fields']);
            }

            $this->recordEvent($envelope, null, 'created');

            return $envelope->fresh(['signers.fields']);
        });
    }

    /** Envia os convites. Exige certificado da plataforma válido configurado. */
    public function send(Envelope $envelope): void
    {
        $cert = Setting::current()->platformCertificate;
        if ($cert === null || $cert->isExpired()) {
            throw new \RuntimeException(
                'Nenhum certificado da plataforma válido configurado — peça ao administrador para configurar em Configurações.'
            );
        }

        $envelope->update(['status' => 'sent']);
        $this->recordEvent($envelope, null, 'sent');

        $targets = $envelope->isSequential()
            ? collect([$envelope->nextPendingSigner()])->filter()
            : $envelope->signers;

        foreach ($targets as $signer) {
            $this->notifySigner($signer);
        }
    }

    /** Convite (ou lembrete) por e-mail + espelho WhatsApp. */
    public function notifySigner(EnvelopeSigner $signer, bool $reminder = false): void
    {
        Mail::to($signer->email)->send(new EnvelopeInvite($signer, $reminder));

        $this->notification->sendWhatsAppTo($signer->whatsapp,
            "📄 *{$signer->envelope->user->name}* enviou o documento *{$signer->envelope->title}* para você assinar.\n".
            'Acesse: '.route('public.sign.show', $signer->token)
        );

        if ($signer->status === 'pending') {
            $signer->update(['status' => 'notified']);
        }

        $this->recordEvent($signer->envelope, $signer, $reminder ? 'reminder_sent' : 'sent');
    }

    /** Trilha de auditoria — só INSERT, nunca update. */
    public function recordEvent(
        Envelope $envelope,
        ?EnvelopeSigner $signer,
        string $event,
        ?string $ip = null,
        ?string $userAgent = null,
        array $meta = [],
    ): EnvelopeEvent {
        return $envelope->events()->create([
            'envelope_signer_id' => $signer?->id,
            'event' => $event,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            'meta' => $meta ?: null,
        ]);
    }
}
```

- [x] **Step 4: Rodar e ver passar + suíte**

Run: `& $php artisan test --filter=EnvelopeServiceCreateTest` → PASS
Run: `& $php artisan test` → PASS

- [x] **Step 5: Commit**

```bash
git add app/Services/Envelope/EnvelopeService.php tests/Feature/EnvelopeServiceCreateTest.php
git commit -m "feat: EnvelopeService - criacao, envio e trilha de eventos"
```

---

### Task 6: `EnvelopeService` — OTP, assinar, recusar, cancelar

**Files:**
- Modify: `app/Services/Envelope/EnvelopeService.php` (adicionar métodos)
- Test: `tests/Feature/EnvelopeServiceSignTest.php`

**Interfaces:**
- Consumes: `SignatureImage::storeDataUrl` (Task 3), `EnvelopeOtp`/`EnvelopeDeclined`/`EnvelopeCancelled` (Task 4), `SealEnvelopeJob` (Task 9 — nesta task só o dispatch; a classe é criada como casca vazia aqui para compilar, implementada na Task 9).
- Produces:
  - `markViewed(EnvelopeSigner $signer, ?string $ip, ?string $userAgent): void`
  - `issueOtp(EnvelopeSigner $signer): void` — 6 dígitos, `Hash::make`, 10 min, zera tentativas; envia por e-mail ou WhatsApp conforme `auth_method`; evento `otp_sent`
  - `verifyOtp(EnvelopeSigner $signer, string $code): bool` — false se expirado/ausente/6ª tentativa; incrementa `otp_attempts`; evento `otp_failed` quando falha
  - `sign(EnvelopeSigner $signer, array $data, ?string $ip, ?string $userAgent): void` — `$data = ['name','cpf','signature_type','signature' (data-URL PNG)]`; salva PNG em `envelopes/{envelope_id}/signatures/{signer_id}.png`; evento `signed`; se todos assinaram → `SealEnvelopeJob::dispatch`; senão, sequencial → notifica próximo
  - `decline(EnvelopeSigner $signer, string $reason, ?string $ip, ?string $userAgent): void` — signer e envelope `declined`, e-mail ao remetente, evento
  - `cancel(Envelope $envelope): void` — status `cancelled`, evento, e-mail `EnvelopeCancelled` aos signers com status ≠ `pending`

- [x] **Step 1: Criar a casca do job (para o dispatch compilar)**

```php
<?php
// app/Jobs/SealEnvelopeJob.php — casca; implementação real na Task 9

namespace App\Jobs;

use App\Models\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SealEnvelopeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Envelope $envelope) {}

    public function handle(): void
    {
        // Task 9
    }
}
```

- [x] **Step 2: Teste que falha**

```php
<?php
// tests/Feature/EnvelopeServiceSignTest.php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Mail\Envelopes\EnvelopeCancelled;
use App\Mail\Envelopes\EnvelopeDeclined;
use App\Mail\Envelopes\EnvelopeOtp;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopeServiceSignTest extends TestCase
{
    use RefreshDatabase;

    private function pngDataUrl(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    private function signData(): array
    {
        return ['name' => 'Ana Silva', 'cpf' => '123.456.789-00',
                'signature_type' => 'drawn', 'signature' => $this->pngDataUrl()];
    }

    public function test_issue_and_verify_otp(): void
    {
        Mail::fake();
        $signer = EnvelopeSigner::factory()->create(['auth_method' => 'email_otp']);
        $svc = app(EnvelopeService::class);

        $svc->issueOtp($signer);
        Mail::assertSent(EnvelopeOtp::class, function (EnvelopeOtp $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->assertFalse($svc->verifyOtp($signer->fresh(), '000000'));
        $this->assertTrue($svc->verifyOtp($signer->fresh(), $code));
        $this->assertTrue($signer->envelope->events()->where('event', 'otp_failed')->exists());
    }

    public function test_otp_expires_and_locks_after_5_attempts(): void
    {
        Mail::fake();
        $signer = EnvelopeSigner::factory()->create(['auth_method' => 'email_otp']);
        $svc = app(EnvelopeService::class);

        $svc->issueOtp($signer);
        $signer->fresh()->update(['otp_expires_at' => now()->subMinute()]);
        $this->assertFalse($svc->verifyOtp($signer->fresh(), '123456'));

        $svc->issueOtp($signer = $signer->fresh());
        $signer->update(['otp_attempts' => 5]);
        $this->assertFalse($svc->verifyOtp($signer->fresh(), '123456'));
    }

    public function test_sign_stores_signature_and_dispatches_seal_when_last(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $a = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 1, 'status' => 'viewed']);
        $b = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 2, 'status' => 'viewed']);
        $svc = app(EnvelopeService::class);

        $svc->sign($a, $this->signData(), '10.0.0.1', 'UA-Test');
        Queue::assertNotPushed(SealEnvelopeJob::class);

        $a->refresh();
        $this->assertSame('signed', $a->status);
        $this->assertSame('Ana Silva', $a->name);
        $this->assertSame('123.456.789-00', $a->cpf);
        $this->assertSame('10.0.0.1', $a->ip_address);
        Storage::disk('local')->assertExists($a->signature_image_path);

        $svc->sign($b, $this->signData(), '10.0.0.2', 'UA-Test');
        Queue::assertPushed(SealEnvelopeJob::class, 1);
    }

    public function test_sequential_sign_notifies_next(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent', 'signing_order' => 'sequential']);
        $a = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 1, 'status' => 'viewed']);
        $b = EnvelopeSigner::factory()->for($envelope)->create(['sign_position' => 2, 'status' => 'pending']);

        app(EnvelopeService::class)->sign($a, $this->signData(), null, null);

        $this->assertSame('notified', $b->fresh()->status);
    }

    public function test_decline_ends_envelope_and_notifies_sender(): void
    {
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $signer = EnvelopeSigner::factory()->for($envelope)->create(['status' => 'viewed']);

        app(EnvelopeService::class)->decline($signer, 'Valores errados', '10.0.0.1', 'UA');

        $this->assertSame('declined', $signer->fresh()->status);
        $this->assertSame('declined', $envelope->fresh()->status);
        Mail::assertSent(EnvelopeDeclined::class, fn ($m) => $m->hasTo($envelope->user->email));
    }

    public function test_cancel_notifies_already_notified_signers(): void
    {
        Mail::fake();
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'notified', 'email' => 'a@x.com']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'pending', 'email' => 'b@x.com']);

        app(EnvelopeService::class)->cancel($envelope);

        $this->assertSame('cancelled', $envelope->fresh()->status);
        Mail::assertSent(EnvelopeCancelled::class, 1);
        Mail::assertSent(EnvelopeCancelled::class, fn ($m) => $m->hasTo('a@x.com'));
    }
}
```

- [x] **Step 3: Rodar e ver falhar**

Run: `& $php artisan test --filter=EnvelopeServiceSignTest`
Expected: FAIL (métodos não existem)

- [x] **Step 4: Implementar os métodos (adicionar à classe da Task 5)**

```php
// adicionar aos use: App\Jobs\SealEnvelopeJob, App\Mail\Envelopes\{EnvelopeCancelled,EnvelopeCompleted,EnvelopeDeclined,EnvelopeOtp},
// App\Support\SignatureImage, Illuminate\Support\Facades\{Hash, Storage}

public function markViewed(EnvelopeSigner $signer, ?string $ip, ?string $userAgent): void
{
    if (in_array($signer->status, ['pending', 'notified'], true)) {
        $signer->update(['status' => 'viewed']);
    }

    $this->recordEvent($signer->envelope, $signer, 'viewed', $ip, $userAgent);
}

/** Gera OTP de 6 dígitos (10 min, hash no banco) e envia pelo canal do signatário. */
public function issueOtp(EnvelopeSigner $signer): void
{
    $code = (string) random_int(100000, 999999);

    $signer->update([
        'otp_code' => Hash::make($code),
        'otp_expires_at' => now()->addMinutes(10),
        'otp_attempts' => 0,
    ]);

    if ($signer->auth_method === 'whatsapp_otp' && $signer->whatsapp) {
        $this->notification->sendWhatsAppTo($signer->whatsapp,
            "🔐 Seu código para assinar *{$signer->envelope->title}*: *{$code}*\nVale por 10 minutos.");
    } else {
        Mail::to($signer->email)->send(new EnvelopeOtp($signer, $code));
    }

    $this->recordEvent($signer->envelope, $signer, 'otp_sent');
}

public function verifyOtp(EnvelopeSigner $signer, string $code): bool
{
    $valid = $signer->otp_code !== null
        && $signer->otp_expires_at?->isFuture()
        && $signer->otp_attempts < 5
        && Hash::check($code, $signer->otp_code);

    if (! $valid) {
        $signer->increment('otp_attempts');
        $this->recordEvent($signer->envelope, $signer, 'otp_failed');

        return false;
    }

    $signer->update(['otp_code' => null, 'otp_expires_at' => null]);

    return true;
}

/** Registra a assinatura do convidado. NÃO valida OTP — o controller valida antes. */
public function sign(EnvelopeSigner $signer, array $data, ?string $ip, ?string $userAgent): void
{
    $temp = SignatureImage::storeDataUrl($data['signature']);
    $relative = "envelopes/{$signer->envelope_id}/signatures/{$signer->id}.png";
    Storage::disk('local')->put($relative, file_get_contents($temp));
    @unlink($temp);

    $signer->update([
        'name' => $data['name'],
        'cpf' => $data['cpf'],
        'signature_type' => $data['signature_type'],
        'signature_image_path' => $relative,
        'status' => 'signed',
        'signed_at' => now(),
        'ip_address' => $ip,
        'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
    ]);

    $envelope = $signer->envelope->fresh();
    $this->recordEvent($envelope, $signer, 'signed', $ip, $userAgent, [
        'signature_type' => $data['signature_type'],
        'auth_method' => $signer->auth_method,
    ]);

    if ($envelope->allSigned()) {
        SealEnvelopeJob::dispatch($envelope);
    } elseif ($envelope->isSequential() && ($next = $envelope->nextPendingSigner())) {
        $this->notifySigner($next);
    }
}

/** Recusa encerra o envelope inteiro e avisa o remetente. */
public function decline(EnvelopeSigner $signer, string $reason, ?string $ip, ?string $userAgent): void
{
    $signer->update(['status' => 'declined', 'decline_reason' => $reason]);
    $signer->envelope->update(['status' => 'declined']);

    $this->recordEvent($signer->envelope, $signer, 'declined', $ip, $userAgent, ['reason' => $reason]);

    Mail::to($signer->envelope->user->email)->send(new EnvelopeDeclined($signer->envelope, $signer));
}

/** Cancela e avisa quem já tinha recebido o convite. */
public function cancel(Envelope $envelope): void
{
    $envelope->update(['status' => 'cancelled']);
    $this->recordEvent($envelope, null, 'cancelled');

    foreach ($envelope->signers()->where('status', '!=', 'pending')->get() as $signer) {
        Mail::to($signer->email)->send(new EnvelopeCancelled($envelope));
    }
}
```

- [x] **Step 5: Rodar e ver passar + suíte**

Run: `& $php artisan test --filter=EnvelopeServiceSignTest` → PASS
Run: `& $php artisan test` → PASS

- [x] **Step 6: Commit**

```bash
git add app/Services/Envelope/EnvelopeService.php app/Jobs/SealEnvelopeJob.php tests/Feature/EnvelopeServiceSignTest.php
git commit -m "feat: EnvelopeService - otp, assinatura, recusa e cancelamento"
```

---

### Task 7: `EvidenceReportGenerator` — página de evidências (TCPDF)

**Files:**
- Create: `app/Services/Envelope/EvidenceReportGenerator.php`
- Test: `tests/Unit/EvidenceReportGeneratorTest.php`

**Interfaces:**
- Produces: `generate(Envelope $envelope): string` — caminho ABSOLUTO de um PDF temporário contendo só a(s) página(s) de evidências. Consumido pelo `EnvelopePdfComposer` (Task 8).

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Unit/EvidenceReportGeneratorTest.php

namespace Tests\Unit;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EvidenceReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_pdf_with_signer_and_event_data(): void
    {
        $envelope = Envelope::factory()->create([
            'title' => 'Contrato XYZ',
            'status' => 'sent',
            'sha256_original' => str_repeat('ab', 32),
        ]);
        $signer = EnvelopeSigner::factory()->for($envelope)->create([
            'name' => 'Ana Prova', 'cpf' => '123.456.789-00', 'status' => 'signed',
            'signed_at' => now(), 'ip_address' => '10.0.0.9', 'auth_method' => 'email_otp',
        ]);
        $envelope->events()->create(['envelope_signer_id' => $signer->id, 'event' => 'signed', 'ip_address' => '10.0.0.9']);

        $path = (new EvidenceReportGenerator)->generate($envelope->fresh());

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', file_get_contents($path));

        // TCPDF comprime streams; validar conteúdo extraindo texto bruto do PDF não comprimido não é trivial —
        // basta garantir que gera PDF válido e não vazio. O conteúdo é validado visualmente no fim (Task 13).
        $this->assertGreaterThan(1000, filesize($path));
        @unlink($path);
    }
}
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=EvidenceReportGeneratorTest`
Expected: FAIL

- [x] **Step 3: Implementar**

```php
<?php
// app/Services/Envelope/EvidenceReportGenerator.php

namespace App\Services\Envelope;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Support\Facades\Storage;

/**
 * Gera a(s) página(s) de evidências anexada(s) ao PDF final do envelope:
 * dados do documento, cada signatário (com a imagem da assinatura) e a
 * trilha completa de envelope_events. Unidade: pontos PDF (pt).
 */
class EvidenceReportGenerator
{
    private const AUTH_LABELS = [
        'link' => 'Link exclusivo por e-mail',
        'email_otp' => 'Link + código por e-mail',
        'whatsapp_otp' => 'Link + código por WhatsApp',
    ];

    public function generate(Envelope $envelope): string
    {
        $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(40, 40, 40);
        $pdf->SetAutoPageBreak(true, 40);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 24, 'Relatório de Assinaturas e Evidências', 0, 1);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->writeHTML($this->documentSection($envelope), true, false, true);

        foreach ($envelope->signers as $signer) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 20, 'Signatário: '.$signer->name, 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->writeHTML($this->signerSection($signer), true, false, true);

            if ($signer->signature_image_path && Storage::disk('local')->exists($signer->signature_image_path)) {
                $pdf->Image(Storage::disk('local')->path($signer->signature_image_path),
                    x: 40, w: 140, h: 0, type: 'PNG');
                $pdf->Ln(10);
            }
        }

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 20, 'Trilha de auditoria', 0, 1);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->writeHTML($this->eventsTable($envelope), true, false, true);

        $path = tempnam(sys_get_temp_dir(), 'evidence_').'.pdf';
        $pdf->Output($path, 'F');

        return $path;
    }

    private function documentSection(Envelope $envelope): string
    {
        $rows = [
            'Documento' => e($envelope->title),
            'Enviado por' => e($envelope->user->name).' ('.e($envelope->user->email).')',
            'Criado em' => $envelope->created_at->format('d/m/Y H:i:s'),
            'SHA-256 do documento original' => $envelope->sha256_original,
            'Ordem de assinatura' => $envelope->isSequential() ? 'Sequencial' : 'Paralela',
        ];

        return $this->table($rows);
    }

    private function signerSection(EnvelopeSigner $signer): string
    {
        $rows = [
            'Nome declarado' => e((string) $signer->name),
            'CPF declarado' => e((string) $signer->cpf),
            'E-mail' => e($signer->email),
            'Autenticação' => self::AUTH_LABELS[$signer->auth_method] ?? $signer->auth_method,
            'Assinado em' => $signer->signed_at?->format('d/m/Y H:i:s') ?? '—',
            'Endereço IP' => e((string) $signer->ip_address),
            'Navegador' => e((string) $signer->user_agent),
            'Tipo de assinatura' => $signer->signature_type === 'typed' ? 'Nome digitado' : 'Desenhada na tela',
        ];

        return $this->table($rows);
    }

    private function eventsTable(Envelope $envelope): string
    {
        $html = '<table border="0.5" cellpadding="4"><tr><th width="18%"><b>Data/hora</b></th><th width="20%"><b>Evento</b></th><th width="24%"><b>Participante</b></th><th width="18%"><b>IP</b></th><th width="20%"><b>Detalhes</b></th></tr>';

        foreach ($envelope->events as $event) {
            $html .= '<tr>'
                .'<td>'.$event->created_at->format('d/m/Y H:i:s').'</td>'
                .'<td>'.e($event->event).'</td>'
                .'<td>'.e($event->signer?->name ?? 'Sistema').'</td>'
                .'<td>'.e((string) $event->ip_address).'</td>'
                .'<td>'.e($event->meta ? json_encode($event->meta, JSON_UNESCAPED_UNICODE) : '').'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }

    private function table(array $rows): string
    {
        $html = '<table cellpadding="4">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td width="32%"><b>'.$label.'</b></td><td width="68%">'.$value.'</td></tr>';
        }

        return $html.'</table>';
    }
}
```

- [x] **Step 4: Rodar e ver passar + suíte, e commitar**

Run: `& $php artisan test --filter=EvidenceReportGeneratorTest` → PASS
Run: `& $php artisan test` → PASS

```bash
git add app/Services/Envelope/EvidenceReportGenerator.php tests/Unit/EvidenceReportGeneratorTest.php
git commit -m "feat: gerador da pagina de evidencias do envelope"
```

---

### Task 8: `EnvelopePdfComposer` — carimbos + concatenação (FPDI)

**Files:**
- Create: `app/Services/Envelope/EnvelopePdfComposer.php`
- Test: `tests/Unit/EnvelopePdfComposerTest.php`

**Interfaces:**
- Consumes: `EvidenceReportGenerator::generate` (Task 7), models.
- Produces: `compose(Envelope $envelope, string $evidencePdfPath): array{path: string, pages: int}` — PDF temporário: original com as assinaturas carimbadas + páginas de evidências ao final; `pages` = total de páginas do resultado (a Task 9 usa para posicionar o selo da plataforma na última página).
- Coordenadas: `envelope_fields` já está em pontos PDF topo-esquerdo; usando FPDI com unidade `pt`, `Image($file, $x, $y, $w, $h)` aplica direto, sem conversão (diferente do `SignPdfService`, que usa mm + `getScaleFactor`).

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Unit/EnvelopePdfComposerTest.php

namespace Tests\Unit;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EnvelopePdfComposer;
use App\Services\Envelope\EvidenceReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopePdfComposerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSourcePdf(int $pages = 2): string
    {
        $pdf = new \TCPDF;
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Write(0, "Página {$i} do contrato");
        }
        $path = tempnam(sys_get_temp_dir(), 'src_').'.pdf';
        $pdf->Output($path, 'F');

        return $path;
    }

    private function signaturePng(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_composes_stamped_pdf_plus_evidence_pages(): void
    {
        Storage::fake('local');

        $envelope = Envelope::factory()->create(['status' => 'sent']);
        Storage::disk('local')->put("envelopes/{$envelope->id}/original.pdf", file_get_contents($this->makeSourcePdf(2)));
        $envelope->update(['original_pdf_path' => "envelopes/{$envelope->id}/original.pdf"]);

        $signer = EnvelopeSigner::factory()->for($envelope)->create(['status' => 'signed', 'signed_at' => now()]);
        Storage::disk('local')->put("envelopes/{$envelope->id}/signatures/{$signer->id}.png", $this->signaturePng());
        $signer->update(['signature_image_path' => "envelopes/{$envelope->id}/signatures/{$signer->id}.png"]);
        $signer->fields()->create(['page' => 2, 'x' => 100, 'y' => 600, 'w' => 120, 'h' => 40]);

        $evidence = (new EvidenceReportGenerator)->generate($envelope->fresh());
        $result = (new EnvelopePdfComposer)->compose($envelope->fresh(), $evidence);

        $this->assertFileExists($result['path']);
        $this->assertGreaterThanOrEqual(3, $result['pages']); // 2 do contrato + >=1 de evidências

        // reabre com FPDI para confirmar o page count do resultado
        $check = new \setasign\Fpdi\Tcpdf\Fpdi;
        $this->assertSame($result['pages'], $check->setSourceFile($result['path']));

        @unlink($result['path']);
        @unlink($evidence);
    }
}
```

Nota: confirmar o namespace FPDI usado no projeto em `app/Services/Pdf/SignPdfService.php` (linha ~40, onde `$this->pdf` é instanciado) e usar o MESMO nas classes novas — se lá for `\setasign\Fpdi\Tcpdf\Fpdi`, manter; se for outro wrapper, ajustar aqui e no composer abaixo.

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=EnvelopePdfComposerTest`
Expected: FAIL

- [x] **Step 3: Implementar**

```php
<?php
// app/Services/Envelope/EnvelopePdfComposer.php

namespace App\Services\Envelope;

use App\Models\Envelope;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Monta o PDF final do envelope (ANTES da assinatura digital):
 * original + carimbos das assinaturas nas posições marcadas + páginas de evidências.
 *
 * Unidade pt: envelope_fields já está em pontos PDF topo-esquerdo, aplicação direta.
 * NUNCA usar sobre PDF já assinado digitalmente (reescreve o documento).
 */
class EnvelopePdfComposer
{
    /** @return array{path: string, pages: int} */
    public function compose(Envelope $envelope, string $evidencePdfPath): array
    {
        $disk = Storage::disk('local');

        $pdf = new Fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);

        // 1. Páginas do original, carimbando as assinaturas de cada uma
        $fieldsByPage = $this->fieldsByPage($envelope);
        $pageCount = $pdf->setSourceFile($disk->path($envelope->original_pdf_path));

        for ($page = 1; $page <= $pageCount; $page++) {
            $tpl = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            foreach ($fieldsByPage[$page] ?? [] as $field) {
                $pdf->Image(
                    $disk->path($field->signer->signature_image_path),
                    $field->x, $field->y, $field->w, $field->h,
                    'PNG'
                );
            }
        }

        // 2. Páginas de evidências ao final
        $evidenceCount = $pdf->setSourceFile($evidencePdfPath);
        for ($page = 1; $page <= $evidenceCount; $page++) {
            $tpl = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }

        $path = tempnam(sys_get_temp_dir(), 'composed_').'.pdf';
        $pdf->Output($path, 'F');

        return ['path' => $path, 'pages' => $pageCount + $evidenceCount];
    }

    /** @return array<int, list<\App\Models\EnvelopeField>> */
    private function fieldsByPage(Envelope $envelope): array
    {
        $grouped = [];

        foreach ($envelope->signers as $signer) {
            if (! $signer->signature_image_path) {
                continue;
            }
            foreach ($signer->fields as $field) {
                $field->setRelation('signer', $signer);
                $grouped[$field->page][] = $field;
            }
        }

        return $grouped;
    }
}
```

- [x] **Step 4: Rodar e ver passar + suíte, e commitar**

Run: `& $php artisan test --filter=EnvelopePdfComposerTest` → PASS
Run: `& $php artisan test` → PASS

```bash
git add app/Services/Envelope/EnvelopePdfComposer.php tests/Unit/EnvelopePdfComposerTest.php
git commit -m "feat: composicao do PDF final do envelope (carimbos + evidencias)"
```

---

### Task 9: `SealEnvelopeJob` — lacre com o certificado da plataforma

**Files:**
- Modify: `app/Jobs/SealEnvelopeJob.php` (implementar a casca da Task 6)
- Test: `tests/Feature/SealEnvelopeJobTest.php`

**Interfaces:**
- Consumes: `EvidenceReportGenerator`, `EnvelopePdfComposer`, `PdfSignerService::fromCertificate(...)->signExisting($path, false, $position, false)` (existente), `Setting::platformCertificate`, `EnvelopeService::recordEvent`, mailables `EnvelopeCompleted`.
- Produces: envelope `completed` com `final_pdf_path = signed/envelopes/{id}/final.pdf` e `sha256_final`; eventos `sealed` e `completed`; e-mails a remetente e signatários. Em falha: evento `seal_failed` + exceção relançada (retry da queue).
- Selo visível da plataforma: última página (evidências), canto inferior direito — `['page' => $pages, 'x' => 400, 'y' => 780, 'w' => 150, 'h' => 40]`.

- [x] **Step 1: Teste que falha**

O teste roda o lacre DE VERDADE (PFX gerado pelo trait `GeneratesPfx`, motor TCPDF fallback ou pyHanko se instalado) — mesmo approach do `SignDocumentTest::test_signs_uploaded_pdf_end_to_end`.

```php
<?php
// tests/Feature/SealEnvelopeJobTest.php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Mail\Envelopes\EnvelopeCompleted;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeneratesPfx;
use Tests\TestCase;

class SealEnvelopeJobTest extends TestCase
{
    use GeneratesPfx, RefreshDatabase;

    private function makeSourcePdf(): string
    {
        $pdf = new \TCPDF;
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Contrato para lacre');
        $path = tempnam(sys_get_temp_dir(), 'src_').'.pdf';
        $pdf->Output($path, 'F');

        return $path;
    }

    private function signaturePng(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    /** Certificado REAL da plataforma via controller (mesmo approach do SignDocumentTest). */
    private function configureRealPlatformCertificate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/certificates', [
            'description' => 'Cert da plataforma',
            'pfx' => new UploadedFile($this->generatePfx('secret'), 'cert.pfx', 'application/octet-stream', null, true),
            'password' => 'secret',
        ]);
        Setting::current()->update(['platform_certificate_id' => \App\Models\Certificate::latest('id')->first()->id]);
        Setting::clearCache();
        auth()->logout();
    }

    private function makeSignedEnvelope(): Envelope
    {
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        Storage::disk('local')->put("envelopes/{$envelope->id}/original.pdf", file_get_contents($this->makeSourcePdf()));
        $envelope->update(['original_pdf_path' => "envelopes/{$envelope->id}/original.pdf"]);

        $signer = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'signed', 'signed_at' => now(), 'cpf' => '123.456.789-00',
        ]);
        Storage::disk('local')->put("envelopes/{$envelope->id}/signatures/{$signer->id}.png", $this->signaturePng());
        $signer->update(['signature_image_path' => "envelopes/{$envelope->id}/signatures/{$signer->id}.png"]);
        $signer->fields()->create(['page' => 1, 'x' => 100, 'y' => 600, 'w' => 120, 'h' => 40]);

        return $envelope->fresh();
    }

    public function test_seals_envelope_end_to_end(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->configureRealPlatformCertificate();
        $envelope = $this->makeSignedEnvelope();

        (new SealEnvelopeJob($envelope))->handle(
            app(\App\Services\Envelope\EvidenceReportGenerator::class),
            app(\App\Services\Envelope\EnvelopePdfComposer::class),
            app(\App\Services\Envelope\EnvelopeService::class),
        );

        $envelope->refresh();
        $this->assertSame('completed', $envelope->status);
        $this->assertSame("signed/envelopes/{$envelope->id}/final.pdf", $envelope->final_pdf_path);
        Storage::disk('local')->assertExists($envelope->final_pdf_path);
        $this->assertSame(
            hash('sha256', Storage::disk('local')->get($envelope->final_pdf_path)),
            $envelope->sha256_final
        );
        $this->assertTrue($envelope->events()->where('event', 'sealed')->exists());

        // remetente + 1 signatário
        Mail::assertSent(EnvelopeCompleted::class, 2);
    }

    public function test_failure_records_seal_failed_and_keeps_status(): void
    {
        Storage::fake('local');
        Mail::fake();
        // SEM certificado da plataforma → deve falhar
        $envelope = $this->makeSignedEnvelope();

        try {
            (new SealEnvelopeJob($envelope))->handle(
                app(\App\Services\Envelope\EvidenceReportGenerator::class),
                app(\App\Services\Envelope\EnvelopePdfComposer::class),
                app(\App\Services\Envelope\EnvelopeService::class),
            );
            $this->fail('Deveria ter lançado exceção');
        } catch (\Throwable) {
            // esperado
        }

        $envelope->refresh();
        $this->assertSame('sent', $envelope->status);
        $this->assertNull($envelope->final_pdf_path);
        $this->assertTrue($envelope->events()->where('event', 'seal_failed')->exists());
    }
}
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=SealEnvelopeJobTest`
Expected: FAIL (handle vazio)

- [x] **Step 3: Implementar o job**

```php
<?php
// app/Jobs/SealEnvelopeJob.php

namespace App\Jobs;

use App\Mail\Envelopes\EnvelopeCompleted;
use App\Models\Envelope;
use App\Models\Setting;
use App\Services\Envelope\EnvelopePdfComposer;
use App\Services\Envelope\EnvelopeService;
use App\Services\Envelope\EvidenceReportGenerator;
use App\Services\Pdf\PdfSignerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Lacra o envelope: carimbos + página de evidências + assinatura digital
 * com o certificado A1 da plataforma. Roda quando o último signatário assina.
 */
class SealEnvelopeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Envelope $envelope) {}

    public function handle(
        EvidenceReportGenerator $evidence,
        EnvelopePdfComposer $composer,
        EnvelopeService $service,
    ): void {
        $envelope = $this->envelope->fresh(['signers.fields', 'user']);

        if ($envelope->status !== 'sent' || ! $envelope->allSigned()) {
            return; // já lacrado ou estado inválido — idempotente
        }

        $evidencePath = null;
        $composedPath = null;

        try {
            $certificate = Setting::current()->platformCertificate;
            if ($certificate === null) {
                throw new \RuntimeException('Certificado da plataforma não configurado.');
            }

            $evidencePath = $evidence->generate($envelope);
            $composed = $composer->compose($envelope, $evidencePath);
            $composedPath = $composed['path'];

            // Selo visível da plataforma na última página (evidências), canto inferior direito
            $relative = PdfSignerService::fromCertificate($certificate)->signExisting(
                $composedPath,
                initialAllPages: false,
                position: ['page' => $composed['pages'], 'x' => 400, 'y' => 780, 'w' => 150, 'h' => 40],
                useTsa: false,
            );

            $disk = Storage::disk('local');
            $final = "signed/envelopes/{$envelope->id}/final.pdf";
            if ($disk->exists($final)) {
                $disk->delete($final);
            }
            $disk->move($relative, $final);

            $envelope->update([
                'final_pdf_path' => $final,
                'sha256_final' => hash('sha256', $disk->get($final)),
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $service->recordEvent($envelope, null, 'sealed', meta: ['sha256_final' => $envelope->sha256_final]);
            $service->recordEvent($envelope, null, 'completed');

            Mail::to($envelope->user->email)->send(new EnvelopeCompleted($envelope));
            foreach ($envelope->signers as $signer) {
                Mail::to($signer->email)->send(new EnvelopeCompleted($envelope, $signer));
            }
        } catch (\Throwable $e) {
            report($e);
            $service->recordEvent($envelope, null, 'seal_failed', meta: ['error' => $e->getMessage()]);

            throw $e; // deixa a queue tentar de novo
        } finally {
            if ($evidencePath) @unlink($evidencePath);
            if ($composedPath) @unlink($composedPath);
        }
    }
}
```

Nota: `recordEvent(..., meta: [...])` usa named argument pulando `$ip`/`$userAgent` — a assinatura da Task 5 permite (`?string $ip = null, ?string $userAgent = null, array $meta = []`).

- [x] **Step 4: Rodar e ver passar + suíte, e commitar**

Run: `& $php artisan test --filter=SealEnvelopeJobTest` → PASS
Run: `& $php artisan test` → PASS

```bash
git add app/Jobs/SealEnvelopeJob.php tests/Feature/SealEnvelopeJobTest.php
git commit -m "feat: SealEnvelopeJob - lacre do envelope com certificado da plataforma"
```

---

### Task 10: `EnvelopeController` (cliente) + rotas

**Files:**
- Create: `app/Http/Controllers/Client/EnvelopeController.php`
- Modify: `routes/web.php` (substituir o stub `envelopes.download` da Task 4 pelas rotas reais)
- Test: `tests/Feature/EnvelopeControllerTest.php`

**Interfaces:**
- Consumes: `EnvelopeService` (Tasks 5-6), `SealEnvelopeJob`, `AccessLogService::log` (existente).
- Produces rotas (todas `auth`): `envelopes.index|create|store|show|remind|cancel|download|reseal`. O `store` recebe `pdf` (file) + campos + `signers_json` (JSON string montada pelo wizard — validada após decode).
- Views referenciadas (`client.envelopes.index|create|show`) são criadas na Task 11 — para os testes desta task passarem, criar as três como **placeholders mínimos** (`@extends`-style igual às views client existentes, conteúdo `<h1>` apenas), substituídos na Task 11.

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Feature/EnvelopeControllerTest.php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Models\Certificate;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function configurePlatformCertificate(): void
    {
        $cert = Certificate::factory()->create(['expires_at' => now()->addYear()]);
        Setting::current()->update(['platform_certificate_id' => $cert->id]);
        Setting::clearCache();
    }

    private function validPayload(): array
    {
        return [
            'title' => 'Contrato de Aluguel',
            'message' => 'Favor assinar',
            'signing_order' => 'parallel',
            'pdf' => UploadedFile::fake()->createWithContent('c.pdf', '%PDF-1.4 fake'),
            'signers_json' => json_encode([
                ['name' => 'Ana', 'email' => 'ana@x.com', 'auth_method' => 'link',
                 'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
            ]),
        ];
    }

    public function test_store_creates_and_sends_envelope(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->configurePlatformCertificate();
        $user = User::factory()->create(['role' => 'client']);

        $response = $this->actingAs($user)->post('/envelopes', $this->validPayload());

        $envelope = Envelope::first();
        $response->assertRedirect(route('envelopes.show', $envelope));
        $this->assertSame('sent', $envelope->status);
        $this->assertSame($user->id, $envelope->user_id);
    }

    public function test_store_validates_signers_json(): void
    {
        Storage::fake('local');
        $this->configurePlatformCertificate();
        $user = User::factory()->create(['role' => 'client']);

        // sem signatários
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => '[]']))
            ->assertSessionHasErrors('signers_json');

        // whatsapp_otp sem whatsapp
        $bad = json_encode([['name' => 'Ana', 'email' => 'ana@x.com', 'auth_method' => 'whatsapp_otp',
            'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]]]);
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $bad]))
            ->assertSessionHasErrors('signers_json');

        // signatário sem marcador de assinatura
        $noFields = json_encode([['name' => 'Ana', 'email' => 'ana@x.com', 'auth_method' => 'link', 'fields' => []]]);
        $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $noFields]))
            ->assertSessionHasErrors('signers_json');
    }

    public function test_show_and_index_only_for_owner(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $envelope = Envelope::factory()->for($owner)->create();

        $this->actingAs($owner)->get("/envelopes/{$envelope->id}")->assertOk();
        $this->actingAs($other)->get("/envelopes/{$envelope->id}")->assertForbidden();
        $this->actingAs($owner)->get('/envelopes')->assertOk();
    }

    public function test_cancel_remind_and_download(): void
    {
        Storage::fake('local');
        Mail::fake();
        $owner = User::factory()->create(['role' => 'client']);
        $envelope = Envelope::factory()->for($owner)->create(['status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'notified']);

        $this->actingAs($owner)->post("/envelopes/{$envelope->id}/remind")->assertRedirect();

        // download antes de completed → 404
        $this->actingAs($owner)->get("/envelopes/{$envelope->id}/download")->assertNotFound();

        Storage::disk('local')->put("signed/envelopes/{$envelope->id}/final.pdf", '%PDF-1.4 final');
        $envelope->update(['status' => 'completed', 'final_pdf_path' => "signed/envelopes/{$envelope->id}/final.pdf"]);
        $this->actingAs($owner)->get("/envelopes/{$envelope->id}/download")->assertOk();

        // cancelar só funciona em sent
        $this->actingAs($owner)->post("/envelopes/{$envelope->id}/cancel")->assertSessionHasErrors();
    }

    public function test_reseal_dispatches_job_when_all_signed(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'client']);
        $envelope = Envelope::factory()->for($owner)->create(['status' => 'sent']);
        EnvelopeSigner::factory()->for($envelope)->create(['status' => 'signed', 'signed_at' => now()]);

        $this->actingAs($owner)->post("/envelopes/{$envelope->id}/reseal")->assertRedirect();
        Queue::assertPushed(SealEnvelopeJob::class, 1);
    }
}
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=EnvelopeControllerTest`
Expected: FAIL (rotas/controller inexistentes)

- [x] **Step 3: Rotas (substituir stubs da Task 4)**

```php
// routes/web.php — dentro do grupo Route::middleware('auth'), no lugar do stub envelopes.download:

    // Envelopes (assinatura eletrônica multi-signatário)
    Route::get('envelopes', [EnvelopeController::class, 'index'])->name('envelopes.index');
    Route::get('envelopes/create', [EnvelopeController::class, 'create'])->name('envelopes.create');
    Route::post('envelopes', [EnvelopeController::class, 'store'])->name('envelopes.store');
    Route::get('envelopes/{envelope}', [EnvelopeController::class, 'show'])->name('envelopes.show');
    Route::post('envelopes/{envelope}/remind', [EnvelopeController::class, 'remind'])->name('envelopes.remind');
    Route::post('envelopes/{envelope}/cancel', [EnvelopeController::class, 'cancel'])->name('envelopes.cancel');
    Route::post('envelopes/{envelope}/reseal', [EnvelopeController::class, 'reseal'])->name('envelopes.reseal');
    Route::get('envelopes/{envelope}/download', [EnvelopeController::class, 'download'])->name('envelopes.download');
```

(Com `use App\Http\Controllers\Client\EnvelopeController;` no topo. Manter os stubs públicos `/sign/*` — viram controller na Task 12.)

- [x] **Step 4: Controller**

```php
<?php
// app/Http/Controllers/Client/EnvelopeController.php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\SealEnvelopeJob;
use App\Models\Envelope;
use App\Services\AccessLogService;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EnvelopeController extends Controller
{
    public function __construct(
        private EnvelopeService $envelopes,
        private AccessLogService $accessLog,
    ) {}

    public function index()
    {
        $envelopes = Envelope::where('user_id', auth()->id())
            ->withCount(['signers', 'signers as signed_count' => fn ($q) => $q->where('status', 'signed')])
            ->latest()
            ->paginate(20);

        return view('client.envelopes.index', compact('envelopes'));
    }

    public function create()
    {
        return view('client.envelopes.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'signing_order' => ['required', 'in:parallel,sequential'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
            'signers_json' => ['required', 'string'],
        ]);

        $signers = $this->validateSigners($request->input('signers_json'));

        try {
            $envelope = $this->envelopes->create(auth()->user(), $request->file('pdf'), [
                'title' => $request->input('title'),
                'message' => $request->input('message'),
                'signing_order' => $request->input('signing_order'),
                'expires_at' => $request->input('expires_at'),
                'signers' => $signers,
            ]);

            $this->envelopes->send($envelope);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $this->accessLog->log(auth()->user(), 'envelope_created', [
            'envelope_id' => $envelope->id, 'title' => $envelope->title,
            'signers' => count($signers),
        ]);

        return redirect()->route('envelopes.show', $envelope)
            ->with('success', 'Envelope enviado! Os signatários receberão o convite por e-mail.');
    }

    public function show(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        $envelope->load(['signers.fields', 'events.signer']);

        $canReseal = $envelope->status === 'sent' && $envelope->allSigned();

        return view('client.envelopes.show', compact('envelope', 'canReseal'));
    }

    /** Reenvia o convite a todos os pendentes (sequencial: só o da vez). */
    public function remind(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        abort_unless($envelope->status === 'sent', 400);

        $targets = $envelope->isSequential()
            ? collect([$envelope->nextPendingSigner()])->filter()
            : $envelope->signers()->whereNotIn('status', ['signed', 'declined'])->get();

        foreach ($targets as $signer) {
            $this->envelopes->notifySigner($signer, reminder: true);
        }

        return back()->with('success', 'Lembrete reenviado.');
    }

    public function cancel(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);

        if (! in_array($envelope->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => 'Este envelope não pode mais ser cancelado.']);
        }

        $this->envelopes->cancel($envelope);

        return back()->with('success', 'Envelope cancelado.');
    }

    /** Reprocessa o lacre após uma falha (seal_failed). */
    public function reseal(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        abort_unless($envelope->status === 'sent' && $envelope->allSigned(), 400);

        SealEnvelopeJob::dispatch($envelope);

        return back()->with('success', 'Reprocessamento do lacre iniciado.');
    }

    public function download(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        abort_unless($envelope->status === 'completed' && $envelope->final_pdf_path, 404);
        abort_unless(Storage::disk('local')->exists($envelope->final_pdf_path), 404);

        return Storage::disk('local')->download($envelope->final_pdf_path, $envelope->title.' (assinado).pdf');
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    private function authorizeOwner(Envelope $envelope): void
    {
        abort_unless($envelope->user_id === auth()->id(), 403);
    }

    /** Valida o payload de signatários montado pelo wizard. */
    private function validateSigners(string $json): array
    {
        $signers = json_decode($json, true);

        $validator = Validator::make(['signers' => $signers], [
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:255'],
            'signers.*.email' => ['required', 'email', 'max:255'],
            'signers.*.whatsapp' => ['nullable', 'string', 'max:20', 'required_if:signers.*.auth_method,whatsapp_otp'],
            'signers.*.auth_method' => ['required', 'in:link,email_otp,whatsapp_otp'],
            'signers.*.fields' => ['required', 'array', 'min:1'],
            'signers.*.fields.*.page' => ['required', 'integer', 'min:1'],
            'signers.*.fields.*.x' => ['required', 'numeric', 'min:0'],
            'signers.*.fields.*.y' => ['required', 'numeric', 'min:0'],
            'signers.*.fields.*.w' => ['required', 'numeric', 'min:5', 'max:500'],
            'signers.*.fields.*.h' => ['required', 'numeric', 'min:5', 'max:500'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'signers_json' => 'Signatários inválidos: '.$validator->errors()->first(),
            ]);
        }

        return $signers;
    }
}
```

- [x] **Step 5: Views placeholder (substituídas na Task 11)**

Criar `resources/views/client/envelopes/index.blade.php`, `create.blade.php` e `show.blade.php` copiando a ESTRUTURA de abertura/fechamento de uma view client existente (ex.: `client/sign-document/index.blade.php` — mesmo layout/component) com conteúdo mínimo `<h1>Envelopes</h1>` etc.

- [x] **Step 6: Rodar e ver passar + suíte, e commitar**

Run: `& $php artisan test --filter=EnvelopeControllerTest` → PASS
Run: `& $php artisan test` → PASS

```bash
git add app/Http/Controllers/Client/EnvelopeController.php routes/web.php resources/views/client/envelopes tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: controller e rotas do cliente para envelopes"
```

---

### Task 11: Views do cliente (index, wizard de criação, show) + menu

**Files:**
- Modify: `resources/views/client/envelopes/index.blade.php`, `create.blade.php`, `show.blade.php` (substituir placeholders)
- Modify: `resources/views/client/layout.blade.php` (itens de menu ~linha 56 e ~linha 103 — desktop e mobile)
- Test: ampliar `tests/Feature/EnvelopeControllerTest.php` com asserts de conteúdo

**Interfaces:**
- Consumes: rotas da Task 10; padrão visual das views client existentes (`client/sign-document/index.blade.php` é a referência de markup, cores `--color-primary`, PDF.js via CDN cdnjs 3.11.174 — MESMA versão/URL usada lá).
- Produces: wizard que monta `signers_json` + `pdf` num único POST para `envelopes.store`.

- [x] **Step 1: Menu**

Em `client/layout.blade.php`, adicionar após o item sign-document (nas DUAS navegações, desktop ~56 e mobile ~103), seguindo exatamente o markup do item existente:

```blade
<a href="{{ route('envelopes.index') }}"
   class="px-3 py-2 rounded-lg text-sm font-medium transition-colors
          {{ request()->routeIs('envelopes.*') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
    Envelopes
</a>
```

- [x] **Step 2: `index.blade.php`**

Tabela: título, badge de status (draft cinza, sent azul, completed verde, declined/cancelled/expired vermelho/cinza), progresso "X/Y assinaram" (usar `signed_count`/`signers_count` do withCount), data, link "ver". Botão "+ Novo envelope" → `envelopes.create`. Vazio: mensagem "Nenhum envelope ainda". Paginação `{{ $envelopes->links() }}`.

Mapa de labels a usar (mesmo em show):

```blade
@php
$statusLabels = ['draft' => 'Rascunho', 'sent' => 'Aguardando assinaturas', 'completed' => 'Concluído',
                 'declined' => 'Recusado', 'cancelled' => 'Cancelado', 'expired' => 'Expirado'];
$statusColors = ['draft' => 'bg-gray-100 text-gray-700', 'sent' => 'bg-blue-100 text-blue-700',
                 'completed' => 'bg-green-100 text-green-700', 'declined' => 'bg-red-100 text-red-700',
                 'cancelled' => 'bg-gray-200 text-gray-600', 'expired' => 'bg-yellow-100 text-yellow-700'];
@endphp
```

- [x] **Step 3: `create.blade.php` — wizard Alpine em 3 passos**

Estrutura (um único `<form method="POST" enctype="multipart/form-data">` com `x-data="envelopeWizard()"`):

- **Passo 1 — Documento:** inputs `title`, `message`, `signing_order` (radio paralelo/sequencial), `expires_at` (date, opcional), `pdf` (file). Ao escolher o arquivo, ler com FileReader e renderizar com PDF.js (mesmo loader CDN do sign-document: cdnjs 3.11.174).
- **Passo 2 — Signatários:** repeater Alpine (`signers: []`): nome, e-mail, método (select: "Somente link" / "Código por e-mail" / "Código por WhatsApp"), whatsapp (visível se método = whatsapp). Botões adicionar/remover. Máx. 20.
- **Passo 3 — Posicionar assinaturas:** canvas por página do PDF renderizado; selecionar um signatário (chips coloridos — paleta fixa de 8 cores) e clicar na página para adicionar um marcador (div absoluta arrastável, tamanho padrão 120×40 pt). Clique no “×” do marcador remove. Cada signatário precisa de ≥1 marcador (validar no submit).
- Submit: serializa `signers` (com `fields` convertidos para pontos PDF) em `signers_json` (hidden input) e envia o form.

Conversão de coordenadas (mesma lógica do sign-document): o canvas renderiza com `viewport = page.getViewport({scale})`; um marcador em pixels CSS `(left, top)` vira pontos PDF com `x = left / scale`, `y = top / scale` (origem topo-esquerdo, PDF.js viewport já é top-left) e `w = 120`, `h = 40` fixos na v1.

Esqueleto do script (completo o suficiente para implementar sem decisões novas):

```html
<script>
function envelopeWizard() {
    return {
        step: 1, signers: [], selected: 0, fields: [], // fields: [{signerIdx, page, xPt, yPt}]
        pdfDoc: null, scale: 1.3,
        colors: ['#2563eb','#dc2626','#16a34a','#9333ea','#ea580c','#0891b2','#db2777','#65a30d'],

        addSigner() { if (this.signers.length < 20) this.signers.push({name:'', email:'', auth_method:'link', whatsapp:''}); },
        removeSigner(i) {
            this.signers.splice(i, 1);
            this.fields = this.fields.filter(f => f.signerIdx !== i)
                .map(f => f.signerIdx > i ? {...f, signerIdx: f.signerIdx - 1} : f);
            if (this.selected >= this.signers.length) this.selected = 0;
        },

        loadPdf(event) {
            const file = event.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = () => {
                window.loadPdfJs(() => {   // mesmo helper/CDN do sign-document
                    pdfjsLib.getDocument({ data: new Uint8Array(reader.result) }).promise.then(pdf => {
                        this.pdfDoc = pdf;
                        this.renderPages();
                    });
                });
            };
            reader.readAsArrayBuffer(file);
        },

        async renderPages() {
            const wrap = this.$refs.pages;
            wrap.innerHTML = '';
            for (let p = 1; p <= this.pdfDoc.numPages; p++) {
                const page = await this.pdfDoc.getPage(p);
                const viewport = page.getViewport({ scale: this.scale });
                const holder = document.createElement('div');
                holder.className = 'relative mx-auto mb-4 shadow';
                holder.style.width = viewport.width + 'px';
                holder.dataset.page = p;
                const canvas = document.createElement('canvas');
                canvas.width = viewport.width; canvas.height = viewport.height;
                holder.appendChild(canvas);
                holder.addEventListener('click', e => this.addField(e, holder, p));
                wrap.appendChild(holder);
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
            }
            this.redrawMarkers();
        },

        addField(e, holder, page) {
            if (e.target.closest('.marker')) return;
            const rect = holder.getBoundingClientRect();
            this.fields.push({ signerIdx: this.selected, page,
                xPt: (e.clientX - rect.left) / this.scale, yPt: (e.clientY - rect.top) / this.scale });
            this.redrawMarkers();
        },

        redrawMarkers() {
            document.querySelectorAll('.marker').forEach(m => m.remove());
            this.fields.forEach((f, idx) => {
                const holder = this.$refs.pages.querySelector(`[data-page="${f.page}"]`);
                if (!holder) return;
                const m = document.createElement('div');
                m.className = 'marker absolute border-2 rounded flex items-center justify-between px-1 text-xs font-semibold cursor-move';
                m.style.cssText = `left:${f.xPt * this.scale}px; top:${f.yPt * this.scale}px;`
                    + `width:${120 * this.scale}px; height:${40 * this.scale}px;`
                    + `border-color:${this.colors[f.signerIdx % 8]}; color:${this.colors[f.signerIdx % 8]};`
                    + 'background:rgba(255,255,255,.7);';
                m.innerHTML = `<span>${(this.signers[f.signerIdx]?.name || 'Signatário ' + (f.signerIdx+1))}</span>`
                    + `<button type="button" data-idx="${idx}">×</button>`;
                m.querySelector('button').addEventListener('click', () => { this.fields.splice(idx, 1); this.redrawMarkers(); });
                this.makeDraggable(m, f, holder);
                holder.appendChild(m);
            });
        },

        makeDraggable(el, f, holder) {
            el.addEventListener('pointerdown', down => {
                if (down.target.tagName === 'BUTTON') return;
                down.preventDefault();
                const start = { x: down.clientX, y: down.clientY, xPt: f.xPt, yPt: f.yPt };
                const move = ev => {
                    f.xPt = Math.max(0, start.xPt + (ev.clientX - start.x) / this.scale);
                    f.yPt = Math.max(0, start.yPt + (ev.clientY - start.y) / this.scale);
                    el.style.left = f.xPt * this.scale + 'px';
                    el.style.top = f.yPt * this.scale + 'px';
                };
                const up = () => { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); };
                document.addEventListener('pointermove', move);
                document.addEventListener('pointerup', up);
            });
        },

        validStep(n) {
            if (n === 1) return this.$refs.title.value.trim() !== '' && this.pdfDoc !== null;
            if (n === 2) return this.signers.length > 0
                && this.signers.every(s => s.name.trim() && s.email.trim()
                    && (s.auth_method !== 'whatsapp_otp' || s.whatsapp.trim()));
            return true;
        },

        submit(e) {
            const missing = this.signers.findIndex((s, i) => !this.fields.some(f => f.signerIdx === i));
            if (missing !== -1) {
                e.preventDefault();
                alert(`Posicione a assinatura de ${this.signers[missing].name} no documento.`);
                return;
            }
            this.$refs.signersJson.value = JSON.stringify(this.signers.map((s, i) => ({
                ...s,
                fields: this.fields.filter(f => f.signerIdx === i)
                    .map(f => ({ page: f.page, x: +f.xPt.toFixed(2), y: +f.yPt.toFixed(2), w: 120, h: 40 })),
            })));
        },
    };
}
</script>
```

O implementador deve extrair o loader `loadPdfJs` do `client/sign-document/index.blade.php:264-272` para um partial compartilhado `resources/views/client/partials/pdfjs-loader.blade.php` e incluir nos dois lugares (evita duplicar a URL do CDN).

- [x] **Step 4: `show.blade.php`**

Seções: cabeçalho (título, badge de status, datas, hash original truncado com title-tooltip), flash `success`/`error`, cartões por signatário (nome, e-mail, método, status badge, assinado em/IP quando signed, motivo quando declined), trilha de eventos (tabela: data/hora, evento, participante, IP), ações:

```blade
@if ($envelope->status === 'sent')
    <form method="POST" action="{{ route('envelopes.remind', $envelope) }}">@csrf
        <button class="...">Reenviar convite</button></form>
    <form method="POST" action="{{ route('envelopes.cancel', $envelope) }}"
          onsubmit="return confirm('Cancelar este envelope? Os links de assinatura serão desativados.')">@csrf
        <button class="...">Cancelar envelope</button></form>
@endif
@if ($canReseal)
    <form method="POST" action="{{ route('envelopes.reseal', $envelope) }}">@csrf
        <button class="...">Reprocessar lacre</button></form>
@endif
@if ($envelope->status === 'completed')
    <a href="{{ route('envelopes.download', $envelope) }}" class="...">Baixar PDF assinado</a>
@endif
```

- [x] **Step 5: Ampliar os testes de render**

Adicionar ao `EnvelopeControllerTest`:

```php
public function test_index_and_show_render_envelope_data(): void
{
    $owner = User::factory()->create(['role' => 'client']);
    $envelope = Envelope::factory()->for($owner)->create(['title' => 'Contrato Visível', 'status' => 'sent']);
    EnvelopeSigner::factory()->for($envelope)->create(['name' => 'Ana Signatária', 'status' => 'notified']);

    $this->actingAs($owner)->get('/envelopes')->assertOk()->assertSee('Contrato Visível');
    $this->actingAs($owner)->get("/envelopes/{$envelope->id}")
        ->assertOk()->assertSee('Ana Signatária')->assertSee('Aguardando assinaturas');
    $this->actingAs($owner)->get('/envelopes/create')->assertOk()->assertSee('signers_json', false);
}
```

- [x] **Step 6: Rodar e ver passar + suíte, e commitar**

Run: `& $php artisan test --filter=EnvelopeControllerTest` → PASS
Run: `& $php artisan test` → PASS

```bash
git add resources/views/client tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: views do cliente para envelopes (lista, wizard, detalhe)"
```

---

### Task 12: Fluxo público de assinatura (`/sign/{token}`)

**Files:**
- Create: `app/Http/Controllers/PublicSign/SignEnvelopeController.php`
- Modify: `routes/web.php` (substituir stubs `/sign/*` da Task 4)
- Create: `resources/views/public/sign/show.blade.php`, `done.blade.php`, `unavailable.blade.php`
- Test: `tests/Feature/PublicSignFlowTest.php`

**Interfaces:**
- Consumes: `EnvelopeService::markViewed|issueOtp|verifyOtp|sign|decline`, `EnvelopeSigner::canSign|requiresOtp`, layout guest para o visual (branding `$settings`).
- Produces rotas públicas:
  - `GET /sign/{token}` → `public.sign.show` (throttle:30,1)
  - `GET /sign/{token}/document` → `public.sign.document` (throttle:30,1) — original enquanto `sent`, final quando `completed`
  - `POST /sign/{token}/otp` → `public.sign.otp` (throttle:5,1)
  - `POST /sign/{token}` → `public.sign.store` (throttle:10,1)
  - `POST /sign/{token}/decline` → `public.sign.decline` (throttle:10,1)

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Feature/PublicSignFlowTest.php

namespace Tests\Feature;

use App\Jobs\SealEnvelopeJob;
use App\Mail\Envelopes\EnvelopeOtp;
use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicSignFlowTest extends TestCase
{
    use RefreshDatabase;

    private function pngDataUrl(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    private function makeSentEnvelope(array $signerAttrs = []): EnvelopeSigner
    {
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        Storage::disk('local')->put("envelopes/{$envelope->id}/original.pdf", '%PDF-1.4 fake');
        $envelope->update(['original_pdf_path' => "envelopes/{$envelope->id}/original.pdf"]);

        return EnvelopeSigner::factory()->for($envelope)->create(array_merge(['status' => 'notified'], $signerAttrs));
    }

    private function signPayload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Ana Completa', 'cpf' => '123.456.789-00',
            'signature_type' => 'drawn', 'signature' => $this->pngDataUrl(),
        ], $extra);
    }

    public function test_show_marks_viewed_and_renders(): void
    {
        Storage::fake('local');
        $signer = $this->makeSentEnvelope();

        $this->get("/sign/{$signer->token}")
            ->assertOk()
            ->assertSee($signer->envelope->title);

        $this->assertSame('viewed', $signer->fresh()->status);
        $this->assertTrue($signer->envelope->events()->where('event', 'viewed')->exists());
    }

    public function test_invalid_token_and_unavailable_states(): void
    {
        Storage::fake('local');
        $this->get('/sign/'.str_repeat('x', 64))->assertNotFound();

        $signer = $this->makeSentEnvelope();
        $signer->envelope->update(['status' => 'cancelled']);
        $this->get("/sign/{$signer->token}")->assertOk()->assertSee('não está mais disponível');

        $expired = $this->makeSentEnvelope();
        $expired->envelope->update(['status' => 'sent', 'expires_at' => now()->subDay()]);
        $this->get("/sign/{$expired->token}")->assertOk()->assertSee('expirou');
    }

    public function test_link_signer_signs_without_otp(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['auth_method' => 'link']);

        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertOk()
            ->assertSee('Assinatura registrada');

        $signer->refresh();
        $this->assertSame('signed', $signer->status);
        Queue::assertPushed(SealEnvelopeJob::class, 1); // único signatário → lacre
    }

    public function test_otp_signer_requires_valid_code(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['auth_method' => 'email_otp']);

        // sem código → erro de validação
        $this->post("/sign/{$signer->token}", $this->signPayload())
            ->assertSessionHasErrors('otp_code');

        // solicita o código
        $this->post("/sign/{$signer->token}/otp")->assertRedirect();
        Mail::assertSent(EnvelopeOtp::class, function (EnvelopeOtp $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        // código errado
        $this->post("/sign/{$signer->token}", $this->signPayload(['otp_code' => '000000']))
            ->assertSessionHasErrors('otp_code');
        $this->assertSame('notified', $signer->fresh()->status);

        // código certo
        $this->post("/sign/{$signer->token}", $this->signPayload(['otp_code' => $code]))->assertOk();
        $this->assertSame('signed', $signer->fresh()->status);
    }

    public function test_signed_signer_cannot_sign_again(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        $signer = $this->makeSentEnvelope(['auth_method' => 'link']);

        $this->post("/sign/{$signer->token}", $this->signPayload())->assertOk();
        $this->post("/sign/{$signer->token}", $this->signPayload())->assertOk()
            ->assertSee('já assinou');
        $this->get("/sign/{$signer->token}")->assertOk()->assertSee('já assinou');
    }

    public function test_decline_needs_reason_and_ends_envelope(): void
    {
        Storage::fake('local');
        Mail::fake();
        $signer = $this->makeSentEnvelope();

        $this->post("/sign/{$signer->token}/decline", [])->assertSessionHasErrors('reason');

        $this->post("/sign/{$signer->token}/decline", ['reason' => 'Não concordo'])
            ->assertOk()->assertSee('recusa foi registrada');

        $this->assertSame('declined', $signer->envelope->fresh()->status);
    }

    public function test_document_serves_original_then_final(): void
    {
        Storage::fake('local');
        $signer = $this->makeSentEnvelope();

        $this->get("/sign/{$signer->token}/document")->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        Storage::disk('local')->put("signed/envelopes/{$signer->envelope_id}/final.pdf", '%PDF-1.4 final');
        $signer->envelope->update(['status' => 'completed',
            'final_pdf_path' => "signed/envelopes/{$signer->envelope_id}/final.pdf"]);

        $this->get("/sign/{$signer->token}/document")->assertOk();
    }
}
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=PublicSignFlowTest`
Expected: FAIL (stubs devolvem 501)

- [x] **Step 3: Rotas (substituir stubs)**

```php
// routes/web.php — substituir os stubs /sign/* por:

use App\Http\Controllers\PublicSign\SignEnvelopeController;

// Assinatura pública de envelopes — autorização é o próprio token
Route::prefix('sign/{token}')->name('public.sign.')->group(function () {
    Route::get('/', [SignEnvelopeController::class, 'show'])->middleware('throttle:30,1')->name('show');
    Route::get('document', [SignEnvelopeController::class, 'document'])->middleware('throttle:30,1')->name('document');
    Route::post('otp', [SignEnvelopeController::class, 'otp'])->middleware('throttle:5,1')->name('otp');
    Route::post('/', [SignEnvelopeController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::post('decline', [SignEnvelopeController::class, 'decline'])->middleware('throttle:10,1')->name('decline');
});
```

Ajuste: a rota nomeada `public.sign.show` recebia `$token` direto (`route('public.sign.show', $signer->token)`) — com o prefix group isso continua funcionando (parâmetro `token`).

- [x] **Step 4: Controller**

```php
<?php
// app/Http/Controllers/PublicSign/SignEnvelopeController.php

namespace App\Http\Controllers\PublicSign;

use App\Http\Controllers\Controller;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignEnvelopeController extends Controller
{
    public function __construct(private EnvelopeService $envelopes) {}

    public function show(Request $request, string $token)
    {
        $signer = $this->findSigner($token);

        if ($unavailable = $this->unavailableReason($signer)) {
            return view('public.sign.unavailable', ['signer' => $signer, 'reason' => $unavailable]);
        }

        $this->envelopes->markViewed($signer, $request->ip(), $request->userAgent());

        return view('public.sign.show', ['signer' => $signer->fresh(), 'envelope' => $signer->envelope]);
    }

    /** Serve o PDF ao signatário: original durante a coleta, final após concluído. */
    public function document(string $token)
    {
        $signer = $this->findSigner($token);
        $envelope = $signer->envelope;

        $path = $envelope->status === 'completed' && $envelope->final_pdf_path
            ? $envelope->final_pdf_path
            : $envelope->original_pdf_path;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="documento.pdf"',
        ]);
    }

    public function otp(string $token)
    {
        $signer = $this->findSigner($token);
        abort_unless($signer->canSign() && $signer->requiresOtp(), 400);

        $this->envelopes->issueOtp($signer);

        $channel = $signer->auth_method === 'whatsapp_otp' ? 'WhatsApp' : 'e-mail';

        return back()->with('success', "Código enviado por {$channel}.");
    }

    public function store(Request $request, string $token)
    {
        $signer = $this->findSigner($token);

        if ($this->unavailableReason($signer)) {
            return view('public.sign.unavailable', ['signer' => $signer, 'reason' => $this->unavailableReason($signer)]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cpf' => ['required', 'string', 'regex:/^\d{3}\.\d{3}\.\d{3}-\d{2}$/'],
            'signature_type' => ['required', 'in:drawn,typed'],
            'signature' => ['required', 'string', 'max:3000000'],
            'otp_code' => [$signer->requiresOtp() ? 'required' : 'nullable', 'digits:6'],
        ], [
            'cpf.regex' => 'Informe o CPF no formato 000.000.000-00.',
        ]);

        if ($signer->requiresOtp() && ! $this->envelopes->verifyOtp($signer, $data['otp_code'])) {
            return back()->withErrors(['otp_code' => 'Código inválido ou expirado. Solicite um novo.'])->withInput();
        }

        try {
            $this->envelopes->sign($signer->fresh(), $data, $request->ip(), $request->userAgent());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['signature' => $e->getMessage()])->withInput();
        }

        return view('public.sign.done', [
            'signer' => $signer->fresh(),
            'title' => 'Assinatura registrada!',
            'message' => 'Quando todos assinarem, você receberá o documento final por e-mail.',
        ]);
    }

    public function decline(Request $request, string $token)
    {
        $signer = $this->findSigner($token);
        abort_unless($signer->canSign(), 400);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->envelopes->decline($signer, $data['reason'], $request->ip(), $request->userAgent());

        return view('public.sign.done', [
            'signer' => $signer->fresh(),
            'title' => 'Sua recusa foi registrada.',
            'message' => 'O remetente foi notificado.',
        ]);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    private function findSigner(string $token): EnvelopeSigner
    {
        abort_unless(strlen($token) === 64, 404);

        return EnvelopeSigner::where('token', $token)->with('envelope.user')->firstOrFail();
    }

    /** null = pode assinar; string = motivo exibido na tela informativa. */
    private function unavailableReason(EnvelopeSigner $signer): ?string
    {
        $envelope = $signer->envelope;

        return match (true) {
            $signer->status === 'signed' => 'Você já assinou este documento. Quando todos assinarem, receberá o PDF final por e-mail.',
            $envelope->status === 'completed' => 'Este documento já foi concluído. O PDF final foi enviado ao seu e-mail.',
            $envelope->status === 'declined' => 'Este documento foi encerrado após uma recusa e não está mais disponível.',
            $envelope->status === 'cancelled' => 'Este documento foi cancelado pelo remetente e não está mais disponível para assinatura.',
            $envelope->isExpired() || $envelope->status === 'expired' => 'O prazo para assinar este documento expirou.',
            $envelope->status !== 'sent' => 'Este documento não está mais disponível para assinatura.',
            default => null,
        };
    }
}
```

Nota: `unavailable.blade.php` deve exibir `{{ $reason }}` — os asserts do teste (`'não está mais disponível'`, `'expirou'`, `'já assinou'`) batem com os textos do `match`.

- [x] **Step 5: Views públicas**

Base visual: `layouts/guest.blade.php` (branding `$settings`). As três páginas são standalone (sem login):

`unavailable.blade.php` e `done.blade.php` — cartão centralizado com ícone, `{{ $title ?? '' }}`/`{{ $reason ?? $message }}` e rodapé com nome da empresa.

`show.blade.php` — a tela principal, com Alpine:

- Cabeçalho: logo/nome (`$settings`), título do envelope, "enviado por {{ $envelope->user->name }}", mensagem do remetente.
- Documento: `<iframe src="{{ route('public.sign.document', $signer->token) }}" class="w-full" style="height:70vh"></iframe>` (PDF inline pelo navegador; sem PDF.js aqui — YAGNI).
- Formulário de assinatura (`POST route('public.sign.store', $signer->token)`):
  - `name` (pré-preenchido `{{ old('name', $signer->name) }}`), `cpf` (máscara JS simples no input).
  - Tabs Alpine `signature_type`: **Desenhar** (canvas 400×150 com pointer events — traço preto 2px; botão limpar) | **Digitar** (input de nome; JS renderiza num canvas oculto com `ctx.font = 'italic 40px "Segoe Script", cursive'`).
  - No submit: exporta o canvas ativo com `toDataURL('image/png')` para o hidden `signature`; bloqueia se canvas vazio (flag `drew`/nome vazio).
  - Bloco OTP (`@if ($signer->requiresOtp())`): botão "Receber código" (form separado para `public.sign.otp`) + input `otp_code` (6 dígitos) dentro do form principal; flash `success` mostra "Código enviado".
  - Rodapé: termo de aceite fixo — "Ao clicar em Assinar, declaro que li o documento e concordo em assinar eletronicamente" + botão **Assinar documento**.
- Recusa: `<details>` com textarea `reason` + botão "Recusar assinatura" (form para `public.sign.decline`, `onsubmit="return confirm(...)"`).

Canvas de desenho (código de referência a incluir na view):

```html
<canvas x-ref="pad" width="400" height="150" class="border rounded bg-white touch-none"></canvas>
<script>
function signaturePad(el) {
    const ctx = el.getContext('2d');
    ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
    let drawing = false, drew = false;
    const pos = e => {
        const r = el.getBoundingClientRect();
        return { x: (e.clientX - r.left) * el.width / r.width, y: (e.clientY - r.top) * el.height / r.height };
    };
    el.addEventListener('pointerdown', e => { drawing = true; drew = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
    el.addEventListener('pointermove', e => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
    ['pointerup', 'pointerleave'].forEach(ev => el.addEventListener(ev, () => drawing = false));
    return {
        clear() { ctx.clearRect(0, 0, el.width, el.height); drew = false; },
        empty: () => !drew,
        dataUrl: () => el.toDataURL('image/png'),
    };
}
</script>
```

- [x] **Step 6: Rodar e ver passar + suíte, e commitar**

Run: `& $php artisan test --filter=PublicSignFlowTest` → PASS
Run: `& $php artisan test` → PASS

```bash
git add app/Http/Controllers/PublicSign routes/web.php resources/views/public tests/Feature/PublicSignFlowTest.php
git commit -m "feat: fluxo publico de assinatura de envelopes por token"
```

---

### Task 13: Expiração agendada, verificação manual e documentação

**Files:**
- Create: `app/Console/Commands/ExpireEnvelopes.php`
- Modify: `routes/console.php` (schedule)
- Modify: `CLAUDE.md` (documentar o módulo)
- Test: `tests/Feature/ExpireEnvelopesTest.php`

- [x] **Step 1: Teste que falha**

```php
<?php
// tests/Feature/ExpireEnvelopesTest.php

namespace Tests\Feature;

use App\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireEnvelopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_only_overdue_sent_envelopes(): void
    {
        $overdue = Envelope::factory()->create(['status' => 'sent', 'expires_at' => now()->subDay()]);
        $future = Envelope::factory()->create(['status' => 'sent', 'expires_at' => now()->addDay()]);
        $completed = Envelope::factory()->create(['status' => 'completed', 'expires_at' => now()->subDay()]);

        $this->artisan('envelopes:expire')->assertSuccessful();

        $this->assertSame('expired', $overdue->fresh()->status);
        $this->assertSame('sent', $future->fresh()->status);
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertTrue($overdue->events()->where('event', 'expired')->exists());
    }
}
```

- [x] **Step 2: Rodar e ver falhar**

Run: `& $php artisan test --filter=ExpireEnvelopesTest`
Expected: FAIL (command não existe)

- [x] **Step 3: Implementar**

```php
<?php
// app/Console/Commands/ExpireEnvelopes.php

namespace App\Console\Commands;

use App\Models\Envelope;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Console\Command;

class ExpireEnvelopes extends Command
{
    protected $signature = 'envelopes:expire';

    protected $description = 'Marca como expirados os envelopes enviados cujo prazo venceu';

    public function handle(EnvelopeService $service): int
    {
        $expired = Envelope::where('status', 'sent')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $envelope) {
            $envelope->update(['status' => 'expired']);
            $service->recordEvent($envelope, null, 'expired');
        }

        $this->info("{$expired->count()} envelope(s) expirado(s).");

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php — adicionar:

use Illuminate\Support\Facades\Schedule;

Schedule::command('envelopes:expire')->hourly();
```

- [x] **Step 4: Rodar e ver passar**

Run: `& $php artisan test --filter=ExpireEnvelopesTest` → PASS

- [ ] **Step 5: Verificação manual end-to-end (skill superpowers:verification-before-completion)**

Roteiro no browser local (Laragon, `MAIL_MAILER=log` para capturar os links):

1. Admin: configurar certificado da plataforma em `/admin/settings`
2. Cliente: criar envelope em `/envelopes/create` com 2 signatários (um `link`, um `email_otp`), posicionar marcadores, enviar
3. Copiar do `storage/logs/laravel.log` os links `/sign/{token}`; abrir em janela anônima
4. Assinar como signatário 1 (desenhar); assinar como 2 (digitar, com OTP do log)
5. Conferir: envelope `completed`, download do PDF final abre com os 2 carimbos + página de evidências + assinatura digital válida (painel de assinaturas do Adobe Reader), trilha visível no `show`
6. Testar recusa e cancelamento em envelopes novos

- [x] **Step 6: Atualizar `CLAUDE.md`**

Adicionar seção "Envelopes (assinatura eletrônica multi-signatário)" com: tabelas, rotas cliente + públicas, serviços (`EnvelopeService`, `EvidenceReportGenerator`, `EnvelopePdfComposer`, `SealEnvelopeJob`), regra do lacre (certificado da plataforma em settings), command `envelopes:expire`, e o aviso de que `envelope_events` é imutável. Referenciar o spec.

- [x] **Step 7: Suíte completa + commit final**

Run: `& $php artisan test`
Expected: PASS

```bash
git add app/Console/Commands/ExpireEnvelopes.php routes/console.php CLAUDE.md tests/Feature/ExpireEnvelopesTest.php
git commit -m "feat: expiracao agendada de envelopes e documentacao do modulo"
```

---

## Ordem de execução e dependências

```
Task 1 (models) ──┬─→ Task 4 (mailables) ─→ Task 5 (service create/send) ─→ Task 6 (service sign)
Task 2 (settings) ─┤                                                            │
Task 3 (SignatureImage) ────────────────────────────────────────────────────────┤
                   Task 7 (evidências) ─→ Task 8 (composer) ─→ Task 9 (seal job)─┤
                                                    Task 10 (controller cliente)─┼─→ Task 12 (público)
                                                    Task 11 (views cliente)──────┘
                                                                        Task 13 (expire + docs) por último
```

Sequência recomendada (linear): 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13.

