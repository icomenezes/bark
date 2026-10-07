# API de Envelopes — Integração Externa (Delphi/outros sistemas)

Data: 2026-07-16 (atualizado em 2026-07-20 e 2026-10-07 — ver notas de atualização abaixo)

> **Atualização 2026-07-20:** `POST /api/v1/envelopes` passou a aceitar o campo
> opcional `send_signed_copy` (ver seção do payload abaixo). Também foi
> adicionada uma API irmã, `POST /api/v1/sign-document`, para assinar um PDF
> avulso (sem envelope, sem coleta de assinatura de terceiros) com um
> certificado próprio do usuário — ver
> `docs/superpowers/specs/2026-07-20-conclusao-adaptativa-copia-opcional-assinatura-avulsa-design.md`
> para o design completo de ambas as mudanças.
>
> **Atualização 2026-10-07:** novo endpoint `POST /api/v1/envelopes/{id}/cancel`
> (ver seção própria abaixo). Consumidor inicial: sistema Ponto, que cancela o
> envelope anterior de uma folha de ponto antes de reenviá-la, para o funcionário
> não ficar com dois links válidos.
>
> **Atualização 2026-10-07 (webhooks):** a plataforma passa a avisar o sistema do
> cliente por `POST` quando um envelope criado pela API termina assinado ou
> cancelado (ver seção "Webhooks").

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
- `POST /api/v1/envelopes/{id}/cancel` — cancela o envelope (adicionado em 2026-10-07)

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
  "field": {"page": 1, "x": 350, "y": 750, "w": 150, "h": 50},
  "pdf_base64": "JVBERi0xLjQK..."
}
```

Regras:
- `title`: obrigatório, string, máx 255
- `message`: opcional, string, máx 2000
- `signer_name`: obrigatório, string, máx 255
- `signer_email`: obrigatório, e-mail válido
- `signer_whatsapp`: opcional, string (só dígitos ou formatado — mesma normalização
  do `WhatsAppService`); obrigatório quando `channel = "whatsapp"`. No canal padrão,
  `email`, o número vira uma **cópia por WhatsApp** dos avisos ao signatário, sem
  deixar de mandar o e-mail: convite, lembrete, conclusão (respeitando
  `send_signed_copy`) e cancelamento. A cópia só sai se:
  - a conta do remetente tiver o WhatsApp de envelope habilitado
    (`users.whatsapp_envelope_enabled`, ligado pelo admin em `/admin/users/{id}/edit`);
  - o WhatsApp da plataforma estiver ativo (`settings.whatsapp_enabled`).

  Sem número ou com a conta não habilitada, só e-mail. Se o envio pelo WhatsApp
  falhar, a falha vai para o log e o e-mail e o envelope seguem normalmente. O código
  OTP (`auth_method = "email_otp"`) continua indo só por e-mail. A regra fica em
  `EnvelopeSigner::noticeChannels()`. (Atualizado em 2026-10-07: entre 2026-07-17 e
  essa data a cópia não saía, porque o convite seguia só o canal.)
- `send_signed_copy`: opcional, boolean, **default `true`** (adicionado em
  2026-07-20). Quando `false`, o signatário não recebe a notificação de
  conclusão com o PDF final (nem por e-mail nem por WhatsApp) — só o convite
  inicial para assinar. Usado quando o dono da conta quer manter o documento
  assinado só em sua posse (ex.: promissórias assinadas por clientes via API).
  O e-mail de conclusão ao dono do envelope (remetente) nunca é afetado por
  este campo. Persistido em `envelope_signers.send_signed_copy`
- `field`: opcional (adicionado em 2026-08-05), objeto com a posição da assinatura
  em pontos PDF, origem topo-esquerdo — mesmo formato da API de assinatura avulsa.
  Sub-campos todos opcionais: `page` (inteiro ≥ 1, limitado ao total de páginas do
  PDF), `x`/`y` (numérico ≥ 0), `w`/`h` (numérico ≥ 1). Ausentes caem no default:
  última página, canto inferior direito (`x = 350, y = 750, w = 150, h = 50`)
- `pdf_base64`: obrigatório, string base64 que decodifica para um PDF válido
  (assinatura `%PDF-` nos primeiros bytes), tamanho decodificado até 15 MB (mesmo
  limite do upload web)

Sempre, sem parâmetro para mudar:
- Único signatário, `sign_position = 1`
- `auth_method = 'link'` (sem OTP)
- `signing_order = 'parallel'` (irrelevante com 1 signatário)
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

### `POST /api/v1/envelopes/{id}/cancel`

Adicionado em 2026-10-07. Sem corpo. Cancela o envelope para que o link de
assinatura deixe de valer: `/sign/{token}` passa a mostrar "não está mais
disponível". Reaproveita `EnvelopeService::cancel()`, a mesma lógica do botão
"Cancelar" da tela web: marca `cancelled`, grava o evento `cancelled` na trilha de
auditoria e avisa os signatários já notificados pelo canal de cada um:
`EnvelopeCancelled` por e-mail, ou mensagem de WhatsApp para quem tem
`channel = whatsapp`. Uma falha da Evolution API é só logada e não impede o
cancelamento.

Regras, avaliadas nesta ordem:

1. Envelope de outro usuário ou inexistente → `404` (mesmo escopo do GET)
2. Já `cancelled` → `200` sem fazer nada: nenhum evento novo, nenhum e-mail.
   **Idempotente**: o integrador pode repetir a chamada depois de um timeout
3. `sent` com todos os signatários já assinados (lacre na fila ou com
   `seal_failed`) → `422`, **não cancela**: as assinaturas são válidas e o
   documento final está sendo gerado
4. `draft` ou `sent` → cancela → `200`
5. Qualquer outro status (`completed`, `declined`, `expired`) → `422`

Resposta em caso de sucesso (`200 OK`), tanto para o cancelamento quanto para a
repetição idempotente:

```json
{
  "id": 42,
  "status": "cancelled"
}
```

Todo `422` deste endpoint traz, além de `message`, o campo `status` com o status
**atual** do envelope já mapeado (mesma tabela do GET):

```json
{
  "message": "Este envelope não pode mais ser cancelado.",
  "status": "signed"
}
```

Uso pelo integrador ao reenviar um documento: com `declined` ou `expired`, o link
antigo já não vale e é seguro criar o envelope novo; com `pending` ou `signed`, o
documento anterior foi (ou está sendo) assinado e o reenvio deve ser abortado.

| Situação | HTTP | Corpo |
|---|---|---|
| Token ausente/inválido | `401` | `{"message": "Unauthenticated."}` |
| Envelope de outro usuário ou inexistente | `404` | `{"message": "Not Found."}` |
| Todos já assinaram, lacre em processamento | `422` | `{"message": "Assinatura já concluída, documento em processamento.", "status": "pending"}` |
| Envelope `completed` | `422` | `{"message": "Este envelope não pode mais ser cancelado.", "status": "signed"}` |
| Envelope `declined` | `422` | `{"message": "Este envelope não pode mais ser cancelado.", "status": "declined"}` |
| Envelope `expired` | `422` | `{"message": "Este envelope não pode mais ser cancelado.", "status": "expired"}` |

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

## Webhooks

Adicionado em 2026-10-07. A plataforma avisa o sistema do cliente com um `POST` em
uma URL cadastrada por ele, quando um envelope criado pela API termina assinado ou
cancelado. Com isso o integrador não precisa ficar consultando o GET. A URL e o
segredo de assinatura são cadastrados pelo próprio cliente, na área dele.

### Quando dispara

| Evento | Quando | `status` no corpo |
|---|---|---|
| `envelope.signed` | O lacre terminou (`SealEnvelopeJob` gravou `completed`) e o PDF final já está disponível | `signed` |
| `envelope.cancelled` | O envelope foi cancelado (`EnvelopeService::cancel()`), pela tela web ou pela API | `cancelled` |

Só dispara quando o envelope foi criado pela API (`envelopes.source = api`) **e** a
conta do dono tem `webhook_url` cadastrada.

Não dispara para:

- envelopes criados pela tela web;
- envelopes criados **antes** da implantação dos webhooks, que ficam com
  `source = web` (para esses, o integrador continua usando o GET);
- recusa (`declined`), expiração (`expired`), visualização ou assinatura de um
  signatário antes do lacre (ver "Fora de escopo").

Se o lacre falhar (`seal_failed`), o `envelope.signed` só sai quando um
reprocessamento concluir o lacre. Um cancelamento feito pelo próprio integrador via
`POST /api/v1/envelopes/{id}/cancel` também gera `envelope.cancelled` para ele.

### O `POST` enviado

```http
POST https://ponto.exemplo.com.br/webhooks/assinador
Content-Type: application/json
X-Webhook-Event: envelope.signed
X-Webhook-Id: 9b2f6c1e-4d7a-4f3b-9a51-0c8e2d6f7a10
X-Webhook-Timestamp: 1791394330
X-Webhook-Signature: sha256=3f1c9a...e07b

