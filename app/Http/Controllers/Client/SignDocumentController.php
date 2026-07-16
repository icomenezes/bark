<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Certificate;
use App\Services\AccessLogService;
use App\Services\Pdf\PdfSignerService;
use App\Services\Pdf\SignPdfService;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignDocumentController extends Controller
{
    public function __construct(private AccessLogService $accessLog, private UsageLimitService $usageLimit) {}

    public function index()
    {
        $certificates = auth()->user()->certificates()->orderBy('description')->get();

        $signedDocuments = AccessLog::where('user_id', auth()->id())
            ->where('event', 'document_signed')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->filter(fn (AccessLog $log) => ! empty($log->meta['file'])
                && Storage::disk('documents')->exists('users/'.auth()->id().'/signed/'.$log->meta['file']));

        return view('client.sign-document.index', compact('certificates', 'signedDocuments'));
    }

    /** Assina um PDF enviado pelo usuário. */
    public function sign(Request $request)
    {
        $request->validate([
            'certificate_id' => ['required', 'integer'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
            ...$this->positionRules(),
        ]);

        return $this->handleSigning($request, function (PdfSignerService $signer, array $position) use ($request) {
            $uploaded = $request->file('pdf')->getRealPath();

            return $signer->signExisting(
                $uploaded,
                $request->boolean('initial_all_pages'),
                $position,
                $request->boolean('use_tsa')
            );
        }, $request->file('pdf')->getClientOriginalName());
    }

    /** Gera um documento a partir do texto redigido pelo usuário (ou do template padrão) e assina. */
    public function generate(Request $request)
    {
        $request->validate([
            'certificate_id' => ['required', 'integer'],
            'document_text' => ['nullable', 'string', 'max:20000'],
            ...$this->positionRules(),
        ]);

        return $this->handleSigning($request, function (PdfSignerService $signer, array $position) use ($request) {
            $html = $this->documentHtml($request->input('document_text', ''));

            return $signer->createAndSign(
                $this->documentHeader(),
                $html,
                [],
                $request->boolean('initial_all_pages'),
                $position,
                $request->boolean('use_tsa')
            );
        }, 'Documento redigido');
    }

    /** Gera uma prévia (sem assinar, sem salvar) do documento redigido, para posicionar a assinatura. */
    public function previewText(Request $request)
    {
        $request->validate([
            'document_text' => ['required', 'string', 'max:20000'],
        ]);

        $svc = new SignPdfService;
        $svc->createPdf($this->documentHeader(), $this->documentHtml($request->input('document_text')));

        return response($svc->outputString(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="previa.pdf"',
        ]);
    }

    /** Download da saída assinada — somente arquivos do próprio usuário. */
    public function download(string $filename)
    {
        abort_unless(preg_match('/^doc_[a-f0-9]+\.pdf(\.tsr)?$/', $filename), 404);

        $disk = Storage::disk('documents');
        $path = 'users/'.auth()->id().'/signed/'.$filename;
        abort_unless($disk->exists($path), 404);

        $url = $disk->temporaryUrl($path, now()->addMinutes(5), [
            'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"',
        ]);

        return redirect($url);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    private function handleSigning(Request $request, \Closure $operation, string $originalName = '')
    {
        $certificate = Certificate::find($request->certificate_id);
        abort_if($certificate === null || $certificate->user_id !== auth()->id(), 403);

        $usage = $this->usageLimit->canSignPdf(auth()->user());
        if (! $usage['allowed']) {
            return redirect()->route('sign-document.index')->with('error', $usage['reason']);
        }

        $drawnTemp = null;

        try {
            $signer = PdfSignerService::fromCertificate($certificate);
            $position = $this->extractPosition($request);

            // Assinatura desenhada na tela substitui a imagem cadastrada nesta operação
            if ($request->input('signature_mode') === 'draw') {
                $drawnTemp = $this->storeDrawnSignature((string) $request->input('drawn_signature'));
                $signer->overrideSignImage($drawnTemp);
            }

            // Selo de autenticação opcional: carimbo acima/à direita da assinatura
            if ($request->boolean('use_seal')) {
                $signer->applySeal();
            }

            $relative = $operation($signer, $position);

            $targetPath = 'users/'.auth()->id().'/signed/'.basename($relative);
            $signer->moveToDisk($relative, 'documents', $targetPath);

            $this->accessLog->log(auth()->user(), 'document_signed', [
                'certificate_id' => $certificate->id,
                'certificate_description' => $certificate->description,
                'engine' => $signer->engine(),
                'tsa' => $request->boolean('use_tsa'),
                'use_seal' => $request->boolean('use_seal'),
                'file' => basename($targetPath),
                'original_name' => $originalName,
            ]);

            $response = redirect()->route('sign-document.index')
                ->with('success', 'PDF assinado com sucesso!')
                ->with('signed_file', basename($targetPath));

            if (! $signer->hasSignatureImage()) {
                $response->with('warning',
                    'O PDF foi assinado, mas sem imagem de assinatura visível — '.
                    'o certificado selecionado não tem imagem cadastrada.');
            }

            return $response;
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('sign-document.index')
                ->with('error', 'Falha ao assinar: '.$e->getMessage())
                ->withInput();
        } finally {
            if ($drawnTemp) {
                @unlink($drawnTemp);
            }
        }
    }

    /** Decodifica o data-URL PNG do pad de assinatura e grava em arquivo temporário. */
    private function storeDrawnSignature(string $dataUrl): string
    {
        return \App\Support\SignatureImage::storeDataUrl($dataUrl);
    }

    private function positionRules(): array
    {
        return [
            'sign_x' => ['nullable', 'numeric', 'min:0'],
            'sign_y' => ['nullable', 'numeric', 'min:0'],
            'sign_w' => ['nullable', 'numeric'],
            'sign_h' => ['nullable', 'numeric'],
            'sign_page' => ['nullable', 'integer', 'min:1'],
            'signature_mode' => ['nullable', 'in:registered,draw'],
            'drawn_signature' => ['nullable', 'string', 'required_if:signature_mode,draw'],
            'use_seal' => ['nullable', 'boolean'],
        ];
    }

    private function documentHeader(): array
    {
        return [];
    }

    /** Texto digitado pelo usuário → HTML seguro para o TCPDF (writeHTMLCell); vazio cai no template de demonstração. */
    private function documentHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return view('pdf.sample-document', ['user' => auth()->user()])->render();
        }

        return '<p>'.nl2br(e($text)).'</p>';
    }

    /** Mesmos clamps do ERP: posição em pontos PDF, origem topo-esquerdo. */
    private function extractPosition(Request $request): array
    {
        return [
            'x' => max(0.0, (float) $request->input('sign_x', 150)),
            'y' => max(0.0, (float) $request->input('sign_y', 240)),
            'page' => max(1, (int) $request->input('sign_page', 1)),
            'w' => min(500.0, max(5.0, (float) $request->input('sign_w', 50))),
            'h' => min(500.0, max(5.0, (float) $request->input('sign_h', 25))),
        ];
    }
}
