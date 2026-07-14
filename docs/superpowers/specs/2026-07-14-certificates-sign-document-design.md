# Módulos: Certificados Digitais + Assinar Documento (área do cliente)

**Data:** 2026-07-14
**Origem:** porte do módulo de assinatura digital do ERP (`erp.fitlikeaglove.com.br`) —
`CertificadoForm`, `AssinarDocumentoForm`, `AssinadorPdfService`, `PyHankoSigner`, `SignPdfService`.
Referências: `.claude/memory/architecture_assinatura_digital_limites.md` e
`docs/financeiro/assinatura-digital.md` do ERP.

**Decisões do usuário:** área do cliente · portar os dois botões (assinar enviado + gerar e assinar) ·
nomenclatura em inglês no código/banco (UI em português).

## Escopo

1. CRUD de certificados digitais (PFX + senha + imagens de logo/assinatura), cada cliente gerencia os seus.
2. Tela de assinatura de PDF com preview PDF.js e marcador arrastável, rubricas e carimbo TSA,
   assinando com pyHanko (preferencial, PAdES incremental) ou fallback TCPDF.

## Dependências

- Composer: `tecnickcom/tcpdf`, `setasign/fpdi`.
- Binário externo opcional: pyHanko CLI (`pip install pyHanko pyHanko-cli "pyHanko[image-support]"`),
  detectado em runtime; override por `PYHANKO_BIN` (exposto em `config/services.php` → `services.pyhanko.bin`).
- Ghostscript opcional (normalização de PDF 1.5+ para o FPDI free).

## Banco

Tabela `certificates`:

| Campo | Tipo | Notas |
|---|---|---|
| user_id | FK users cascade | dono do certificado |
| description | string 250 | obrigatório |
| reference | string 15 nullable | |
| pfx_path | string | disk `local` (privado), `certificates/{id}/certificate.pfx` |
| password | text | cast `encrypted` (melhoria sobre o ERP, que guarda em texto puro) |
| sign_image_path | string nullable | imagem da assinatura visual |
| logo_image_path | string nullable | logo |
| expires_at | date nullable | `validTo` do X.509, extraído no upload |
| timestamps | | |

No upload o PFX é aberto com `openssl_pkcs12_read` — valida a senha na hora do cadastro
e extrai `expires_at`. Senha errada = erro de validação, não grava.

## Serviços (`app/Services/Pdf/`)

Porte fiel preservando os limites documentados no memory do ERP:

- **`SignPdfService`** — motor TCPDF+FPDI: `createPdf()` (HTML → PDF), `stamp()` (imagem principal +
  rubricas; reescreve via FPDI, NUNCA sobre PDF já assinado), `sign()` (fallback TCPDF, assinatura única),
  `save()` (+ TSA sidecar `.tsr` — `applyTSA` do TCPDF é stub). PEM cru direto no `setSignature`
  (prefixo `data://` quebra `openssl_pkcs7_sign` silenciosamente). Coordenadas chegam em pontos PDF
  topo-esquerdo e são convertidas por `getScaleFactor()` (PDF_UNIT = mm). Normalização Ghostscript
  para PDFs incompatíveis com FPDI free.
- **`PyHankoSigner`** — motor preferencial: PAdES B-B/B-T incremental via CLI, multi-assinatura,
  `--use-pades` (signingCertificateV2), TSA DigiCert embutido. Conversão topo-esquerdo →
  base-esquerda (`y1 = alturaPagina − y − h`). Detecção do binário: config/env → caminhos fixos →
  which/where → glob Windows; **nunca cacheia resultado negativo em static**. Senha do PFX validada
  com openssl antes de chamar a CLI.
- **`PdfSignerService`** (ex-`AssinadorPdfService`) — fachada: `fromCertificate(Certificate)`
  (única fonte; casca não tem Apikeys/NFCe), `signExisting()`, `createAndSign()`,
  `hasSignatureImage()`. PDF já assinado (`/ByteRange`) pula rubricas e recebe assinatura incremental.
  Saída em `signed/{user_id}/doc_<random>.pdf` no disk `local`.

## Rotas e controllers (grupo `auth`, escopo por dono)

- `Client\CertificateController` — resource `certificates` (index, create, store, edit, update, destroy)
  + `GET certificates/{certificate}/image/{type}` (serve logo/assinatura privadas para o form).
  Autorização: `user_id = auth()->id()` (403 caso contrário).
- `Client\SignDocumentController`:
  - `GET /sign-document` — formulário
  - `POST /sign-document/sign` — assina PDF enviado (multipart: pdf, certificate_id, sign_x/y/w/h/page, initial_all_pages, use_tsa)
  - `POST /sign-document/generate` — gera PDF do template genérico (`resources/views/pdf/sample-document.blade.php`) e assina
  - `GET /sign-document/download/{filename}` — download da saída assinada (só arquivos do próprio usuário)

## UI (padrão dark do casca, textos em português)

- Nav do cliente ganha "Certificados" e "Assinar Documento".
- `client/certificates/index` — tabela com descrição, referência, validade (badge verde `dd/mm/aaaa (N dias)` /
  vermelho expirado, estilo do print do ERP), imagens ✓/—, ações editar/excluir.
- `client/certificates/create|edit` — descrição, referência, PFX, senha, previews de logo/assinatura.
- `client/sign-document/index` — certificado (select), PDF (upload), checkboxes rubricar/TSA,
  preview PDF.js (CDN cdnjs, como no ERP) com navegação de páginas e marcador arrastável.
  Melhoria sobre o ERP: preview lê o arquivo local (`URL.createObjectURL`), sem round-trip.
  Coordenadas trafegam em pontos PDF topo-esquerdo nos hidden `sign_x/y/w/h/page`.
- Pós-assinatura: flash de sucesso com link de download; aviso quando o certificado não tem
  imagem de assinatura (PDF sai assinado sem carimbo visual).

## Erros

- PFX ausente/senha errada → erro de validação no form.
- Certificado expirado → bloqueia assinatura com mensagem clara.
- pyHanko indisponível → fallback TCPDF transparente; TSA vira sidecar.
- Falha da CLI pyHanko → exceção com últimas linhas da saída (sem senha no comando; senha via passfile temporário).

## Logs

`AccessLogService`: `certificate_created`, `certificate_deleted`, `document_signed`
(meta: certificate_id, engine usado, tsa).

## Testes

- Feature: CRUD completo com `Storage::fake` + PFX real gerado com `openssl_pkcs12_export`;
  senha errada rejeitada; escopo por dono (403 para certificado alheio); telas exigem auth.
- Unit: `SignPdfService` round-trip (criar PDF → assinar fallback → arquivo com `/ByteRange`);
  clamp de posição.

## Fora de escopo

- Multi-tenancy, fila/queue para assinatura, API pública de assinatura, conformidade ICP-Brasil AD-RB
  (limite documentado: nenhuma ferramenta gratuita emite política DOC-ICP-15).
