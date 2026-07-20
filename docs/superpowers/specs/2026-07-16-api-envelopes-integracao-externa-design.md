# API de Envelopes — Integração Externa (Delphi/outros sistemas)

Data: 2026-07-16 (atualizado em 2026-07-20 — ver nota de atualização abaixo)

> **Atualização 2026-07-20:** `POST /api/v1/envelopes` passou a aceitar o campo
> opcional `send_signed_copy` (ver seção do payload abaixo). Também foi
> adicionada uma API irmã, `POST /api/v1/sign-document`, para assinar um PDF
> avulso (sem envelope, sem coleta de assinatura de terceiros) com um
> certificado próprio do usuário — ver
> `docs/superpowers/specs/2026-07-20-conclusao-adaptativa-copia-opcional-assinatura-avulsa-design.md`
> para o design completo de ambas as mudanças.

## Contexto

O usuário administra uma loja física com crediário (venda fiado), hoje formalizado
com nota promissória em papel assinada na hora. Quer digitalizar esse fluxo: o
sistema de ponto de venda (Delphi ou outra linguagem) chama uma API para criar um
envelope de assinatura eletrônica com a nota promissória, o cliente recebe o link
por e-mail/WhatsApp e assina remotamente, e o sistema de origem consulta
periodicamente (polling, sem webhook por ora) se já foi assinado.

Reaproveita a infraestrutura de envelopes já existente (`EnvelopeService`,
`EnvelopeSigner`, `SealEnvelopeJob`, `UsageLimitService`) — a API é uma nova casca
de entrada (rota HTTP autenticada por token) sobre a mesma lógica de negócio do
formulário web, não um sistema paralelo.

## Escopo

### Autenticação

- Instalar **Laravel Sanctum** (personal access tokens), ainda não presente no projeto
- Cada usuário (cliente da plataforma) pode ter um token de API ativo
- Token é gerado **somente pelo admin**, na tela `/admin/users/{id}/edit`:
  - Botão "Gerar token de API" — cria via `$user->createToken('api')->plainTextToken`,
    exibido uma única vez na tela (padrão Sanctum — não é recuperável depois)
  - Se já existe token ativo, mostra indicador "Token ativo" (sem revelar o valor)
    e botão "Revogar" (`$user->tokens()->delete()`)
  - Um usuário tem no máximo 1 token ativo por vez (gerar novo revoga o anterior)
- Chamadas à API usam `Authorization: Bearer {token}`; o Sanctum resolve o `User`
  autenticado automaticamente — esse é o dono do envelope criado, contando no
  limite do plano dele (mesmo `UsageLimitService` do formulário web)

### Rotas (novo `routes/api.php`, prefixo `/api/v1`, middleware `auth:sanctum`)

- `POST /api/v1/envelopes` — cria e envia o envelope
- `GET /api/v1/envelopes/{id}` — consulta status

### `POST /api/v1/envelopes`

Payload JSON:

```json
{
  "title": "Nota Promissória #1234",
  "message": "Assine para confirmar a compra a crediário",
  "signer_name": "João da Silva",
  "signer_email": "joao@example.com",
  "signer_whatsapp": "11999998888",
  "send_signed_copy": true,
  "pdf_base64": "JVBERi0xLjQK..."
}
```

Regras:
- `title`: obrigatório, string, máx 255
- `message`: opcional, string, máx 2000
- `signer_name`: obrigatório, string, máx 255
- `signer_email`: obrigatório, e-mail válido
- `signer_whatsapp`: opcional, string (só dígitos ou formatado — mesma normalização
  do `WhatsAppService`). O convite sempre sai por e-mail (`EnvelopeInvite`); se
  `signer_whatsapp` for informado, `EnvelopeService::notifySigner()` também envia
  um espelho por WhatsApp automaticamente — comportamento já existente, reaproveitado
  sem mudança
- `send_signed_copy`: opcional, boolean, **default `true`** (adicionado em
  2026-07-20). Quando `false`, o signatário não recebe a notificação de
  conclusão com o PDF final (nem por e-mail nem por WhatsApp) — só o convite
  inicial para assinar. Usado quando o dono da conta quer manter o documento
  assinado só em sua posse (ex.: promissórias assinadas por clientes via API).
  O e-mail de conclusão ao dono do envelope (remetente) nunca é afetado por
  este campo. Persistido em `envelope_signers.send_signed_copy`
- `pdf_base64`: obrigatório, string base64 que decodifica para um PDF válido
  (assinatura `%PDF-` nos primeiros bytes), tamanho decodificado até 15 MB (mesmo
  limite do upload web)

Sempre, sem parâmetro para mudar:
- Único signatário, `sign_position = 1`
- `auth_method = 'link'` (sem OTP)
- `signing_order = 'parallel'` (irrelevante com 1 signatário)
- Posição de assinatura fixa: última página do PDF, canto inferior direito
  (`page = última página, x = 350, y = 750, w = 150, h = 50` — mesmos pontos PDF,
  origem topo-esquerdo, usados no restante do sistema)
