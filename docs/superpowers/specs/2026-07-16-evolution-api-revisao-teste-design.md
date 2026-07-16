# Revisão da Evolution API + tela de teste manual de WhatsApp

Data: 2026-07-16

## Contexto

`App\Services\WhatsAppService` encapsula o envio de mensagens de texto via Evolution
API (`POST {baseUrl}/message/sendText/{instance}`). É consumido por
`App\Services\NotificationService`, usado hoje em `boasVindas()` e nos convites/OTP
de envelope (`EnvelopeService::notifySigner()`, `issueOtp()`). Não existe hoje
nenhuma forma de disparar um envio avulso de teste pela interface — só rodando os
fluxos reais (criar usuário, enviar convite de envelope) e checando o log.

## Escopo

### 1. Correção da normalização de número

Hoje (`WhatsAppService::send()`):

```php
$number = preg_replace('/\D/', '', $phone);
if (strlen($number) === 11) {
    $number = '55' . $number; // adiciona DDI Brasil
}
```

Só cobre celular BR sem DDI (11 dígitos, com o 9). Não cobre: fixo sem DDI (10
dígitos), nem números que já vêm com DDI (12-13 dígitos) — esses passam sem
tratamento algum hoje, o que é o comportamento correto por acidente (não precisam
de ajuste), mas fixos sem DDI (10 dígitos) ficam sem o `55` e provavelmente falham
no envio.

Ajuste: adicionar `55` também quando o número tiver exatamente 10 dígitos (fixo
sem DDI), mantendo o comportamento atual para 11 dígitos e para números que já
tragam DDI.

### 2. Tela de teste manual em `/admin/settings`

Nova aba/seção "Testar WhatsApp" na página de Configurações existente
(`resources/views/admin/settings/edit.blade.php`), perto do toggle
`whatsapp_enabled` já existente. Contém:

- Campo de número (com DDI ou não, mesma normalização do serviço real)
- Campo de mensagem (textarea, valor default tipo "Mensagem de teste do sistema")
- Botão "Enviar teste"

Novo endpoint `POST /admin/settings/whatsapp-test` (`SettingController::testWhatsApp`
ou controller dedicado `Admin\WhatsAppTestController`), que:

- Valida `numero` (required, string) e `mensagem` (required, string, max 1000)
- Chama `WhatsAppService::send()` diretamente (não passa pelo `NotificationService`,
  pois este depende de `settings.whatsapp_enabled` e de haver um `User`/signer —
  o teste deve funcionar independente do toggle, para permitir testar mesmo com
  a notificação automática desligada)
- Retorna sucesso/erro imediatamente via `back()->with('success', ...)` ou
  `->withErrors([...])`, mostrando a resposta HTTP da Evolution API em caso de
  falha (hoje isso só vai pro log — a tela de teste precisa expor isso ao admin)

Isso exige um pequeno ajuste: `WhatsAppService::send()` hoje retorna só `bool`, sem
detalhe do erro. O controller de teste precisa desse detalhe para mostrar ao admin
— ver nota de implementação abaixo.

### 3. Fora de escopo

- Não adiciona suporte a envio de mídia/PDF por WhatsApp (só texto, como hoje)
- Não muda a lógica de `NotificationService` nem os pontos de disparo automático
  (`boasVindas`, convites, OTP, lembrete) — só a tela de teste é nova
- Não adiciona endpoint de "status da instância" (`/instance/connectionState` da
  Evolution API) — fica só o envio de texto de teste, igual ao uso real

## Nota de implementação

Para expor o motivo do erro na tela de teste sem quebrar a assinatura usada pelos
callers existentes (`NotificationService`, que só usa o retorno bool), a forma mais
simples é o controller de teste chamar a Evolution API diretamente através de um
novo método público `sendWithDetails()` no `WhatsAppService` (retorna
array `['ok' => bool, 'error' => ?string]`), reaproveitando a mesma normalização de
número e configuração — `send()` passa a delegar para `sendWithDetails()` internamente,
mantendo compatibilidade com todo o código existente.

## Teste

- Teste unitário do `WhatsAppService`: normalização de número cobre 10 dígitos
  (fixo), 11 dígitos (celular), e número já com DDI (sem duplicar)
- Teste de feature: `POST /admin/settings/whatsapp-test` com Evolution não
  configurada retorna erro amigável; com `Http::fake()` simulando sucesso/falha da
  Evolution API, a tela reflete o resultado
