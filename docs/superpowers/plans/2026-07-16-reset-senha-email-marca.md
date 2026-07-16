# E-mail de Redefinição de Senha com Marca — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trocar o template de e-mail do fluxo "esqueci minha senha" (já funcional) pelo padrão visual usado pelos demais e-mails do sistema, sem alterar rota, controller ou lógica de token.

**Architecture:** Uma notification customizada `App\Notifications\ResetPasswordNotification` substitui `Illuminate\Auth\Notifications\ResetPassword` via override de `sendPasswordResetNotification()` no model `User`. A notification usa `MailMessage::view()` apontando para uma view Blade própria que replica o HTML/CSS inline dos e-mails existentes (`emails.envelopes.invite`, `emails.boas-vindas`).

**Tech Stack:** Laravel 13 Notifications (`Illuminate\Notifications\Notification`), Blade.

## Global Constraints

- UI em pt-BR; código (classes, métodos, variáveis) em inglês — convenção do projeto (`[[casca-module-conventions]]`)
- Não mexer em `NewPasswordController`, rotas, ou geração/validação de token
- Não usar o `emails.layout` genérico — seguir o padrão standalone dos e-mails existentes (cor `#1e40af`, mesma estrutura header/body/footer)
- Testes rodam em sqlite `:memory:` via `php artisan test`
- PHP do Laragon: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`

---

### Task 1: View de e-mail de redefinição de senha

**Files:**
- Create: `resources/views/emails/reset-senha.blade.php`

**Interfaces:**
- Consumes: variáveis Blade `$url` (string, link de reset), `$expireMinutes` (int)
- Produces: view renderizável standalone, sem dependência de layout externo

- [ ] **Step 1: Criar a view seguindo o padrão visual existente**

```blade
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body { margin:0; padding:0; background:#f1f5f9; font-family: ui-sans-serif, system-ui, sans-serif; }
  .wrap { max-width:560px; margin:40px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
  .header { background:#1e40af; padding:32px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; font-weight:700; }
  .body { padding:36px 40px; color:#334155; font-size:15px; line-height:1.7; }
  .body h2 { color:#1e293b; font-size:18px; margin-top:0; }
  .btn { display:inline-block; background:#1e40af; color:#fff!important; text-decoration:none; padding:12px 28px; border-radius:8px; font-weight:600; font-size:15px; margin:20px 0; }
  .footer { padding:20px 40px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #f1f5f9; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>{{ config('app.name') }}</h1>
  </div>
  <div class="body">
    <h2>Redefinição de senha</h2>
    <p>Recebemos uma solicitação para redefinir a senha da sua conta.</p>
    <a class="btn" href="{{ $url }}">Redefinir senha</a>
    <p style="font-size:13px;color:#64748b;">Este link expira em {{ $expireMinutes }} minutos.</p>
    <p style="font-size:13px;color:#64748b;">Se você não solicitou a redefinição, ignore este e-mail — nenhuma alteração será feita.</p>
  </div>
  <div class="footer">
    Equipe {{ config('app.name') }}
  </div>
</div>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/emails/reset-senha.blade.php
git commit -m "feat: view de e-mail de redefinicao de senha com marca do sistema"
```

---

### Task 2: Notification customizada

**Files:**
- Create: `app/Notifications/ResetPasswordNotification.php`
- Test: `tests/Feature/Auth/PasswordResetTest.php:6,30,41,58` (atualizar referências à notification padrão)

**Interfaces:**
- Consumes: view `emails.reset-senha` (Task 1)
- Produces: `App\Notifications\ResetPasswordNotification` — construtor `__construct(string $token)`, método `toMail($notifiable): MailMessage`

- [ ] **Step 1: Atualizar o teste existente para esperar a notification customizada**

O teste `tests/Feature/Auth/PasswordResetTest.php` hoje importa e asserta contra
`Illuminate\Auth\Notifications\ResetPassword`. Substitua todas as 4 ocorrências
pela notification nova:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_reset_password_mail_renders_branded_view(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $rendered = $mail->render();

            $this->assertStringContainsString('Redefinição de senha', $rendered);
            $this->assertStringContainsString('reset-password', $rendered);

            return true;
        });
    }
}
```

- [ ] **Step 2: Rodar os testes para confirmar que falham (classe ainda não existe)**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/Auth/PasswordResetTest.php`
Expected: FAIL — `Class "App\Notifications\ResetPasswordNotification" not found`

- [ ] **Step 3: Criar a notification**

```php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $expireMinutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Redefinição de senha — '.config('app.name'))
            ->view('emails.reset-senha', [
                'url' => $url,
                'expireMinutes' => $expireMinutes,
            ]);
    }
}
```

- [ ] **Step 4: Rodar os testes para confirmar que passam**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/Auth/PasswordResetTest.php`
Expected: PASS (5 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Notifications/ResetPasswordNotification.php tests/Feature/Auth/PasswordResetTest.php
git commit -m "feat: notification customizada de redefinicao de senha com marca"
```

---

### Task 3: Ligar a notification customizada ao model User

**Files:**
- Modify: `app/Models/User.php`

**Interfaces:**
- Consumes: `App\Notifications\ResetPasswordNotification` (Task 2)
- Produces: `User::sendPasswordResetNotification(string $token): void` — override do método do trait `Illuminate\Auth\Passwords\CanResetPassword`, chamado automaticamente pelo `Password::sendResetLink()`

- [ ] **Step 1: Rodar a suíte de auth antes da mudança para confirmar baseline**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test tests/Feature/Auth/PasswordResetTest.php`
Expected: PASS (já passou na Task 2 — este é só o baseline antes do override, que não deveria quebrar nada já que a notification já é a certa)

- [ ] **Step 2: Adicionar o override no model**

Editar `app/Models/User.php`, adicionando o import e o método:

```php
use App\Notifications\ResetPasswordNotification;
```

(adicionar junto aos demais `use` no topo do arquivo, após `use Illuminate\Notifications\Notifiable;`)

```php
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
```

(adicionar como novo método público, próximo aos demais métodos de classe — ex.: logo após o bloco `// ── Certificados digitais ──`)

- [ ] **Step 3: Rodar a suíte completa de testes**

Run: `& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan test`
Expected: PASS — nenhuma regressão em outras áreas (auth, envelopes, certificados)

- [ ] **Step 4: Commit**

```bash
git add app/Models/User.php
git commit -m "feat: usar notification customizada no fluxo de redefinicao de senha"
```

---

## Verificação manual (pós-implementação)

Não faz parte dos testes automatizados, mas vale confirmar visualmente antes de considerar encerrado:

1. Local: `& $php artisan tinker --execute="Illuminate\Support\Facades\Notification::route('mail', 'teste@example.com')->notify(new App\Notifications\ResetPasswordNotification('token-fake'));"` com `MAIL_MAILER=log` e checar `storage/logs/laravel.log` para ver o HTML renderizado
2. Fluxo real: acessar `/forgot-password`, informar e-mail de um usuário existente, confirmar que o e-mail chega (em produção) com o novo visual