- `expires_at = null` (sem expiração automática — mesma regra do formulário quando
  o campo é deixado em branco)

Fluxo interno: decodifica `pdf_base64` para um arquivo temporário, envolve num
`Illuminate\Http\UploadedFile` sintético, chama
`EnvelopeService::create($user, $file, [...])` seguido de
`EnvelopeService::send($envelope)` — mesmas duas chamadas que
`EnvelopeController::store()` já faz. Validação de limite de uso
(`UsageLimitService::canCreateEnvelope`) roda **antes** de decodificar o base64,
mesma posição que no controller web.

Resposta em caso de sucesso (`201 Created`):

```json
{
  "id": 42,
  "status": "sent",
  "sign_url": "https://assinador.trsystem.com.br/sign/{token}"
}
```

`id` é o identificador que o sistema de origem guarda para consultar depois — o
próprio ID do envelope, sem necessidade de gerar uma chave separada.

### `GET /api/v1/envelopes/{id}`

Escopado ao dono: só retorna envelopes onde `envelope.user_id === $request->user()->id`;
de outro usuário → `404` (não `403`, para não vazar existência de IDs de terceiros).

Resposta:

```json
{
  "id": 42,
  "status": "signed",
  "created_at": "2026-07-16T18:00:00-03:00",
  "signed_at": "2026-07-16T18:05:00-03:00",
  "download_url": "https://assinador.trsystem.com.br/envelopes/42/download"
}
```

Mapeamento de `status` (traduzido do valor interno de `envelopes.status` para um
vocabulário mais claro à integração externa):

| `envelopes.status` (interno) | `status` (API) |
|---|---|
| `draft` | `draft` |
| `sent` | `pending` |
| `completed` | `signed` |
| `declined` | `declined` |
| `cancelled` | `cancelled` |
| `expired` | `expired` |

- `signed_at`: `null` enquanto não `completed`
- `download_url`: presente somente quando `status = "signed"` **e** o PDF final
  existir no disk `documents`; é uma URL assinada temporária do S3
  (`Storage::disk('documents')->temporaryUrl()`, 5 minutos de validade, mesmo
  padrão já usado em `EnvelopeController::download()`) — funciona sem sessão web,
  baixável diretamente pelo sistema de origem (ex.: Delphi). Revisado após uso
  real: a versão original apontava para a rota web autenticada por sessão
  (`envelopes.download`), inviável para consumo por API

### Erros

| Situação | HTTP | Corpo |
|---|---|---|
| Token ausente/inválido | `401` | `{"message": "Unauthenticated."}` (padrão Sanctum) |
| Sem plano atribuído | `422` | `{"message": "Nenhum plano atribuído — contate o administrador."}` |
| Limite mensal de envelopes atingido | `422` | `{"message": "Você atingiu o limite de N envelopes este mês."}` |
| Campo obrigatório ausente/inválido | `422` | `{"message": "...", "errors": {"campo": [...]}}` (`ValidationException` padrão do Laravel) |
| `pdf_base64` não decodifica para PDF válido | `422` | `{"message": "O arquivo enviado não é um PDF válido.", "errors": {"pdf_base64": [...]}}` |
| Certificado da plataforma ausente/vencido (falha no `send()`) | `422` | `{"message": "<mensagem do RuntimeException>"}` |
| Envelope de outro usuário ou inexistente no GET | `404` | `{"message": "Not Found."}` |

## Fora de escopo (decidido explicitamente)

- **Webhook de notificação de assinatura** — só polling via GET por enquanto,
  conforme pedido
- **Múltiplos signatários via API** — sempre 1 signatário por chamada; para
  múltiplos, o cliente deve usar o formulário web
- **OTP (e-mail/WhatsApp) como autenticação do signatário via API** — sempre
  `auth_method = 'link'`
- **Posição de assinatura customizável por chamada** — sempre fixa (canto inferior
  direito da última página); se o layout do documento do usuário não combinar com
  essa posição, ele ajusta o PDF de origem antes de enviar
- **Rotação/expiração automática de token** — token dura até ser revogado
  manualmente pelo admin, sem TTL
- **Rate limiting dedicado** — usa o throttle padrão do Laravel (`api` middleware
  group), sem configuração especial nesta fase

## Testes

- Teste de feature: `POST /api/v1/envelopes` sem token → `401`; com token válido e
  payload completo → `201`, envelope criado com 1 signatário `auth_method = link`,
  `EnvelopeInvite` disparado (`Mail::fake()`)
- Teste: `pdf_base64` inválido (não decodifica pra PDF) → `422`
- Teste: usuário sem plano ou no limite → `422` com mensagem do `UsageLimitService`
- Teste: `GET /api/v1/envelopes/{id}` de outro usuário → `404`
- Teste: `GET /api/v1/envelopes/{id}` reflete `status` mapeado corretamente nos
  6 estados de `envelopes.status`
- Teste: geração/revogação de token na tela admin de edição de usuário
- Teste (adicionado 2026-07-20): `send_signed_copy` omitido → persistido como
  `true`; `send_signed_copy: false` → persistido como `false` em
  `envelope_signers.send_signed_copy`
