# Armazenamento de Documentos em S3 — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrar o armazenamento de documentos (PDF original/final de envelope, PNG de assinatura de signatário, PDF de assinatura avulsa) do disco local da VPS para um novo disk S3 (`documents`), prefixado por `users/{user_id}/...`, mantendo PFX e imagens de certificado no disco local.

**Architecture:** Novo disk nomeado `documents` (driver `s3`) em `config/filesystems.php`, com variáveis de ambiente próprias. Os pontos que hoje escrevem/leem diretamente do disk `local` para documentos passam a usar `documents`. Os serviços que dependem de `$disk->path()` (PyHanko CLI, TCPDF/FPDI, GD) continuam operando sobre arquivos locais temporários — a camada que os chama baixa do S3 antes e sobe o resultado depois. Downloads/preview passam de stream via Laravel para redirect com `temporaryUrl()` assinada.

**Tech Stack:** Laravel 13 Filesystem (`league/flysystem-aws-s3-v3`), Hetzner Object Storage (S3-compatible).

## Global Constraints

- UI em pt-BR; código em inglês
- PFX do certificado (`certificates.pfx_path`) e imagens de assinatura/logo do certificado (`sign_image_path`, `logo_image_path`) **continuam no disk `local`** — não fazem parte desta migração
- Disk `public` (branding) não muda
- Paths no disk `documents` são prefixados por `users/{user_id}/...`
- Sem suporte a registros antigos em disco local — dados de produção já foram resetados (truncate + limpeza de storage já executados na VPS antes deste plano)
- Testes rodam em sqlite `:memory:` via `php artisan test`
- PHP do Laragon: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`

---

### Task 1: Dependência e configuração do disk `documents`

**Files:**
- Modify: `composer.json` (via `composer require`)
- Modify: `config/filesystems.php`
- Modify: `.env` (placeholders)

**Interfaces:**
- Produces: disk nomeado `documents` disponível via `Storage::disk('documents')`, driver `s3`

- [ ] **Step 1: Instalar o adapter S3 do Flysystem**

Run: `cd c:\projetos\casca && composer require league/flysystem-aws-s3-v3`
Expected: pacote adicionado a `composer.json`/`composer.lock`, sem erros

- [ ] **Step 2: Adicionar o disk `documents` em `config/filesystems.php`**

Editar `config/filesystems.php`, adicionando um novo disk logo após o bloco `'s3'` existente (linha 61, antes do `],` de fechamento de `'disks'`):

```php
        'documents' => [
            'driver' => 's3',
            'key' => env('DOCUMENTS_S3_ACCESS_KEY_ID'),
            'secret' => env('DOCUMENTS_S3_SECRET_ACCESS_KEY'),
            'region' => env('DOCUMENTS_S3_REGION'),
            'bucket' => env('DOCUMENTS_S3_BUCKET'),
            'endpoint' => env('DOCUMENTS_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('DOCUMENTS_S3_USE_PATH_STYLE_ENDPOINT', true),
            'throw' => false,
            'report' => false,
        ],
```

- [ ] **Step 3: Adicionar placeholders no `.env`**

Adicionar ao final de `.env` (após o bloco `AWS_*` existente, linha 73):

```env

# Hetzner Object Storage — documentos (envelopes, assinaturas avulsas)
DOCUMENTS_S3_ACCESS_KEY_ID=
DOCUMENTS_S3_SECRET_ACCESS_KEY=
DOCUMENTS_S3_REGION=
DOCUMENTS_S3_BUCKET=
DOCUMENTS_S3_ENDPOINT=
DOCUMENTS_S3_USE_PATH_STYLE_ENDPOINT=true
```

- [ ] **Step 4: Confirmar que a aplicação sobe sem erro com o disk configurado (mas vazio)**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan config:clear`
Expected: sem erro

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan tinker --execute="echo get_class(Illuminate\Support\Facades\Storage::disk('documents')->getAdapter());"`
Expected: imprime a classe do adapter S3 (`League\Flysystem\AwsS3V3\AwsS3V3Adapter`), sem exception — confirma que o disk foi registrado corretamente mesmo com credenciais vazias (só falha ao tentar operação real de rede)

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock config/filesystems.php .env
git commit -m "feat: adicionar disk S3 dedicado 'documents' para armazenamento de PDFs"
```

---

### Task 2: `EnvelopeService::create()` e `sign()` gravam no disk `documents`

**Files:**
- Modify: `app/Services/Envelope/EnvelopeService.php:41,154`
- Test: `tests/Feature/EnvelopeServiceCreateTest.php`, `tests/Feature/EnvelopeServiceSignTest.php`

**Interfaces:**
- Consumes: disk `documents` (Task 1)
- Produces: sem mudança de assinatura pública — `create()` e `sign()` continuam retornando/atualizando os mesmos campos de modelo, só o disk de gravação muda

- [ ] **Step 1: Atualizar os testes para fake o disk `documents` e checar paths lá**

Em `tests/Feature/EnvelopeServiceCreateTest.php`, trocar `Storage::fake('local')` por
`Storage::fake('documents')` nas 4 ocorrências (linhas 46, 63, 72, 86), e trocar a
asserção da linha 53:

```php
        Storage::disk('documents')->assertExists($envelope->original_pdf_path);
```

Em `tests/Feature/EnvelopeServiceSignTest.php`, trocar `Storage::fake('local')` por
`Storage::fake('documents')` nas 2 ocorrências (linhas 72, 96), e a asserção da linha 88:

```php
        Storage::disk('documents')->assertExists($a->signature_image_path);
```

- [ ] **Step 2: Rodar os testes para confirmar que falham (ainda grava em `local`)**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/EnvelopeServiceCreateTest.php tests/Feature/EnvelopeServiceSignTest.php`
Expected: FAIL — os arquivos não existem no disk `documents` fake (foram gravados no `local` real, que não está fake)

- [ ] **Step 3: Trocar o disk em `EnvelopeService::create()`**

Em `app/Services/Envelope/EnvelopeService.php:41`:

```php
            $path = $pdf->storeAs("envelopes/{$envelope->id}", 'original.pdf', 'local');
```

vira:

```php
            $path = $pdf->storeAs("users/{$user->id}/envelopes/{$envelope->id}", 'original.pdf', 'documents');
```

- [ ] **Step 4: Trocar o disk em `EnvelopeService::sign()`**

Em `app/Services/Envelope/EnvelopeService.php:150-155`:

```php
    public function sign(EnvelopeSigner $signer, array $data, ?string $ip, ?string $userAgent): void
    {
        $temp = SignatureImage::storeDataUrl($data['signature']);
        $relative = "envelopes/{$signer->envelope_id}/signatures/{$signer->id}.png";
        Storage::disk('local')->put($relative, file_get_contents($temp));
        @unlink($temp);
```

vira:

```php
    public function sign(EnvelopeSigner $signer, array $data, ?string $ip, ?string $userAgent): void
    {
        $temp = SignatureImage::storeDataUrl($data['signature']);
        $relative = "users/{$signer->envelope->user_id}/envelopes/{$signer->envelope_id}/signatures/{$signer->id}.png";
        Storage::disk('documents')->put($relative, file_get_contents($temp));
        @unlink($temp);
```

- [ ] **Step 5: Rodar os testes para confirmar que passam**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/EnvelopeServiceCreateTest.php tests/Feature/EnvelopeServiceSignTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Envelope/EnvelopeService.php tests/Feature/EnvelopeServiceCreateTest.php tests/Feature/EnvelopeServiceSignTest.php
git commit -m "feat: EnvelopeService grava PDF original e assinaturas no disk documents (S3)"
```

---

### Task 3: `EnvelopePdfComposer` baixa do S3 para tmp antes de compor

**Files:**
- Modify: `app/Services/Envelope/EnvelopePdfComposer.php`
- Test: `tests/Unit/EnvelopePdfComposerTest.php`

**Interfaces:**
- Consumes: disk `documents` (Task 1); `envelope->original_pdf_path`, `signer->signature_image_path` agora residem lá
- Produces: `EnvelopePdfComposer::compose(Envelope, string $evidencePdfPath): array{path: string, pages: int}` — assinatura inalterada, continua devolvendo path local temporário

- [ ] **Step 1: Atualizar o teste para fake o disk `documents`**

Em `tests/Unit/EnvelopePdfComposerTest.php`, trocar `Storage::fake('local')` (linha 42)
por `Storage::fake('documents')`, e as duas chamadas `Storage::disk('local')->put(...)`
(linhas 45, 49) por `Storage::disk('documents')->put(...)`.

- [ ] **Step 2: Rodar o teste para confirmar que falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/EnvelopePdfComposerTest.php`
Expected: FAIL — `EnvelopePdfComposer` ainda lê do disk `local`, que está vazio no teste (só `documents` foi populado)

- [ ] **Step 3: Reescrever `EnvelopePdfComposer::compose()` para baixar do S3 antes de usar `Fpdi`**

Substituir o conteúdo de `app/Services/Envelope/EnvelopePdfComposer.php`:

```php
<?php

namespace App\Services\Envelope;

use App\Models\Envelope;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Monta o PDF final do envelope (ANTES da assinatura digital):
 * original + carimbos das assinaturas nas posições marcadas + páginas de evidências.
 *
 * Unidade pt: envelope_fields já está em pontos PDF topo-esquerdo, aplicação direta.
 * NUNCA usar sobre PDF já assinado digitalmente (reescreve o documento).
 */
class EnvelopePdfComposer
{
    /** Arquivos temporários locais baixados do S3, para apagar ao final. */
    private array $downloadedTemps = [];

    /** @return array{path: string, pages: int} */
    public function compose(Envelope $envelope, string $evidencePdfPath): array
    {
        $disk = Storage::disk('documents');

        try {
            $pdf = new Fpdi('P', 'pt');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetAutoPageBreak(false);

            // 1. Páginas do original, carimbando as assinaturas de cada uma
            $fieldsByPage = $this->fieldsByPage($envelope);
            $originalLocal = $this->downloadToTemp($disk, $envelope->original_pdf_path);
            $pageCount = $pdf->setSourceFile($originalLocal);

            for ($page = 1; $page <= $pageCount; $page++) {
                $tpl = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);

                foreach ($fieldsByPage[$page] ?? [] as $field) {
                    $signatureLocal = $this->downloadToTemp($disk, $field->signer->signature_image_path);
                    $pdf->Image($signatureLocal, $field->x, $field->y, $field->w, $field->h, 'PNG');
                }
            }

            // 2. Páginas de evidências ao final
            $evidenceCount = $pdf->setSourceFile($evidencePdfPath);
            for ($page = 1; $page <= $evidenceCount; $page++) {
                $tpl = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }

            $path = tempnam(sys_get_temp_dir(), 'composed_').'.pdf';
            $pdf->Output($path, 'F');

            return ['path' => $path, 'pages' => $pageCount + $evidenceCount];
        } finally {
            foreach ($this->downloadedTemps as $temp) {
                @unlink($temp);
            }
            $this->downloadedTemps = [];
        }
    }

    /** Baixa um arquivo do disk documents para um temporário local; TCPDF/FPDI exigem path real. */
    private function downloadToTemp($disk, string $path): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'dl_').'_'.basename($path);
        file_put_contents($temp, $disk->get($path));
        $this->downloadedTemps[] = $temp;

        return $temp;
    }

    /** @return array<int, list<\App\Models\EnvelopeField>> */
    private function fieldsByPage(Envelope $envelope): array
    {
        $grouped = [];

        foreach ($envelope->signers as $signer) {
            if (! $signer->signature_image_path) {
                continue;
            }
            foreach ($signer->fields as $field) {
                $field->setRelation('signer', $signer);
                $grouped[$field->page][] = $field;
            }
        }

        return $grouped;
    }
}
```

- [ ] **Step 4: Rodar o teste para confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/EnvelopePdfComposerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Envelope/EnvelopePdfComposer.php tests/Unit/EnvelopePdfComposerTest.php
git commit -m "feat: EnvelopePdfComposer baixa original e assinaturas do S3 para tmp antes de compor"
```

