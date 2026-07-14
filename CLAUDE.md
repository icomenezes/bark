# Casca — Projeto Base SaaS — Documentação do Projeto

## Visão geral
Projeto **casca** (skeleton): base Laravel genérica para criar novos sistemas SaaS de qualquer nicho.
Contém autenticação, usuários (admin/client), logs de acesso, sessões ativas com heartbeat,
configurações white-label e notificações WhatsApp — **sem nenhum módulo de nicho**.

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
- `POST /heartbeat` — atualiza `active_sessions.last_seen_at`
- `POST /api/register` — registro público de leads (sem CSRF)
- `/profile` — perfil Breeze

---

## Serviços

- `AccessLogService` — `log($user, $event, $meta)`, `login()`, `logout()`, `denied()`, `heartbeat()`
- `NotificationService` — `sendWhatsApp($user, $msg)`, `boasVindas($user)` (respeita `settings.whatsapp_enabled`)
- `WhatsAppService` — Evolution API (`services.evolution.*` no config, env `EVOLUTION_API_*`)
- `Mail\BoasVindas` — e-mail de boas-vindas (view `emails.boas-vindas`, layout `emails.layout`)

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
