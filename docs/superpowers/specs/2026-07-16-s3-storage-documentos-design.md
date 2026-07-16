# Armazenamento de documentos em S3 (Hetzner Object Storage)

Data: 2026-07-16

## Contexto

Hoje todos os arquivos de documento (PDF original de envelope, PNG de assinatura de
signatário, PDF final lacrado, PDF de assinatura avulsa) são salvos no disk `local`
(`storage/app/private/`) da própria VPS. Isso não escala bem com múltiplos clientes
(cada acesso de login é um cliente diferente da plataforma) e mistura dados de
diferentes clientes no mesmo disco da aplicação.

A Hetzner (que já hospeda a VPS) também oferece Object Storage S3-compatible. O
usuário já tem um bucket criado e credenciais em mãos. `config/filesystems.php` já
tem um bloco `s3` pronto (endpoint customizado, path-style), mas não instalado nem
usado em nenhum lugar do código ainda.

**Reset de dados**: antes deste trabalho, os dados de produção existentes
(`certificates`, `envelopes`, `envelope_signers`, `envelope_fields`, `envelope_events`)
foram truncados na VPS a pedido do usuário (backup em `/root/backups/` na VPS antes do
truncate). Não há necessidade de suportar registros "antigos" em disco local — todo
registro criado a partir de agora já usa o esquema novo.

## Escopo

### Migra para S3 (novo disk `documents`)
- PDF original do envelope (`envelopes.original_pdf_path`)
- PNG de assinatura de cada signatário (`envelope_signers.signature_image_path`)
- PDF final lacrado do envelope (`envelopes.final_pdf_path`)
- PDF de assinatura avulsa (`sign-document`, hoje em `signed/{user_id}/doc_*.pdf`)

### Continua no disk `local` (disco da VPS)
- PFX do certificado (`certificates.pfx_path`) — dado sensível, senha do certificado
- Imagem de assinatura/logo do certificado (`certificates.sign_image_path`,
  `logo_image_path`)
- Disk `public` (branding do white-label — logo/favicon)

### Fora de escopo
- Migração de dados históricos (não existem mais, foram resetados)
- Mudança de driver do disk `public`

## Particionamento de paths

Paths no disk `documents` são prefixados por dono (`user_id`), já que cada cliente
(cada login da plataforma) é o "dono" de seus documentos:

```
users/{user_id}/envelopes/{envelope_id}/original.pdf
users/{user_id}/envelopes/{envelope_id}/signatures/{signer_id}.png
users/{user_id}/envelopes/{envelope_id}/final.pdf
users/{user_id}/signed/doc_{hex}.pdf
```

## Configuração

Novo disk nomeado `documents` em `config/filesystems.php`, driver `s3`, lendo de
variáveis de ambiente dedicadas (não reaproveita `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`
genéricos, que hoje já existem vazios no `.env` e poderiam ser confundidos com uso
futuro de outro serviço AWS):

```env
DOCUMENTS_S3_ACCESS_KEY_ID=
DOCUMENTS_S3_SECRET_ACCESS_KEY=
DOCUMENTS_S3_REGION=
DOCUMENTS_S3_BUCKET=
DOCUMENTS_S3_ENDPOINT=
DOCUMENTS_S3_USE_PATH_STYLE_ENDPOINT=true
```

O usuário preenche os valores reais localmente e na VPS — não fazem parte deste
trabalho de código (placeholders vazios no `.env`, comentário explicando o formato
esperado do endpoint Hetzner: `https://<region>.your-objectstorage.com`).

Dependência nova: `composer require league/flysystem-aws-s3-v3` (não está instalado
hoje, só sugerido opcionalmente pelo `league/flysystem`).

## Arquivos que dependem de path absoluto (`$disk->path()`)

`PdfSignerService`, `EnvelopePdfComposer`, `EvidenceReportGenerator` e `SealComposer`
(GD) hoje operam sobre caminhos absolutos no disco local — usados por PyHanko CLI
(processo externo), TCPDF/FPDI e GD, nenhum dos quais aceita stream remoto. Isso
**não muda**: essas classes continuam recebendo/produzindo paths absolutos locais.

O que muda é a camada acima delas (`EnvelopeService`, `SealEnvelopeJob`,
`SignDocumentController`), que passa a:
1. Baixar do disk `documents` (S3) para um arquivo temporário local
   (`tempnam(sys_get_temp_dir(), ...)`, mesmo padrão já usado em `EnvelopePdfComposer::compose()`
   e `PdfSignerService::tempPath()`)
