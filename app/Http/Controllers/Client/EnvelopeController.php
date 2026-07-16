<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\SealEnvelopeJob;
use App\Models\Envelope;
use App\Services\AccessLogService;
use App\Services\Envelope\EnvelopeService;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EnvelopeController extends Controller
{
    public function __construct(
        private EnvelopeService $envelopes,
        private AccessLogService $accessLog,
        private UsageLimitService $usageLimit,
    ) {}

    public function index()
    {
        $envelopes = Envelope::where('user_id', auth()->id())
            ->withCount(['signers', 'signers as signed_count' => fn ($q) => $q->where('status', 'signed')])
            ->latest()
            ->paginate(20);

        return view('client.envelopes.index', compact('envelopes'));
    }

    public function create()
    {
        return view('client.envelopes.create');
    }

    public function store(Request $request)
    {
        $usage = $this->usageLimit->canCreateEnvelope(auth()->user());
        if (! $usage['allowed']) {
            return back()->with('error', $usage['reason'])->withInput();
        }

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'signing_order' => ['required', 'in:parallel,sequential'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
            'signers_json' => ['required', 'string'],
        ]);

        $signers = $this->validateSigners($request->input('signers_json'));

        try {
            $envelope = $this->envelopes->create(auth()->user(), $request->file('pdf'), [
                'title' => $request->input('title'),
                'message' => $request->input('message'),
                'signing_order' => $request->input('signing_order'),
                'expires_at' => $request->input('expires_at'),
                'signers' => $signers,
            ]);

            $this->envelopes->send($envelope);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $this->accessLog->log(auth()->user(), 'envelope_created', [
            'envelope_id' => $envelope->id, 'title' => $envelope->title,
            'signers' => count($signers),
        ]);

        return redirect()->route('envelopes.show', $envelope)
            ->with('success', 'Envelope enviado! Os signatários receberão o convite por e-mail.');
    }

    public function show(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        $envelope->load(['signers.fields', 'events.signer']);

        $canReseal = $envelope->status === 'sent' && $envelope->allSigned();

        return view('client.envelopes.show', compact('envelope', 'canReseal'));
    }

    /** Reenvia o convite a todos os pendentes (sequencial: só o da vez). */
    public function remind(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        abort_unless($envelope->status === 'sent', 400);

        $targets = $envelope->isSequential()
            ? collect([$envelope->nextPendingSigner()])->filter()
            : $envelope->signers()->whereNotIn('status', ['signed', 'declined'])->get();

        foreach ($targets as $signer) {
            $this->envelopes->notifySigner($signer, reminder: true);
        }

        return back()->with('success', 'Lembrete reenviado.');
    }

    public function cancel(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);

        if (! in_array($envelope->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => 'Este envelope não pode mais ser cancelado.']);
        }

        $this->envelopes->cancel($envelope);

        return back()->with('success', 'Envelope cancelado.');
    }

    /** Reprocessa o lacre após uma falha (seal_failed). */
    public function reseal(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        abort_unless($envelope->status === 'sent' && $envelope->allSigned(), 400);

        SealEnvelopeJob::dispatch($envelope);

        return back()->with('success', 'Reprocessamento do lacre iniciado.');
    }

    public function download(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        abort_unless($envelope->status === 'completed' && $envelope->final_pdf_path, 404);

        $disk = Storage::disk('documents');
        abort_unless($disk->exists($envelope->final_pdf_path), 404);

        $url = $disk->temporaryUrl($envelope->final_pdf_path, now()->addMinutes(5), [
            'ResponseContentDisposition' => 'attachment; filename="'.$envelope->title.' (assinado).pdf"',
        ]);

        return redirect($url);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    private function authorizeOwner(Envelope $envelope): void
    {
        abort_unless($envelope->user_id === auth()->id(), 403);
    }

    /** Valida o payload de signatários montado pelo wizard. */
    private function validateSigners(string $json): array
    {
        $signers = json_decode($json, true);

        $validator = Validator::make(['signers' => $signers], [
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:255'],
            'signers.*.email' => ['required', 'email', 'max:255'],
            'signers.*.whatsapp' => ['nullable', 'string', 'max:20', 'required_if:signers.*.auth_method,whatsapp_otp'],
            'signers.*.auth_method' => ['required', 'in:link,email_otp,whatsapp_otp'],
            'signers.*.fields' => ['required', 'array', 'min:1'],
            'signers.*.fields.*.page' => ['required', 'integer', 'min:1'],
            'signers.*.fields.*.x' => ['required', 'numeric', 'min:0'],
            'signers.*.fields.*.y' => ['required', 'numeric', 'min:0'],
            'signers.*.fields.*.w' => ['required', 'numeric', 'min:5', 'max:500'],
            'signers.*.fields.*.h' => ['required', 'numeric', 'min:5', 'max:500'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'signers_json' => 'Signatários inválidos: '.$validator->errors()->first(),
            ]);
        }

        return $signers;
    }
}
