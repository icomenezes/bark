# E-mail de redefinição de senha com a marca do white-label

Data: 2026-07-16

## Contexto

O fluxo de "esqueci minha senha" já está implementado (scaffolding padrão do Laravel
Breeze): `PasswordResetLinkController`, `NewPasswordController`, rotas nomeadas
`password.request`/`password.email`/`password.reset`, link já presente em
`resources/views/auth/login.blade.php`. Em produção o `MAIL_MAILER` já está
configurado corretamente (SMTP/SES) e os e-mails já saem de verdade — não há bug
funcional a corrigir.

O único ponto pendente: o e-mail de reset usa a notification padrão do Laravel
(`Illuminate\Auth\Notifications\ResetPassword`), com template genérico em inglês,
sem nenhuma referência à marca/cores do cliente (`settings`). Todos os outros e-mails
do sistema (`BoasVindas`, `EnvelopeInvite`, `EnvelopeOtp`, etc.) seguem um padrão
visual próprio (HTML standalone, cor de destaque `#1e40af`, mesma estrutura de
header/body/footer) — o e-mail de reset deve seguir o mesmo padrão.

## Escopo

- Sobrescrever `sendPasswordResetNotification(string $token)` no model `App\Models\User`
  para disparar uma notification customizada (`App\Notifications\ResetPasswordNotification`)
  em vez da notification padrão do framework
- Notification customizada implementa `toMail()` retornando `MailMessage::view(
  'emails.reset-senha', [...])` (view Blade própria, não os componentes markdown
  padrão do Laravel), seguindo o mesmo padrão visual dos e-mails existentes (mesmo
  HTML/CSS inline dos outros, adaptado ao conteúdo de reset de senha). A URL de reset
  é gerada da mesma forma que a notification padrão do framework gera hoje:
  `url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false))`
- Texto em pt-BR: assunto "Redefinição de senha — {app.name}", corpo explicando que
  foi solicitada uma redefinição, botão "Redefinir senha" apontando para a URL de
  reset (mesma URL que a notification padrão já gera, só muda a apresentação),
  aviso de expiração (tempo default do Breeze, `config('auth.passwords.users.expire')`
  minutos) e aviso "se você não solicitou, ignore este e-mail"
- Sem mudança de rota, controller, ou lógica de geração/validação de token — só a
  camada de apresentação do e-mail

## Fora de escopo

- Não mexe no fluxo de troca de senha em si (`NewPasswordController`), que já
  funciona
- Não usa o `emails.layout` genérico (componente que existe mas nenhum e-mail do
  sistema usa hoje) — segue o padrão standalone que `BoasVindas`/`EnvelopeInvite`
  já usam, para manter consistência visual entre todos os e-mails
- Não adiciona branding dinâmico (logo/cor de `settings`) neste momento, já que os
  e-mails existentes também não usam — mesma cor fixa `#1e40af` dos demais

## Teste

- Teste de feature: solicitar reset com e-mail de usuário existente dispara a
  notification customizada (`Notification::fake()` + `assertSentTo`), e a view
  renderiza sem erro com um token de exemplo
