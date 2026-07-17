<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\User;
use App\Services\Pdf\Pkcs12Reader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeneratesPfx;
use Tests\TestCase;

class CertificateCrudTest extends TestCase
{
    use GeneratesPfx, RefreshDatabase;

    private function pfxUpload(string $password = 'secret'): UploadedFile
    {
        return new UploadedFile($this->generatePfx($password), 'certificado.pfx', 'application/octet-stream', null, true);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/certificates')->assertRedirect(route('login'));
    }

    public function test_index_renders_for_client(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        Certificate::factory()->for($client)->create(['description' => 'Meu Certificado A1']);

        $this->actingAs($client)
            ->get('/certificates')
            ->assertOk()
            ->assertSee('Meu Certificado A1');
    }

    public function test_store_validates_password_and_extracts_expiration(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->post('/certificates', [
            'description' => 'Certificado A1',
            'reference' => '15',
            'pfx' => $this->pfxUpload('minhasenha'),
            'password' => 'minhasenha',
        ])->assertRedirect(route('certificates.index'));

        $certificate = Certificate::first();
        $this->assertNotNull($certificate);
        $this->assertSame($client->id, $certificate->user_id);
        $this->assertSame('minhasenha', $certificate->password); // cast encrypted, decripta ao ler
        $this->assertNotNull($certificate->expires_at);
        $this->assertTrue($certificate->expires_at->isFuture());
        Storage::disk('local')->assertExists($certificate->pfx_path);
    }

    public function test_store_accepts_legacy_rc2_pfx_and_normalizes_it(): void
    {
        if (! (new Pkcs12Reader)->conversionAvailable()) {
            $this->markTestSkipped('Nenhum CLI openssl capaz de ler PFX legado disponível nesta máquina.');
        }

        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);

        // Cópia do fixture: UploadedFile pode mover o arquivo em alguns fluxos
        $tmp = tempnam(sys_get_temp_dir(), 'leg_').'.pfx';
        copy(base_path('tests/Fixtures/legacy-rc2-40.pfx'), $tmp);

        $this->actingAs($client)->post('/certificates', [
            'description' => 'A1 legado (RC2-40)',
            'pfx' => new UploadedFile($tmp, 'legacy.pfx', 'application/octet-stream', null, true),
            'password' => 'secret',
        ])->assertRedirect(route('certificates.index'))
            ->assertSessionHasNoErrors();

        $certificate = Certificate::first();
        $this->assertNotNull($certificate->expires_at);

        // O PFX armazenado deve ter sido normalizado: legível pelo OpenSSL 3 nativo
        $native = [];
        $this->assertTrue(
            openssl_pkcs12_read((string) Storage::disk('local')->get($certificate->pfx_path), $native, 'secret'),
            'PFX armazenado deve estar em formato moderno'
        );
    }

    public function test_store_rejects_wrong_pfx_password(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->post('/certificates', [
            'description' => 'Certificado A1',
            'pfx' => $this->pfxUpload('senha-certa'),
            'password' => 'senha-errada',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, Certificate::count());
    }

    public function test_store_saves_images(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->post('/certificates', [
            'description' => 'Com imagens',
            'pfx' => $this->pfxUpload(),
            'password' => 'secret',
            'sign_image' => UploadedFile::fake()->image('assinatura.png', 300, 120),
            'logo_image' => UploadedFile::fake()->image('logo.jpg', 200, 200),
        ])->assertRedirect(route('certificates.index'));

        $certificate = Certificate::first();
        Storage::disk('local')->assertExists($certificate->sign_image_path);
        Storage::disk('local')->assertExists($certificate->logo_image_path);

        $this->actingAs($client)
            ->get(route('certificates.image', [$certificate, 'sign']))
            ->assertOk();
    }

    public function test_update_without_new_pfx_keeps_certificate_data(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);
        $certificate = Certificate::factory()->for($client)->create(['description' => 'Antiga']);

        $this->actingAs($client)->patch(route('certificates.update', $certificate), [
            'description' => 'Nova descrição',
            'reference' => '99',
        ])->assertRedirect(route('certificates.index'));

        $certificate->refresh();
        $this->assertSame('Nova descrição', $certificate->description);
        $this->assertSame('99', $certificate->reference);
        $this->assertSame('secret', $certificate->password);
    }

    public function test_client_cannot_touch_another_users_certificate(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $certificate = Certificate::factory()->for($owner)->create();

        $this->actingAs($other)->get(route('certificates.edit', $certificate))->assertForbidden();
        $this->actingAs($other)->delete(route('certificates.destroy', $certificate))->assertForbidden();
        $this->actingAs($other)->get(route('certificates.image', [$certificate, 'sign']))->assertForbidden();
        $this->assertSame(1, Certificate::count());
    }

    public function test_destroy_removes_certificate_and_files(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->post('/certificates', [
            'description' => 'Para remover',
            'pfx' => $this->pfxUpload(),
            'password' => 'secret',
        ]);

        $certificate = Certificate::first();
        $pfxPath = $certificate->pfx_path;

        $this->actingAs($client)
            ->delete(route('certificates.destroy', $certificate))
            ->assertRedirect(route('certificates.index'));

        $this->assertSame(0, Certificate::count());
        Storage::disk('local')->assertMissing($pfxPath);
        $this->assertDatabaseHas('access_logs', ['event' => 'certificate_deleted']);
    }

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
}
