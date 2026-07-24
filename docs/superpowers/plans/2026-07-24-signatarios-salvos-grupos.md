# Signatários salvos e grupos de signatários — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que o cliente salve signatários (contatos) e grupos de signatários reutilizáveis, e usá-los ao criar um novo envelope sem redigitar dados a cada envio.

**Architecture:** Duas tabelas novas (`saved_signers`, `signer_groups`) mais uma pivot (`signer_group_members`), todas escopadas por `user_id`. Uma tela CRUD dedicada em `/signatarios` gerencia as duas listas. O wizard de envelope existente (`resources/views/client/envelopes/create.blade.php`, Alpine.js) ganha um autocomplete (rota JSON leve) para adicionar um contato salvo, um seletor de grupo (dados injetados na página, sem chamada AJAX — no máximo 20 grupos por usuário) e um checkbox por linha para persistir um novo contato ao enviar o envelope.

**Tech Stack:** Laravel 13, Eloquent (relação many-to-many via pivot), Alpine.js (já usado no wizard), Blade.

## Global Constraints

- Tudo escopado por `user_id` — nenhum contato/grupo é visível ou editável por outro usuário (mesmo padrão de `EnvelopeController::authorizeOwner()`).
- Limite fixo: máximo 100 `saved_signers` e 20 `signer_groups` por usuário, validado no controller (sem `UsageLimitService`, que é por plano/mês — conceito diferente).
- Editar/excluir um `SavedSigner` ou `SignerGroup` nunca afeta envelopes já criados — os dados do signatário já foram copiados para `envelope_signers` no momento da criação.
- Mesma validação de canal↔método de `EnvelopeController::validateSigners()`: `whatsapp` só aceita `link`/`whatsapp_otp`; `email` só aceita `link`/`email_otp`.
- Editar uma linha do wizard que veio de um contato salvo, com o checkbox "salvar" marcado, cria um `SavedSigner` **novo** — nunca atualiza o original silenciosamente.

---

## Mapa de arquivos

- **Criar** `database/migrations/2026_07_25_000001_create_saved_signers_table.php`
- **Criar** `database/migrations/2026_07_25_000002_create_signer_groups_table.php`
- **Criar** `database/migrations/2026_07_25_000003_create_signer_group_members_table.php`
- **Criar** `database/migrations/2026_07_25_000004_add_saved_signer_id_to_envelope_signers_table.php`
- **Criar** `app/Models/SavedSigner.php`
- **Criar** `app/Models/SignerGroup.php`
- **Criar** `database/factories/SavedSignerFactory.php`
- **Criar** `database/factories/SignerGroupFactory.php`
- **Modificar** `app/Models/EnvelopeSigner.php` (fillable + relação `savedSigner()`)
- **Criar** `app/Services/SignerDirectoryService.php`
- **Criar** `app/Http/Controllers/Client/SignerDirectoryController.php`
- **Criar** `resources/views/client/signers/index.blade.php`
- **Modificar** `resources/views/client/layout.blade.php` (link no header)
- **Modificar** `routes/web.php` (rotas `/signatarios`)
- **Modificar** `app/Http/Controllers/Client/EnvelopeController.php` (`create()` injeta grupos; `store()` persiste contatos marcados)
- **Modificar** `resources/views/client/envelopes/create.blade.php` (autocomplete, seletor de grupo, checkbox "salvar")
- **Testes:** `tests/Feature/SignerDirectoryServiceTest.php`, `tests/Feature/SignerDirectoryControllerTest.php`, `tests/Feature/SavedSignerModelTest.php`, ajustes em `tests/Feature/EnvelopeControllerTest.php`

---

### Task 1: Migrations e models base (`SavedSigner`, `SignerGroup`, pivot)

**Files:**
- Create: `database/migrations/2026_07_25_000001_create_saved_signers_table.php`
- Create: `database/migrations/2026_07_25_000002_create_signer_groups_table.php`
- Create: `database/migrations/2026_07_25_000003_create_signer_group_members_table.php`
- Create: `app/Models/SavedSigner.php`
- Create: `app/Models/SignerGroup.php`
- Create: `database/factories/SavedSignerFactory.php`
- Create: `database/factories/SignerGroupFactory.php`
- Test: `tests/Feature/SavedSignerModelTest.php`

**Interfaces:**
- Produces: `SavedSigner` (fillable `user_id, name, channel, email, whatsapp, auth_method`), relação `groups(): BelongsToMany`.
- Produces: `SignerGroup` (fillable `user_id, name`), relação `members(): BelongsToMany` (para `SavedSigner`).

- [ ] **Step 1: Migration `saved_signers`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('channel', ['email', 'whatsapp'])->default('email');
            $table->string('email')->nullable();
            $table->string('whatsapp')->nullable();
            $table->enum('auth_method', ['link', 'email_otp', 'whatsapp_otp'])->default('link');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_signers');
    }
};
```

- [ ] **Step 2: Migration `signer_groups`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signer_groups');
    }
};
```

- [ ] **Step 3: Migration `signer_group_members` (pivot)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signer_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signer_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_signer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['signer_group_id', 'saved_signer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signer_group_members');
    }
};
```

- [ ] **Step 4: Model `SavedSigner`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SavedSigner extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'channel', 'email', 'whatsapp', 'auth_method'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(SignerGroup::class, 'signer_group_members');
    }
}
```