---

### Task 4: `EvidenceReportGenerator` lê imagem de assinatura do S3

**Files:**
- Modify: `app/Services/Envelope/EvidenceReportGenerator.php:43-47`
- Test: novo teste unitário `tests/Unit/EvidenceReportGeneratorTest.php`

**Interfaces:**
- Consumes: disk `documents` (Task 1); `signer->signature_image_path`
- Produces: `EvidenceReportGenerator::generate(Envelope): string` — assinatura inalterada, path local temporário

- [ ] **Step 1: Escrever teste unitário (falhando) confirmando que lê do disk `documents`**

Criar `tests/Unit/EvidenceReportGeneratorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Envelope;
use App\Models\EnvelopeSigner;
use App\Services\Envelope\EvidenceReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidenceReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function signaturePng(): string
    {
        $img = imagecreatetruecolor(120, 40);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_generates_report_with_signature_image_from_documents_disk(): void
    {
        Storage::fake('documents');

        $envelope = Envelope::factory()->create();
        $signer = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'signed',
            'signed_at' => now(),
            'cpf' => '123.456.789-00',
        ]);

        $path = "users/{$envelope->user_id}/envelopes/{$envelope->id}/signatures/{$signer->id}.png";
        Storage::disk('documents')->put($path, $this->signaturePng());
        $signer->update(['signature_image_path' => $path]);

        $result = (new EvidenceReportGenerator)->generate($envelope->fresh(['signers', 'events']));

        $this->assertFileExists($result);

        @unlink($result);
    }
}
```

