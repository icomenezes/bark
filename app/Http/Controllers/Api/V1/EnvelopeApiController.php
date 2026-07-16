<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Services\Envelope\EnvelopeService;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            'pdf_base64' => ['required', 'string'],
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
                        'fields' => [
                            ['page' => $pageCount, 'x' => 350, 'y' => 750, 'w' => 150, 'h' => 50],
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
            'download_url' => $envelope->status === 'completed'
                ? route('envelopes.download', $envelope)
                : null,
        ]);
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
