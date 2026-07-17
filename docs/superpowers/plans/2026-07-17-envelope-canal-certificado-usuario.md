# Canal por Signatário, Certificado Próprio e Remoção do Delete de Conta — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each envelope signer be reached via email or WhatsApp (not email-always), let each client seal their own envelopes with their own certificate (falling back to the platform certificate), let the admin set a per-client default send channel, and remove the self-service account deletion from `/profile`.

**Architecture:** Additive migrations on `envelope_signers` (new `channel` column) and `users` (new `signing_certificate_id`, `whatsapp_envelope_enabled`, `default_envelope_channel` columns). `EnvelopeService` and `SealEnvelopeJob` switch their notification/certificate-resolution branches from `auth_method`/global-only to `channel`/user-preference-first. Blade views (wizard, certificates index, admin user form) gain matching UI. Breeze's delete-account path is deleted outright (view partial, route, controller method).

**Tech Stack:** Laravel 13, PHP 8.3, MySQL (sqlite `:memory:` in tests), Blade + Alpine.js, PHPUnit (`php artisan test`).

## Global Constraints

- PHP binary for all artisan/test commands: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe` (never the Laragon default `php` alias or XAMPP's 7.4).
- Tests run against sqlite `:memory:` via `php artisan test`; every new feature needs feature-test coverage following the existing patterns in `tests/Feature/`.
- `channel=email` limits `auth_method` to `link`|`email_otp`; `channel=whatsapp` limits `auth_method` to `link`|`whatsapp_otp`. No cross combination (e.g. `channel=whatsapp` + `auth_method=email_otp`) is ever valid.
- A client never sees or selects the platform-wide certificate (`settings.platform_certificate_id`) — only their own certificates in `/certificates`.
- `whatsapp_envelope_enabled` (per-client) and `settings.whatsapp_enabled` (global) are independent gates — WhatsApp sending only happens when both are true.
- The self-account-deletion feature is removed entirely (UI + route + controller method), no replacement flag or dead code left behind.

---

## Task 1: `channel` column on `envelope_signers` + model/factory support

**Files:**
- Create: `database/migrations/2026_07_17_000001_add_channel_to_envelope_signers_table.php`
- Modify: `app/Models/EnvelopeSigner.php`
- Modify: `database/factories/EnvelopeSignerFactory.php`
- Test: `tests/Feature/EnvelopeModelTest.php`

**Interfaces:**
- Consumes: nothing new (uses existing `EnvelopeSigner` model/migration conventions).
- Produces: `envelope_signers.channel` enum(`email`,`whatsapp`) default `email`, fillable on the model, factory default `'email'`. Later tasks (2, 3) read/write `$signer->channel`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/EnvelopeModelTest.php` (open the file first to match its existing style/imports, then add this test method inside the existing test class):

```php
public function test_signer_defaults_to_email_channel(): void
{
    $signer = \App\Models\EnvelopeSigner::factory()->create();

    $this->assertSame('email', $signer->channel);
}

public function test_signer_channel_can_be_whatsapp(): void
{
    $signer = \App\Models\EnvelopeSigner::factory()->create([
        'channel' => 'whatsapp',
        'whatsapp' => '11999998888',
        'auth_method' => 'whatsapp_otp',
    ]);

    $this->assertSame('whatsapp', $signer->fresh()->channel);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=test_signer_defaults_to_email_channel`