- [ ] **Step 2: Rodar o teste para confirmar que falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/EvidenceReportGeneratorTest.php`
Expected: FAIL — `EvidenceReportGenerator` ainda lê do disk `local`, path não existe lá

- [ ] **Step 3: Atualizar `EvidenceReportGenerator` para baixar do S3 antes de embutir a imagem**

Em `app/Services/Envelope/EvidenceReportGenerator.php`, trocar o trecho (linhas 37-48):

```php
        foreach ($envelope->signers as $signer) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 20, 'Signatário: '.$signer->name, 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->writeHTML($this->signerSection($signer), true, false, true);

            if ($signer->signature_image_path && Storage::disk('local')->exists($signer->signature_image_path)) {
                $pdf->Image(Storage::disk('local')->path($signer->signature_image_path),
                    x: 40, w: 140, h: 0, type: 'PNG');
                $pdf->Ln(10);
            }
        }
```

por:

```php
        $tempFiles = [];

        foreach ($envelope->signers as $signer) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 20, 'Signatário: '.$signer->name, 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->writeHTML($this->signerSection($signer), true, false, true);

            $disk = Storage::disk('documents');
            if ($signer->signature_image_path && $disk->exists($signer->signature_image_path)) {
                $temp = tempnam(sys_get_temp_dir(), 'sig_').'.png';
                file_put_contents($temp, $disk->get($signer->signature_image_path));
                $tempFiles[] = $temp;

                $pdf->Image($temp, x: 40, w: 140, h: 0, type: 'PNG');
                $pdf->Ln(10);
            }
        }
