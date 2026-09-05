<?php

namespace App\Services\Envelope;

use App\Models\EnvelopeSigner;

/**
 * Linhas descritivas de um signatário no certificado de evidências.
 *
 * Separado do EvidenceReportGenerator para que o texto seja testável sem
 * depender do desenho no PDF — o gerador só imprime o que vier daqui.
 */
class SignerSummary
{
    /** @return list<string> */
    public static function lines(EnvelopeSigner $signer): array
    {
        $lines = [(string) ($signer->email ?: $signer->whatsapp)];

        if ($signer->cpf) {
            // A conferência contra o cadastro do remetente é o que sustenta a
            // autoria numa contestação — por isso sai explícita no certificado.
            $lines[] = $signer->expected_cpf
                ? 'CPF '.$signer->cpf.' - conferido com o cadastro do remetente'
                : 'CPF '.$signer->cpf;
        }

        $lines[] = 'Assinou em '.($signer->signed_at?->format('d/m/Y H:i:s') ?? '—');

        return $lines;
    }
}