- [ ] **Step 5: Model `SignerGroup`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SignerGroup extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(SavedSigner::class, 'signer_group_members');
    }
}
```

- [ ] **Step 6: Factories**

```php
<?php
// database/factories/SavedSignerFactory.php
namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavedSignerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'channel' => 'email',
            'email' => fake()->unique()->safeEmail(),
            'whatsapp' => null,
            'auth_method' => 'link',
        ];
    }
}
```

```php
<?php
// database/factories/SignerGroupFactory.php
namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SignerGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
        ];
    }
}
```

- [ ] **Step 7: Escrever o teste do model (relação many-to-many)**

```php
<?php

namespace Tests\Feature;

use App\Models\SavedSigner;
use App\Models\SignerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedSignerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_signer_can_belong_to_multiple_groups(): void
    {
        $signer = SavedSigner::factory()->create();
        $groupA = SignerGroup::factory()->create(['user_id' => $signer->user_id]);
        $groupB = SignerGroup::factory()->create(['user_id' => $signer->user_id]);

        $signer->groups()->attach([$groupA->id, $groupB->id]);

        $this->assertCount(2, $signer->fresh()->groups);
    }

    public function test_a_group_can_have_multiple_signers(): void
    {
        $group = SignerGroup::factory()->create();
        $signerA = SavedSigner::factory()->create(['user_id' => $group->user_id]);
        $signerB = SavedSigner::factory()->create(['user_id' => $group->user_id]);

        $group->members()->attach([$signerA->id, $signerB->id]);

        $this->assertCount(2, $group->fresh()->members);
    }
}
```

- [ ] **Step 8: Rodar o teste**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SavedSignerModelTest`
Expected: PASS (2 testes)

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_25_000001_create_saved_signers_table.php \
        database/migrations/2026_07_25_000002_create_signer_groups_table.php \
        database/migrations/2026_07_25_000003_create_signer_group_members_table.php \
        app/Models/SavedSigner.php app/Models/SignerGroup.php \
        database/factories/SavedSignerFactory.php database/factories/SignerGroupFactory.php \
        tests/Feature/SavedSignerModelTest.php
git commit -m "feat: tabelas e models de signatarios salvos e grupos"
```

---

### Task 2: `envelope_signers.saved_signer_id` (rastreabilidade)

**Files:**
- Create: `database/migrations/2026_07_25_000004_add_saved_signer_id_to_envelope_signers_table.php`
- Modify: `app/Models/EnvelopeSigner.php:15-21`
- Test: `tests/Feature/EnvelopeModelTest.php`

**Interfaces:**
- Produces: `EnvelopeSigner::$savedSigner(): BelongsTo` (nullable).

- [ ] **Step 1: Migration**

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
            $table->foreignId('saved_signer_id')->nullable()->after('envelope_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saved_signer_id');
        });
    }
};
```

- [ ] **Step 2: Ler o teste existente antes de editar**

Abrir `tests/Feature/EnvelopeModelTest.php` para confirmar convenções de teste do model (nome dos métodos, uso de factories).

- [ ] **Step 3: Escrever o teste de que a FK aceita null e sobrevive à exclusão do contato**

Adicionar a `tests/Feature/EnvelopeModelTest.php`:

```php
public function test_saved_signer_id_becomes_null_when_saved_signer_is_deleted(): void
{
    $savedSigner = \App\Models\SavedSigner::factory()->create();
    $envelope = Envelope::factory()->create(['user_id' => $savedSigner->user_id]);
    $signer = EnvelopeSigner::factory()->for($envelope)->create(['saved_signer_id' => $savedSigner->id]);

    $savedSigner->delete();

    $this->assertNull($signer->fresh()->saved_signer_id);
}
```

- [ ] **Step 4: Rodar e confirmar a falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=test_saved_signer_id_becomes_null_when_saved_signer_is_deleted`
Expected: FAIL — `saved_signer_id` não é fillable ainda (mass assignment silenciosamente ignorado, campo fica null desde o início, o que faria o teste passar por acidente; para evitar falso-positivo, o Step 3 deve rodar DEPOIS do Step 5 abaixo — inverta a ordem se seu ambiente de teste não permitir mass-assignment de campo não-fillable).

> **Nota:** como `EnvelopeSignerFactory` usa `create()`, um campo fora do `$fillable` lança `MassAssignmentException` (Laravel com `Model::preventSilentlyDiscardingAttributes()` ou similar) ou é silenciosamente ignorado dependendo da config do projeto. Verifique qual comportamento ocorre rodando o teste antes do Step 5; se passar sem implementar nada, adicione uma asserção extra confirmando `$signer->saved_signer_id` não nulo logo após a criação, antes do delete.

- [ ] **Step 5: Adicionar `saved_signer_id` ao fillable e a relação**

Em `app/Models/EnvelopeSigner.php`:

```php
protected $fillable = [
    'envelope_id', 'saved_signer_id', 'name', 'email', 'whatsapp', 'cpf', 'channel', 'send_signed_copy',
    'auth_method', 'sign_position', 'token', 'status',
    'signature_image_path', 'signature_type',
    'otp_code', 'otp_expires_at', 'otp_attempts',
    'signed_at', 'ip_address', 'user_agent', 'decline_reason',
];
```

Adicionar a relação, perto de `envelope()`:

```php
public function savedSigner(): BelongsTo
{
    return $this->belongsTo(SavedSigner::class);
}
```

(Adicionar `use App\Models\SavedSigner;` não é necessário — mesma namespace `App\Models`.)

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=test_saved_signer_id_becomes_null_when_saved_signer_is_deleted`
Expected: PASS

