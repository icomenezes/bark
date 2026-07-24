# Rodapé de rastreabilidade, página de lacre redesenhada e verificação pública

Data: 2026-07-24

## Contexto

Comparação entre um PDF assinado pelo nosso sistema (fluxo de envelopes) e um PDF
assinado pela D4Sign (`https://secure.d4sign.com.br/`), a pedido do usuário. A
comparação revelou lacunas na rastreabilidade visual do documento final:

1. Nosso PDF não tem rodapé nas páginas do documento original — nenhuma marca de
   que aquele documento foi assinado eletronicamente até a página de evidências.
2. A página de evidências (`EvidenceReportGenerator`) é puramente tabular, com o
   rodapé padrão do TCPDF ("Powered by TCPDF"), sem identidade visual, sem QR
   code, sem preview da assinatura ao lado do nome do signatário.
3. Não existe nenhuma forma de verificação pública do documento — nenhuma URL,
   nenhum código, nenhum QR.
4. A assinatura avulsa (API, sem envelope) não persiste nenhum registro
   consultável por documento — só grava em `access_logs` (não é uma fonte
   adequada para uma página pública).

O D4Sign resolve isso com: rodapé em toda página do documento
(`D4Sign {uuid} - Para confirmar as assinaturas acesse https://secure.d4sign.com.br/verificar`),
e uma página de certificado com moldura decorativa "de diploma" (papel
tramado/cross-hatch, cores da marca), QR code de verificação, lista de
assinaturas com preview da assinatura manuscrita ao lado do nome, hash
SHA-256/512 e trilha de eventos.

O design abaixo foi validado com mockups HTML comparados lado a lado
(companion visual de brainstorming) — a direção aprovada é moldura cross-hatch
em tons do `primary_color` do settings (sem usar `accent_color`), textura de
fundo sutil, QR code e ícone de check desenhados em vetor (SVG/TCPDF nativo,
não emoji), e preview da assinatura manuscrita alinhada à direita de cada
signatário.

## Escopo

Aplica-se aos dois fluxos que geram PDF assinado para o cliente final:

- **Envelopes** (multi-signatário, `EnvelopePdfComposer` +
  `EvidenceReportGenerator`): rodapé em todas as páginas + página de lacre
  redesenhada.
- **Assinatura avulsa** (API, `SignDocumentApiController` +
  `SignPdfService`): só o rodapé em todas as páginas. Sem página de lacre —
  o fluxo continua sendo "assina e devolve o arquivo", sem página extra.

Fora de escopo:

- Geolocalização de IP (o D4Sign mostra; não coletamos isso hoje e não é
  necessário para o objetivo de "ter a cara do nosso sistema").
- Qualquer mudança no fluxo de assinatura em si (posições de campo, ordem de
  assinatura, OTP, etc.) — só o que é adicionado ao PDF final e a nova página
  de verificação.

## Código público de verificação

Cada documento assinado (envelope ou avulso) ganha um `verification_code`
(UUID v4), gerado uma única vez na criação do registro, nunca regenerado
(estável mesmo se o envelope for relacrado via "Reprocessar lacre"). É esse
código que aparece no rodapé, no QR code e na URL pública — nunca o ID
incremental do banco.

- `envelopes`: nova coluna `verification_code` (uuid, unique, not null),
  gerada em `EnvelopeService::create()`.
- Nova tabela `signed_documents`, exclusiva para a assinatura avulsa:
  - `id`, `user_id` (FK), `certificate_id` (FK nullable — pode ter sido
    removido depois), `verification_code` (uuid, unique), `title` (string,
    nome original do arquivo ou "Documento avulso"), `sha256` (string(64)),
    `signed_at` (timestamp).
  - Criada em `SignDocumentApiController::store()`, logo após assinar com
    sucesso.
  - Sem relação com envelopes; é só a fonte de dados da página pública de
    verificação para esse fluxo.

## 1. Rodapé de rastreabilidade em todas as páginas

Uma faixa fina, carimbada na margem inferior de cada página do PDF final
(sobrepõe a margem branca existente, como no D4Sign):

```
Código do documento {verification_code} · assinado eletronicamente · Verifique em {APP_URL}/verificar/{verification_code}
```

- Fonte 7-8pt, cinza (`#999`), não interfere na leitura do conteúdo original.
- Centralizada ou alinhada à esquerda, ocupando uma linha só.

**Envelopes** — `EnvelopePdfComposer::compose()`: novo método privado
`stampFooter(Fpdi $pdf, string $code)`, chamado logo após `useTemplate($tpl)`
em cada página do original (não nas páginas de evidências, que já têm seu
próprio rodapé). Usa `writeHTMLCell` com posição absoluta próxima ao rodapé
da página (calculada a partir de `getPageHeight()`).

