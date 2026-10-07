<?php

namespace App\Support;

use App\Models\Envelope;
use Illuminate\Support\Facades\Storage;

/**
 * O envelope como os integradores o veem: corpo do GET /api/v1/envelopes/{id}
 * e objeto "envelope" do webhook. Um só lugar para os dois não divergirem.
 */
final class EnvelopeApiPayload
{
    /** Status interno → vocabulário da API. */
    private const STATUS_MAP = [
        'draft' => 'draft',
        'sent' => 'pending',
        'completed' => 'signed',
        'declined' => 'declined',
        'cancelled' => 'cancelled',
        'expired' => 'expired',
    ];

    public static function status(Envelope $envelope): string
    {
        return self::STATUS_MAP[$envelope->status] ?? $envelope->status;
    }

    /** @return array{id: int, status: string, created_at: string, signed_at: ?string, download_url: ?string} */
    public static function of(Envelope $envelope): array
    {
        return [
            'id' => $envelope->id,
            'status' => self::status($envelope),
            'created_at' => $envelope->created_at->toIso8601String(),
            'signed_at' => $envelope->completed_at?->toIso8601String(),
            'download_url' => self::downloadUrl($envelope),
        ];
    }

    private static function downloadUrl(Envelope $envelope): ?string
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
}