Expected: FAIL — "Unknown column 'channel'" or similar (column doesn't exist yet).

- [ ] **Step 3: Write the migration**

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
            $table->enum('channel', ['email', 'whatsapp'])->default('email')->after('cpf');
        });
    }

    public function down(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/EnvelopeSigner.php`, add `'channel'` to `$fillable` (right after `'cpf'`):

```php
protected $fillable = [
    'envelope_id', 'name', 'email', 'whatsapp', 'cpf', 'channel',
    'auth_method', 'sign_position', 'token', 'status',
    'signature_image_path', 'signature_type',
    'otp_code', 'otp_expires_at', 'otp_attempts',
    'signed_at', 'ip_address', 'user_agent', 'decline_reason',
];
```

- [ ] **Step 5: Update the factory**

In `database/factories/EnvelopeSignerFactory.php`, add `'channel' => 'email',` to the `definition()` array (next to `'auth_method' => 'link',`).

- [ ] **Step 6: Run test to verify it passes**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=EnvelopeModelTest`
Expected: PASS (all tests in the file, including the two new ones).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_17_000001_add_channel_to_envelope_signers_table.php app/Models/EnvelopeSigner.php database/factories/EnvelopeSignerFactory.php tests/Feature/EnvelopeModelTest.php
git commit -m "feat: adiciona canal de envio (email/whatsapp) por signatário de envelope"
```

---

## Task 2: Validation + notification/OTP routing by channel

**Files:**
- Modify: `app/Http/Controllers/Client/EnvelopeController.php:154-179` (`validateSigners`)
- Modify: `app/Services/Envelope/EnvelopeService.php:28-59` (`create`), `84-98` (`notifySigner`), `109-128` (`issueOtp`)
- Test: `tests/Feature/EnvelopeControllerTest.php`, `tests/Feature/EnvelopeServiceCreateTest.php`

**Interfaces:**
- Consumes: `EnvelopeSigner::$fillable` now includes `channel` (Task 1).
- Produces: `EnvelopeService::create()` persists `channel` per signer; `notifySigner()` and `issueOtp()` branch on `$signer->channel` instead of always-email/`auth_method`. `EnvelopeController::validateSigners()` rejects channel/auth_method mismatches and enforces conditional required email/whatsapp.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/EnvelopeControllerTest.php` (inside the class, alongside `test_store_validates_signers_json`):

```php
public function test_store_validates_channel_and_auth_method_combination(): void
{
    Storage::fake('local');
    Storage::fake('documents');
    $this->configurePlatformCertificate();
    $user = User::factory()->withPlan()->create(['role' => 'client']);

    // whatsapp channel requires whatsapp number
    $noWhatsapp = json_encode([['name' => 'Ana', 'channel' => 'whatsapp', 'auth_method' => 'whatsapp_otp',
        'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]]]);
    $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $noWhatsapp]))
        ->assertSessionHasErrors('signers_json');

    // email channel with whatsapp_otp is not allowed
    $crossed = json_encode([['name' => 'Ana', 'email' => 'ana@x.com', 'channel' => 'email', 'auth_method' => 'whatsapp_otp',
        'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]]]);
    $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $crossed]))
        ->assertSessionHasErrors('signers_json');

    // whatsapp channel with email_otp is not allowed
    $crossed2 = json_encode([['name' => 'Ana', 'whatsapp' => '11999998888', 'channel' => 'whatsapp', 'auth_method' => 'email_otp',
        'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]]]);
    $this->actingAs($user)->post('/envelopes', array_merge($this->validPayload(), ['signers_json' => $crossed2]))
        ->assertSessionHasErrors('signers_json');
}

public function test_store_accepts_whatsapp_channel_signer(): void
{
    Storage::fake('local');
    Storage::fake('documents');
    Mail::fake();
    $this->configurePlatformCertificate();
    $user = User::factory()->withPlan()->create(['role' => 'client']);

    $payload = array_merge($this->validPayload(), ['signers_json' => json_encode([
        ['name' => 'Ana', 'whatsapp' => '11999998888', 'channel' => 'whatsapp', 'auth_method' => 'whatsapp_otp',
         'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]],
    ])]);

    $response = $this->actingAs($user)->post('/envelopes', $payload);

    $envelope = Envelope::first();
    $response->assertRedirect(route('envelopes.show', $envelope));
    $this->assertSame('whatsapp', $envelope->signers->first()->channel);
}
```

Add to `tests/Feature/EnvelopeServiceCreateTest.php` (inside the class):

```php
public function test_send_notifies_whatsapp_channel_signer_via_whatsapp_only(): void
{
    Storage::fake('documents');
    Mail::fake();
    $this->configurePlatformCertificate();
    $envelope = $this->makeEnvelope(User::factory()->create(['role' => 'client']), [
        'signers' => [
            ['name' => 'Ana', 'whatsapp' => '11999998888', 'channel' => 'whatsapp', 'auth_method' => 'whatsapp_otp',
             'fields' => [['page' => 1, 'x' => 100, 'y' => 200, 'w' => 120, 'h' => 40]]],
        ],
    ]);

    app(\App\Services\Envelope\EnvelopeService::class)->send($envelope);

    Mail::assertNotSent(\App\Mail\Envelopes\EnvelopeInvite::class);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=EnvelopeControllerTest`
Expected: `test_store_validates_channel_and_auth_method_combination` and `test_store_accepts_whatsapp_channel_signer` FAIL (no `channel` field wired yet, whatsapp-channel signer still requires email today).

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=test_send_notifies_whatsapp_channel_signer_via_whatsapp_only`
Expected: FAIL — `Mail::assertNotSent` fails because `EnvelopeInvite` is still sent to everyone today.

- [ ] **Step 3: Update `EnvelopeController::validateSigners`**

Replace the whole method body in `app/Http/Controllers/Client/EnvelopeController.php` (lines 154-179):

```php
    /** Valida o payload de signatários montado pelo wizard. */
    private function validateSigners(string $json): array
    {
        $signers = json_decode($json, true);

        $validator = Validator::make(['signers' => $signers], [
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:255'],
            'signers.*.channel' => ['required', 'in:email,whatsapp'],
            'signers.*.email' => ['nullable', 'email', 'max:255', 'required_if:signers.*.channel,email'],
            'signers.*.whatsapp' => ['nullable', 'string', 'max:20', 'required_if:signers.*.channel,whatsapp'],
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

        foreach ($signers as $signer) {
            $allowed = $signer['channel'] === 'whatsapp' ? ['link', 'whatsapp_otp'] : ['link', 'email_otp'];
            if (! in_array($signer['auth_method'], $allowed, true)) {
                throw ValidationException::withMessages([
                    'signers_json' => 'Signatários inválidos: método de verificação incompatível com o canal escolhido.',
                ]);
            }
        }

        return $signers;
    }
```

- [ ] **Step 4: Update `EnvelopeService::create` to persist `channel`**

In `app/Services/Envelope/EnvelopeService.php`, inside `create()` (around line 44-53), add `'channel'` to the signer creation:

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

- [ ] **Step 5: Update `EnvelopeService::notifySigner` to branch on channel**

Replace lines 84-98 in `app/Services/Envelope/EnvelopeService.php`:

```php
    /** Convite (ou lembrete) pelo canal do signatário (e-mail ou WhatsApp). */
    public function notifySigner(EnvelopeSigner $signer, bool $reminder = false): void
    {
        if ($signer->channel === 'whatsapp') {
            $this->notification->sendWhatsAppTo($signer->whatsapp,
                "📄 *{$signer->envelope->user->name}* enviou o documento *{$signer->envelope->title}* para você assinar.\n".
                'Acesse: '.route('public.sign.show', $signer->token)
            );
        } else {
            Mail::to($signer->email)->send(new EnvelopeInvite($signer, $reminder));
        }

        if ($signer->status === 'pending') {
            $signer->update(['status' => 'notified']);
        }

        $this->recordEvent($signer->envelope, $signer, $reminder ? 'reminder_sent' : 'sent');
    }
```

- [ ] **Step 6: Update `EnvelopeService::issueOtp` to branch on channel**

Replace lines 109-128 in `app/Services/Envelope/EnvelopeService.php`:

```php
    /** Gera OTP de 6 dígitos (10 min, hash no banco) e envia pelo canal do signatário. */
    public function issueOtp(EnvelopeSigner $signer): void
    {
        $code = (string) random_int(100000, 999999);

        $signer->update([
            'otp_code' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(10),
            'otp_attempts' => 0,
        ]);

        if ($signer->channel === 'whatsapp') {
            $this->notification->sendWhatsAppTo($signer->whatsapp,
                "🔐 Seu código para assinar *{$signer->envelope->title}*: *{$code}*\nVale por 10 minutos.");
        } else {
            Mail::to($signer->email)->send(new EnvelopeOtp($signer, $code));
        }

        $this->recordEvent($signer->envelope, $signer, 'otp_sent');
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=EnvelopeControllerTest`
Expected: PASS (all methods including the two new ones).

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=EnvelopeServiceCreateTest`
Expected: PASS (all methods including `test_send_notifies_whatsapp_channel_signer_via_whatsapp_only`).

Run full envelope suite to catch regressions: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=Envelope`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Client/EnvelopeController.php app/Services/Envelope/EnvelopeService.php tests/Feature/EnvelopeControllerTest.php tests/Feature/EnvelopeServiceCreateTest.php
git commit -m "feat: convite e OTP de envelope seguem o canal (email/whatsapp) do signatário"
```

---

## Task 3: Wizard UI — channel selector per signer

**Files:**
- Modify: `resources/views/client/envelopes/create.blade.php`
- Modify: `resources/views/client/envelopes/show.blade.php:15,91` (`$authLabels` + display)
- Modify: `resources/views/public/sign/show.blade.php:106`
- Test: `tests/Feature/EnvelopeControllerTest.php` (`test_index_and_show_render_envelope_data`, extend)

**Interfaces:**
- Consumes: `signer.channel` field in the Alpine `envelopeWizard()` state (Task 2's backend now expects it in `signers_json`).
- Produces: wizard payload includes `channel` per signer; show/public views display channel alongside auth method.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/EnvelopeControllerTest.php`, extend `test_index_and_show_render_envelope_data` — actually add a new focused test instead (safer, doesn't touch existing assertions):

```php
public function test_show_displays_signer_channel_label(): void
{
    $owner = User::factory()->create(['role' => 'client']);
    $envelope = Envelope::factory()->for($owner)->create(['status' => 'sent']);
    EnvelopeSigner::factory()->for($envelope)->create([
        'name' => 'Ana WhatsApp', 'channel' => 'whatsapp', 'whatsapp' => '11999998888',
        'auth_method' => 'whatsapp_otp', 'status' => 'notified',
    ]);

    $this->actingAs($owner)->get("/envelopes/{$envelope->id}")
        ->assertOk()->assertSee('WhatsApp');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=test_show_displays_signer_channel_label`
Expected: This may already pass by coincidence (the auth method label already says "WhatsApp" for `whatsapp_otp`) — check the failure/pass reason. If it passes trivially without a `channel`-specific label, that's fine; the real behavior change is visual (verified manually per Task 6). Proceed regardless — this test guards against regressions once the label changes in Step 4.

- [ ] **Step 3: Update the wizard (`create.blade.php`)**

Replace the step-2 signer block (lines 79-101) with:

```blade
            <template x-for="(signer, i) in signers" :key="i">
                <div class="border border-gray-800 rounded-lg p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium" :style="`color:${colors[i % 8]}`" x-text="`Signatário ${i + 1}`"></p>
                        <button type="button" class="text-xs text-red-400 hover:text-red-300" @click="removeSigner(i)">remover</button>
                    </div>
                    <div class="grid md:grid-cols-2 gap-3">
                        <input type="text" placeholder="Nome completo" x-model="signer.name" maxlength="255"
                               class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                        <select x-model="signer.channel" @change="onChannelChange(signer)"
                                class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                            <option value="email">Canal: E-mail</option>
                            <option value="whatsapp">Canal: WhatsApp</option>
                        </select>
                        <input type="email" placeholder="E-mail" x-model="signer.email" maxlength="255"
                               x-show="signer.channel === 'email'"
                               class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                        <input type="text" placeholder="WhatsApp (com DDD)" x-model="signer.whatsapp" maxlength="20"
                               x-show="signer.channel === 'whatsapp'"
                               class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                        <select x-model="signer.auth_method"
                                class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                            <option value="link">Somente link</option>
                            <option value="email_otp" x-show="signer.channel === 'email'">Código por e-mail</option>
                            <option value="whatsapp_otp" x-show="signer.channel === 'whatsapp'">Código por WhatsApp</option>
                        </select>
                    </div>
                </div>
            </template>
```

- [ ] **Step 4: Update the Alpine component script**

In the same file, `envelopeWizard()` function:

1. Change `addSigner()` (line 159) default state to include `channel`:

```javascript
        addSigner() { if (this.signers.length < 20) this.signers.push({name:'', email:'', channel: this.defaultChannel, auth_method:'link', whatsapp:''}); },
```

2. Add `defaultChannel` to the returned state object (right after `numPages: 0, scale: 1.3,` on line 155):

```javascript
        step: 1, signers: [], selected: 0, fields: [], // fields: [{signerIdx, page, xPt, yPt}]
        numPages: 0, scale: 1.3, defaultChannel: window.__envelopeDefaultChannel || 'email',
        colors: ['#2563eb','#dc2626','#16a34a','#9333ea','#ea580c','#0891b2','#db2777','#65a30d'],
```

3. Add an `onChannelChange` method (right after `removeSigner`, before `loadPdf`):

```javascript
        onChannelChange(signer) {
            const allowed = signer.channel === 'whatsapp' ? ['link', 'whatsapp_otp'] : ['link', 'email_otp'];
            if (!allowed.includes(signer.auth_method)) signer.auth_method = 'link';
        },
```

4. Update `validStep(n)` (lines 251-257) to validate per-channel required contact field:

```javascript
        validStep(n) {
            if (n === 1) return this.$refs.title.value.trim() !== '' && this.numPages > 0;
            if (n === 2) return this.signers.length > 0
                && this.signers.every(s => s.name.trim()
                    && (s.channel === 'email' ? s.email.trim() : s.whatsapp.trim()));
            return true;
        },
```

5. Update the "Avançar" button's alert copy (line 131) to reflect the new rule:

```blade
                    @click="if (validStep(step)) { if (step === 1 && signers.length === 0) addSigner(); step++; if (step === 3) $nextTick(() => renderPages()); }
                            else alert(step === 1 ? 'Informe o título e selecione um PDF.' : 'Preencha nome e o contato correspondente ao canal escolhido (e-mail ou WhatsApp) de todos os signatários.')"
```

- [ ] **Step 5: Update `show.blade.php` and `public/sign/show.blade.php` labels**

In `resources/views/client/envelopes/show.blade.php` line 15, extend `$authLabels` unchanged (still valid), but add a channel label line right after it:

```php
$authLabels = ['link' => 'Somente link', 'email_otp' => 'Código por e-mail', 'whatsapp_otp' => 'Código por WhatsApp'];
$channelLabels = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp'];
```

Then update line 91 (the autenticação line) to also show the channel:

```blade
                <p class="text-xs text-gray-500">Canal: {{ $channelLabels[$signer->channel] ?? $signer->channel }} · Autenticação: {{ $authLabels[$signer->auth_method] ?? $signer->auth_method }}</p>
```

In `resources/views/public/sign/show.blade.php` line 106, replace the inline ternary with a check on `channel` (more accurate than inferring from `auth_method`):

```blade
                    ({{ $signer->channel === 'whatsapp' ? 'WhatsApp' : 'e-mail' }}).
```

- [ ] **Step 6: Run test to verify it passes**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=EnvelopeControllerTest`
Expected: PASS (all methods).

- [ ] **Step 7: Commit**

```bash
git add resources/views/client/envelopes/create.blade.php resources/views/client/envelopes/show.blade.php resources/views/public/sign/show.blade.php tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: seletor de canal (email/whatsapp) por signatário no wizard de envelope"
```

---

## Task 4: `signing_certificate_id` on users — client picks their own sealing certificate

**Files:**
- Create: `database/migrations/2026_07_17_000002_add_signing_certificate_id_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `app/Http/Controllers/Client/CertificateController.php`
- Modify: `resources/views/client/certificates/index.blade.php`
- Modify: `routes/web.php` (certificates group)
- Test: `tests/Feature/CertificateCrudTest.php`

**Interfaces:**
- Consumes: `Certificate` model (existing, unchanged).
- Produces: `users.signing_certificate_id` nullable FK; `User::signingCertificate(): BelongsTo`; route `certificates.use-as-signing` (POST `/certificates/{certificate}/use-as-signing`); `CertificateController::useAsSigning(Certificate $certificate)`. Task 5 consumes `$user->signingCertificate`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/CertificateCrudTest.php` (inside the class):

```php
public function test_client_can_mark_certificate_as_signing_default(): void
{
    $client = User::factory()->create(['role' => 'client']);
    $certA = Certificate::factory()->for($client)->create(['expires_at' => now()->addYear()]);
    $certB = Certificate::factory()->for($client)->create(['expires_at' => now()->addYear()]);

    $this->actingAs($client)->post(route('certificates.use-as-signing', $certA))
        ->assertRedirect(route('certificates.index'));
    $this->assertSame($certA->id, $client->fresh()->signing_certificate_id);

    // marking B unmarks A (only one at a time)
    $this->actingAs($client)->post(route('certificates.use-as-signing', $certB));
    $this->assertSame($certB->id, $client->fresh()->signing_certificate_id);
}

public function test_client_cannot_mark_expired_certificate_as_signing_default(): void
{
    $client = User::factory()->create(['role' => 'client']);
    $expired = Certificate::factory()->for($client)->create(['expires_at' => now()->subDay()]);

    $this->actingAs($client)->post(route('certificates.use-as-signing', $expired))
        ->assertSessionHasErrors();
    $this->assertNull($client->fresh()->signing_certificate_id);
}

public function test_client_cannot_mark_another_users_certificate_as_signing_default(): void
{
    $owner = User::factory()->create(['role' => 'client']);
    $other = User::factory()->create(['role' => 'client']);
    $certificate = Certificate::factory()->for($owner)->create(['expires_at' => now()->addYear()]);

    $this->actingAs($other)->post(route('certificates.use-as-signing', $certificate))->assertForbidden();
    $this->assertNull($other->fresh()->signing_certificate_id);
}

public function test_deleting_signing_certificate_clears_users_selection(): void
{
    Storage::fake('local');
    $client = User::factory()->create(['role' => 'client']);
    $certificate = Certificate::factory()->for($client)->create(['expires_at' => now()->addYear()]);
    $client->update(['signing_certificate_id' => $certificate->id]);

    $this->actingAs($client)->delete(route('certificates.destroy', $certificate));

    $this->assertNull($client->fresh()->signing_certificate_id);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=CertificateCrudTest`
Expected: The four new tests FAIL (column/route/method don't exist yet).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('signing_certificate_id')->nullable()->after('plan_id')
                ->constrained('certificates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signing_certificate_id');
        });
    }
};
```

- [ ] **Step 4: Update the `User` model**

In `app/Models/User.php`, add `'signing_certificate_id'` to `$fillable`, and add a new relation method after `certificates()`:

```php
    protected $fillable = ['name', 'email', 'password', 'role', 'whatsapp', 'plan_id', 'signing_certificate_id'];
```

```php
    public function signingCertificate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'signing_certificate_id');
    }
```

- [ ] **Step 5: Add the controller action**

In `app/Http/Controllers/Client/CertificateController.php`, add a new public method after `destroy()` (before the private section):

```php
    /** Marca este certificado como o usado para lacrar os próprios envelopes do cliente. */
    public function useAsSigning(Certificate $certificate)
    {
        $this->authorizeOwner($certificate);

        if ($certificate->isExpired()) {
            throw ValidationException::withMessages([
                'certificate' => 'Não é possível usar um certificado vencido para assinatura.',
            ]);
        }

        auth()->user()->update(['signing_certificate_id' => $certificate->id]);

        return redirect()->route('certificates.index')
            ->with('success', 'Certificado definido como padrão de assinatura.');
    }
```

- [ ] **Step 6: Add the route**

In `routes/web.php`, right after the existing `certificates.image` route (around line 55), add:

```php
    Route::post('certificates/{certificate}/use-as-signing', [CertificateController::class, 'useAsSigning'])
        ->name('certificates.use-as-signing');
```

- [ ] **Step 7: Update the certificates index view**

In `resources/views/client/certificates/index.blade.php`, add a "Padrão" column. Replace the `<thead>` block (lines 22-31):

```blade
            <thead>
                <tr class="border-b border-gray-800">
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Descrição</th>
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Referência</th>
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Certificado expira</th>
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Imagens</th>
                    <th class="text-left px-6 py-4 text-gray-400 font-medium">Assinatura</th>
                    <th class="px-6 py-4"></th>
                </tr>
            </thead>
```

And add a new `<td>` right before the actions `<td>` (before line 70's `<td class="px-6 py-4">` that holds edit/delete buttons):

```blade
                    <td class="px-6 py-4">
                        @if($certificate->id === auth()->user()->signing_certificate_id)
                            <span class="px-2.5 py-1 rounded-md text-xs font-semibold bg-blue-900/40 text-blue-400 border border-blue-800">Padrão</span>
                        @elseif($certificate->isExpired())
                            <span class="text-xs text-gray-600">—</span>
                        @else
                            <form method="POST" action="{{ route('certificates.use-as-signing', $certificate) }}">
                                @csrf
                                <button class="text-xs text-blue-400 hover:text-blue-300">Usar como assinatura</button>
                            </form>
                        @endif
                    </td>
```

Also update `colspan="5"` to `colspan="6"` in the empty-state row (line 94).

- [ ] **Step 8: Run tests to verify they pass**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=CertificateCrudTest`
Expected: PASS (all methods, including the four new ones).

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_17_000002_add_signing_certificate_id_to_users_table.php app/Models/User.php app/Http/Controllers/Client/CertificateController.php resources/views/client/certificates/index.blade.php routes/web.php tests/Feature/CertificateCrudTest.php
git commit -m "feat: cliente escolhe seu proprio certificado para lacrar envelopes"
```

---

## Task 5: Seal resolution uses the owner's certificate, falling back to platform's

**Files:**
- Modify: `app/Services/Envelope/EnvelopeService.php:62-69` (`send`)
- Modify: `app/Jobs/SealEnvelopeJob.php:44-47`
- Test: `tests/Feature/EnvelopeServiceCreateTest.php`

**Interfaces:**
- Consumes: `User::signingCertificate` (Task 4).
- Produces: both `send()` and `SealEnvelopeJob::handle()` resolve `$envelope->user->signingCertificate ?? Setting::current()->platformCertificate`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/EnvelopeServiceCreateTest.php`:

```php
public function test_send_uses_owners_own_certificate_when_configured(): void
{
    Storage::fake('documents');
    Mail::fake();
    $this->configurePlatformCertificate(); // platform cert exists but should NOT be required/used

    $user = User::factory()->create(['role' => 'client']);
    $ownCert = Certificate::factory()->for($user)->create(['expires_at' => now()->addYear()]);
    $user->update(['signing_certificate_id' => $ownCert->id]);

    $envelope = $this->makeEnvelope($user);

    app(EnvelopeService::class)->send($envelope);

    $this->assertSame('sent', $envelope->fresh()->status);
}

public function test_send_fails_when_owner_has_no_certificate_and_no_platform_certificate(): void
{
    Storage::fake('documents');
    $user = User::factory()->create(['role' => 'client']); // no signing_certificate_id, no platform cert configured
    $envelope = $this->makeEnvelope($user);

    $this->expectException(\RuntimeException::class);
    app(EnvelopeService::class)->send($envelope);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=test_send_uses_owners_own_certificate_when_configured`
Expected: This particular test actually PASSES already today by coincidence (platform cert is configured so `send()` succeeds regardless of which cert would be used) — the real gap is in `SealEnvelopeJob`, which isn't exercised by this synchronous test. Proceed to Step 3 anyway: it locks in behavior once `send()`'s resolution logic changes, and Step 5 verifies the job directly.

- [ ] **Step 3: Update `EnvelopeService::send`**

Replace lines 62-69 in `app/Services/Envelope/EnvelopeService.php`:

```php
    /** Envia os convites. Exige certificado válido: do próprio dono, senão o da plataforma. */
    public function send(Envelope $envelope): void
    {
        $cert = $envelope->user->signingCertificate ?? Setting::current()->platformCertificate;
        if ($cert === null || $cert->isExpired()) {
            throw new \RuntimeException(
                'Nenhum certificado válido configurado — cadastre um certificado próprio em Certificados ou peça ao administrador para configurar o certificado da plataforma.'
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
```

- [ ] **Step 4: Update `SealEnvelopeJob::handle`**

In `app/Jobs/SealEnvelopeJob.php`, replace lines 44-47:

```php
            $certificate = $envelope->user->signingCertificate ?? Setting::current()->platformCertificate;
            if ($certificate === null) {
                throw new \RuntimeException('Nenhum certificado válido configurado para lacrar este envelope.');
            }
```

(`$envelope` here is already loaded with `->fresh(['signers.fields', 'user'])` on line 34 — add `signingCertificate` to that eager load to avoid an extra query: change line 34 to `$envelope = $this->envelope->fresh(['signers.fields', 'user.signingCertificate']);`.)

- [ ] **Step 5: Add a job-level test**

Add to `tests/Feature/EnvelopeServiceSignTest.php` (open it first to match import/style conventions) a test that dispatches `SealEnvelopeJob` synchronously for a user with their own certificate and asserts it completes — follow the existing patterns in that file for how the platform certificate is set up and how `SealEnvelopeJob::dispatchSync` or direct `handle()` invocation is exercised there. Mirror an existing "seals successfully" test but swap `Setting::current()->platformCertificate` setup for `$envelope->user->update(['signing_certificate_id' => $ownCert->id])`, using a certificate owned by the envelope's own user instead of a platform-wide one, and assert `$envelope->fresh()->status === 'completed'`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=EnvelopeServiceCreateTest`
Expected: PASS.

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=EnvelopeServiceSignTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Envelope/EnvelopeService.php app/Jobs/SealEnvelopeJob.php tests/Feature/EnvelopeServiceCreateTest.php tests/Feature/EnvelopeServiceSignTest.php
git commit -m "feat: lacre do envelope usa certificado proprio do dono, com fallback pro da plataforma"
```

---

## Task 6: Admin sets per-client WhatsApp gate + default channel

**Files:**
- Create: `database/migrations/2026_07_17_000003_add_envelope_channel_prefs_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `app/Http/Controllers/Admin/UserController.php:45-70` (`store`), `80-98` (`update`)
- Modify: `resources/views/admin/users/create.blade.php`
- Modify: `resources/views/admin/users/edit.blade.php`
- Test: `tests/Feature/UserCrudTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `users.whatsapp_envelope_enabled` (bool, default false), `users.default_envelope_channel` (enum email/whatsapp, default email). Task 7 reads `auth()->user()->whatsapp_envelope_enabled` and `default_envelope_channel`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/UserCrudTest.php`:

```php
public function test_store_sets_envelope_channel_preferences(): void
{
    $response = $this->actingAs($this->admin())->post('/admin/users', [
        'name' => 'Cliente Novo',
        'email' => 'novo@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'client',
        'whatsapp_envelope_enabled' => '1',
        'default_envelope_channel' => 'whatsapp',
    ]);

    $response->assertRedirect();
    $user = User::where('email', 'novo@example.com')->first();
    $this->assertTrue($user->whatsapp_envelope_enabled);
    $this->assertSame('whatsapp', $user->default_envelope_channel);
}

public function test_store_forces_email_channel_when_whatsapp_not_enabled(): void
{
    $this->actingAs($this->admin())->post('/admin/users', [
        'name' => 'Cliente Novo',
        'email' => 'novo2@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'client',
        'default_envelope_channel' => 'whatsapp', // should be ignored — checkbox not sent
    ]);

    $user = User::where('email', 'novo2@example.com')->first();
    $this->assertFalse($user->whatsapp_envelope_enabled);
    $this->assertSame('email', $user->default_envelope_channel);
}

public function test_update_toggles_envelope_channel_preferences(): void
{
    $client = User::factory()->create(['role' => 'client', 'whatsapp_envelope_enabled' => false, 'default_envelope_channel' => 'email']);

    $this->actingAs($this->admin())->patch("/admin/users/{$client->id}", [
        'name' => $client->name,
        'email' => $client->email,
        'whatsapp_envelope_enabled' => '1',
        'default_envelope_channel' => 'whatsapp',
    ]);

    $client->refresh();
    $this->assertTrue($client->whatsapp_envelope_enabled);
    $this->assertSame('whatsapp', $client->default_envelope_channel);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=test_store_sets_envelope_channel_preferences`
Expected: FAIL — columns don't exist yet.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('whatsapp_envelope_enabled')->default(false)->after('whatsapp');
            $table->enum('default_envelope_channel', ['email', 'whatsapp'])->default('email')->after('whatsapp_envelope_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_envelope_enabled', 'default_envelope_channel']);
        });
    }
};
```

- [ ] **Step 4: Update the `User` model**

In `app/Models/User.php`, update `$fillable` and `casts()`:

```php
    protected $fillable = [
        'name', 'email', 'password', 'role', 'whatsapp', 'plan_id',
        'signing_certificate_id', 'whatsapp_envelope_enabled', 'default_envelope_channel',
    ];
