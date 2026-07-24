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

    public function test_rejects_expired_certificate(): void
    {
        $user = $this->userWithPlan();
        $certificate = $this->attachRealCertificate($user);
        $certificate->update(['expires_at' => now()->subDay()]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', ['pdf_base64' => $this->makeSourcePdfBase64()]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('expirado', $response->json('message'));
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

    public function test_creates_signed_document_record_with_verification_code(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $user = $this->userWithPlan();
        $this->attachRealCertificate($user);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sign-document', ['pdf_base64' => $this->makeSourcePdfBase64()]);

        $response->assertOk();

        $this->assertDatabaseCount('signed_documents', 1);
        $record = \App\Models\SignedDocument::first();
        $this->assertSame($user->id, $record->user_id);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($record->verification_code));
        $this->assertNotEmpty($record->sha256);
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