{
  "id": "9b2f6c1e-4d7a-4f3b-9a51-0c8e2d6f7a10",
  "event": "envelope.signed",
  "occurred_at": "2026-10-07T14:32:10-03:00",
  "envelope": {
    "id": 42,
    "status": "signed",
    "created_at": "2026-10-07T14:20:00-03:00",
    "signed_at": "2026-10-07T14:32:10-03:00",
    "download_url": "https://<bucket>.s3.../final.pdf?X-Amz-Signature=..."
  }
}
```

Para `envelope.cancelled`, o corpo tem o mesmo formato, com
`"event": "envelope.cancelled"`, `"status": "cancelled"`, `"signed_at": null` e
`"download_url": null`.

Campos:

- `id`: identificador da notificação (UUID). É **o mesmo em todas as tentativas**
  da mesma notificação; o receptor usa para descartar repetições. Repetido no
  cabeçalho `X-Webhook-Id`.
- `event`: `envelope.signed` ou `envelope.cancelled`. Repetido no cabeçalho
  `X-Webhook-Event`. O botão "Enviar teste" da tela do cliente manda `test`, com
  `"envelope": null`.
- `occurred_at`: quando o status mudou. Não muda entre tentativas.
- `envelope`: **exatamente** o corpo do `GET /api/v1/envelopes/{id}` (mesmo código
  monta os dois). O `download_url` é gerado a cada tentativa e vale 5 minutos a
  partir dela. Se expirar, o GET gera um novo.

### Verificando a assinatura

Cada tentativa leva `X-Webhook-Timestamp` (Unix, segundos, da própria tentativa) e
`X-Webhook-Signature` = `sha256=` + HMAC-SHA256 em hexadecimal de
`"{timestamp}.{corpo bruto}"`, com o segredo da conta como chave. O receptor
recalcula sobre o corpo **bruto** (antes de decodificar o JSON), compara em tempo
constante e recusa timestamps com mais de 5 minutos de diferença, o que barra
reenvio de uma requisição capturada.

Exemplo em PHP (receptor do Ponto):

```php
$body = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $segredo);

