<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeneratesPfx;
use Tests\TestCase;

class SignDocumentTest extends TestCase
{
    use GeneratesPfx, RefreshDatabase;

    private function makeSourcePdf(): string
    {
        $pdf = new \TCPDF;
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Documento de teste para assinatura');
        $path = tempnam(sys_get_temp_dir(), 'src_').'.pdf';
        $pdf->Output($path, 'F');

        return $path;
    }

    /** Cadastra um certificado real (PFX válido) para o usuário via controller. */
    private function createRealCertificate(User $user): Certificate
    {
        $this->actingAs($user)->post('/certificates', [
            'description' => 'Certificado de teste',
            'pfx' => new UploadedFile($this->generatePfx('secret'), 'cert.pfx', 'application/octet-stream', null, true),
            'password' => 'secret',
        ]);

        return Certificate::latest('id')->first();
    }

    public function test_page_renders_with_and_without_certificates(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)->get('/sign-document')
            ->assertOk()
            ->assertSee('Nenhum certificado cadastrado');

        Certificate::factory()->for($client)->create(['description' => 'Cert Visível']);

        $this->actingAs($client)->get('/sign-document')
            ->assertOk()
            ->assertSee('Cert Visível');
    }

    public function test_cannot_sign_with_another_users_certificate(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $certificate = Certificate::factory()->for($owner)->create();

        $this->actingAs($other)->post('/sign-document/sign', [
            'certificate_id' => $certificate->id,
            'pdf' => new UploadedFile($this->makeSourcePdf(), 'doc.pdf', 'application/pdf', null, true),
        ])->assertForbidden();
    }

    public function test_signs_uploaded_pdf_end_to_end(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);
        $certificate = $this->createRealCertificate($client);

        $response = $this->actingAs($client)->post('/sign-document/sign', [
            'certificate_id' => $certificate->id,
            'pdf' => new UploadedFile($this->makeSourcePdf(), 'doc.pdf', 'application/pdf', null, true),
            'sign_x' => 150,
            'sign_y' => 240,
            'sign_w' => 150,
            'sign_h' => 60,
            'sign_page' => 1,
        ]);

        $response->assertRedirect(route('sign-document.index'))
            ->assertSessionHas('signed_file')
            ->assertSessionMissing('error');

        $filename = session('signed_file');
        $relative = 'signed/'.$client->id.'/'.$filename;
        Storage::disk('local')->assertExists($relative);

        $content = Storage::disk('local')->get($relative);
        $this->assertStringContainsString('/ByteRange', $content, 'PDF de saída deve conter assinatura digital');

        $this->assertDatabaseHas('access_logs', ['event' => 'document_signed']);
    }

    public function test_generates_document_from_template_and_signs(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);
        $certificate = $this->createRealCertificate($client);

        $response = $this->actingAs($client)->post('/sign-document/generate', [
            'certificate_id' => $certificate->id,
        ]);

        $response->assertRedirect(route('sign-document.index'))
            ->assertSessionHas('signed_file')
            ->assertSessionMissing('error');

        $relative = 'signed/'.$client->id.'/'.session('signed_file');
        $this->assertStringContainsString('/ByteRange', Storage::disk('local')->get($relative));
    }

    public function test_signs_with_drawn_signature(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);
        $certificate = $this->createRealCertificate($client);

        // PNG 1x1 válido — o pad envia data-URL PNG do canvas
        $drawn = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        $response = $this->actingAs($client)->post('/sign-document/sign', [
            'certificate_id' => $certificate->id,
            'pdf' => new UploadedFile($this->makeSourcePdf(), 'doc.pdf', 'application/pdf', null, true),
            'signature_mode' => 'draw',
            'drawn_signature' => $drawn,
        ]);

        $response->assertRedirect(route('sign-document.index'))
            ->assertSessionHas('signed_file')
            ->assertSessionMissing('error')
            ->assertSessionMissing('warning'); // assinatura desenhada conta como imagem visual

        $relative = 'signed/'.$client->id.'/'.session('signed_file');
        $this->assertStringContainsString('/ByteRange', Storage::disk('local')->get($relative));
    }

    public function test_invalid_drawn_signature_is_rejected(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);
        $certificate = $this->createRealCertificate($client);

        $this->actingAs($client)->post('/sign-document/sign', [
            'certificate_id' => $certificate->id,
            'pdf' => new UploadedFile($this->makeSourcePdf(), 'doc.pdf', 'application/pdf', null, true),
            'signature_mode' => 'draw',
            'drawn_signature' => 'data:image/png;base64,not-a-png',
        ])->assertRedirect(route('sign-document.index'))
            ->assertSessionHas('error');
    }

    public function test_signing_with_expired_certificate_fails_clearly(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);
        $certificate = $this->createRealCertificate($client);
        $certificate->update(['expires_at' => now()->subDay()]);

        $this->actingAs($client)->post('/sign-document/sign', [
            'certificate_id' => $certificate->id,
            'pdf' => new UploadedFile($this->makeSourcePdf(), 'doc.pdf', 'application/pdf', null, true),
        ])->assertRedirect(route('sign-document.index'))
            ->assertSessionHas('error');
    }

    public function test_download_rejects_foreign_and_malformed_filenames(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);

        Storage::disk('local')->put('signed/'.$other->id.'/doc_abc123.pdf', '%PDF-fake');

        // Arquivo de outro usuário não é alcançável (escopo pela pasta do auth user)
        $this->actingAs($client)
            ->get(route('sign-document.download', 'doc_abc123.pdf'))
            ->assertNotFound();

        // Nome fora do padrão doc_<hex>.pdf
        $this->actingAs($client)
            ->get('/sign-document/download/..%2F..%2F.env')
            ->assertNotFound();

        // Dono baixa normalmente
        $this->actingAs($other)
            ->get(route('sign-document.download', 'doc_abc123.pdf'))
            ->assertOk();
    }
}
