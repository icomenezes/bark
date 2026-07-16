<?php

namespace Tests\Feature\Api;

use App\Models\Certificate;
use App\Models\Envelope;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnvelopeApiControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSourcePdfBase64(int $pages = 1): string
    {
        $pdf = new \TCPDF;
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Write(0, "Página {$i} da nota promissória");
        }
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

    private function configurePlatformCertificate(): void
    {
        $cert = Certificate::factory()->create(['expires_at' => now()->addYear()]);
        Setting::current()->update(['platform_certificate_id' => $cert->id]);
        Setting::clearCache();
    }

    private function validPayload(): array
    {
        return [
            'title' => 'Nota Promissória #1234',
            'message' => 'Assine para confirmar a compra a crediário',
            'signer_name' => 'João da Silva',
            'signer_email' => 'joao@example.com',
            'signer_whatsapp' => '11999998888',
            'pdf_base64' => $this->makeSourcePdfBase64(),
        ];
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/envelopes', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_creates_and_sends_envelope(): void
    {
        Storage::fake('documents');
        Mail::fake();
        $this->configurePlatformCertificate();
        $user = $this->userWithPlan();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/envelopes', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'status', 'sign_url']);
        $response->assertJson(['status' => 'pending']);

        $envelope = Envelope::first();
        $this->assertSame($user->id, $envelope->user_id);
        $this->assertSame('Nota Promissória #1234', $envelope->title);
        $this->assertSame('sent', $envelope->status);
        $this->assertCount(1, $envelope->signers);

        $signer = $envelope->signers->first();
        $this->assertSame('João da Silva', $signer->name);
        $this->assertSame('joao@example.com', $signer->email);
        $this->assertSame('11999998888', $signer->whatsapp);
        $this->assertSame('link', $signer->auth_method);
        $this->assertCount(1, $signer->fields);
    }

    public function test_validates_required_fields(): void
    {
        $user = $this->userWithPlan();
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/envelopes', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'signer_name', 'signer_email', 'pdf_base64']);
    }

    public function test_rejects_invalid_base64_pdf(): void
    {
        $user = $this->userWithPlan();
        $token = $user->createToken('api')->plainTextToken;

        $payload = array_merge($this->validPayload(), ['pdf_base64' => base64_encode('not a pdf')]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/envelopes', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pdf_base64']);
    }

    public function test_blocked_without_plan(): void
    {
        $user = User::factory()->create(['role' => 'client', 'plan_id' => null]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/envelopes', $this->validPayload());

        $response->assertUnprocessable();
        $this->assertStringContainsString('plano', $response->json('message'));
    }

    public function test_blocked_when_monthly_limit_reached(): void
    {
        $plan = Plan::factory()->create(['max_envelopes_per_month' => 0]);
        $user = User::factory()->create(['role' => 'client', 'plan_id' => $plan->id]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/envelopes', $this->validPayload());

        $response->assertUnprocessable();
        $this->assertStringContainsString('limite', $response->json('message'));
    }

    public function test_show_returns_status_for_owner(): void
    {
        $user = $this->userWithPlan();
        $token = $user->createToken('api')->plainTextToken;
        $envelope = Envelope::factory()->for($user)->create(['status' => 'sent']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/envelopes/{$envelope->id}");

        $response->assertOk();
        $response->assertJson([
            'id' => $envelope->id,
            'status' => 'pending',
        ]);
        $response->assertJsonStructure(['id', 'status', 'created_at', 'signed_at', 'download_url']);
    }

    public function test_show_maps_completed_status_to_signed_with_download_url(): void
    {
        $user = $this->userWithPlan();
        $token = $user->createToken('api')->plainTextToken;
        $envelope = Envelope::factory()->for($user)->create([
            'status' => 'completed',
            'completed_at' => now(),
            'final_pdf_path' => "users/{$user->id}/envelopes/1/final.pdf",
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/envelopes/{$envelope->id}");

        $response->assertOk();
        $response->assertJson(['status' => 'signed']);
        $this->assertNotNull($response->json('signed_at'));
        $this->assertNotNull($response->json('download_url'));
    }

    public function test_show_returns_404_for_other_users_envelope(): void
    {
        $owner = $this->userWithPlan();
        $other = $this->userWithPlan();
        $token = $other->createToken('api')->plainTextToken;
        $envelope = Envelope::factory()->for($owner)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/envelopes/{$envelope->id}")
            ->assertNotFound();
    }
}