```

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'whatsapp_envelope_enabled' => 'boolean',
        ];
    }
```

- [ ] **Step 5: Update `Admin\UserController::store` and `update`**

In `app/Http/Controllers/Admin/UserController.php`, replace `store()` (lines 45-70):

```php
    public function store(Request $request)
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::min(8)],
            'role'     => ['required', 'in:admin,client'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'plan_id'  => ['nullable', 'integer', 'exists:plans,id'],
            'whatsapp_envelope_enabled' => ['nullable', 'boolean'],
            'default_envelope_channel' => ['nullable', 'in:email,whatsapp'],
        ]);

        $whatsappEnabled = $request->boolean('whatsapp_envelope_enabled');

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
            'whatsapp' => $request->whatsapp ? preg_replace('/\D/', '', $request->whatsapp) : null,
            'plan_id'  => $request->plan_id ?: null,
            'whatsapp_envelope_enabled' => $whatsappEnabled,
            'default_envelope_channel' => $whatsappEnabled ? ($request->input('default_envelope_channel') ?: 'email') : 'email',
        ]);

        Mail::to($user->email)->queue(new BoasVindas($user));
        $this->notify->boasVindas($user);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'Usuário criado com sucesso.');
    }
```

Replace `update()` (lines 80-98):

```php
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'plan_id'  => ['nullable', 'integer', 'exists:plans,id'],
            'whatsapp_envelope_enabled' => ['nullable', 'boolean'],
            'default_envelope_channel' => ['nullable', 'in:email,whatsapp'],
        ]);

        $whatsappEnabled = $request->boolean('whatsapp_envelope_enabled');

        $user->update([
            'name'     => $request->name,
            'email'    => $request->email,
            'whatsapp' => $request->whatsapp ? preg_replace('/\D/', '', $request->whatsapp) : null,
            'plan_id'  => $request->plan_id ?: null,
            'whatsapp_envelope_enabled' => $whatsappEnabled,
            'default_envelope_channel' => $whatsappEnabled ? ($request->input('default_envelope_channel') ?: 'email') : 'email',
        ]);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'Usuário atualizado com sucesso.');
    }
```

