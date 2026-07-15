# Envelopes — Assinatura Eletrônica Multi-Signatário (estilo DocuSign)

**Data:** 2026-07-15
**Status:** Aprovado

## Objetivo

Permitir que um cliente envie um PDF (ex.: contrato de aluguel) para N destinatários
por e-mail — pessoas físicas, em geral **sem** certificado digital — e colete a
assinatura eletrônica de todos, com evidências suficientes para validade jurídica
(MP 2.200-2/2001, art. 10 §2º: assinatura eletrônica admitida pelas partes).

O sistema já assina PDF com certificado A1 (pyHanko/TCPDF). Este módulo **não substitui**
esse fluxo; adiciona o fluxo de coleta de assinaturas eletrônicas, e usa a infra
existente para o lacre final.

## Decisões de design (aprovadas)

| Decisão | Escolha |
|---|---|
| Lacre final | Página de evidências anexada + assinatura digital única com certificado A1 **da plataforma** (modelo Clicksign/ZapSign) |
| Autenticação do signatário | Configurável por signatário: `link` (só o link único), `email_otp`, `whatsapp_otp` |
| Ordem de assinatura | Configurável por envelope: `parallel` (todos de uma vez) ou `sequential` (um por vez, em ordem) |
| Ato de assinar | Desenhar no canvas **ou** digitar o nome (renderizado em fonte cursiva) + nome completo + CPF declarados |
| Posicionamento | Remetente arrasta marcadores por signatário sobre preview PDF.js (reaproveita código do sign-document) |
| Estratégia de construção | Nativa, lacre único ao final (abordagem A). Assinaturas de convidados são dados no banco; o PDF só é modificado uma vez, no lacre |

### Abordagens descartadas
- **Lacre incremental por assinatura** (pyHanko re-assina a cada signatário): ganho jurídico marginal, complexidade alta (versões intermediárias, stamp sobre PDF assinado é proibido no motor TCPDF).
- **API externa** (ZapSign/Clicksign/D4Sign): custo por documento, dependência de terceiro, white-label limitado; o projeto já tem ~80% da infra.

## Modelo de dados

### `envelopes`
| Campo | Tipo | Descrição |
|---|---|---|
| user_id | FK users | Cliente que criou |
| title | string | Nome do documento/envelope |
| message | text nullable | Mensagem incluída no e-mail de convite |
| original_pdf_path | string | Disk `local`, `envelopes/{id}/original.pdf` |
| final_pdf_path | string nullable | `signed/envelopes/{id}/final.pdf` após lacre |
| sha256_original | string(64) | Hash do PDF original, calculado no upload |
| sha256_final | string(64) nullable | Hash do PDF lacrado |
| signing_order | enum | `parallel` \| `sequential` |
| status | enum | `draft` → `sent` → `completed`; terminais alternativos: `declined`, `cancelled`, `expired` |
| expires_at | timestamp nullable | Expiração opcional do envelope |
| completed_at | timestamp nullable | |

### `envelope_signers`
| Campo | Tipo | Descrição |
|---|---|---|
| envelope_id | FK | |
| name / email | string | Informados pelo remetente |
| whatsapp | string nullable | Obrigatório se `auth_method = whatsapp_otp` |
| cpf | string nullable | Preenchido pelo próprio signatário ao assinar (identidade declarada) |
| auth_method | enum | `link` \| `email_otp` \| `whatsapp_otp` |
| sign_position | int | Ordem na sequência (ignorado em `parallel`) |
| token | string(64) unique | Chave do link público; aleatório (`Str::random(64)`) |
| status | enum | `pending` → `notified` → `viewed` → `signed`; alternativo: `declined` |
| signature_image_path | string nullable | PNG do traço (canvas) ou do nome renderizado |
| signature_type | enum nullable | `drawn` \| `typed` |
| otp_code | string nullable | **Hash** do OTP (nunca em claro) |
| otp_expires_at | timestamp nullable | Validade 10 min |
| otp_attempts | tinyint default 0 | Máx. 5; excedido invalida o código e exige reenvio |
| signed_at | timestamp nullable | |
| ip_address / user_agent | string nullable | Capturados no ato da assinatura |
| decline_reason | text nullable | |

### `envelope_fields`
Posições de carimbo. Um signatário pode ter N marcadores (ex.: rubrica por página).

