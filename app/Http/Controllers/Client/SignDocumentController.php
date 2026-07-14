<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\AccessLogService;
use App\Services\Pdf\PdfSignerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignDocumentController extends Controller
{
    public function __construct(private AccessLogService $accessLog) {}

    public function index()
    {
        $certificates = auth()->user()->certificates()->orderBy('description')->get();

        return view('client.sign-document.index', compact('certificates'));
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
        });
    }

    /** Gera um documento a partir do template genérico e assina. */
    public function generate(Request $request)
    {
        $request->validate([
            'certificate_id' => ['required', 'integer'],
            ...$this->positionRules(),
        ]);

        return $this->handleSigning($request, function (PdfSignerService $signer, array $position) use ($request) {
            $html = view('pdf.sample-document', ['user' => auth()->user()])->render();

            return $signer->createAndSign(
                ['title' => 'DOCUMENTO', 'subtitle' => 'Documento gerado pelo sistema'],
                $html,
                [],
                $request->boolean('initial_all_pages'),
                $position,
                $request->boolean('use_tsa')
            );
        });
    }

    /** Download da saída assinada — somente arquivos do próprio usuário. */
    public function download(string $filename)
    {
        abort_unless(preg_match('/^doc_[a-f0-9]+\.pdf(\.tsr)?$/', $filename), 404);

        $path = 'signed/'.auth()->id().'/'.$filename;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    private function handleSigning(Request $request, \Closure $operation)
    {
        $certificate = Certificate::find($request->certificate_id);
        abort_if($certificate === null || $certificate->user_id !== auth()->id(), 403);

        $drawnTemp = null;

        try {
            $signer = PdfSignerService::fromCertificate($certificate);
            $position = $this->extractPosition($request);

            // Assinatura desenhada na tela substitui a imagem cadastrada nesta operação
            if ($request->input('signature_mode') === 'draw') {
                $drawnTemp = $this->storeDrawnSignature((string) $request->input('drawn_signature'));
                $signer->overrideSignImage($drawnTemp);
            }

            $relative = $operation($signer, $position);

            $this->accessLog->log(auth()->user(), 'document_signed', [
                'certificate_id' => $certificate->id,
                'engine' => $signer->engine(),
                'tsa' => $request->boolean('use_tsa'),
            ]);

            $response = redirect()->route('sign-document.index')
                ->with('success', 'PDF assinado com sucesso!')
                ->with('signed_file', basename($relative));

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
        ];
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