- [ ] **Step 6: Add the fields to admin create/edit views**

Both `create.blade.php` and `edit.blade.php` are separate full-form templates (not shared), styled identically (`bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm`). Insert this block in `resources/views/admin/users/create.blade.php` right after the "Plano" `<div>` (after line 117's closing `</div>`, before the submit-buttons `<div class="flex items-center gap-3 pt-2">` on line 119):

```blade
            <div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="whatsapp_envelope_enabled" value="1"
                           {{ old('whatsapp_envelope_enabled') ? 'checked' : '' }}
                           class="rounded bg-gray-800 border-gray-700 text-blue-600 focus:ring-blue-500 focus:ring-offset-gray-900">
                    <span class="text-xs font-medium text-gray-400">Permitir envio de envelope via WhatsApp</span>
                </label>
            </div>

            <div>
                <label for="default_envelope_channel" class="block text-xs font-medium text-gray-400 mb-1.5">Canal padrão de envio de envelope</label>
                <select id="default_envelope_channel" name="default_envelope_channel"
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm
                               focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                    <option value="email" @selected(old('default_envelope_channel', 'email') === 'email')>E-mail</option>
                    <option value="whatsapp" @selected(old('default_envelope_channel') === 'whatsapp')>WhatsApp</option>
                </select>
            </div>
```

Insert the equivalent block in `resources/views/admin/users/edit.blade.php` right after the "Plano" `<div>` (after line 65's closing `</div>`, before the submit-buttons `<div class="flex items-center gap-3 pt-2">` on line 67), using `$user` for defaults instead of hardcoded fallbacks:

```blade
            <div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="whatsapp_envelope_enabled" value="1"
                           {{ old('whatsapp_envelope_enabled', $user->whatsapp_envelope_enabled) ? 'checked' : '' }}
                           class="rounded bg-gray-800 border-gray-700 text-blue-600 focus:ring-blue-500 focus:ring-offset-gray-900">
                    <span class="text-xs font-medium text-gray-400">Permitir envio de envelope via WhatsApp</span>
                </label>
            </div>

            <div>
                <label for="default_envelope_channel" class="block text-xs font-medium text-gray-400 mb-1.5">Canal padrão de envio de envelope</label>
                <select id="default_envelope_channel" name="default_envelope_channel"
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3.5 py-2.5 text-white text-sm
                               focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                    <option value="email" @selected(old('default_envelope_channel', $user->default_envelope_channel) === 'email')>E-mail</option>
                    <option value="whatsapp" @selected(old('default_envelope_channel', $user->default_envelope_channel) === 'whatsapp')>WhatsApp</option>
                </select>
            </div>
```

Both fields are shown unconditionally (no role-based `x-show`): `UserController::index()` only lists `role=client` users, and `create()`/`edit()` don't restrict by role in the form itself, but these preferences are harmless no-ops for an admin account (never read anywhere for `role=admin`), so no conditional rendering is needed.

- [ ] **Step 7: Run tests to verify they pass**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=UserCrudTest`
Expected: PASS (all methods including the three new ones).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_17_000003_add_envelope_channel_prefs_to_users_table.php app/Models/User.php app/Http/Controllers/Admin/UserController.php resources/views/admin/users/create.blade.php resources/views/admin/users/edit.blade.php tests/Feature/UserCrudTest.php
git commit -m "feat: admin define permissao de whatsapp e canal padrao de envelope por cliente"
```

---

## Task 7: Wizard pre-selects the client's default channel

**Files:**
- Modify: `app/Http/Controllers/Client/EnvelopeController.php:34-37` (`create`)
- Modify: `resources/views/client/envelopes/create.blade.php` (pass default channel to Alpine)
- Test: `tests/Feature/EnvelopeControllerTest.php`

**Interfaces:**
- Consumes: `auth()->user()->whatsapp_envelope_enabled`, `default_envelope_channel` (Task 6).
- Produces: `client.envelopes.create` view receives `$defaultChannel` ('email'|'whatsapp'); wizard's `window.__envelopeDefaultChannel` is set from it (Task 3 already reads this global).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/EnvelopeControllerTest.php`:

```php
public function test_create_exposes_clients_default_channel_to_the_wizard(): void
{
    $user = User::factory()->create([
        'role' => 'client', 'whatsapp_envelope_enabled' => true, 'default_envelope_channel' => 'whatsapp',
    ]);

    $this->actingAs($user)->get('/envelopes/create')
        ->assertOk()->assertSee("__envelopeDefaultChannel = 'whatsapp'", false);
}

public function test_create_defaults_to_email_when_whatsapp_not_enabled_for_client(): void
{
    $user = User::factory()->create([
        'role' => 'client', 'whatsapp_envelope_enabled' => false, 'default_envelope_channel' => 'whatsapp',
    ]);

    $this->actingAs($user)->get('/envelopes/create')
        ->assertOk()->assertSee("__envelopeDefaultChannel = 'email'", false);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=test_create_exposes_clients_default_channel_to_the_wizard`
Expected: FAIL — the view doesn't emit `__envelopeDefaultChannel` yet.

- [ ] **Step 3: Update `EnvelopeController::create`**

Replace lines 34-37 in `app/Http/Controllers/Client/EnvelopeController.php`:

```php
    public function create()
    {
        $user = auth()->user();
        $defaultChannel = $user->whatsapp_envelope_enabled ? $user->default_envelope_channel : 'email';

        return view('client.envelopes.create', compact('defaultChannel'));
    }
```

- [ ] **Step 4: Emit the value in the view**

In `resources/views/client/envelopes/create.blade.php`, inside the `@push('scripts')` block, right before the `function envelopeWizard()` declaration, add:

```blade
<script>window.__envelopeDefaultChannel = '{{ $defaultChannel }}';</script>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=EnvelopeControllerTest`
Expected: PASS (all methods including the two new ones).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/EnvelopeController.php resources/views/client/envelopes/create.blade.php tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: wizard de envelope pre-seleciona o canal padrao do cliente"
```

---

## Task 8: Remove self-service account deletion from `/profile`

**Files:**
- Delete: `resources/views/profile/partials/delete-user-form.blade.php`
- Modify: `resources/views/profile/edit.blade.php`
- Modify: `routes/web.php` (remove `profile.destroy`)
- Modify: `app/Http/Controllers/ProfileController.php` (remove `destroy()`)
- Modify: `tests/Feature/ProfileTest.php` (remove the two delete-account tests)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks — this is the last task.

- [ ] **Step 1: Remove the delete-account tests**

In `tests/Feature/ProfileTest.php`, delete `test_user_can_delete_their_account` (lines 64-80) and `test_correct_password_must_be_provided_to_delete_account` (lines 82-98) entirely, leaving the file ending after `test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged` (closing the class).

- [ ] **Step 2: Run the full profile test file to confirm current state**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=ProfileTest`
Expected: PASS (remaining 3 tests; the deleted ones are gone, not failing).

- [ ] **Step 3: Remove the view partial and its inclusion**

Delete `resources/views/profile/partials/delete-user-form.blade.php`.

In `resources/views/profile/edit.blade.php`, remove lines 17-19:

```blade
    <div class="bg-gray-900 border border-gray-800 rounded-lg p-6">
        @include('profile.partials.delete-user-form')
    </div>
```

leaving the file as:

```blade
@extends('client.layout')

@section('title', 'Perfil')

@section('content')
<div class="max-w-2xl space-y-6">
    <h2 class="text-xl font-bold text-white mb-2">Perfil</h2>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-6">
        @include('profile.partials.update-profile-information-form')
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-6">
        @include('profile.partials.update-password-form')
    </div>
</div>
@endsection
```

- [ ] **Step 4: Remove the route**

In `routes/web.php`, remove line 73:

```php
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
```

- [ ] **Step 5: Remove the controller method**

In `app/Http/Controllers/ProfileController.php`, remove the `destroy()` method (lines 40-59) and the now-unused `Auth` import if nothing else in the file uses it (check: `Auth::logout()` was only called inside `destroy()` — remove `use Illuminate\Support\Facades\Auth;` from the top of the file too).

Resulting file:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
```

- [ ] **Step 6: Run the full profile test file to confirm nothing broke**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --filter=ProfileTest`
Expected: PASS (3 tests, 0 failures).

- [ ] **Step 7: Grep for any other references to `profile.destroy` or the deleted partial**

Run: `grep -rn "profile.destroy\|delete-user-form\|confirm-user-deletion" resources/ routes/ app/ --include="*.php" --include="*.blade.php"`
Expected: no output (no remaining references). If any turn up (e.g. a nav link), remove them too.

- [ ] **Step 8: Commit**

```bash
git add -A resources/views/profile routes/web.php app/Http/Controllers/ProfileController.php tests/Feature/ProfileTest.php
git commit -m "feat: remove autoexclusao de conta do perfil do cliente"
```

---

## Task 9: Full regression pass

**Files:** none (verification only).

**Interfaces:** none.

- [ ] **Step 1: Run the entire test suite**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test`
Expected: PASS, 0 failures. If anything fails, use the `superpowers:systematic-debugging` skill to diagnose before touching code.

- [ ] **Step 2: Manually verify the wizard in a browser**

Start the dev server (`php artisan serve` and `npm run dev` if Vite isn't already running), log in as a client, go to `/envelopes/create`, and confirm: switching a signer's channel between E-mail/WhatsApp toggles the correct contact field and resets an incompatible auth method to "Somente link"; the new signer's channel is pre-filled per the logged-in client's `default_envelope_channel` (set one via `/admin/users/{id}/edit` as an admin first, with the WhatsApp checkbox on).

- [ ] **Step 3: Manually verify certificate selection**

As a client with two certificates in `/certificates`, click "Usar como assinatura" on one, confirm the "Padrão" badge appears on it and not the other; confirm an expired certificate shows no button.

- [ ] **Step 4: Manually verify `/profile` has no delete button**

Log in as any user, visit `/profile`, confirm there is no "Delete Account" section and `DELETE /profile` is not reachable (e.g. `curl -X DELETE` returns 405/404 or route-not-found).

- [ ] **Step 5: Final commit if any fixups were needed**

If Steps 1-4 required code changes, stage and commit them with a message describing what regression was fixed. If nothing needed fixing, no commit is necessary for this task.
