# Casca — Projeto Base SaaS — Documentação do Projeto

## Visão geral
Projeto **casca** (skeleton): base Laravel genérica para criar novos sistemas SaaS de qualquer nicho.
Contém autenticação, usuários (admin/client), logs de acesso, sessões ativas com heartbeat,
configurações white-label, notificações WhatsApp e assinatura digital de PDF
(certificados PFX + PAdES) — **sem nenhum módulo de nicho**.

Fluxo de uso: clonar/copiar este projeto → renomear → adicionar os módulos do nicho
(ex.: assinatura digital, agendamento) como novos controllers/models/views.

Extraído do sistema de câmeras (`gestor_cameras`) em 2026-07-14, removendo tudo específico
de câmeras, assinaturas e multi-tenancy.

---

## Stack

| Componente | Detalhe |
|---|---|
| PHP | 8.3 (Laragon: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`) |
| Laravel | 13.x |
| MySQL | banco `casca` (root sem senha no local) |
| Breeze | autenticação |
| Tailwind CSS + Alpine.js | frontend |
| Vite | build de assets |

### Comandos locais (Windows/Laragon)

```powershell
$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
Set-Location "c:\projetos\casca"
& $php artisan migrate --seed
& $php artisan test
```

---

## Banco de dados

### `users`
| Campo | Tipo | Descrição |
|---|---|---|
| role | enum | `admin` \| `client` |
| whatsapp | string nullable | Telefone para notificações |

### `access_logs`
| Campo | Tipo | Descrição |
|---|---|---|
| user_id | FK | |
| event | string(50) | `login` \| `logout` \| `access_denied` + eventos livres dos módulos |
| ip_address / user_agent | string | |
| meta | json | Dados extras (ex.: motivo do denied) |

### `active_sessions`
| Campo | Tipo | Descrição |
|---|---|---|
| user_id | FK unique | Um registro por usuário |
| logged_in_at / last_seen_at | timestamp | Heartbeat atualiza `last_seen_at` a cada 30s |

Usuário é considerado online se `last_seen_at >= now - 2 minutos`.

### `certificates`

| Campo | Tipo | Descrição |
|---|---|---|
| user_id | FK | Dono do certificado (cliente) |
| description / reference | string | Referência é opcional |
| pfx_path | string | Disk `local` (privado), `certificates/{id}/certificate.pfx` |
| password | text | Cast `encrypted`; validada contra o PFX no upload |
| sign_image_path / logo_image_path | string nullable | Carimbo visual e logo |
| expires_at | date nullable | `validTo` do X.509, extraído no upload |

### `settings`
Registro único (singleton via `Setting::current()`, cacheado 5 min):
`company_name`, `logo_url`, `favicon_url`, `primary_color`, `accent_color`,
`support_email`, `support_whatsapp`, `whatsapp_enabled`.

Compartilhado com todas as views como `$settings` (view composer no `AppServiceProvider`).

---

## Perfis e middleware

| Perfil | Acesso |
|---|---|
| `admin` | `/admin/*` (middleware `auth` + `admin`) |
| `client` | `/dashboard` (middleware `auth`) |

Alias `admin` registrado em `bootstrap/app.php`. Login redireciona admin → `admin.dashboard`, client → `dashboard`.

---

## Rotas principais

- `/` → redirect login
- `/admin` — dashboard admin (clientes, online agora, acessos negados, atividade recente)
- `/admin/users` — CRUD usuários (index, show, create, store, destroy)
- `/admin/access-logs` — logs com filtro por usuário/evento/data
- `/admin/settings` — white-label
- `/dashboard` — área do cliente (placeholder para módulos)
- `/certificates` — CRUD de certificados digitais do cliente (+ `certificates/{id}/image/{sign|logo}`)
- `/sign-document` — assinar PDF (preview PDF.js + marcador); POST `sign` / `generate`; GET `download/{file}`
- `POST /heartbeat` — atualiza `active_sessions.last_seen_at`
- `POST /api/register` — registro público de leads (sem CSRF)
- `/profile` — perfil Breeze

---

## Serviços

- `AccessLogService` — `log($user, $event, $meta)`, `login()`, `logout()`, `denied()`, `heartbeat()`
- `NotificationService` — `sendWhatsApp($user, $msg)`, `boasVindas($user)` (respeita `settings.whatsapp_enabled`)
- `WhatsAppService` — Evolution API (`services.evolution.*` no config, env `EVOLUTION_API_*`)
- `Mail\BoasVindas` — e-mail de boas-vindas (view `emails.boas-vindas`, layout `emails.layout`)

### Assinatura digital de PDF (`app/Services/Pdf/`)

Porte do módulo do ERP (`erp.fitlikeaglove.com.br`) — spec em
`docs/superpowers/specs/2026-07-14-certificates-sign-document-design.md`.

- `PdfSignerService` — fachada: `fromCertificate()`, `signExisting()`, `createAndSign()`, `hasSignatureImage()`
- `PyHankoSigner` — motor preferencial (PAdES incremental, multi-assinatura, TSA embutido).
  Requer pyHanko CLI no host (`pip install pyHanko pyHanko-cli "pyHanko[image-support]"`);
  override do binário via env `PYHANKO_BIN` (`services.pyhanko.bin`). **Nunca cachear detecção negativa.**
- `SignPdfService` — motor TCPDF+FPDI (fallback): assinatura única, reescreve o PDF, TSA vira sidecar `.tsr`.
  PEM cru no `setSignature` (prefixo `data://` quebra em silêncio); posições em pontos PDF
  convertidas por `getScaleFactor()`; `stamp()` jamais sobre PDF já assinado.
- Coordenadas: form → backend em pontos PDF origem topo-esquerdo; pyHanko usa base-esquerda
  (`y1 = alturaPagina − y − h`, conversão em `PyHankoSigner::fieldSpec()`).
- Saída assinada: disk `local` em `signed/{user_id}/doc_<hex>.pdf`.
- `SealComposer` — flag "Usar selo de autenticação" (`use_seal`): compõe o selo (logo do
  certificado) acima/à direita da assinatura via GD (PNG transparente) e usa como imagem
  principal do carimbo; rubricas das demais páginas ficam só com a assinatura.
- `Pkcs12Reader` — leitura de PFX resiliente: OpenSSL 3 (PHP 8.3) não lê PFX legado
  (RC2-40/3DES, comum em A1 de ACs brasileiras) e falha com "unsupported" MESMO com senha
  correta (senha errada dá "mac verify failure" — são distinguíveis). O reader converte via
  CLI openssl (`-legacy` no v3; v0.9.8/1.x lê direto; override `OPENSSL_BIN` →
  `services.openssl.bin`) e reexporta moderno com `openssl_pkcs12_export`; o conteúdo
  normalizado é persistido no upload. Falhas de leitura são logadas em `storage/logs/laravel.log`.

---

## Layouts

- `resources/views/admin/layout.blade.php` — sidebar admin (Dashboard, Usuários, Logs, Configurações) + heartbeat
- `resources/views/client/layout.blade.php` — header cliente + PWA + heartbeat
- `resources/views/layouts/guest.blade.php` — telas de auth (branding genérico)
- `resources/views/layouts/app.blade.php` — layout Breeze (perfil)

Todos usam `$settings` para cores (`--color-primary`, `--color-accent`), logo e nome.

---

## Como adicionar um módulo de nicho

1. Migration + model do domínio
2. Controllers em `Admin/` e/ou `Client/`
3. Rotas nos grupos existentes em `routes/web.php`
4. Item de menu nos layouts (admin sidebar / client header)
5. Substituir o placeholder de `client/dashboard.blade.php`
6. Logar eventos relevantes com `AccessLogService`
7. Notificar via `NotificationService` quando fizer sentido

## Observações

- Usar sempre o PHP do Laragon, nunca o XAMPP (PHP 7.4)
- Seeder do admin lê `ADMIN_NAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD` do `.env`
- Testes rodam em sqlite `:memory:` (`php artisan test`)
