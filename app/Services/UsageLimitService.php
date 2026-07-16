<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\Envelope;
use App\Models\User;

class UsageLimitService
{
    /** @return array{allowed: bool, reason: ?string} */
    public function canSignPdf(User $user): array
    {
        if ($user->plan === null) {
            return ['allowed' => false, 'reason' => 'Nenhum plano atribuído — contate o administrador para liberar o uso.'];
        }

        $used = AccessLog::where('user_id', $user->id)
            ->where('event', 'document_signed')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        if ($used >= $user->plan->max_pdfs_per_month) {
            return ['allowed' => false, 'reason' => "Você atingiu o limite de {$user->plan->max_pdfs_per_month} PDFs assinados este mês."];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /** @return array{allowed: bool, reason: ?string} */
    public function canCreateEnvelope(User $user): array
    {
        if ($user->plan === null) {
            return ['allowed' => false, 'reason' => 'Nenhum plano atribuído — contate o administrador para liberar o uso.'];
        }

        $used = Envelope::where('user_id', $user->id)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        if ($used >= $user->plan->max_envelopes_per_month) {
            return ['allowed' => false, 'reason' => "Você atingiu o limite de {$user->plan->max_envelopes_per_month} envelopes este mês."];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