```

E, logo antes do `return $path;` no fim de `generate()` (linha 58), adicionar a limpeza:

```php
        foreach ($tempFiles as $temp) {
            @unlink($temp);
        }

        return $path;
```

- [ ] **Step 4: Rodar o teste para confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Unit/EvidenceReportGeneratorTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Envelope/EvidenceReportGenerator.php tests/Unit/EvidenceReportGeneratorTest.php
git commit -m "feat: EvidenceReportGenerator baixa imagem de assinatura do S3 antes de embutir no PDF"
```

---

### Task 5: `SealEnvelopeJob` sobe o PDF final para o disk `documents`

**Files:**
- Modify: `app/Jobs/SealEnvelopeJob.php:53-70`
- Test: `tests/Feature/SealEnvelopeJobTest.php`

**Interfaces:**
- Consumes: `EnvelopePdfComposer::compose()` (Task 3, já devolve path local), `PdfSignerService::signExisting()` (inalterado, continua devolvendo path relativo dentro do disk `local` como scratch)
- Produces: `envelope.final_pdf_path` agora aponta para um path no disk `documents`

- [ ] **Step 1: Atualizar o teste para fake `documents` também, e checar o final lá**

Em `tests/Feature/SealEnvelopeJobTest.php`:

- `makeSignedEnvelope()` (linhas 57-71): trocar `Storage::disk('local')->put(...)` das
  linhas 60 e 66 por `Storage::disk('documents')->put(...)`, com paths prefixados:

```php
    private function makeSignedEnvelope(): Envelope
    {
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $originalPath = "users/{$envelope->user_id}/envelopes/{$envelope->id}/original.pdf";
        Storage::disk('documents')->put($originalPath, file_get_contents($this->makeSourcePdf()));
        $envelope->update(['original_pdf_path' => $originalPath]);

        $signer = EnvelopeSigner::factory()->for($envelope)->create([
            'status' => 'signed', 'signed_at' => now(), 'cpf' => '123.456.789-00',
        ]);
        $signaturePath = "users/{$envelope->user_id}/envelopes/{$envelope->id}/signatures/{$signer->id}.png";
        Storage::disk('documents')->put($signaturePath, $this->signaturePng());
        $signer->update(['signature_image_path' => $signaturePath]);
        $signer->fields()->create(['page' => 1, 'x' => 100, 'y' => 600, 'w' => 120, 'h' => 40]);

        return $envelope->fresh();
    }
```

- Em `test_seals_envelope_end_to_end` (linha 73-98): adicionar `Storage::fake('documents');`
  logo após `Storage::fake('local');` (linha 75), e trocar as asserções (linhas 89-92):

```php
        Storage::disk('documents')->assertExists($envelope->final_pdf_path);
        $this->assertSame(
            hash('sha256', Storage::disk('documents')->get($envelope->final_pdf_path)),
            $envelope->sha256_final
        );
```

- Em `test_failure_records_seal_failed_and_keeps_status` (linha 100-122): adicionar
  `Storage::fake('documents');` logo após `Storage::fake('local');` (linha 102).

