<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Rules\Cpf as CpfRule;
use App\Services\Envelope\EnvelopeService;
use App\Services\UsageLimitService;
use App\Support\EnvelopeApiPayload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Tcpdf\Fpdi;

class EnvelopeApiController extends Controller
{
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
            'signer_email' => ['nullable', 'email', 'required_unless:channel,whatsapp'],
            'signer_whatsapp' => ['nullable', 'string', 'max:20', 'required_if:channel,whatsapp'],
            'signer_cpf' => ['nullable', 'string', new CpfRule],
            'channel' => ['nullable', 'in:email,whatsapp'],
            'auth_method' => ['nullable', 'in:link,email_otp,whatsapp_otp'],
            'send_signed_copy' => ['nullable', 'boolean'],
            'pdf_base64' => ['required', 'string'],
            'field' => ['nullable', 'array'],
            'field.page' => ['nullable', 'integer', 'min:1'],
            'field.x' => ['nullable', 'numeric', 'min:0'],
            'field.y' => ['nullable', 'numeric', 'min:0'],
            'field.w' => ['nullable', 'numeric', 'min:1'],
            'field.h' => ['nullable', 'numeric', 'min:1'],
        ]);

        $channel = $request->input('channel', 'email');
        $authMethod = $request->input('auth_method', 'link');

        // Coerência antes de decodificar o PDF — não faz sentido gravar arquivo para depois recusar.
        if ($channel === 'whatsapp' && ! $user->whatsapp_envelope_enabled) {
            return $this->unprocessable('Canal WhatsApp não habilitado para esta conta.');
        }

        $allowed = $channel === 'whatsapp' ? ['link', 'whatsapp_otp'] : ['link', 'email_otp'];
        if (! in_array($authMethod, $allowed, true)) {
            return $this->unprocessable('Método de verificação incompatível com o canal escolhido.');
        }

        $pdfPath = $this->decodeBase64Pdf($request->input('pdf_base64'));

        try {
            $pageCount = (new Fpdi)->setSourceFile($pdfPath);

            $pdf = new UploadedFile($pdfPath, 'documento.pdf', 'application/pdf', null, true);

            $envelope = $this->envelopes->create($user, $pdf, [
                'title' => $request->input('title'),
                'message' => $request->input('message'),
                'signing_order' => 'parallel',
                'source' => 'api',
                'signers' => [
                    [
                        'name' => $request->input('signer_name'),
                        'email' => $request->input('signer_email'),
                        'whatsapp' => $request->input('signer_whatsapp'),
                        'expected_cpf' => $request->input('signer_cpf'),
                        'channel' => $channel,
                        'auth_method' => $authMethod,
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
            'status' => EnvelopeApiPayload::status($envelope),
            'sign_url' => route('public.sign.show', $signer->token),
        ], 201);
    }

    public function show(Request $request, Envelope $envelope)
    {
        abort_unless($envelope->user_id === $request->user()->id, 404);

        return response()->json(EnvelopeApiPayload::of($envelope));
    }

    public function cancel(Request $request, Envelope $envelope)
    {
        abort_unless($envelope->user_id === $request->user()->id, 404);

        // Idempotente: o integrador pode repetir a chamada após um timeout — sem evento nem e-mail novos.
        if ($envelope->status === 'cancelled') {
            return response()->json(['id' => $envelope->id, 'status' => EnvelopeApiPayload::status($envelope)]);
        }

        // Todos assinaram e o lacre ainda não concluiu (na fila ou seal_failed): cancelar agora descartaria assinaturas válidas.
        if ($envelope->status === 'sent' && $envelope->allSigned()) {
            return $this->unprocessable('Assinatura já concluída, documento em processamento.', $envelope);
        }

        if (! in_array($envelope->status, ['draft', 'sent'], true)) {
            return $this->unprocessable('Este envelope não pode mais ser cancelado.', $envelope);
        }

        $this->envelopes->cancel($envelope);

        return response()->json(['id' => $envelope->id, 'status' => EnvelopeApiPayload::status($envelope)]);
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

    /** Com $envelope, inclui o status mapeado — o integrador decide o próximo passo por ele. */
    private function unprocessable(string $message, ?Envelope $envelope = null)
    {
        $body = ['message' => $message];
        if ($envelope) {
            $body['status'] = EnvelopeApiPayload::status($envelope);
        }

        return response()->json($body, 422);
    }
}
