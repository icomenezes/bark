<?php

namespace App\Console\Commands;

use App\Models\Envelope;
use App\Services\Envelope\EnvelopeService;
use Illuminate\Console\Command;

class ExpireEnvelopes extends Command
{
    protected $signature = 'envelopes:expire';

    protected $description = 'Marca como expirados os envelopes enviados cujo prazo venceu';

    public function handle(EnvelopeService $service): int
    {
        $expired = Envelope::where('status', 'sent')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $envelope) {
            $envelope->update(['status' => 'expired']);
            $service->recordEvent($envelope, null, 'expired');
        }

        $this->info("{$expired->count()} envelope(s) expirado(s).");

        return self::SUCCESS;
    }
}
