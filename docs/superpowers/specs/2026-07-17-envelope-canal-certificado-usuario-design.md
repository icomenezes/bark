# Canal de envio por signatário, certificado próprio do cliente e remoção do delete de conta

Data: 2026-07-17

## Contexto

Hoje, no fluxo de envelopes:

- Todo signatário recebe convite e OTP predominantemente por e-mail (`envelope_signers.email` é
  sempre obrigatório); WhatsApp existe apenas como "espelho" adicional quando
  `auth_method = whatsapp_otp`, mas nunca substitui o e-mail.
- O lacre final de todo envelope usa **um único certificado global**
  (`settings.platform_certificate_id`), configurado pelo admin em `/admin/settings`. Não existe
  caminho para um cliente lacrar com o próprio certificado.
- Não existe preferência por cliente de canal de envio, nem gate de WhatsApp por cliente — só o
  toggle global `settings.whatsapp_enabled`.
- O `/profile` (Breeze) tem um botão "Delete Account" que qualquer usuário autenticado pode usar
  para se autoexcluir.

Este documento cobre quatro mudanças relacionadas, decididas em conjunto por serem todas ajustes
no fluxo de envelopes e conta de usuário:

1. Canal de envio por signatário (e-mail **ou** WhatsApp, não somente e-mail + espelho)
2. Certificado próprio do cliente para lacrar seus envelopes (com fallback pro certificado global)
3. Canal padrão de envio definido pelo admin, por cliente
4. Remoção completa (UI + backend) da autoexclusão de conta em `/profile`

## 1. Canal de envio por signatário

### Modelo de dados

Novo campo em `envelope_signers`:

```php
$table->enum('channel', ['email', 'whatsapp'])->default('email')->after('cpf');
```

`auth_method` (`link`|`email_otp`|`whatsapp_otp`) continua existindo, mas agora fica **restrito
pelo canal**:

| channel | auth_method permitido |
|---|---|
| `email` | `link`, `email_otp` |
| `whatsapp` | `link`, `whatsapp_otp` |

Não é permitida a combinação cruzada (`channel=whatsapp` + `auth_method=email_otp`, ou
`channel=email` + `auth_method=whatsapp_otp`) — simplifica a UI e evita ambiguidade sobre por
onde o código chega.

### Validação (`EnvelopeController::validateSigners`)

```php
'signers.*.channel' => ['required', 'in:email,whatsapp'],
'signers.*.email' => ['required_if:signers.*.channel,email', 'nullable', 'email', 'max:255'],
'signers.*.whatsapp' => ['required_if:signers.*.channel,whatsapp', 'nullable', 'string', 'max:20'],
'signers.*.auth_method' => [
    'required',
    Rule::in(fn ($input) => $input['channel'] === 'whatsapp' ? ['link', 'whatsapp_otp'] : ['link', 'email_otp']),
],
```

(A regra condicional de `auth_method` é resolvida por signatário no loop de validação do
controller, não com uma única regra estática, já que depende do `channel` daquele item do array.)

### `EnvelopeService`

- `notifySigner()`: passa a enviar o convite **pelo canal do signatário**, não mais e-mail sempre
  + espelho condicional:
  ```php
  if ($signer->channel === 'whatsapp') {
      $this->notification->sendWhatsAppTo($signer->whatsapp, /* mensagem de convite */);
  } else {
      Mail::to($signer->email)->send(new EnvelopeInvite($signer, $reminder));
  }
  ```
- `issueOtp()`: já decide o canal por `auth_method`; passa a decidir por `channel` (equivalente
  após a restrição acima, mas mais direto):
  ```php
  if ($signer->channel === 'whatsapp') {
      $this->notification->sendWhatsAppTo(...);
  } else {
      Mail::to($signer->email)->send(new EnvelopeOtp($signer, $code));
  }
  ```
- `decline()` e `cancel()` notificam o **dono do envelope** (sempre por e-mail, sem mudança — o
  channel é uma propriedade do signatário, não do dono).

### Wizard (`client/envelopes/create.blade.php`, step 2 "Signatários")