- [ ] **Step 7: Rodar toda a suíte de envelope para checar regressão**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=Envelope`
Expected: PASS em todos

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_25_000004_add_saved_signer_id_to_envelope_signers_table.php \
        app/Models/EnvelopeSigner.php tests/Feature/EnvelopeModelTest.php
git commit -m "feat: adicionar saved_signer_id a envelope_signers"
```

---

### Task 3: `SignerDirectoryService` — CRUD, limites e busca

**Files:**
- Create: `app/Services/SignerDirectoryService.php`
- Test: `tests/Feature/SignerDirectoryServiceTest.php`

**Interfaces:**
- Produces: `createSigner(User $user, array $data): SavedSigner`, `updateSigner(SavedSigner $signer, array $data): SavedSigner`, `deleteSigner(SavedSigner $signer): void`, `createGroup(User $user, string $name): SignerGroup`, `updateGroup(SignerGroup $group, string $name, array $savedSignerIds): SignerGroup`, `deleteGroup(SignerGroup $group): void`, `search(User $user, string $query): Collection` (de `SavedSigner`).
- Consumes: `SavedSigner`, `SignerGroup` (Task 1).

- [ ] **Step 1: Escrever o teste (CRUD, limites, validação canal↔método, busca)**

```php
<?php

namespace Tests\Feature;

use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Models\User;
use App\Services\SignerDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SignerDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SignerDirectoryService
    {
        return app(SignerDirectoryService::class);
    }

    public function test_creates_signer_with_email_channel(): void
    {
        $user = User::factory()->create();

        $signer = $this->service()->createSigner($user, [
            'name' => 'Ana', 'channel' => 'email', 'email' => 'ana@x.com', 'auth_method' => 'link',
        ]);

        $this->assertSame($user->id, $signer->user_id);
        $this->assertDatabaseCount('saved_signers', 1);
    }

    public function test_rejects_whatsapp_channel_with_email_otp(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->createSigner($user, [
            'name' => 'Ana', 'channel' => 'whatsapp', 'whatsapp' => '11999998888', 'auth_method' => 'email_otp',
        ]);
    }

    public function test_enforces_max_100_signers_per_user(): void
    {
        $user = User::factory()->create();
        SavedSigner::factory()->count(100)->create(['user_id' => $user->id]);

        $this->expectException(ValidationException::class);

        $this->service()->createSigner($user, [
            'name' => 'Extra', 'channel' => 'email', 'email' => 'extra@x.com', 'auth_method' => 'link',
        ]);
    }

    public function test_enforces_max_20_groups_per_user(): void
    {
        $user = User::factory()->create();
        SignerGroup::factory()->count(20)->create(['user_id' => $user->id]);

        $this->expectException(ValidationException::class);

        $this->service()->createGroup($user, 'Grupo extra');
    }

    public function test_update_group_members_replaces_the_set(): void
    {
        $user = User::factory()->create();
        $group = SignerGroup::factory()->create(['user_id' => $user->id]);
        $a = SavedSigner::factory()->create(['user_id' => $user->id]);
        $b = SavedSigner::factory()->create(['user_id' => $user->id]);
        $group->members()->attach($a->id);

        $this->service()->updateGroup($group, 'Renomeado', [$b->id]);

        $this->assertSame('Renomeado', $group->fresh()->name);
        $this->assertEquals([$b->id], $group->fresh()->members->pluck('id')->all());
    }

    public function test_delete_group_does_not_delete_its_signers(): void
    {
        $user = User::factory()->create();
        $group = SignerGroup::factory()->create(['user_id' => $user->id]);
        $signer = SavedSigner::factory()->create(['user_id' => $user->id]);
        $group->members()->attach($signer->id);

        $this->service()->deleteGroup($group);

        $this->assertDatabaseMissing('signer_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('saved_signers', ['id' => $signer->id]);
    }

    public function test_search_only_returns_the_users_own_signers(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Maria Ana']);
        SavedSigner::factory()->create(['user_id' => $other->id, 'name' => 'Ana de Outro']);

        $results = $this->service()->search($user, 'ana');

        $this->assertCount(1, $results);
        $this->assertSame('Maria Ana', $results->first()->name);
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SignerDirectoryServiceTest`
Expected: FAIL — classe `SignerDirectoryService` não existe.

- [ ] **Step 3: Implementar o serviço**

