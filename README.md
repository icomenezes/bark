# Casca — Projeto Base SaaS

Projeto **casca** (skeleton) em Laravel para servir de ponto de partida a novos sistemas SaaS de qualquer nicho
(assinatura digital, agendamento, etc.). Clone, renomeie e adicione os módulos do seu nicho.

## O que já vem pronto

- **Autenticação completa** (Laravel Breeze): login, registro, reset de senha, perfil
- **Perfis de usuário**: `admin` (painel administrativo) e `client` (área do cliente)
- **CRUD de usuários** no admin, com campo WhatsApp opcional
- **Logs de acesso** genéricos (`access_logs`): login, logout, access_denied + eventos livres dos seus módulos via `AccessLogService`
- **Sessões ativas + heartbeat**: POST `/heartbeat` a cada 30s; "online agora" no dashboard admin
- **Configurações white-label** (`settings`): nome da empresa, logo, favicon, cores, contatos de suporte — aplicadas em todos os layouts
- **Notificações WhatsApp** via Evolution API (`WhatsAppService` + `NotificationService`)
- **E-mail de boas-vindas** ao criar usuário
- **Registro público** de leads: `POST /api/register` (sem CSRF, para sites externos)
- **PWA básico**: manifest + service worker + banner de instalação
- **Dashboard admin** com métricas genéricas e **área do cliente** com placeholder para os módulos do nicho

## Stack

PHP 8.3 · Laravel 13 · MySQL · Tailwind CSS + Alpine.js · Vite

## Como iniciar um novo sistema a partir da casca

1. Copie a pasta (ou clone o repo) para o novo projeto
2. Ajuste `.env` (`APP_NAME`, `DB_DATABASE`, credenciais do admin)
3. `composer install && npm install && npm run build`
4. `php artisan key:generate`
5. `php artisan migrate --seed` (cria o admin com `ADMIN_EMAIL` / `ADMIN_PASSWORD` do `.env`)
6. Crie os models/controllers/views do seu nicho:
   - Controllers de admin em `app/Http/Controllers/Admin/`
   - Controllers de cliente em `app/Http/Controllers/Client/`
   - Adicione itens de menu em `resources/views/admin/layout.blade.php` e `resources/views/client/layout.blade.php`
   - Substitua o placeholder de `resources/views/client/dashboard.blade.php`
   - Logue eventos dos módulos com `AccessLogService::log($user, 'meu_evento', [...])`

## Estrutura

```
app/Http/Controllers/
├── Admin/
│   ├── DashboardController   — métricas: clientes, online agora, acessos negados
│   ├── UserController        — CRUD usuários
│   ├── AccessLogController   — logs com filtros
│   └── SettingController     — configurações white-label
├── Client/
│   └── DashboardController   — área do cliente (placeholder)
├── Auth/                     — Breeze
├── HeartbeatController       — POST /heartbeat
└── PublicRegisterController  — POST /api/register
```

## Testes

```bash
php artisan test
```
