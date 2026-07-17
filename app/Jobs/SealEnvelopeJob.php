<?php

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
        $envelope = $this->envelope->fresh(['signers.fields', 'user.signingCertificate']);

        if ($envelope->status !== 'sent' || ! $envelope->allSigned()) {
            return; // já lacrado ou estado inválido — idempotente
        }

        $evidencePath = null;
        $composedPath = null;

        try {
            $certificate = $envelope->user->signingCertificate ?? Setting::current()->platformCertificate;
            if ($certificate === null) {
                throw new \RuntimeException('Nenhum certificado válido configurado para lacrar este envelope.');
            }

            $evidencePath = $evidence->generate($envelope);
            $composed = $composer->compose($envelope, $evidencePath);
            $composedPath = $composed['path'];

            // Selo visível da plataforma na última página (evidências), canto inferior direito
            // PdfSignerService grava o resultado no disk local (scratch) — usado só como
            // arquivo de trabalho, o definitivo vai para o disk documents (S3) logo abaixo.
            $relative = PdfSignerService::fromCertificate($certificate)->signExisting(
                $composedPath,
                initialAllPages: false,
                position: ['page' => $composed['pages'], 'x' => 400, 'y' => 780, 'w' => 150, 'h' => 40],
                useTsa: false,
            );

            $localDisk = Storage::disk('local');
            $documentsDisk = Storage::disk('documents');
            $final = "users/{$envelope->user_id}/envelopes/{$envelope->id}/final.pdf";

            $finalContent = $localDisk->get($relative);
            $documentsDisk->put($final, $finalContent);
            $localDisk->delete($relative);

            $envelope->update([
                'final_pdf_path' => $final,
                'sha256_final' => hash('sha256', $finalContent),
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
