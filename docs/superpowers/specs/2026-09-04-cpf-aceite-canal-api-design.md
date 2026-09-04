# Conferência de CPF, termo de aceite e canal na API de envelopes

**Data:** 2026-09-04
**Status:** aprovado, pronto para plano de implementação

## Problema

Envelopes criados pela API (integração da loja) hoje produzem uma assinatura eletrônica
**simples** com cadeia de custódia fraca:

1. O CPF coletado na tela pública só é validado quanto ao **formato**
   (`SignEnvelopeController::store`, regex `/^\d{3}\.\d{3}\.\d{3}-\d{2}$/`) — `111.111.111-11` passa.
   Nada amarra o assinante ao CPF do contrato da loja.
2. O "aceite" do meio eletrônico é um parágrafo passivo no rodapé do formulário
   (`resources/views/public/sign/show.blade.php`), sem registro de que foi lido ou aceito.
   O art. 10, § 2º da MP 2.200-2/2001 condiciona a validade da assinatura não-ICP-Brasil ao
   aceite do meio pela pessoa a quem o documento for oposto — aceite que hoje não é provável.
3. A API (`EnvelopeApiController::store`) aceita `signer_whatsapp` mas **nunca define `channel`**,
   caindo no default `email` de `EnvelopeService::create`. O número fica gravado e nunca é usado:
   convite, OTP e cópia final saem sempre por e-mail.

Consequência prática: se o lojista informa um canal que não é do devedor (o e-mail da filha, p.ex.)
e outra pessoa assina, não há como provar autoria numa contestação.

## Decisões

| Decisão | Escolha |
|---|---|
| CPF divergente do esperado | Bloqueia a assinatura; após 5 tentativas trava o link e avisa o remetente |
| `signer_cpf` na API | Opcional — integração atual da loja segue funcionando sem mudança |
| Termo de aceite | 1 checkbox obrigatório, texto versionado, registrado na trilha |
| `channel`/`auth_method` na API | Default `email`/`link`; `whatsapp` respeita a flag `whatsapp_envelope_enabled` |
| Persistência do estado novo | Híbrido: 3 colunas para o que é lido na validação; tentativas/bloqueio **derivados** da trilha |

### Por que derivar tentativas em vez de criar contador

`otp_attempts` é coluna porque zera a cada novo código emitido — tem ciclo de vida. Tentativa de
CPF é monotônica pela vida do signatário. Contar eventos `cpf_mismatch` é exato, não custa coluna,
e é impossível de dessincronizar do certificado de evidências, que lê a mesma trilha.

## Arquitetura

### 1. Validação de CPF

**`App\Support\Cpf`** — sem estado, sem dependências:

- `digits(?string $cpf): string` — devolve só os dígitos
- `isValid(?string $cpf): bool` — 11 dígitos, não todos iguais, os dois DVs conferem
- `format(string $cpf): string` — `000.000.000-00`
- `mask(string $cpf): string` — `***.456.789-**` (convenção da Receita)

**`App\Rules\Cpf implements ValidationRule`** — casca fina sobre `Cpf::isValid()`.
Mensagem: `"Informe um CPF válido."` Primeiro arquivo de `app/Rules/`.

Aplicada em `SignEnvelopeController::store` (substituindo o regex) e em
`EnvelopeApiController::store` (`signer_cpf`).

**Formato armazenado:** continua formatado (`000.000.000-00`), compatível com as linhas
existentes. **Toda comparação é sobre `Cpf::digits()`** — nunca sobre a string formatada.

### 2. CPF esperado, tentativas e bloqueio

**Migration** `2026_09_04_000001_add_expected_cpf_and_consent_to_envelope_signers_table`:

| Coluna | Tipo | Descrição |
|---|---|---|
| `expected_cpf` | string(14) nullable, after `cpf` | CPF que a loja espera; `null` = sem conferência |
| `consent_accepted_at` | timestamp nullable | Momento do aceite do termo |
| `consent_version` | string(10) nullable | Versão do texto aceito |

**`EnvelopeSigner`** — acrescenta ao `$fillable` as 3 colunas, cast `consent_accepted_at => datetime`,
e:

```php
public const MAX_CPF_ATTEMPTS = 5;

public function events(): HasMany
{
    return $this->hasMany(EnvelopeEvent::class, 'envelope_signer_id');
}

/** Divergências desde o último desbloqueio. Conta por id (insert-only, auto-inc) e não por
 *  created_at, que tem granularidade de segundo e empataria em tentativas rápidas. */
public function cpfAttempts(): int
{
    $lastUnlock = $this->events()->where('event', 'cpf_unlocked')->max('id');

    return $this->events()
        ->where('event', 'cpf_mismatch')
        ->when($lastUnlock, fn ($q) => $q->where('id', '>', $lastUnlock))
        ->count();
}

public function isCpfLocked(): bool
{
    return $this->expected_cpf !== null && $this->cpfAttempts() >= self::MAX_CPF_ATTEMPTS;
}
```

**`EnvelopeService`** ganha dois métodos:

- `recordCpfMismatch(EnvelopeSigner $signer, string $attempted, ?string $ip, ?string $ua): bool`
  Registra `cpf_mismatch` com `meta = ['attempted' => Cpf::mask($attempted)]` — **o CPF tentado
  nunca é gravado íntegro**: é dado pessoal de terceiro, e o mascarado basta para diagnosticar erro
  de digitação. Se a tentativa fecha o limite, registra também `cpf_locked` e envia
  `Mail\Envelopes\EnvelopeSignerLocked` ao dono do envelope. Devolve `true` se travou.
- `unlockCpf(EnvelopeSigner $signer): void`
  Registra `cpf_unlocked`, o que zera o contador derivado.

**`Mail\Envelopes\EnvelopeSignerLocked`** — segue o padrão dos mailables existentes
(`EnvelopeDeclined`), view em `resources/views/emails/envelopes/signer-locked.blade.php`,
estendendo `emails.layout` como as demais. Avisa nome do signatário, título do envelope e link
para o `/envelopes/{id}`.

**Desbloqueio pelo cliente:** rota `POST envelopes/{envelope}/signers/{signer}/unlock-cpf`
(`envelopes.signers.unlock-cpf`), no mesmo grupo `auth` das demais. `EnvelopeController::unlockCpf`
confere posse do envelope (`$envelope->user_id === auth()->id()`) e que o signatário pertence a
ele — sem isso é IDOR. Botão "Desbloquear" aparece no `/envelopes/{id}` só para signatários com
`isCpfLocked()`. Sem essa rota, um cliente que erra o próprio CPF 5 vezes inutiliza o envelope e
a loja precisa refazê-lo.

### 3. Termo de aceite

**`App\Support\ConsentTerm`**:

```php
public const VERSION = 'v1';

public static function text(EnvelopeSigner $signer): string;
```

Texto v1, com o canal real interpolado:

> Declaro que o e-mail **{email}** é meu e de meu uso pessoal, que li o documento acima e que
> aceito assinar eletronicamente, nos termos do art. 10, § 2º da MP 2.200-2/2001.

Para canal WhatsApp, troca "o e-mail {email}" por "o telefone {whatsapp}".

Persistimos apenas `consent_version` — o texto é reproduzível a partir de (versão + signatário),
logo não precisa de coluna TEXT nem duplica o canal, que já está na linha.

**View** (`public/sign/show.blade.php`): checkbox obrigatório `name="consent"` renderizando
`ConsentTerm::text($signer)`, no lugar do parágrafo passivo atual, acima do botão Assinar.

**Controller:** `'consent' => ['accepted']`, mensagem
`"É necessário aceitar o termo para assinar."`

**`EnvelopeService::sign()`:** grava `consent_accepted_at = now()` e `consent_version`, e registra
o evento `consent_accepted` (com IP e user-agent, `meta = ['version' => ...]`) **antes** do evento
`signed`, para a trilha ler na ordem cronológica correta.

### 4. Certificado de evidências

`EvidenceReportGenerator::eventProse()` ganha os casos novos:

| Evento | Prosa |
|---|---|
| `consent_accepted` | `{NOME}` **aceitou o termo de assinatura eletrônica** (v1) - IP: x |
| `cpf_mismatch` | `{nome}` informou um **CPF divergente** do cadastro (`***.456.789-**`) - IP: x |
| `cpf_locked` | Link de `{nome}` **bloqueado** após 5 tentativas de identificação incorreta. |
| `cpf_unlocked` | Link de `{nome}` **desbloqueado** pelo remetente. |

`drawSignerRow()` passa a imprimir o CPF do signatário e, quando `expected_cpf` estava definido,
a linha *"CPF conferido com o cadastro do remetente"* — que é a frase que serve numa contestação.