- [ ] **Step 2: Rodar o teste para confirmar que falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/SealEnvelopeJobTest.php`
Expected: FAIL — `final_pdf_path` ainda é gravado só no disk `local`

- [ ] **Step 3: Atualizar `SealEnvelopeJob::handle()` para subir o resultado ao disk `documents`**

Em `app/Jobs/SealEnvelopeJob.php`, trocar o trecho (linhas 53-70):

```php
            $evidencePath = $evidence->generate($envelope);
            $composed = $composer->compose($envelope, $evidencePath);
            $composedPath = $composed['path'];

            // Selo visível da plataforma na última página (evidências), canto inferior direito
            $relative = PdfSignerService::fromCertificate($certificate)->signExisting(
                $composedPath,
                initialAllPages: false,
                position: ['page' => $composed['pages'], 'x' => 400, 'y' => 780, 'w' => 150, 'h' => 40],
                useTsa: false,
            );

            $disk = Storage::disk('local');
            $final = "signed/envelopes/{$envelope->id}/final.pdf";
            if ($disk->exists($final)) {
                $disk->delete($final);
            }
            $disk->move($relative, $final);

            $envelope->update([
                'final_pdf_path' => $final,
                'sha256_final' => hash('sha256', $disk->get($final)),
                'status' => 'completed',
                'completed_at' => now(),
            ]);
```

por:

```php
            $evidencePath = $evidence->generate($envelope);
            $composed = $composer->compose($envelope, $evidencePath);
            $composedPath = $composed['path'];

            // Selo visível da plataforma na última página (evidências), canto inferior direito
            // PdfSignerService grava o resultado no disk local (scratch) — usado só como
            // arquivo de trabalho, o definitivo vai para o disk documents (S3) logo abaixo.
            $relative = PdfSignerService::fromCertificate($certificate)->signExisting(
                $composedPath,
                initialAllPages: false,
                position: ['page' => $composed['pages'], 'x' => 400, 'y' => 780, 'w' => 150, 'h' => 40],
                useTsa: false,
            );

            $localDisk = Storage::disk('local');
            $documentsDisk = Storage::disk('documents');
            $final = "users/{$envelope->user_id}/envelopes/{$envelope->id}/final.pdf";

            $finalContent = $localDisk->get($relative);
            $documentsDisk->put($final, $finalContent);
            $localDisk->delete($relative);

            $envelope->update([
                'final_pdf_path' => $final,
                'sha256_final' => hash('sha256', $finalContent),
                'status' => 'completed',
                'completed_at' => now(),
            ]);
```

- [ ] **Step 4: Rodar o teste para confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/SealEnvelopeJobTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SealEnvelopeJob.php tests/Feature/SealEnvelopeJobTest.php
git commit -m "feat: SealEnvelopeJob sobe o PDF final lacrado para o disk documents (S3)"
```

---

### Task 6: Downloads de envelope via URL assinada (attachment)

**Files:**
- Modify: `app/Http/Controllers/Client/EnvelopeController.php:124-131`
- Test: `tests/Feature/EnvelopeControllerTest.php`

**Interfaces:**
- Consumes: `Storage::disk('documents')->temporaryUrl()`
- Produces: `EnvelopeController::download()` retorna redirect em vez de stream

- [ ] **Step 1: Atualizar o teste**

Em `tests/Feature/EnvelopeControllerTest.php`, no teste `test_cancel_remind_and_download`
(linha 91-...): adicionar `Storage::fake('documents');` logo após `Storage::fake('local');`
(linha 93), trocar a gravação do PDF final (linha 104):

```php
        Storage::disk('documents')->put("users/{$owner->id}/envelopes/{$envelope->id}/final.pdf", '%PDF-1.4 final');
        $envelope->update(['status' => 'completed', 'final_pdf_path' => "users/{$owner->id}/envelopes/{$envelope->id}/final.pdf"]);
        $this->actingAs($owner)->get("/envelopes/{$envelope->id}/download")->assertRedirect();
```

(troca `->assertOk()` por `->assertRedirect()` na linha 106, já que agora é um redirect
para URL assinada, não mais um stream direto)

- [ ] **Step 2: Rodar o teste para confirmar que falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/EnvelopeControllerTest.php`
Expected: FAIL — controller ainda faz `Storage::disk('local')->download()`, path não existe lá

- [ ] **Step 3: Atualizar `EnvelopeController::download()`**

Em `app/Http/Controllers/Client/EnvelopeController.php`, trocar (linhas 124-131):

```php
    public function download(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        abort_unless($envelope->status === 'completed' && $envelope->final_pdf_path, 404);
        abort_unless(Storage::disk('local')->exists($envelope->final_pdf_path), 404);

        return Storage::disk('local')->download($envelope->final_pdf_path, $envelope->title.' (assinado).pdf');
    }
