<?php

namespace App\Jobs;

use App\Models\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SealEnvelopeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Envelope $envelope) {}

    public function handle(): void
    {
        // Task 9
    }
}