```php
<?php

namespace App\Services;

use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SignerDirectoryService
{
    private const MAX_SIGNERS_PER_USER = 100;

    private const MAX_GROUPS_PER_USER = 20;

    public function createSigner(User $user, array $data): SavedSigner
    {
        $this->validateSignerData($data);

        if ($user->savedSigners()->count() >= self::MAX_SIGNERS_PER_USER) {
            throw ValidationException::withMessages([
                'name' => 'Limite de '.self::MAX_SIGNERS_PER_USER.' signatários salvos atingido.',
            ]);
        }

        return $user->savedSigners()->create($data);
    }

    public function updateSigner(SavedSigner $signer, array $data): SavedSigner
    {
        $this->validateSignerData($data);
        $signer->update($data);

        return $signer;
    }

    public function deleteSigner(SavedSigner $signer): void
    {
        $signer->delete();
    }

    public function createGroup(User $user, string $name): SignerGroup
    {
        if ($user->signerGroups()->count() >= self::MAX_GROUPS_PER_USER) {
            throw ValidationException::withMessages([
                'name' => 'Limite de '.self::MAX_GROUPS_PER_USER.' grupos atingido.',
            ]);
        }

        return $user->signerGroups()->create(['name' => $name]);
    }

    /** @param  list<int>  $savedSignerIds */
    public function updateGroup(SignerGroup $group, string $name, array $savedSignerIds): SignerGroup
    {
        $group->update(['name' => $name]);
        $group->members()->sync($savedSignerIds);

        return $group;
    }

    public function deleteGroup(SignerGroup $group): void
    {
        $group->delete();
    }

    public function search(User $user, string $query): Collection
    {
        return $user->savedSigners()
            ->where('name', 'like', '%'.$query.'%')
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    private function validateSignerData(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:email,whatsapp'],
            'email' => ['nullable', 'email', 'max:255', 'required_if:channel,email'],
            'whatsapp' => ['nullable', 'string', 'max:20', 'required_if:channel,whatsapp'],
            'auth_method' => ['required', 'in:link,email_otp,whatsapp_otp'],
        ]);

        $validator->validate();

        $allowed = $data['channel'] === 'whatsapp' ? ['link', 'whatsapp_otp'] : ['link', 'email_otp'];
        if (! in_array($data['auth_method'], $allowed, true)) {
            throw ValidationException::withMessages([
                'auth_method' => 'Método de verificação incompatível com o canal escolhido.',
            ]);
        }
    }
}
```

- [ ] **Step 4: Adicionar as relações que faltam em `User`**

Ler `app/Models/User.php` para localizar onde outras relações (`certificates()`, etc.) estão definidas, e adicionar:

```php
public function savedSigners(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(SavedSigner::class);
}

public function signerGroups(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(SignerGroup::class);
}
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SignerDirectoryServiceTest`
Expected: PASS (7 testes)

- [ ] **Step 6: Commit**

```bash
git add app/Services/SignerDirectoryService.php app/Models/User.php tests/Feature/SignerDirectoryServiceTest.php
git commit -m "feat: SignerDirectoryService com CRUD, limites e busca"
```

---

### Task 4: Controller e rotas de `/signatarios`

**Files:**
- Create: `app/Http/Controllers/Client/SignerDirectoryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/SignerDirectoryControllerTest.php`

**Interfaces:**
- Consumes: `SignerDirectoryService` (Task 3).
- Produces: rotas HTTP conforme a spec (`index`, `store`, `update`, `destroy` para signer; `storeGroup`, `updateGroup`, `destroyGroup`; `search`).

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignerDirectoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_the_users_own_signers_and_groups(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Meu Contato']);
        SavedSigner::factory()->create(['user_id' => $other->id, 'name' => 'Contato Alheio']);

        $this->actingAs($user)->get('/signatarios')
            ->assertOk()->assertSee('Meu Contato')->assertDontSee('Contato Alheio');
    }

    public function test_store_creates_a_signer(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->actingAs($user)->post('/signatarios', [
            'name' => 'Ana', 'channel' => 'email', 'email' => 'ana@x.com', 'auth_method' => 'link',
        ])->assertRedirect();

        $this->assertDatabaseHas('saved_signers', ['user_id' => $user->id, 'name' => 'Ana']);
    }

    public function test_cannot_update_another_users_signer(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)->patch("/signatarios/{$signer->id}", [
            'name' => 'Hackeado', 'channel' => 'email', 'email' => 'x@x.com', 'auth_method' => 'link',
        ])->assertForbidden();
    }

    public function test_cannot_delete_another_users_group(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $group = SignerGroup::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)->delete("/signatarios/grupos/{$group->id}")->assertForbidden();
        $this->assertDatabaseHas('signer_groups', ['id' => $group->id]);
    }

    public function test_search_returns_json_of_matching_signers(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Carlos Souza']);

        $response = $this->actingAs($user)->get('/signatarios/buscar?q=carlos');

        $response->assertOk()->assertJsonFragment(['name' => 'Carlos Souza']);
    }

    public function test_store_group_with_members(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $signer = SavedSigner::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/signatarios/grupos', [
            'name' => 'Diretoria', 'members' => [$signer->id],
        ])->assertRedirect();

        $group = SignerGroup::where('name', 'Diretoria')->first();
        $this->assertNotNull($group);
        $this->assertCount(1, $group->members);
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SignerDirectoryControllerTest`
Expected: FAIL — rotas não existem (404).

- [ ] **Step 3: Implementar o controller**

```php
<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Services\SignerDirectoryService;
use Illuminate\Http\Request;

class SignerDirectoryController extends Controller
{
    public function __construct(private SignerDirectoryService $directory) {}

    public function index()
    {
        $signers = auth()->user()->savedSigners()->orderBy('name')->get();
        $groups = auth()->user()->signerGroups()->with('members')->orderBy('name')->get();

        return view('client.signers.index', compact('signers', 'groups'));
    }