```

por:

```php
    public function download(Envelope $envelope)
    {
        $this->authorizeOwner($envelope);
        abort_unless($envelope->status === 'completed' && $envelope->final_pdf_path, 404);

        $disk = Storage::disk('documents');
        abort_unless($disk->exists($envelope->final_pdf_path), 404);

        $url = $disk->temporaryUrl($envelope->final_pdf_path, now()->addMinutes(5), [
            'ResponseContentDisposition' => 'attachment; filename="'.$envelope->title.' (assinado).pdf"',
        ]);

        return redirect($url);
    }
```

- [ ] **Step 4: Rodar o teste para confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/EnvelopeControllerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Client/EnvelopeController.php tests/Feature/EnvelopeControllerTest.php
git commit -m "feat: download de envelope assinado via URL assinada do S3"
```

---

### Task 7: Preview inline do signatário público via URL assinada

**Files:**
- Modify: `app/Http/Controllers/PublicSign/SignEnvelopeController.php:28-44`
- Test: `tests/Feature/PublicSignFlowTest.php`

**Interfaces:**
- Consumes: `Storage::disk('documents')->temporaryUrl()`
- Produces: `SignEnvelopeController::document()` retorna redirect em vez de stream inline

- [ ] **Step 1: Atualizar o teste**

Em `tests/Feature/PublicSignFlowTest.php`:

- `makeSentEnvelope()` (linhas 28-35): trocar `Storage::disk('local')->put(...)` (linha 31)
  por gravação no disk `documents` com o path prefixado:

```php
    private function makeSentEnvelope(array $signerAttrs = []): EnvelopeSigner
    {
        $envelope = Envelope::factory()->create(['status' => 'sent']);
        $path = "users/{$envelope->user_id}/envelopes/{$envelope->id}/original.pdf";
        Storage::disk('documents')->put($path, '%PDF-1.4 fake');
        $envelope->update(['original_pdf_path' => $path]);

        return EnvelopeSigner::factory()->for($envelope)->create(array_merge(['status' => 'notified'], $signerAttrs));
    }
```

- Em todos os testes que chamam `Storage::fake('local')` (linhas 47, 60, 74, 90, 119,
  132, 146), adicionar `Storage::fake('documents');` na linha seguinte.

- Em `test_document_serves_original_then_final` (linha 144-157), trocar as asserções:

```php
    public function test_document_serves_original_then_final(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $signer = $this->makeSentEnvelope();

        $this->get("/sign/{$signer->token}/document")->assertRedirect();

        $finalPath = "users/{$signer->envelope->user_id}/envelopes/{$signer->envelope_id}/final.pdf";
        Storage::disk('documents')->put($finalPath, '%PDF-1.4 final');
        $signer->envelope->update(['status' => 'completed', 'final_pdf_path' => $finalPath]);

        $this->get("/sign/{$signer->token}/document")->assertRedirect();
    }
```

- [ ] **Step 2: Rodar o teste para confirmar que falha**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/PublicSignFlowTest.php`
Expected: FAIL — controller ainda lê/serve do disk `local`

- [ ] **Step 3: Atualizar `SignEnvelopeController::document()`**

Em `app/Http/Controllers/PublicSign/SignEnvelopeController.php`, trocar (linhas 28-44):

```php
    /** Serve o PDF ao signatário: original durante a coleta, final após concluído. */
    public function document(string $token)
    {
        $signer = $this->findSigner($token);
        $envelope = $signer->envelope;

        $path = $envelope->status === 'completed' && $envelope->final_pdf_path
            ? $envelope->final_pdf_path
            : $envelope->original_pdf_path;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="documento.pdf"',
        ]);
    }
```

por:

```php
    /** Serve o PDF ao signatário: original durante a coleta, final após concluído. */
    public function document(string $token)
    {
        $signer = $this->findSigner($token);
        $envelope = $signer->envelope;

        $path = $envelope->status === 'completed' && $envelope->final_pdf_path
            ? $envelope->final_pdf_path
            : $envelope->original_pdf_path;

        $disk = Storage::disk('documents');
        abort_unless($disk->exists($path), 404);

        $url = $disk->temporaryUrl($path, now()->addMinutes(5), [
            'ResponseContentType' => 'application/pdf',
            'ResponseContentDisposition' => 'inline; filename="documento.pdf"',
        ]);

        return redirect($url);
    }
