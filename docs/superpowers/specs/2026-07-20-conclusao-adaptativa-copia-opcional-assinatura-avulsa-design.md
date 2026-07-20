# Conclusão adaptativa, cópia assinada opcional e API de assinatura avulsa

Data: 2026-07-20

## Contexto

Três ajustes pedidos após uso real do fluxo de envelopes:

1. A tela de conclusão do link público (`/sign/{token}`) mostra sempre a mesma mensagem
   ("Assinatura registrada! Quando todos assinarem, você receberá o documento final por
   e-mail."), mesmo quando o envelope tem um único signatário — nesse caso o envelope já
   está completo naquele momento e a menção a "quando todos assinarem" não faz sentido.
2. Envelopes criados via API (`POST /api/v1/envelopes`) são usados para promissórias que
   clientes assinam eletronicamente. O remetente (dono da conta) não quer que esses clientes
   recebam a cópia final assinada por e-mail/WhatsApp — o documento deve ficar só em posse
   do remetente.
3. Não existe hoje uma API para assinar um PDF avulso (sem envelope, sem coleta de assinatura
   de terceiros) usando um certificado próprio do usuário — equivalente programático da tela
   `/sign-document`.

## 1. Mensagem de conclusão adaptativa

**Arquivo:** `app/Http/Controllers/PublicSign/SignEnvelopeController.php`, método `store()`.

A mensagem exibida em `public.sign.done` passa a variar conforme o número de signatários do
envelope e, quando ainda há pendências, o canal (`channel`) do signatário que acabou de
assinar:

- **Envelope com um único signatário** (portanto completo assim que ele assina): título
  "Documento assinado com sucesso!", mensagem confirmando a conclusão, sem menção a espera
  por outros signatários.
- **Envelope com múltiplos signatários e ainda há pendentes:** título "Assinatura registrada!",
  mensagem "Quando todos assinarem, você receberá o documento final por e-mail." ou
  "...por WhatsApp.", de acordo com `$signer->channel` (`email` ou `whatsapp`).
- **Envelope com múltiplos signatários e esse era o último** (`$envelope->fresh()->allSigned()`
  verdadeiro após `sign()`): mesmo tratamento do caso "conclusão", já que o `SealEnvelopeJob`
  foi despachado.

Implementado como método privado `completionMessage(EnvelopeSigner $signer): array` no
controller, retornando `['title' => ..., 'message' => ...]`. Não requer mudanças em
`EnvelopeService` — a lógica é puramente de apresentação.

## 2. Cópia assinada opcional (`send_signed_copy`)

**Migration nova:** adiciona `send_signed_copy` (boolean, default `true`) a
`envelope_signers`.

**Motivo de ficar no signatário, não no envelope:** a decisão de quem recebe a cópia final é
por-pessoa. Hoje a API só cria um signatário por envelope, então na prática o efeito é
"por envelope criado via API" — mas modelar no lugar certo evita retrabalho de migration
quando a API passar a aceitar múltiplos signatários.

**`EnvelopeApiController::store()`:** aceita `send_signed_copy` (boolean, opcional, default
`true`) no payload da requisição e propaga para o array do signer passado a
`EnvelopeService::create()`.

**`EnvelopeService::create()`:** grava `send_signed_copy` (default `true` se ausente) ao criar
cada `EnvelopeSigner`.

**Pontos de envio da notificação final** (ambos precisam checar o flag antes de notificar
cada signatário; o e-mail ao remetente/dono do envelope nunca é afetado):

- `App\Jobs\SealEnvelopeJob::handle()` — loop que envia `EnvelopeCompleted` (e-mail) ou
  WhatsApp de conclusão a cada signatário.
- `EnvelopeService::notifyCompletion()` — mesmo loop, usado para reenvio manual.

Em ambos: `if (! $signer->send_signed_copy) { continue; }` antes de notificar o signatário
(o convite inicial para assinar, em `notifySigner()`, não é afetado — só a notificação de
conclusão com o PDF final).

## 3. API de assinatura avulsa

**Rota nova:** `POST /api/v1/sign-document`, dentro do grupo existente
`Route::prefix('v1')->middleware('auth:sanctum')`.

**Controller novo:** `App\Http\Controllers\Api\V1\SignDocumentApiController`.

**Payload:**

```json
{
  "pdf_base64": "...",
  "certificate_id": 3,
  "field": {"page": 1, "x": 350, "y": 750, "w": 150, "h": 50}
}
```

- `pdf_base64` — obrigatório, mesma validação de `EnvelopeApiController` (decodifica, checa
  prefixo `%PDF-`, grava em arquivo temporário).
- `certificate_id` — opcional. Se informado, deve pertencer ao usuário autenticado
  (`$user->certificates()->findOrFail($id)`, 404/422 se não pertencer) e não estar vencido.
  Se omitido, usa `$user->signingCertificate`; se o usuário não tiver nenhum certificado
  configurado, retorna 422 com mensagem clara.
- `field` — opcional. Default: última página do PDF enviado, `x=350, y=750, w=150, h=50`
  (mesmo padrão de posição já usado em `EnvelopeApiController::store()`), pontos PDF,
  origem topo-esquerdo.

**Assinatura:** reaproveita `App\Services\Pdf\PdfSignerService::fromCertificate($certificate)
->signExisting($tempPath, initialAllPages: false, $position, useTsa: false)` — o mesmo motor
usado por `Client\SignDocumentController::sign()`. Sem selo (`use_seal`) nem TSA nesta v1;
podem virar parâmetros opcionais depois se houver necessidade.

**Persistência:** move o resultado para
`users/{user_id}/signed/{basename}` no disk `documents` (S3), via
`$signer->moveToDisk(...)` como já faz `SignDocumentController`. Loga o evento
`document_signed` via `AccessLogService`, incluindo `certificate_id`, `engine` e `original_name`
(sempre `"documento.pdf"`, já que a API não recebe nome de arquivo original).

**Resposta:**

```json
{
  "status": "signed",
  "download_url": "https://.../doc_xxx.pdf?..."
}
```

`download_url` é uma `temporaryUrl` do disk `documents` (5 minutos), consistente com o padrão
já usado em `EnvelopeApiController::show()`.

**Erros:** 422 com `{"message": "..."}` para PDF inválido, certificado inexistente/não
pertencente ao usuário, certificado vencido, ou falha durante a assinatura (mesma forma de
erro que `EnvelopeApiController::unprocessable()`).

## Fora de escopo

- Selo de autenticação (`use_seal`) e TSA na API de assinatura avulsa — não pedidos agora.
- `send_signed_copy` por signatário individual quando a API aceitar múltiplos signatários —
  a coluna já suporta, mas o contrato da API de envelopes não muda além do campo único atual.
- Qualquer mudança na tela web `/sign-document` — só a API nova é adicionada.