- O select único de "Verificação" (Somente link / Código por e-mail / Código por WhatsApp) vira
  dois controles:
  1. **Canal**: E-mail / WhatsApp (define qual campo de contato aparece: e-mail ou WhatsApp).
  2. **Verificação**: Somente link / Código pelo canal escolhido (rótulo dinâmico: "Código por
     e-mail" quando canal=e-mail, "Código por WhatsApp" quando canal=WhatsApp).
- Ao trocar o canal, o campo de contato correspondente é exibido (`x-show`) e o outro é limpo;
  se a verificação selecionada não é compatível com o novo canal, ela é resetada para "Somente
  link".
- Novo signatário nasce com `channel` = preferência padrão do usuário logado (seção 3).

### Views de exibição

`client/envelopes/show.blade.php` e `public/sign/show.blade.php` (mapas `$authLabels`) passam a
também exibir o canal (ícone/rótulo "E-mail" ou "WhatsApp") ao lado do método de verificação.

## 2. Certificado próprio do cliente para lacrar envelopes

### Modelo de dados

Novo campo em `users`:

```php
$table->foreignId('signing_certificate_id')->nullable()->after('plan_id')
    ->constrained('certificates')->nullOnDelete();
```

### Escolha do certificado (tela do cliente)

Em `/certificates` (`client/certificates/index.blade.php`), cada card de certificado próprio
ganha um botão "Usar como certificado de assinatura" — visível somente se o certificado não está
vencido. Marcar um novo desmarca o anterior (é sempre no máximo um por usuário: grava
`auth()->user()->update(['signing_certificate_id' => $certificate->id])`). O card atualmente
marcado exibe um badge "Padrão".

Nova rota: `POST /certificates/{certificate}/use-as-signing` →
`CertificateController::useAsSigning()`, autorização igual às demais ações (`authorizeOwner`).
Ao deletar um certificado (`destroy()`), se ele era o `signing_certificate_id` do usuário, o FK
`nullOnDelete()` já cuida de zerar a referência — sem lógica extra necessária.

### Resolução do certificado de lacre

`EnvelopeService::send()` e `SealEnvelopeJob::handle()` hoje resolvem sempre
`Setting::current()->platformCertificate`. Passam a resolver:

```php
$cert = $envelope->user->signingCertificate ?? Setting::current()->platformCertificate;
```

Novo relacionamento em `User`:

```php
public function signingCertificate(): BelongsTo
{
    return $this->belongsTo(Certificate::class, 'signing_certificate_id');
}
```

Se o certificado resolvido (próprio ou fallback da plataforma) for nulo ou estiver vencido,
mantém-se o mesmo erro de bloqueio que existe hoje — sem novo texto de erro por caminho, apenas
a mesma mensagem genérica ("Nenhum certificado válido configurado...").

**Importante**: um cliente nunca acessa/vê o certificado global do admin — ele só vê e escolhe
entre certificados que ele mesmo cadastrou em `/certificates`. `/admin/settings` continua
exclusivo do admin, sem mudanças.

## 3. Canal padrão de envio (definido pelo admin, por cliente)

### Modelo de dados

Dois novos campos em `users`:

```php
$table->boolean('whatsapp_envelope_enabled')->default(false)->after('whatsapp');
$table->enum('default_envelope_channel', ['email', 'whatsapp'])->default('email')->after('whatsapp_envelope_enabled');
```

`whatsapp_envelope_enabled` é um gate **por cliente**, independente do toggle global
`settings.whatsapp_enabled` — para o canal WhatsApp funcionar de fato num envelope, os dois
precisam estar ativos: `settings.whatsapp_enabled && $user->whatsapp_envelope_enabled`.

### UI (`/admin/users/create` e `/admin/users/edit`)

Novo grupo de campos, exibido apenas quando `role = client`:

- Checkbox "Permitir envio de envelope via WhatsApp" (`whatsapp_envelope_enabled`).
- Select "Canal padrão de envio" (`default_envelope_channel`: E-mail / WhatsApp), habilitado
  apenas se o checkbox acima estiver marcado (via Alpine `x-model`/`:disabled`, com fallback de
  validação no backend: se `whatsapp_envelope_enabled` for falso, força
  `default_envelope_channel = 'email'` no `store()`/`update()` do `UserController`).

### Uso no wizard

Ao adicionar um novo signatário no wizard de criação de envelope, o campo `channel` daquele
signatário nasce pré-preenchido com:

```php
auth()->user()->whatsapp_envelope_enabled
    ? auth()->user()->default_envelope_channel
    : 'email'
```

(Passado do controller para a view/Alpine como dado inicial do componente `envelopeWizard()`.)
O cliente pode alterar o canal por signatário livremente dentro do wizard — isso é só o valor
inicial, não uma trava.

## 4. Remoção da autoexclusão de conta em `/profile`

Remoção completa, UI + backend:

- Remover `resources/views/profile/partials/delete-user-form.blade.php` e sua inclusão em
  `resources/views/profile/edit.blade.php`.
- Remover a rota `profile.destroy` de `routes/web.php`.
- Remover o método `destroy()` de `app/Http/Controllers/ProfileController.php`.

Sem substituto — a exclusão de conta de cliente já existe via `Admin\UserController::destroy()`
(`/admin/users/{user}` DELETE), que é o único caminho suportado a partir de agora.

## Fora de escopo

- Não se cria uma tela de "configurações do cliente" nova — as duas novas preferências vivem
  onde já faz sentido (canal padrão: formulário de usuário do admin; certificado padrão: tela de
  certificados do cliente).
- Não se adiciona um seletor de certificado por envelope individual (só uma preferência fixa por
  usuário, com o card em `/certificates` como fonte da verdade).
- Não se altera o comportamento de `settings.whatsapp_enabled` global nem a tela
  `/admin/settings`.