```

- [ ] **Step 4: Rodar o teste para confirmar que passa**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/PublicSignFlowTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PublicSign/SignEnvelopeController.php tests/Feature/PublicSignFlowTest.php
git commit -m "feat: preview de PDF ao signatario publico via URL assinada do S3"
```

---

### Task 8: Assinatura avulsa (`sign-document`) usa o disk `documents`

**Files:**
- Modify: `app/Services/Pdf/PdfSignerService.php` (novo método para mover resultado)
- Modify: `app/Http/Controllers/Client/SignDocumentController.php:18-31,94-102`
- Test: `tests/Feature/SignDocumentTest.php`

**Interfaces:**
- Consumes: `PdfSignerService::signExisting()`/`createAndSign()` (inalterados, continuam devolvendo path relativo no disk `local` como scratch)
- Produces: `SignDocumentController::index()`/`download()` passam a listar/servir do disk `documents`, prefixados por `users/{user_id}/signed/...`

- [ ] **Step 1: Atualizar o teste para fake `documents` e checar/servir de lá**

Em `tests/Feature/SignDocumentTest.php`, em cada teste que hoje faz
`Storage::fake('local');` (linhas 65, 78, 108, 126, 151, 166, 186, 202, 216),
adicionar `Storage::fake('documents');` na linha seguinte (o certificado continua
usando o disk `local`, então ambos precisam estar fake).

Trocar as asserções de conteúdo assinado para o disk `documents`:

- `test_signs_uploaded_pdf_end_to_end` (linhas 96-101):

```php
        $filename = session('signed_file');
        $relative = 'users/'.$client->id.'/signed/'.$filename;
        Storage::disk('documents')->assertExists($relative);

        $content = Storage::disk('documents')->get($relative);
        $this->assertStringContainsString('/ByteRange', $content, 'PDF de saída deve conter assinatura digital');
```

- `test_generates_document_from_template_and_signs` (linhas 120-121):

```php
        $relative = 'users/'.$client->id.'/signed/'.session('signed_file');
        $this->assertStringContainsString('/ByteRange', Storage::disk('documents')->get($relative));
```

- `test_signs_with_drawn_signature` (linhas 145-146) e `test_signs_with_authentication_seal`
  (linhas 180-181): mesmo padrão, trocar `'signed/'.$client->id.'/'` por
  `'users/'.$client->id.'/signed/'` e `Storage::disk('local')` por `Storage::disk('documents')`.

- `test_download_rejects_foreign_and_malformed_filenames` (linhas 214-236): trocar a
  gravação de teste (linha 220):

```php
        Storage::disk('documents')->put('users/'.$other->id.'/signed/doc_abc123.pdf', '%PDF-fake');
```

  e trocar a última asserção (linha 233-235) de `->assertOk()` para `->assertRedirect()`
  (download agora é redirect assinado):

```php
        $this->actingAs($other)
            ->get(route('sign-document.download', 'doc_abc123.pdf'))
            ->assertRedirect();
```

- [ ] **Step 2: Rodar os testes para confirmar que falham**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/SignDocumentTest.php`
Expected: FAIL

- [ ] **Step 3: Adicionar método `moveToDisk()` no `PdfSignerService`**

Em `app/Services/Pdf/PdfSignerService.php`, adicionar um novo método público, logo
após `engine()` (linha 95):

```php
    /**
     * Move o resultado (path relativo no disk local, devolvido por signExisting()/
     * createAndSign()) para o disk de destino definitivo, apagando o scratch local.
     * Retorna o path relativo no disk de destino.
     */
    public function moveToDisk(string $localRelativePath, string $targetDisk, string $targetRelativePath): string
    {
        $local = Storage::disk('local');
        $content = $local->get($localRelativePath);

        Storage::disk($targetDisk)->put($targetRelativePath, $content);
        $local->delete($localRelativePath);

        return $targetRelativePath;
    }
```

- [ ] **Step 4: Atualizar `SignDocumentController` para subir o resultado ao disk `documents`**

Em `app/Http/Controllers/Client/SignDocumentController.php`, trocar o corpo de
`handleSigning()` (linhas 128-136) — a linha que grava o access log — precede a
mudança; o ponto certo é logo após `$relative = $operation($signer, $position);`
(linha 128):

```php
            $relative = $operation($signer, $position);

            $targetPath = 'users/'.auth()->id().'/signed/'.basename($relative);
            $signer->moveToDisk($relative, 'documents', $targetPath);

            $this->accessLog->log(auth()->user(), 'document_signed', [
                'certificate_id' => $certificate->id,
                'certificate_description' => $certificate->description,
                'engine' => $signer->engine(),
                'tsa' => $request->boolean('use_tsa'),
                'use_seal' => $request->boolean('use_seal'),
                'file' => basename($targetPath),
                'original_name' => $originalName,
            ]);