Ressalva aceita: em envelope com vários signatários, o PDF final leva o CPF de todos, visível a
todos. É o comportamento de Clicksign e DocuSign, mas é uma mudança de exposição em relação ao
certificado atual, que não imprime CPF.

### 5. API

`EnvelopeApiController::store` — regras acrescentadas:

```php
'signer_cpf'      => ['nullable', 'string', new Cpf],
'channel'         => ['nullable', 'in:email,whatsapp'],
'auth_method'     => ['nullable', 'in:link,email_otp,whatsapp_otp'],
'signer_email'    => ['nullable', 'email', 'required_unless:channel,whatsapp'],
'signer_whatsapp' => ['nullable', 'string', 'max:20', 'required_if:channel,whatsapp'],
```

Mais duas regras de coerência, espelhando `EnvelopeController::parseSigners`:

- `channel = whatsapp` exige `user.whatsapp_envelope_enabled` → 422
  `"Canal WhatsApp não habilitado para esta conta."`
- `auth_method` compatível com o canal — `whatsapp` → `link|whatsapp_otp`;
  `email` → `link|email_otp` → 422 `"Método de verificação incompatível com o canal escolhido."`

Defaults `channel = 'email'` e `auth_method = 'link'` são aplicados no controller antes de montar
o array de `signers`, e `signer_cpf` é repassado como `expected_cpf`. `EnvelopeService::create`
passa a gravar `expected_cpf` a partir de `$s['expected_cpf'] ?? null`.

**Compatibilidade:** payload atual (sem `channel`, `auth_method` nem `signer_cpf`) produz
exatamente o comportamento de hoje.

## Fluxo de assinatura (ordem final em `SignEnvelopeController::store`)

1. `findSigner($token)`
2. `unavailableReason($signer)` — ganha o ramo `isCpfLocked()` →
   *"Este link foi bloqueado por tentativas repetidas de identificação incorreta. Fale com o remetente."*
3. `$request->validate(...)` — nome, CPF (regra `Cpf`), assinatura, OTP condicional, `consent`
4. Verificação de OTP (existente)
5. **Conferência de CPF:** se `expected_cpf !== null` e
   `Cpf::digits($input) !== Cpf::digits($signer->expected_cpf)` → `recordCpfMismatch()` e
   `back()->withErrors(['cpf' => 'O CPF informado não confere com o do destinatário deste documento.'])`
6. `EnvelopeService::sign()`

**OTP antes da conferência de CPF é intencional:** limita a queima das 5 tentativas a quem tem o
código. Com `auth_method = link` (o default da API) essa proteção não existe — qualquer um com o
link pode travar o envelope. É o custo aceito do bloqueio; o desbloqueio do § 2 é a válvula.

## Testes

**`tests/Unit/CpfTest.php`** — DV válido e inválido, sequências repetidas (`111...`), string vazia,
`digits`, `format`, `mask`.

**`tests/Feature/PublicSignFlowTest.php`** (acréscimos):

- assinar sem marcar o aceite → erro de validação, não assina
- aceite marcado grava `consent_accepted_at`/`consent_version` e o evento `consent_accepted`
- CPF com DV inválido → erro, não assina
- CPF divergente do `expected_cpf` → evento `cpf_mismatch`, signatário segue não-assinado
- 5ª divergência → evento `cpf_locked`, `Mail::assertSent(EnvelopeSignerLocked::class)`,
  e o `GET /sign/{token}` seguinte cai na tela `unavailable`
- `unlockCpf` zera o contador e o link volta a aceitar assinatura
- envelope sem `expected_cpf` assina normalmente (regressão)

**`tests/Feature/Api/EnvelopeApiControllerTest.php`** (acréscimos):

- `channel = whatsapp` cria o signatário com o canal certo e dispara o convite por WhatsApp (fake HTTP)
- `channel = whatsapp` com `whatsapp_envelope_enabled = false` → 422
- `channel = email` + `auth_method = whatsapp_otp` → 422
- `signer_cpf` inválido → 422
- `signer_cpf` válido grava `expected_cpf`
- payload atual sem os campos novos → `email`/`link` (regressão)

## Fora de escopo

- Biometria facial / prova de vida — próxima etapa, exige fornecedor externo
- Consulta de CPF na Receita/Serpro — este design confere contra o cadastro da loja, não contra a base oficial
- CPF esperado na tela de criação de envelopes (`/envelopes/create`) — só a API o informa nesta etapa
- Flag por cliente para tornar `signer_cpf` obrigatório — reavaliar depois da migração da loja