if (! hash_equals($expected, $signature) || abs(time() - (int) $timestamp) > 300) {
    http_response_code(401);
    exit;
}

$notificacao = json_decode($body, true);
// Já processou $notificacao['id']? Responde 200 e ignora.
```

### Entrega e novas tentativas

- **Sucesso:** qualquer resposta `2xx` em até **10 segundos**. O corpo da resposta
  é ignorado.
- **Redirecionamento** (`3xx`) não é seguido e conta como falha.
- **Falha** (timeout, erro de conexão, qualquer status fora de `2xx`): nova
  tentativa. São **6 tentativas no total**, com intervalos de 1 min, 5 min, 15 min,
  1 h e 3 h (cerca de 4h20 entre a primeira e a última). Depois da última, a
  plataforma desiste; o GET continua respondendo normalmente.
- Cada tentativa usa a URL e o segredo **atuais** da conta. Corrigir uma URL
  quebrada faz as tentativas seguintes irem para a nova; remover a URL interrompe
  as tentativas pendentes.
- A ordem entre notificações não é garantida. Os dois eventos são finais, então
  isso não afeta o integrador.

O receptor deve:

1. verificar a assinatura;
2. responder `2xx` rápido e deixar processamento pesado (ex.: baixar o PDF) para
   depois, se puder demorar mais que 10 segundos;
3. descartar `id` já processado: a mesma notificação pode chegar mais de uma vez
   (ex.: o receptor processou, mas a resposta não chegou a tempo);
4. ignorar envelopes que não conhece.

### Cadastro pelo cliente (tela "Integração")

- Item **"Integração"** no menu do cliente, visível só para contas com token de API
  ativo (o webhook só vale para envelopes da API). Sem token, a rota responde `404`.
- Rotas (middleware `auth`, escopo do próprio usuário):
  - `GET /integration` (`integration.edit`)
  - `PATCH /integration` (`integration.update`)
  - `POST /integration/secret` (`integration.secret`)
  - `POST /integration/test` (`integration.test`)
- **URL**: campo único; salvar vazio desativa os webhooks. Regra `App\Rules\WebhookUrl`:
  URL `http(s)` de até 2048 caracteres, com o destino conferido pela
  `WebhookDestination` (ver "Segurança" abaixo). Em `local` aceita `http` e
  `localhost`, para o integrador testar na própria máquina.
- **Segredo**: gerado automaticamente (`whsec_` + 40 caracteres aleatórios) quando a
  URL é salva pela primeira vez, e exibido na tela para copiar. O botão
  "Gerar novo segredo" troca na hora; tentativas pendentes já saem assinadas com o
  novo, então o integrador precisa atualizar o dele em seguida.
- **"Enviar teste"**: faz na hora um `POST` assinado do mesmo jeito, com
  `"event": "test"` e `"envelope": null`, em uma tentativa só, e mostra o resultado
  (status HTTP ou erro). Fica registrado nas entregas, sem envelope.
- **Últimas entregas**: as 20 tentativas mais recentes da conta, com data e hora,
  evento, envelope, número da tentativa e resultado.

### Segurança (SSRF)

A plataforma faz requisições para uma URL escolhida pelo cliente, e a tela mostra o
status HTTP de cada tentativa. Sem proteção, isso permitiria sondar a rede interna do
servidor (ex.: um domínio público apontando para `10.0.0.5` ou `169.254.169.254`). A
`App\Services\Webhook\WebhookDestination` confere o destino **no cadastro e de novo a
cada envio** (o DNS pode mudar depois do cadastro). Fora do ambiente `local`:

1. Exige `https`.
2. Recusa `localhost`, nomes sem domínio de topo alfabético (`intranet`) e IPs
   disfarçados (`2130706433`, `127.1`), que o curl aceitaria como `127.0.0.1`.
3. Resolve o DNS (IPv4 e IPv6, `WebhookHostResolver`) e recusa se o domínio não
   resolver ou se **qualquer** IP for privado ou reservado (inclui loopback,
   `169.254.x` e `::1`). IP digitado direto passa pela mesma checagem.
4. Trava a conexão no IP conferido (`CURLOPT_RESOLVE`), para o DNS não trocar entre a
   conferência e o `POST` (DNS rebinding).

Destino recusado no envio vira tentativa com falha (`Destino recusado: ...`), sem
requisição. Além disso, **o corpo da resposta nunca é gravado nem exibido**, e erros de
conexão aparecem só como categoria ("Tempo esgotado" ou "Falha de conexão"); a mensagem
crua do curl vai para o log.

### Implementação interna

Dados:

- `envelopes.source` (`web` | `api`, padrão `web`): gravado como `api` pelo
  `POST /api/v1/envelopes`. Envelopes anteriores ficam `web`, porque não há como
  saber quais vieram da API.
- `users.webhook_url` (nullable) e `users.webhook_secret` (cast `encrypted`): uma
  URL por conta.
- Tabela `webhook_deliveries`: uma linha por tentativa, com `user_id`,
  `envelope_id`, `event_id` (o `id` da notificação), `event`, `url` (cópia da URL
  usada), `attempt`, `response_status` e `error`. O corpo da resposta **nunca** é
  gravado. Linhas com mais de 90 dias são apagadas pelo `model:prune`, agendado em
  `routes/console.php`. **Não** usa `envelope_events`: o
  `EvidenceReportGenerator` imprime todos os eventos no certificado de evidências.

Fluxo:

1. O status muda em um dos dois pontos: `SealEnvelopeJob`, logo após gravar
   `completed`, ou `EnvelopeService::cancel()`.
2. Esse ponto chama `EnvelopeWebhook::dispatch($envelope, $event)`.
3. Se `source = api` e o dono tem `webhook_url`, o serviço gera o `id` da
   notificação e o `occurred_at` e enfileira o `SendEnvelopeWebhookJob`. Se não,
   não faz nada.
4. O job monta o corpo, assina e envia a cada tentativa (`WebhookSender`, o mesmo
   usado pelo botão de teste) e registra o resultado em `webhook_deliveries`. Em
   falha, devolve o job à fila (`release`) com o próximo intervalo, sem lançar
   exceção: falha do receptor não é erro da plataforma e não deve sujar o log.

Não sai webhook duplicado em repetições: cancelar de novo pela API devolve `200`
sem chamar `cancel()`, e o `SealEnvelopeJob` não refaz o lacre de envelope já
lacrado.

## Fora de escopo (decidido explicitamente)

- **Webhook para outros eventos**: só `envelope.signed` e `envelope.cancelled`.
  Recusa, expiração, visualização e assinatura individual continuam só pelo GET.
  Webhooks para envelopes criados pela tela web também ficam de fora. (A versão
  original desta spec deixava qualquer webhook de fora, só polling; os dois
  eventos foram adicionados em 2026-10-07.)
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
- Testes (adicionados 2026-10-07), `POST /api/v1/envelopes/{id}/cancel`: sem token
  → `401`; envelope de outro usuário → `404`; `sent` → `200`, `cancelled` no
  banco, evento `cancelled` e `EnvelopeCancelled` enviado; signatário de WhatsApp
  sem e-mail → `200` com o mailer real (antes estourava `500`); `draft` → `200`; já
  `cancelled` → `200` sem evento novo nem e-mail; `sent` com todos assinados →
  `422` e continua `sent`; `completed` → `422` com `"status": "signed"`;
  `declined` → `422` com `"status": "declined"`
- Testes (adicionados 2026-10-07), webhooks:
  - `source`: envelope criado pela API grava `api`; pela tela web, `web`
  - `EnvelopeWebhook`: enfileira só para envelope `api` de conta com URL; o
    `cancel()` dispara `envelope.cancelled` e o lacre dispara `envelope.signed`
  - `WebhookSender`: corpo JSON e cabeçalhos com assinatura HMAC conferível;
    resposta fora de `2xx` e falha de conexão registradas como falha, sem o corpo
    da resposta; redirecionamento não seguido
  - `SendEnvelopeWebhookJob`: corpo igual ao do GET; intervalos de 1 min, 5 min,
    15 min, 1 h e 3 h; desiste após a 6ª tentativa sem marcar o job como falho;
    usa a URL atual; para se a URL for removida ou o envelope não existir mais
  - `WebhookUrl`: aceita `https` público; recusa `http`, `localhost`, IPs privados
    e reservados, IPv6 de loopback, IP em forma decimal, nome sem domínio de
    topo, domínio que resolve para IP interno (mesmo que só um dos IPs) e domínio
    que não resolve; em `local` aceita `http://localhost`
  - `WebhookSender` (SSRF): trava a conexão no IP conferido (`CURLOPT_RESOLVE`);
    não envia se o domínio passou a resolver para IP interno depois do cadastro;
    erro de conexão gravado só como categoria
  - Tela "Integração": `404` sem token; menu só com token; salvar gera o segredo
    uma vez; URL vazia desativa; URL inválida recusada; novo segredo; envio de
    teste assinado (sucesso e falha); só as entregas da própria conta
  - Limpeza: linhas com mais de 90 dias são `prunable`; `model:prune` agendado
    diariamente