```

- [ ] **Step 5: Atualizar `index()` para checar existência no disk `documents`**

Em `app/Http/Controllers/Client/SignDocumentController.php:22-28`:

```php
        $signedDocuments = AccessLog::where('user_id', auth()->id())
            ->where('event', 'document_signed')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->filter(fn (AccessLog $log) => ! empty($log->meta['file'])
                && Storage::disk('local')->exists('signed/'.auth()->id().'/'.$log->meta['file']));
```

vira:

```php
        $signedDocuments = AccessLog::where('user_id', auth()->id())
            ->where('event', 'document_signed')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->filter(fn (AccessLog $log) => ! empty($log->meta['file'])
                && Storage::disk('documents')->exists('users/'.auth()->id().'/signed/'.$log->meta['file']));
```

- [ ] **Step 6: Atualizar `download()` para redirect via URL assinada**

Em `app/Http/Controllers/Client/SignDocumentController.php:94-102`:

```php
    /** Download da saída assinada — somente arquivos do próprio usuário. */
    public function download(string $filename)
    {
        abort_unless(preg_match('/^doc_[a-f0-9]+\.pdf(\.tsr)?$/', $filename), 404);

        $path = 'signed/'.auth()->id().'/'.$filename;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }
```

vira:

```php
    /** Download da saída assinada — somente arquivos do próprio usuário. */
    public function download(string $filename)
    {
        abort_unless(preg_match('/^doc_[a-f0-9]+\.pdf(\.tsr)?$/', $filename), 404);

        $disk = Storage::disk('documents');
        $path = 'users/'.auth()->id().'/signed/'.$filename;
        abort_unless($disk->exists($path), 404);

        $url = $disk->temporaryUrl($path, now()->addMinutes(5), [
            'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"',
        ]);

        return redirect($url);
    }
```

- [ ] **Step 7: Rodar os testes para confirmar que passam**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/SignDocumentTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Services/Pdf/PdfSignerService.php app/Http/Controllers/Client/SignDocumentController.php tests/Feature/SignDocumentTest.php
git commit -m "feat: assinatura avulsa (sign-document) salva e serve resultado via disk documents (S3)"
```

---

### Task 9: Suíte completa e limpeza

**Files:**
- Nenhum arquivo novo — verificação final

- [ ] **Step 1: Rodar a suíte completa de testes**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test`
Expected: PASS em todos os testes (nenhuma regressão em `CertificateCrudTest` — que
continua 100% no disk `local` — nem nas demais áreas do sistema)

- [ ] **Step 2: Se algum teste falhar por referência residual a `Storage::disk('local')` em contexto de documento, corrigir e re-rodar**

Buscar por qualquer ocorrência residual de `signed/` ou `envelopes/{` sem o prefixo
`users/{user_id}/` fora dos arquivos já tratados nas Tasks 2-8:

Run: `grep -rn "disk('local')" app/Services/Envelope app/Services/Pdf app/Http/Controllers/Client/EnvelopeController.php app/Http/Controllers/Client/SignDocumentController.php app/Http/Controllers/PublicSign app/Jobs/SealEnvelopeJob.php`
Expected: só ocorrências onde `local` é de fato o scratch de trabalho do PyHanko/TCPDF
(dentro de `PdfSignerService`) — nenhuma leitura/escrita definitiva de documento

- [ ] **Step 3: Commit final (se houver ajustes)**

```bash
git add -A
git commit -m "test: ajustes finais pos-migracao S3 apos rodada completa da suite"
```

(pular este commit se a Task 8 já deixou tudo verde e nenhum arquivo mudou)

---

## Verificação manual (pós-implementação, exige credenciais reais no `.env`)

Não faz parte dos testes automatizados (que usam `Storage::fake`, sem rede real):

1. Preencher `DOCUMENTS_S3_*` no `.env` local com as credenciais reais da Hetzner
2. Criar um envelope de teste, enviar, assinar como signatário único (link direto),
   confirmar que `original.pdf`, `signatures/{id}.png` e `final.pdf` aparecem no bucket
   sob `users/{user_id}/envelopes/{envelope_id}/...`
3. Baixar o PDF final pelo botão de download do cliente — confirmar que abre corretamente
   (via redirect para URL assinada)
4. Assinar um documento avulso em `/sign-document` e confirmar o mesmo em
   `users/{user_id}/signed/doc_*.pdf`
5. Repetir na VPS após deploy, com `DOCUMENTS_S3_*` preenchido no `.env` de produção