| Campo | Tipo | Descrição |
|---|---|---|
| envelope_signer_id | FK | |
| page | int | 1-indexed |
| x / y / w / h | decimal | Pontos PDF, origem topo-esquerdo (mesma convenção do sign-document) |

### `envelope_events`
Trilha de auditoria imutável (somente INSERT; sem update/delete). Vira a página de evidências.

| Campo | Tipo | Descrição |
|---|---|---|
| envelope_id | FK | |
| envelope_signer_id | FK nullable | Null para eventos do envelope |
| event | string(50) | `created`, `sent`, `viewed`, `otp_sent`, `otp_failed`, `signed`, `declined`, `reminder_sent`, `completed`, `sealed`, `cancelled`, `expired`, `seal_failed` |
| ip_address / user_agent | string nullable | |
| meta | json nullable | Dados extras (motivo de recusa, erro do lacre, etc.) |
| created_at | timestamp | Sem `updated_at` |

### `settings` (alteração)
Novo campo `platform_certificate_id` (FK nullable → `certificates`): o admin escolhe qual
certificado A1 cadastrado é o "certificado da plataforma" usado no lacre. Envelope não pode
ser enviado se não houver certificado de plataforma válido (não vencido) configurado.

## Rotas

### Cliente (middleware `auth`)
```
GET    /envelopes                     index — lista com status e progresso (2/3 assinaram)
GET    /envelopes/create              wizard de criação
POST   /envelopes                     store (upload PDF + signatários + marcadores) e envio
GET    /envelopes/{envelope}          show — status, trilha de eventos, ações
POST   /envelopes/{envelope}/remind   reenvia convite aos pendentes
POST   /envelopes/{envelope}/cancel   cancela (só antes de completed)
GET    /envelopes/{envelope}/download baixa o PDF final (dono, após completed)
```

### Pública (sem auth; throttle por IP e por token)
```
GET    /sign/{token}                  tela do signatário (PDF.js + dados + ação); registra `viewed`
POST   /sign/{token}/otp              envia/reenvia OTP conforme auth_method (throttle agressivo)
POST   /sign/{token}                  submete assinatura (OTP se exigido + nome + CPF + imagem)
POST   /sign/{token}/decline          recusa com motivo → envelope inteiro `declined`
GET    /sign/{token}/document         serve o PDF (original antes; final após completed)
```

Links de envelope `cancelled` / `expired` / já assinado mostram tela informativa, nunca erro 4xx cru.
CSRF normal do Laravel (páginas web). Autorização é o próprio token (64 chars, unique, imprevisível).

## Fluxo

### Criação (wizard em passos na mesma tela)
1. Upload do PDF (validado: mime, tamanho; hash SHA-256 calculado e gravado)
2. Signatários: nome, e-mail, método de autenticação, WhatsApp (se otp por WhatsApp), ordem
3. Posicionamento: preview PDF.js com marcadores arrastáveis, um conjunto por signatário
   (cor distinta por signatário); coordenadas convertidas para pontos PDF como no sign-document
4. Revisão e envio → status `sent`, dispara convites

### Coleta
- `parallel`: todos os signatários recebem o convite imediatamente.
- `sequential`: só o de menor `sign_position` pendente recebe; ao assinar, o próximo é notificado.
- Assinar: valida OTP quando exigido → grava nome, CPF, imagem da assinatura (PNG base64 do
  canvas ou nome renderizado em cursiva no client e re-renderizado/sanitizado no server),
  IP, user agent, `signed_at` → evento `signed`.
- Recusar: qualquer signatário recusa → envelope `declined`, demais links desativados,
  remetente notificado.
- Último signatário assina → dispara `SealEnvelopeJob`.

### Lacre (`SealEnvelopeJob`, queued, com retries)
1. Carimba os PNGs das assinaturas nas posições de `envelope_fields` via `SignPdfService::stamp()`/FPDI
   (seguro: o original nunca recebeu assinatura digital — a regra "jamais stamp sobre PDF assinado" é respeitada)
2. `EvidenceReportGenerator` (TCPDF, sem dependência nova) gera a(s) página(s) de evidências:
   dados do documento (título, SHA-256 do original, datas), por signatário (nome, CPF, e-mail,
   método de autenticação, IP, user agent, data/hora, imagem da assinatura) e a trilha completa
   de `envelope_events`
3. Concatena documento carimbado + páginas de evidências (FPDI)
4. Assina digitalmente o conjunto com o certificado da plataforma via `PdfSignerService`
   (pyHanko preferencial; fallback TCPDF funciona pois é assinatura única)