    public function store(Request $request)
    {
        $data = $this->validatedSignerData($request);
        $this->directory->createSigner(auth()->user(), $data);

        return back()->with('success', 'Signatário salvo.');
    }

    public function update(Request $request, SavedSigner $savedSigner)
    {
        $this->authorizeOwner($savedSigner);
        $data = $this->validatedSignerData($request);
        $this->directory->updateSigner($savedSigner, $data);

        return back()->with('success', 'Signatário atualizado.');
    }

    public function destroy(SavedSigner $savedSigner)
    {
        $this->authorizeOwner($savedSigner);
        $this->directory->deleteSigner($savedSigner);

        return back()->with('success', 'Signatário removido.');
    }

    public function search(Request $request)
    {
        $results = $this->directory->search(auth()->user(), (string) $request->query('q', ''));

        return response()->json($results->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'channel' => $s->channel,
            'email' => $s->email, 'whatsapp' => $s->whatsapp, 'auth_method' => $s->auth_method,
        ]));
    }

    public function storeGroup(Request $request)
    {
        $request->validate(['name' => ['required', 'string', 'max:255'], 'members' => ['array']]);

        $group = $this->directory->createGroup(auth()->user(), $request->input('name'));
        $group->members()->sync($request->input('members', []));

        return back()->with('success', 'Grupo criado.');
    }

    public function updateGroup(Request $request, SignerGroup $signerGroup)
    {
        $this->authorizeOwnerGroup($signerGroup);
        $request->validate(['name' => ['required', 'string', 'max:255'], 'members' => ['array']]);

        $this->directory->updateGroup($signerGroup, $request->input('name'), $request->input('members', []));

        return back()->with('success', 'Grupo atualizado.');
    }

    public function destroyGroup(SignerGroup $signerGroup)
    {
        $this->authorizeOwnerGroup($signerGroup);
        $this->directory->deleteGroup($signerGroup);

        return back()->with('success', 'Grupo removido.');
    }

    private function validatedSignerData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:email,whatsapp'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'auth_method' => ['required', 'in:link,email_otp,whatsapp_otp'],
        ]);
    }

    private function authorizeOwner(SavedSigner $signer): void
    {
        abort_unless($signer->user_id === auth()->id(), 403);
    }

    private function authorizeOwnerGroup(SignerGroup $group): void
    {
        abort_unless($group->user_id === auth()->id(), 403);
    }
}
```

- [ ] **Step 4: Adicionar as rotas**

Em `routes/web.php`, dentro do grupo `Route::middleware('auth')->group(...)` que já contém `certificates`/`sign-document` (linha 47-65), adicionar antes do fechamento:

```php
// Signatários salvos e grupos de signatários
Route::get('signatarios', [\App\Http\Controllers\Client\SignerDirectoryController::class, 'index'])->name('signers.index');
Route::post('signatarios', [\App\Http\Controllers\Client\SignerDirectoryController::class, 'store'])->name('signers.store');
Route::patch('signatarios/{savedSigner}', [\App\Http\Controllers\Client\SignerDirectoryController::class, 'update'])->name('signers.update');
Route::delete('signatarios/{savedSigner}', [\App\Http\Controllers\Client\SignerDirectoryController::class, 'destroy'])->name('signers.destroy');
Route::get('signatarios/buscar', [\App\Http\Controllers\Client\SignerDirectoryController::class, 'search'])->name('signers.search');
Route::post('signatarios/grupos', [\App\Http\Controllers\Client\SignerDirectoryController::class, 'storeGroup'])->name('signers.groups.store');
Route::patch('signatarios/grupos/{signerGroup}', [\App\Http\Controllers\Client\SignerDirectoryController::class, 'updateGroup'])->name('signers.groups.update');
Route::delete('signatarios/grupos/{signerGroup}', [\App\Http\Controllers\Client\SignerDirectoryController::class, 'destroyGroup'])->name('signers.groups.destroy');
```

> **Nota:** a rota `signatarios/buscar` deve ser registrada ANTES de qualquer rota com parâmetro dinâmico tipo `signatarios/{savedSigner}` seria um problema de ordem, mas aqui `buscar` é um segmento fixo e `{savedSigner}` está diretamente em `signatarios/{savedSigner}` (mesmo nível) — o Laravel resolve pela ordem de definição quando os segmentos colidem. Mantenha `search` definida ANTES de `update`/`destroy` no arquivo de rotas para evitar que `GET signatarios/buscar` seja capturado por engano (não deveria acontecer aqui pois methods diferem — GET vs PATCH/DELETE — mas mantenha a ordem por clareza).

- [ ] **Step 5: Criar a view (mínima, só para os testes passarem — refinamento visual fica para depois se necessário)**

```blade
@extends('client.layout')
@section('title', 'Signatários')