2. Chamar os serviços de PDF normalmente, com paths locais
3. Subir (`put`) o resultado final para o disk `documents`
4. Apagar o(s) arquivo(s) temporário(s) local(is) (`@unlink`, mesmo padrão já usado
   no `finally` do `SealEnvelopeJob`)

### Pontos de mudança específicos

- **`EnvelopeService::create()`** — `$pdf->storeAs(..., 'local')` vira `storeAs(..., 'documents')`
- **`EnvelopeService::sign()`** — `Storage::disk('local')->put($relative, ...)` do PNG de
  assinatura vira `Storage::disk('documents')->put(...)`
- **`EnvelopePdfComposer::compose()`** — antes de montar o PDF com FPDI, baixa
  `original_pdf_path` e cada `signature_image_path` do disk `documents` para arquivos
  temporários locais; usa esses paths temporários nas chamadas `setSourceFile()`/`Image()`
- **`SealEnvelopeJob::handle()`** — o `PdfSignerService::signExisting()` continua operando
  local (recebe o PDF composto, que já está em tmp local vindo do composer); ao final,
  em vez de `$disk->move($relative, $final)` dentro do disk local, faz upload do resultado
  para `documents` no path final e apaga o temporário
- **`SignDocumentController`** — fluxo de assinatura avulsa: `PdfSignerService` gera o PDF
  assinado em local (`signed/{user_id}/doc_*.pdf` dentro de um diretório tmp, não mais
  `storage/app/private/signed`); controller sobe o resultado para `documents` no path
  `users/{user_id}/signed/doc_{hex}.pdf` e apaga o local
- **`EvidenceReportGenerator`** — já gera em `tempnam()` (path local), sem mudança; apenas
  os assets que ele lê (se algum vier de documento já em S3) precisam ser baixados antes

## Downloads e preview

Rotas que hoje fazem `Storage::disk('local')->download(...)` ou leitura manual +
`response()` passam a redirecionar para uma URL assinada temporária do S3:

```php
$url = Storage::disk('documents')->temporaryUrl($path, now()->addMinutes(5));
return redirect($url);
```

Autorização continua igual — o controller checa auth do cliente (dono do envelope) ou
o token do signatário público **antes** de gerar a URL; só o que acontece depois da
checagem muda (redirect assinado em vez de stream via Laravel).

Pontos afetados:

- `EnvelopeController::download()` — força download; `ResponseContentDisposition:
  attachment; filename="{title} (assinado).pdf"` na `temporaryUrl()`, preservando o
  nome amigável que o download tem hoje
- `SignDocumentController` (download de assinatura avulsa) — mesmo padrão, `attachment`
- `SignEnvelopeController::document()` — **exibição inline** (o wizard de assinatura
  embute o PDF via PDF.js na própria página, não é um download). Aqui o redirect para
  a URL assinada precisa levar `ResponseContentDisposition: inline; filename="documento.pdf"`
  e `ResponseContentType: application/pdf` como parâmetros de `temporaryUrl()`, para o
  navegador exibir em vez de baixar — sem isso, o comportamento inline atual quebraria

Em todos os casos, `temporaryUrl($path, $expiration, $options)` aceita esse terceiro
array de opções (repassado como query params SigV4 `response-content-disposition` /
`response-content-type`), suportado pelo `league/flysystem-aws-s3-v3`.

Hetzner Object Storage confirmadamente suporta presigned URLs SigV4 (`GetObject`) sem
restrição relevante — só atenção ao `use_path_style_endpoint` se a assinatura vier
com erro de host/assinatura ao testar.

## Testes

- Testes de feature existentes que usam `Storage::fake('local')` precisam trocar para
  `Storage::fake('documents')` nos fluxos de envelope/assinatura avulsa
- Não é viável (nem desejável) testar contra o bucket real da Hetzner em CI — os testes
  usam `Storage::fake()`, que já simula o disk sem I/O de rede

## Fora de escopo (decidido explicitamente)

- Não há suporte a servir arquivos "antigos" do disco local — reset já feito
- Não há coluna nova em modelo para indicar o disk — sempre `documents` para
  documentos, sempre `local` para certificado
- Bucket já existe, criação/configuração do bucket em si não faz parte deste trabalho
