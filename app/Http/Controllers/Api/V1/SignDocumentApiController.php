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
            $verificationCode = (string) \Illuminate\Support\Str::uuid();

            $signer = PdfSignerService::fromCertificate($certificate);
            $signer->setVerificationCode($verificationCode);
            $relative = $signer->signExisting($pdfPath, initialAllPages: false, position: $position, useTsa: false);

            $targetPath = "users/{$user->id}/signed/".basename($relative);
            $signer->moveToDisk($relative, 'documents', $targetPath);

            \App\Models\SignedDocument::create([
                'user_id' => $user->id,
                'certificate_id' => $certificate->id,
                'verification_code' => $verificationCode,
                'title' => 'Documento avulso',
                'sha256' => hash('sha256', Storage::disk('documents')->get($targetPath)),
                'signed_at' => now(),
            ]);

            $this->accessLog->log($user, 'document_signed', [
                'certificate_id' => $certificate->id,
                'certificate_description' => $certificate->description,
                'engine' => $signer->engine(),
                'file' => basename($targetPath),
                'original_name' => 'documento.pdf',
                'source' => 'api',
                'verification_code' => $verificationCode,
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
