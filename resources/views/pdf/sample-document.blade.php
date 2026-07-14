{{-- Template genérico usado pelo botão "Gerar documento e assinar".
     Substitua pelo documento do seu nicho (laudo, contrato, recibo...).
     O HTML é renderizado pelo TCPDF (writeHTMLCell) — use marcação simples. --}}
<h1 style="text-align:center">Documento de Demonstração</h1>
<p style="text-align:center; color:#555">Gerado por {{ config('app.name') }} em {{ now()->format('d/m/Y H:i') }}</p>
<br><br>
<p>Este documento foi gerado automaticamente pelo sistema e assinado digitalmente
por <strong>{{ $user->name }}</strong> ({{ $user->email }}).</p>
<br>
<p>A assinatura digital aplicada a este arquivo garante a integridade do conteúdo:
qualquer alteração posterior invalida a assinatura. A posição do carimbo visual
foi definida na tela de assinatura.</p>
<br><br>
<p style="color:#888; font-size:10px">
Este é um template de demonstração do projeto base. Edite
<em>resources/views/pdf/sample-document.blade.php</em> para gerar o documento do seu nicho.
</p>
