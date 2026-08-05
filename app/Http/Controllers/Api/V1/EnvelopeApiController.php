<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Services\Envelope\EnvelopeService;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Tcpdf\Fpdi;

class EnvelopeApiController extends Controller
{
    private const STATUS_MAP = [
        'draft' => 'draft',
        'sent' => 'pending',
        'completed' => 'signed',
        'declined' => 'declined',
        'cancelled' => 'cancelled',
        'expired' => 'expired',
    ];

    public function __construct(
        private EnvelopeService $envelopes,
        private UsageLimitService $usageLimit,
    ) {}

    public function store(Request $request)
    {
        $user = $request->user();

        $usage = $this->usageLimit->canCreateEnvelope($user);
        if (! $usage['allowed']) {
            return $this->unprocessable($usage['reason']);
        }

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'signer_name' => ['required', 'string', 'max:255'],
            'signer_email' => ['required', 'email'],
            'signer_whatsapp' => ['nullable', 'string', 'max:20'],
            'send_signed_copy' => ['nullable', 'boolean'],
            'pdf_base64' => ['required', 'string'],
            'field' => ['nullable', 'array'],
            'field.page' => ['nullable', 'integer', 'min:1'],
            'field.x' => ['nullable', 'numeric', 'min:0'],
            'field.y' => ['nullable', 'numeric', 'min:0'],
            'field.w' => ['nullable', 'numeric', 'min:1'],
            'field.h' => ['nullable', 'numeric', 'min:1'],
        ]);

        $pdfPath = $this->decodeBase64Pdf($request->input('pdf_base64'));

        try {
            $pageCount = (new Fpdi)->setSourceFile($pdfPath);

            $pdf = new UploadedFile($pdfPath, 'documento.pdf', 'application/pdf', null, true);

            $envelope = $this->envelopes->create($user, $pdf, [
                'title' => $request->input('title'),
                'message' => $request->input('message'),
                'signing_order' => 'parallel',
                'signers' => [
                    [
                        'name' => $request->input('signer_name'),
                        'email' => $request->input('signer_email'),
                        'whatsapp' => $request->input('signer_whatsapp'),
                        'auth_method' => 'link',
                        'send_signed_copy' => $request->boolean('send_signed_copy', true),
                        'fields' => [
                            $this->resolvePosition($request->input('field', []), $pageCount),
                        ],
                    ],
                ],
            ]);

            $this->envelopes->send($envelope);
        } catch (\RuntimeException $e) {
            return $this->unprocessable($e->getMessage());
        } finally {
            @unlink($pdfPath);
        }

        $signer = $envelope->signers->first();

        return response()->json([
            'id' => $envelope->id,
            'status' => self::STATUS_MAP[$envelope->status] ?? $envelope->status,
            'sign_url' => route('public.sign.show', $signer->token),
        ], 201);
    }

    public function show(Request $request, Envelope $envelope)
    {
        abort_unless($envelope->user_id === $request->user()->id, 404);

        return response()->json([
            'id' => $envelope->id,
            'status' => self::STATUS_MAP[$envelope->status] ?? $envelope->status,
            'created_at' => $envelope->created_at->toIso8601String(),
            'signed_at' => $envelope->completed_at?->toIso8601String(),
            'download_url' => $this->downloadUrl($envelope),
        ]);
    }

    private function downloadUrl(Envelope $envelope): ?string
    {
        if ($envelope->status !== 'completed' || ! $envelope->final_pdf_path) {
            return null;
        }

        $disk = Storage::disk('documents');
        if (! $disk->exists($envelope->final_pdf_path)) {
            return null;
        }

        return $disk->temporaryUrl($envelope->final_pdf_path, now()->addMinutes(5), [
            'ResponseContentDisposition' => 'attachment; filename="'.$envelope->title.' (assinado).pdf"',
        ]);
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
