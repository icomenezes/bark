<?php

namespace App\Http\Controllers;

use App\Models\Envelope;
use App\Models\SignedDocument;
use Illuminate\View\View;

class PublicVerificationController extends Controller
{
    public function show(string $code): View
    {
        $envelope = Envelope::where('verification_code', $code)
            ->with(['signers' => fn ($q) => $q->where('status', 'signed')])
            ->first();

        if ($envelope) {
            return view('public.verification.show', [
                'title' => $envelope->title,
                'sha256' => $envelope->sha256_final ?? $envelope->sha256_original,
                'status' => $envelope->status,
                'code' => $envelope->verification_code,
                'signers' => $envelope->signers->map(fn ($s) => [
                    'name' => $s->name,
                    'signed_at' => $s->signed_at,
                ]),
                'kind' => 'envelope',
            ]);
        }

        $signedDocument = SignedDocument::where('verification_code', $code)->first();

        if ($signedDocument) {
            return view('public.verification.show', [
                'title' => $signedDocument->title,
                'sha256' => $signedDocument->sha256,
                'status' => 'completed',
                'code' => $signedDocument->verification_code,
                'signers' => collect([[
                    'name' => $signedDocument->certificate?->description ?? 'Assinatura avulsa',
                    'signed_at' => $signedDocument->signed_at,
                ]]),
                'kind' => 'signed_document',
            ]);
        }

        abort(404);
    }
}
