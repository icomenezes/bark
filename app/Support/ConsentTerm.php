<?php

namespace App\Support;

use App\Models\EnvelopeSigner;

/**
 * Termo de aceite do meio eletrônico exibido na tela pública de assinatura.
 *
 * Só a VERSÃO é persistida no signatário: o texto é reproduzível a partir de
 * (versão + signatário), então não precisa duplicar o canal no banco.
 * Alterar o texto exige uma VERSION nova — aceites antigos precisam continuar
 * renderizáveis exatamente como foram aceitos.
 */
class ConsentTerm
{
    public const VERSION = 'v1';

    public static function text(EnvelopeSigner $signer): string
    {
        $channel = $signer->channel === 'whatsapp'
            ? 'o telefone '.$signer->whatsapp
            : 'o e-mail '.$signer->email;

        return "Declaro que {$channel} é meu e de meu uso pessoal, que li o documento acima "
            .'e que aceito assinar eletronicamente, nos termos do art. 10, § 2º da MP 2.200-2/2001.';
    }
}
