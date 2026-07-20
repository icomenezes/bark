<?php

namespace App\Http\Controllers\PublicSign;

use App\Http\Controllers\Controller;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignEnvelopeController extends Controller
{
    public function __construct(private EnvelopeService $envelopes) {}

    public function show(Request $request, string $token)
    {
        $signer = $this->findSigner($token);

        if ($unavailable = $this->unavailableReason($signer)) {
            return view('public.sign.unavailable', ['signer' => $signer, 'reason' => $unavailable]);
        }

        $this->envelopes->markViewed($signer, $request->ip(), $request->userAgent());

        return view('public.sign.show', ['signer' => $signer->fresh(), 'envelope' => $signer->envelope]);
    }

    /** Serve o PDF ao signatário: original durante a coleta, final após concluído. */
    public function document(string $token)
    {
        $signer = $this->findSigner($token);
        $envelope = $signer->envelope;

        $path = $envelope->status === 'completed' && $envelope->final_pdf_path
            ? $envelope->final_pdf_path
            : $envelope->original_pdf_path;

        $disk = Storage::disk('documents');
        abort_unless($disk->exists($path), 404);

        $url = $disk->temporaryUrl($path, now()->addMinutes(5), [
            'ResponseContentType' => 'application/pdf',
            'ResponseContentDisposition' => 'inline; filename="documento.pdf"',
        ]);

        return redirect($url);
    }

    public function otp(string $token)
    {
        $signer = $this->findSigner($token);
        abort_unless($signer->canSign() && $signer->requiresOtp(), 400);

        $this->envelopes->issueOtp($signer);

        $channel = $signer->auth_method === 'whatsapp_otp' ? 'WhatsApp' : 'e-mail';

        return back()->with('success', "Código enviado por {$channel}.");
    }

    public function store(Request $request, string $token)
    {
        $signer = $this->findSigner($token);

        if ($this->unavailableReason($signer)) {
            return view('public.sign.unavailable', ['signer' => $signer, 'reason' => $this->unavailableReason($signer)]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cpf' => ['required', 'string', 'regex:/^\d{3}\.\d{3}\.\d{3}-\d{2}$/'],
            'signature_type' => ['required', 'in:drawn,typed'],
            'signature' => ['required', 'string', 'max:3000000'],
            'otp_code' => [$signer->requiresOtp() ? 'required' : 'nullable', 'digits:6'],
        ], [
            'cpf.regex' => 'Informe o CPF no formato 000.000.000-00.',
        ]);

        if ($signer->requiresOtp() && ! $this->envelopes->verifyOtp($signer, $data['otp_code'])) {
            return back()->withErrors(['otp_code' => 'Código inválido ou expirado. Solicite um novo.'])->withInput();
        }

        try {
            $this->envelopes->sign($signer->fresh(), $data, $request->ip(), $request->userAgent());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['signature' => $e->getMessage()])->withInput();
        }

        return view('public.sign.done', array_merge(
            ['signer' => $signer->fresh()],
            $this->completionMessage($signer->fresh())
        ));
    }

    public function decline(Request $request, string $token)
    {
        $signer = $this->findSigner($token);
        abort_unless($signer->canSign(), 400);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->envelopes->decline($signer, $data['reason'], $request->ip(), $request->userAgent());

        return view('public.sign.done', [
            'signer' => $signer->fresh(),
            'title' => 'Sua recusa foi registrada.',
            'message' => 'O remetente foi notificado.',
        ]);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    /** @return array{title: string, message: string} */
    private function completionMessage(EnvelopeSigner $signer): array
    {
        $envelope = $signer->envelope->fresh();

        if ($envelope->allSigned()) {
            return [
                'title' => 'Documento assinado com sucesso!',
                'message' => 'Obrigado por assinar. O documento está concluído.',
            ];
        }

        $channel = $signer->channel === 'whatsapp' ? 'WhatsApp' : 'e-mail';

        return [
            'title' => 'Assinatura registrada!',
            'message' => "Quando todos assinarem, você receberá o documento final por {$channel}.",
        ];
    }

    private function findSigner(string $token): EnvelopeSigner
    {
        abort_unless(strlen($token) === 64, 404);

        return EnvelopeSigner::where('token', $token)->with('envelope.user')->firstOrFail();
    }

    /** null = pode assinar; string = motivo exibido na tela informativa. */
    private function unavailableReason(EnvelopeSigner $signer): ?string
    {
        $envelope = $signer->envelope;

        return match (true) {
            $signer->status === 'signed' => 'Você já assinou este documento. Quando todos assinarem, receberá o PDF final por e-mail.',
            $envelope->status === 'completed' => 'Este documento já foi concluído. O PDF final foi enviado ao seu e-mail.',
            $envelope->status === 'declined' => 'Este documento foi encerrado após uma recusa e não está mais disponível.',
            $envelope->status === 'cancelled' => 'Este documento foi cancelado pelo remetente e não está mais disponível para assinatura.',
            $envelope->isExpired() || $envelope->status === 'expired' => 'O prazo para assinar este documento expirou.',
            $envelope->status !== 'sent' => 'Este documento não está mais disponível para assinatura.',
            default => null,
        };
    }
}