@section('content')
<div class="max-w-5xl mx-auto space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-white">Signatários</h1>
        <p class="text-xs text-gray-500 mt-0.5">Contatos e grupos reutilizáveis para seus envelopes</p>
    </div>

    @if (session('success'))
        <div class="bg-green-900/40 border border-green-700 text-green-300 px-4 py-3 rounded text-sm">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <h2 class="text-sm font-semibold text-white">Novo signatário</h2>
        <form method="POST" action="{{ route('signers.store') }}" class="grid md:grid-cols-2 gap-3">
            @csrf
            <input type="text" name="name" placeholder="Nome completo" required maxlength="255"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="channel" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
                <option value="email">Canal: E-mail</option>
                <option value="whatsapp">Canal: WhatsApp</option>
            </select>
            <input type="text" name="email" placeholder="E-mail" maxlength="255"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <input type="text" name="whatsapp" placeholder="WhatsApp (com DDD)" maxlength="20"
                   class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="auth_method" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
                <option value="link">Somente link</option>
                <option value="email_otp">Código por e-mail</option>
                <option value="whatsapp_otp">Código por WhatsApp</option>
            </select>
            <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white md:col-span-2"
                    style="background-color: var(--color-primary);">Salvar</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
        <h2 class="text-sm font-semibold text-white mb-3">Seus signatários</h2>
        <div class="space-y-2">
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
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        <h2 class="text-sm font-semibold text-white">Novo grupo</h2>
        <form method="POST" action="{{ route('signers.groups.store') }}" class="space-y-3">
            @csrf
            <input type="text" name="name" placeholder="Nome do grupo" required maxlength="255"
                   class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <select name="members[]" multiple class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm" size="5">
                @foreach ($signers as $signer)
                    <option value="{{ $signer->id }}">{{ $signer->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white"
                    style="background-color: var(--color-primary);">Criar grupo</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-lg p-5">
        <h2 class="text-sm font-semibold text-white mb-3">Seus grupos</h2>
        <div class="space-y-2">
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
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=SignerDirectoryControllerTest`
Expected: PASS (6 testes)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Client/SignerDirectoryController.php routes/web.php \
        resources/views/client/signers/index.blade.php \
        tests/Feature/SignerDirectoryControllerTest.php
git commit -m "feat: controller, rotas e tela de gestao de signatarios/grupos"
```

---

### Task 5: Link no header do cliente

**Files:**
- Modify: `resources/views/client/layout.blade.php:41-113`
- Test: manual (visual) — sem teste automatizado dedicado; coberto indiretamente por qualquer teste que já visite páginas do cliente autenticado.

- [ ] **Step 1: Adicionar o link no menu desktop**

Em `resources/views/client/layout.blade.php`, logo após o link de Envelopes no menu desktop (linha ~56-60, mesmo padrão dos outros `<a>`):

```blade
<a href="{{ route('signers.index') }}"
   class="text-sm {{ request()->routeIs('signers.*') ? 'text-white' : 'text-gray-400 hover:text-white' }} transition-colors">
    Signatários
</a>
```

- [ ] **Step 2: Adicionar o mesmo link no menu mobile**

Repetir o padrão equivalente próximo à linha ~108-113 (menu mobile), seguindo a mesma estrutura dos links existentes ali.

- [ ] **Step 3: Rodar a suíte de envelope/certificados para confirmar que o layout não quebrou**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter="Envelope|Certificate"`
Expected: PASS em todos (essas suítes renderizam `client.layout` em várias páginas)

- [ ] **Step 4: Commit**

```bash
git add resources/views/client/layout.blade.php
git commit -m "feat: link Signatarios no header do cliente"
```

---

### Task 6: Integração no wizard de envelope — autocomplete e carregar grupo

**Files:**
- Modify: `app/Http/Controllers/Client/EnvelopeController.php:34-40` (`create()`)
- Modify: `resources/views/client/envelopes/create.blade.php`
- Test: `tests/Feature/EnvelopeControllerTest.php`

**Interfaces:**
- Consumes: `auth()->user()->signerGroups()->with('members')`.
- Produces: `create()` passa `$groups` para a view.

- [ ] **Step 1: Escrever o teste que confirma os grupos chegam à view**

Adicionar a `tests/Feature/EnvelopeControllerTest.php`:

```php
public function test_create_exposes_the_users_signer_groups_to_the_wizard(): void
{
    $user = User::factory()->create(['role' => 'client']);
    $group = \App\Models\SignerGroup::factory()->create(['user_id' => $user->id, 'name' => 'Diretoria']);
    $signer = \App\Models\SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Fulano']);
    $group->members()->attach($signer->id);

    $this->actingAs($user)->get('/envelopes/create')
        ->assertOk()->assertSee('Diretoria');
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=test_create_exposes_the_users_signer_groups_to_the_wizard`
Expected: FAIL — view não recebe `$groups`, "Diretoria" não aparece.

- [ ] **Step 3: Atualizar `EnvelopeController::create()`**

```php
public function create()
{
    $user = auth()->user();
    $defaultChannel = $user->whatsapp_envelope_enabled ? $user->default_envelope_channel : 'email';
    $groups = $user->signerGroups()->with('members')->orderBy('name')->get();

    return view('client.envelopes.create', compact('defaultChannel', 'groups'));
}
```

- [ ] **Step 4: Adicionar o seletor de grupo e o autocomplete na view**

Em `resources/views/client/envelopes/create.blade.php`, dentro do Passo 2 (linha 78, logo após a abertura da div), adicionar antes do `<template x-for="(signer, i) in signers">`:

```blade
<div class="flex flex-wrap items-center gap-3 pb-2 border-b border-gray-800">
    <div class="relative flex-1 min-w-[200px]">
        <input type="text" placeholder="Buscar signatário salvo..." x-model="signerQuery" @input.debounce.300ms="searchSigners()"
               class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
        <div x-show="signerResults.length > 0" x-cloak
             class="absolute z-10 mt-1 w-full bg-gray-800 border border-gray-700 rounded shadow-lg max-h-48 overflow-auto">
            <template x-for="result in signerResults" :key="result.id">
                <button type="button" @click="addSavedSigner(result)"
                        class="block w-full text-left px-3 py-2 text-sm text-gray-200 hover:bg-gray-700"
                        x-text="result.name"></button>
            </template>
        </div>
    </div>
    @if ($groups->isNotEmpty())
        <select @change="if ($event.target.value) { addGroup($event.target.value); $event.target.value = ''; }"
                class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm">
            <option value="">Carregar grupo...</option>
            @foreach ($groups as $group)
                <option value="{{ $group->id }}">{{ $group->name }} ({{ $group->members->count() }})</option>
            @endforeach
        </select>
    @endif
</div>
```

E dentro de cada linha de signatário (`<template x-for="(signer, i) in signers">`, logo antes do fechamento da div do cabeçalho da linha, junto do botão "remover" na linha 80-84), adicionar o checkbox:

```blade
<label class="flex items-center gap-2 text-xs text-gray-400">
    <input type="checkbox" x-model="signer.save_as_contact">
    Salvar para reutilizar depois
</label>
```

- [ ] **Step 5: Expor os grupos (com membros) para o JS Alpine e implementar as funções**

Adicionar antes do `<script>` de `envelopeWizard()`, logo após a linha `<script>window.__envelopeDefaultChannel = ...</script>` (linha 154):

```blade
<script>window.__envelopeGroups = @json($groups->map(fn ($g) => [
    'id' => $g->id,
    'name' => $g->name,
    'members' => $g->members->map(fn ($m) => [
        'name' => $m->name, 'channel' => $m->channel, 'email' => $m->email,
        'whatsapp' => $m->whatsapp, 'auth_method' => $m->auth_method, 'saved_signer_id' => $m->id,
    ]),
]));</script>
```

Dentro de `envelopeWizard()` (função JS, `return { ... }`), adicionar aos dados iniciais (perto de `step: 1, signers: [], ...`):

```js
signerQuery: '', signerResults: [], groups: window.__envelopeGroups || [],
```

E adicionar os métodos, próximos a `addSigner()`:

```js
async searchSigners() {
    if (this.signerQuery.trim().length < 2) { this.signerResults = []; return; }
    const res = await fetch(`/signatarios/buscar?q=${encodeURIComponent(this.signerQuery)}`);
    this.signerResults = res.ok ? await res.json() : [];
},
addSavedSigner(result) {
    if (this.signers.length >= 20) return;
    this.signers.push({
        name: result.name, channel: result.channel, email: result.email || '',
        whatsapp: result.whatsapp || '', auth_method: result.auth_method,
        saved_signer_id: result.id, save_as_contact: false,
    });
    this.signerQuery = ''; this.signerResults = [];
},
addGroup(groupId) {
    const group = this.groups.find(g => String(g.id) === String(groupId));
    if (!group) return;
    group.members.forEach(m => {
        if (this.signers.length >= 20) return;
        this.signers.push({
            name: m.name, channel: m.channel, email: m.email || '',
            whatsapp: m.whatsapp || '', auth_method: m.auth_method,
            saved_signer_id: m.saved_signer_id, save_as_contact: false,
        });
    });
},
```

Também ajustar `addSigner()` (definição existente na linha 166) para incluir os novos campos:

```js
addSigner() { if (this.signers.length < 20) this.signers.push({name:'', email:'', channel: this.defaultChannel, auth_method:'link', whatsapp:'', saved_signer_id: null, save_as_contact: false}); },
```

E ajustar `submit()` (linha 270-282) para incluir `saved_signer_id` e `save_as_contact` no JSON enviado:

```js
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
```

(`...s` já inclui `saved_signer_id` e `save_as_contact` automaticamente, já que fazem parte do objeto `signer`.)

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=test_create_exposes_the_users_signer_groups_to_the_wizard`
Expected: PASS

- [ ] **Step 7: Rodar toda a suíte de EnvelopeControllerTest para checar regressão**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=EnvelopeControllerTest`
Expected: PASS em todos

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Client/EnvelopeController.php resources/views/client/envelopes/create.blade.php \
        tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: autocomplete e carregar grupo no wizard de envelope"
```

---

### Task 7: Persistir signatários marcados como "salvar" ao enviar o envelope

**Files:**
- Modify: `app/Http/Controllers/Client/EnvelopeController.php:42-81,167-203`
- Modify: `app/Services/Envelope/EnvelopeService.php:28-61` (aceitar e gravar `saved_signer_id` por signatário)
- Test: `tests/Feature/EnvelopeControllerTest.php`

**Interfaces:**
- Consumes: `SignerDirectoryService::createSigner()` (Task 3).
- Produces: `EnvelopeService::create()` grava `saved_signer_id` em cada `EnvelopeSigner` quando presente no payload.

- [ ] **Step 1: Escrever o teste**

Adicionar a `tests/Feature/EnvelopeControllerTest.php`:

```php
public function test_store_saves_signer_marked_to_save_as_contact(): void
{
    Storage::fake('local');
    Storage::fake('documents');
    Mail::fake();
    $this->configurePlatformCertificate();
    $user = User::factory()->withPlan()->create(['role' => 'client']);

    $payload = array_merge($this->validPayload(), ['signers_json' => json_encode([
        ['name' => 'Nova Ana', 'email' => 'nova.ana@x.com', 'channel' => 'email', 'auth_method' => 'link',
         'save_as_contact' => true,
         'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]],
    ])]);

    $this->actingAs($user)->post('/envelopes', $payload);

    $this->assertDatabaseHas('saved_signers', ['user_id' => $user->id, 'name' => 'Nova Ana', 'email' => 'nova.ana@x.com']);
}

public function test_store_does_not_duplicate_signer_when_not_marked_to_save(): void
{
    Storage::fake('local');
    Storage::fake('documents');
    Mail::fake();
    $this->configurePlatformCertificate();
    $user = User::factory()->withPlan()->create(['role' => 'client']);

    $this->actingAs($user)->post('/envelopes', $this->validPayload());

    $this->assertDatabaseCount('saved_signers', 0);
}

public function test_store_records_saved_signer_id_when_signer_came_from_a_contact(): void
{
    Storage::fake('local');
    Storage::fake('documents');
    Mail::fake();
    $this->configurePlatformCertificate();
    $user = User::factory()->withPlan()->create(['role' => 'client']);
    $contact = \App\Models\SavedSigner::factory()->create(['user_id' => $user->id, 'name' => 'Contato Existente', 'email' => 'contato@x.com']);

    $payload = array_merge($this->validPayload(), ['signers_json' => json_encode([
        ['name' => 'Contato Existente', 'email' => 'contato@x.com', 'channel' => 'email', 'auth_method' => 'link',
         'saved_signer_id' => $contact->id,
         'fields' => [['page' => 1, 'x' => 1, 'y' => 1, 'w' => 50, 'h' => 20]]],
    ])]);

    $this->actingAs($user)->post('/envelopes', $payload);

    $envelope = Envelope::first();
    $this->assertSame($contact->id, $envelope->signers->first()->saved_signer_id);
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=test_store_saves_signer_marked_to_save_as_contact`
Expected: FAIL — `saved_signers` continua vazio.

- [ ] **Step 3: Ajustar `validateSigners()` no controller para aceitar os novos campos**

Em `app/Http/Controllers/Client/EnvelopeController.php`, no método `validateSigners()` (linha 168-203), adicionar às regras de validação (perto de `signers.*.auth_method`):

```php
'signers.*.saved_signer_id' => ['nullable', 'integer'],
'signers.*.save_as_contact' => ['nullable', 'boolean'],
```

- [ ] **Step 4: Ajustar `store()` para persistir os contatos marcados após criar o envelope**

Em `app/Http/Controllers/Client/EnvelopeController.php`, injetar `SignerDirectoryService` no construtor:

```php
public function __construct(
    private EnvelopeService $envelopes,
    private AccessLogService $accessLog,
    private UsageLimitService $usageLimit,
    private \App\Services\SignerDirectoryService $signerDirectory,
) {}
```

E em `store()`, logo após `$this->envelopes->send($envelope);` (linha 69) e antes do `accessLog->log(...)`:

```php
$this->persistSignersMarkedToSave(auth()->user(), $signers);
```

Adicionar o método privado, perto de `validateSigners()`:

```php
private function persistSignersMarkedToSave(\App\Models\User $user, array $signers): void
{
    foreach ($signers as $signer) {
        if (empty($signer['save_as_contact']) || ! empty($signer['saved_signer_id'])) {
            continue; // só salva signatários novos/editados, nunca duplica um contato já existente
        }

        try {
            $this->signerDirectory->createSigner($user, [
                'name' => $signer['name'],
                'channel' => $signer['channel'],
                'email' => $signer['email'] ?? null,
                'whatsapp' => $signer['whatsapp'] ?? null,
                'auth_method' => $signer['auth_method'],
            ]);
        } catch (\Illuminate\Validation\ValidationException) {
            // limite de 100 contatos atingido, ou dados incompatíveis — não bloqueia o envio do envelope
        }
    }
}
```

- [ ] **Step 5: Ajustar `EnvelopeService::create()` para gravar `saved_signer_id`**

Em `app/Services/Envelope/EnvelopeService.php`, no laço de criação de signatários (linha 44-54):

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
        'saved_signer_id' => $s['saved_signer_id'] ?? null,
    ]);
    $signer->fields()->createMany($s['fields']);
}
```

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=EnvelopeControllerTest`
Expected: PASS em todos (incluindo os 3 novos testes)

- [ ] **Step 7: Rodar a suíte completa de envelope e serviço para checar regressão**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test --filter=Envelope`
Expected: PASS em todos

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Client/EnvelopeController.php app/Services/Envelope/EnvelopeService.php \
        tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: persistir signatarios marcados para reutilizar ao enviar envelope"
```

---

## Verificação final

- [ ] Rodar a suíte completa: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test`
- [ ] Confirmar que nenhum teste pré-existente quebrou
- [ ] QA manual: criar 2-3 signatários salvos e um grupo em `/signatarios`, depois criar um envelope usando o autocomplete e o botão de carregar grupo, marcar "salvar" em um signatário novo e confirmar que ele aparece em `/signatarios` depois do envio
