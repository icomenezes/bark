<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\Envelope;
use App\Models\EnvelopeEvent;
use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Agregações das dashboards — total geral com recorte do mês corrente. */
class DashboardStatsService
{
    /**
     * @return array{envelopes_sent:int, envelopes_sent_month:int,
     *               envelopes_completed:int, envelopes_completed_month:int,
     *               signatures:int, signatures_month:int}
     */
    public function admin(): array
    {
        $month = $this->currentMonth();

        return [
            'envelopes_sent' => $this->sends()->distinct()->count('envelope_id'),
            'envelopes_sent_month' => $this->sends()->whereBetween('created_at', $month)->distinct()->count('envelope_id'),
            'envelopes_completed' => $this->completedEnvelopes()->count(),
            'envelopes_completed_month' => $this->completedEnvelopes()->whereBetween('completed_at', $month)->count(),
            'signatures' => $this->signatures()->count(),
            'signatures_month' => $this->signatures()->whereBetween('created_at', $month)->count(),
        ];
    }

    /**
     * @return array{envelopes:int, envelopes_month:int, signatures:int, signatures_month:int,
     *               signers:int, groups:int, max_envelopes_month:?int, max_signatures_month:?int}
     */
    public function client(User $user): array
    {
        $month = $this->currentMonth();

        return [
            // Conta rascunhos: é o mesmo critério que o UsageLimitService usa para o limite do plano.
            'envelopes' => Envelope::where('user_id', $user->id)->count(),
            'envelopes_month' => Envelope::where('user_id', $user->id)->whereBetween('created_at', $month)->count(),
            'signatures' => $this->signatures()->where('user_id', $user->id)->count(),
            'signatures_month' => $this->signatures()->where('user_id', $user->id)->whereBetween('created_at', $month)->count(),
            'signers' => SavedSigner::where('user_id', $user->id)->count(),
            'groups' => SignerGroup::where('user_id', $user->id)->count(),
            'max_envelopes_month' => $user->plan?->max_envelopes_per_month,
            'max_signatures_month' => $user->plan?->max_pdfs_per_month,
        ];
    }

    /**
     * Envios de nível de envelope. Não existe coluna `sent_at`, e contar por
     * `envelopes.created_at` atribuiria ao mês errado um envelope criado em rascunho
     * num mês e enviado no seguinte. O filtro por `envelope_signer_id` nulo é
     * obrigatório: notifySigner() grava um evento `sent` por signatário.
     */
    private function sends(): Builder
    {
        return EnvelopeEvent::where('event', 'sent')->whereNull('envelope_signer_id');
    }

    private function completedEnvelopes(): Builder
    {
        return Envelope::where('status', 'completed');
    }

    /**
     * Assinaturas avulsas. A tabela `signed_documents` só recebe registro no fluxo de
     * API; o fluxo web grava apenas o log — `access_logs` cobre os dois, e é a mesma
     * fonte que o UsageLimitService usa para aplicar o limite do plano.
     */
    private function signatures(): Builder
    {
        return AccessLog::where('event', 'document_signed');
    }

    /** @return array{0:\Illuminate\Support\Carbon,1:\Illuminate\Support\Carbon} */
    private function currentMonth(): array
    {
        return [now()->startOfMonth(), now()->endOfMonth()];
    }
}
