# Design: Projeto Casca (base SaaS genérica)

**Data:** 2026-07-14
**Origem:** extraído do `gestor_cameras` (sistema de câmeras de segurança)

## Objetivo

Criar um projeto "casca" Laravel — base limpa e clonável para novos sistemas SaaS de qualquer nicho —
mantendo a infraestrutura genérica já lapidada no sistema de câmeras e removendo tudo que é específico do nicho.

## Decisões (aprovadas pelo usuário)

| Decisão | Escolha |
|---|---|
| Nome/pasta | `c:\projetos\casca` |
| Módulos SaaS mantidos | Notificações WhatsApp + Registro público de leads |
| Módulos removidos | Câmeras (tudo), Assinaturas, Multi-tenant/SuperAdmin/provisionamento Docker |
| Migrations | Consolidadas em poucas migrations limpas |
| Git | Repositório novo, commit inicial único |

## Abordagem

**Copiar e depurar** (em vez de Laravel zerado + port): copia preserva layouts, heartbeat, logs e
white-label já funcionando; risco de sobras mitigado com varredura final por grep
(`camera`, `go2rtc`, `clip`, `dvr`, `tenant`, `subscription`, `snapshot`, `mosaic`, etc.).

## O que fica

- Auth Breeze completo (login, registro, reset, perfil)
- Usuários com roles `admin`/`client` + whatsapp (sem quota de clipes, sem grant de câmeras)
- `access_logs` generalizado: `event` string(50) livre, sem `camera_id`
- `active_sessions` + heartbeat 30s (sem `watching_camera_id`)
- `TenantSetting` → `Setting` (tabela `settings`): white-label singleton, view composer `$settings`
- `WhatsAppService` (Evolution API via `services.evolution.*`) + `NotificationService` enxuto
- `PublicRegisterController` genérico: cria User client + boas-vindas (sem Tenant/provisionamento)
- Dashboard admin genérico (clientes, online, negados, atividade) e client placeholder
- PWA (manifest/sw genéricos)

## O que sai

Models/controllers/services/jobs/commands/views/migrations de: câmeras, gravações, segmentos,
clipes, snapshots, mosaico, eventos de câmera, playback DVR, go2rtc, analytics, assinaturas,
tenants/superadmin; scripts de provisionamento (`*.sh`, `provision-agent.php`), Docker,
`.github/workflows/deploy.yml`, pastas `api/` e `site/`, docs do nicho.

## Migrations consolidadas

1. `create_users_table` (com `role` e `whatsapp`) + password_resets + sessions
2. `create_cache_table` (padrão)
3. `create_jobs_table` (padrão)
4. `create_access_logs_table`
5. `create_active_sessions_table`
6. `create_settings_table` (com registro padrão)

Seeder: `AdminSeeder` (env `ADMIN_*`) chamado pelo `DatabaseSeeder`.

## Verificação

- `grep` sem sobras de termos do nicho
- `composer install` + `php artisan migrate:fresh --seed` no banco MySQL `casca`
- `php artisan test` (Breeze) verde
- `git init` + commit inicial