**Assinatura avulsa** — `SignPdfService::stampPage()`: mesma lógica, aplicada
a todas as páginas, chamada antes de `setSignature()` em `sign()` (stampar
antes de assinar digitalmente — nunca depois, para não invalidar a
assinatura). O código é passado de fora (gerado pelo controller ao criar o
registro em `signed_documents`) via novo método público
`SignPdfService::setVerificationFooter(string $code)`.

## 2. Página de lacre redesenhada (só envelopes)

Reescreve `EvidenceReportGenerator::generate()`. Estrutura por página, em
pontos PDF, usando `writeHTML` + primitivas do TCPDF (`Circle`, `Line`,
`Image`) e `write2DBarcode` (nativo do TCPDF, sem dependência nova):

- **Moldura**: borda grossa (~22pt) em padrão cross-hatch — duas camadas de
  `repeating-linear-gradient` diagonais cruzadas (45° e -45°), em dois tons
  derivados de `primary_color` (o próprio tom e uma variação ~25% mais clara,
  calculada em PHP a partir do hex). Sem uso de `accent_color`.
- **Textura de fundo**: padrão de pontos sutil (opacidade ~5%) atrás do
  conteúdo, dentro da moldura.
- **Cabeçalho**: logo circular (iniciais da empresa, ou `logo_url` do
  settings se configurado) + nome da empresa (`settings.company_name`) +
  linha "Datas e horários baseados em Brasília, Brasil" + timestamp de
  geração do certificado. QR code grande no canto superior direito, gerado
  com `write2DBarcode($url, 'QRCODE,H', ...)` apontando para
  `{APP_URL}/verificar/{verification_code}`.
- **Bloco de dados do documento**: título do envelope + código de
  verificação, dentro de uma caixa com fundo levemente cinza e borda lateral
  esquerda em `primary_color` (estilo "card de destaque").
- **Lista de assinaturas**: para cada signatário, uma linha com:
  - à esquerda: círculo de check preenchido (desenhado com `Circle` +
    `Line`/`Polygon` do TCPDF, cor `primary_color`) + nome, e-mail, "Assinou
    em {data}";
  - à direita: preview da imagem de assinatura manuscrita
    (`signer.signature_image_path`, já existente), a mesma imagem usada no
    carimbo do documento.
  - Linha divisória fina entre signatários.
- **Trilha de auditoria**: mantida como está hoje (tabela de eventos), abaixo
  da lista de assinaturas.
- **Rodapé da página**: hash SHA-256 completo do documento final + texto
  "Este relatório pertence única e exclusivamente ao documento do hash acima."

Sem alteração no `EnvelopePdfComposer` quanto à composição das páginas de
evidência (continua importando as páginas geradas pelo
`EvidenceReportGenerator` como template, ao final do documento).

## 3. Página pública de verificação

- Rota `GET /verificar/{code}`, pública, `throttle:30,1`, fora de qualquer
  grupo de middleware `auth`.
- Novo `PublicVerificationController::show(string $code)`:
  - Busca `Envelope::where('verification_code', $code)->first()`; se não
    achar, busca `SignedDocument::where('verification_code', $code)->first()`.
  - 404 se nenhum dos dois encontrar.
- View pública (usa o branding do settings, layout simples tipo
  `layouts.guest`), mostrando:
  - Título do documento, código de verificação, hash SHA-256, data de
    criação/assinatura.
  - Se for envelope: status (`completed`/`sent`/etc.) e lista de
    signatários com nome e data de assinatura (sem e-mail, CPF, IP ou
    user-agent — dados sensíveis não vão para a página pública).
  - Se for assinatura avulsa: nome do certificado usado (`description`) e
    data de assinatura.
  - **Não** expõe o PDF nem link de download — só confirma metadados de
    integridade/autenticidade.

## Testes

- `EnvelopePdfComposerTest` (ou equivalente): rodapé presente em todas as
  páginas do original, ausente nas páginas de evidência (que têm o seu
  próprio).
- `SignPdfServiceTest`: rodapé presente antes da assinatura digital (ordem
  importa — stampar antes de `setSignature()`), assinatura permanece válida
  após o carimbo.
- `EvidenceReportGeneratorTest`: página de lacre contém QR code (via
  presença de imagem/objeto gerado), hash completo, preview de assinatura por
  signatário.
- `PublicVerificationControllerTest`: 200 com dados corretos para envelope e
  para `signed_documents`; 404 para código inexistente; nenhum dado sensível
  (CPF/IP/user-agent) no corpo da resposta.
- Migration/model tests para `verification_code` único em `envelopes` e para
  a tabela `signed_documents`.