5. Grava `final_pdf_path`, `sha256_final`, `completed_at`, status `completed`, evento `sealed`
6. Notifica remetente e signatários (e-mail com link de download; signatário usa o próprio token,
   remetente baixa logado)

**Falha no lacre** (ex.: certificado vencido, pyHanko e TCPDF indisponíveis): envelope permanece
`sent`, evento `seal_failed` com o erro em `meta`, log em `laravel.log`. Nada é perdido;
corrigida a causa, o job pode ser reprocessado (botão no show do envelope para o dono, visível
quando existe `seal_failed` posterior ao último `signed`).

## Notificações

Mailables no padrão existente (`emails.layout`):
- Convite para assinar (com mensagem do remetente)
- Código OTP
- Lembrete (reenvio manual)
- Documento concluído (remetente + todos os signatários, com link)
- Recusado / Cancelado (avisa o remetente; cancelado avisa também signatários já notificados)

WhatsApp via `NotificationService` (respeita `settings.whatsapp_enabled`): espelha convite e
OTP quando o signatário tem número. OTP por WhatsApp usa exclusivamente o WhatsApp.

Ciclo de vida:
- Reenvio manual de convite no `show` (v1; lembrete automático fica para v2)
- Command `envelopes:expire` no scheduler: envelopes `sent` com `expires_at` vencido → `expired`, evento registrado
- Eventos do remetente também vão ao `AccessLogService` (`envelope_created`, `envelope_completed`, `envelope_declined`)

## Componentes novos

```
app/Models/Envelope.php, EnvelopeSigner.php, EnvelopeField.php, EnvelopeEvent.php
app/Http/Controllers/Client/EnvelopeController.php
app/Http/Controllers/Public/SignEnvelopeController.php
app/Services/Envelope/EnvelopeService.php        — criação, envio, transições de status, eventos
app/Services/Envelope/EvidenceReportGenerator.php — página de evidências (TCPDF)
app/Jobs/SealEnvelopeJob.php
app/Console/Commands/ExpireEnvelopes.php
Mailables: EnvelopeInvite, EnvelopeOtp, EnvelopeReminder, EnvelopeCompleted, EnvelopeDeclined, EnvelopeCancelled
Views: client/envelopes/{index,create,show}.blade.php, public/sign/{show,done,unavailable}.blade.php
```

Menu do cliente ganha item "Envelopes". Admin settings ganha o seletor de certificado da plataforma.

## Segurança

- Token de 64 chars aleatórios por signatário; nunca reutilizado; rota pública com `throttle`
  (por IP e por token); OTP armazenado como hash, 10 min de validade, 5 tentativas
- PDFs em disk `local` (privado); download só via controller com autorização (dono ou token)
- Upload da assinatura: aceita apenas PNG data-URL do canvas, tamanho máximo definido,
  re-validado no server (imagem GD válida)
- `envelope_events` sem rota de escrita externa; models sem `updated_at` e sem métodos de update

## Testes

Feature (sqlite `:memory:`, `Storage::fake`, `Mail::fake`, `Queue::fake`):
- Criação de envelope com signatários e marcadores; validações (PDF obrigatório, e-mail válido,
  WhatsApp obrigatório quando `whatsapp_otp`, certificado de plataforma configurado)
- Fluxo público por método: link direto assina; OTP e-mail (código correto, expirado, 5 erros);
  OTP WhatsApp
- `sequential`: segundo signatário só é notificado após o primeiro assinar; `parallel`: todos de uma vez
- Recusa → envelope `declined`, links restantes desativados, remetente notificado
- Token inválido / envelope expirado / cancelado / já assinado → tela informativa
- Último assina → `SealEnvelopeJob` enfileirado; job com motor de assinatura mockado →
  status `completed`, `sha256_final` gravado, notificações disparadas; falha → `seal_failed`, status mantido

Unit:
- `EvidenceReportGenerator` produz PDF com os dados esperados
- Conversão de coordenadas dos marcadores (client → pontos PDF)

## Fora de escopo (v2+)

- Lembretes automáticos agendados
- Upload de imagem de assinatura pelo convidado
- Signatário com certificado próprio no fluxo de envelope (híbrido)
- Carimbo do tempo (TSA) obrigatório no lacre — usa a config existente se disponível
- Templates de documento e campos de formulário (texto, data, checkbox) estilo DocuSign
- Geolocalização do signatário
